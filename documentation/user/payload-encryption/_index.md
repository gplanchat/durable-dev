---
title: Encrypting payloads
weight: 31
---

# Encrypting payloads

With a Temporal server run by someone else, Temporal Cloud for instance, every payload Durable
sends is stored in the provider's history: workflow inputs and results, activity arguments and
results, signals, updates, queries, memos and headers. TLS protects the transport, not the
storage. An order number or an e-mail address in a payload is personal data handed to a processor.

A **payload codec** closes most of that gap. It encodes every payload before it leaves the
application and decodes every payload that comes back, so the server stores ciphertext. Durable
applies it at the workflow service client, where every call to Temporal passes
([DUR055](https://github.com/gplanchat/durable-dev/blob/main/documentation/adr/DUR055-a-payload-codec-at-the-client-boundary.md)).

The codec belongs to the **Temporal backend** only. The in-memory and SQL journals stay in your own
database, and Messenger messages never pass through the codec.

---

## Durable ships the interface, not a cipher

`Gplanchat\Bridge\Temporal\Codec\PayloadCodecInterface` has two methods, `encode(Payload): Payload`
and `decode(Payload): Payload`. The contract is Temporal's:

- an encoded payload marks itself in its metadata;
- `decode()` returns a payload without that mark unchanged, so history written before the codec was
  enabled stays readable;
- `decode()` throws on a payload it recognises but cannot decode, an unknown key for instance.

Durable provides no implementation. The algorithm, the keys and their rotation are yours. The class
below is an **example** to start from, not a class Durable ships or supports. It needs PHP's
`sodium` extension (`ext-sodium`).

## An example codec: libsodium XChaCha20-Poly1305

```php
<?php

declare(strict_types=1);

namespace App\Temporal;

use Gplanchat\Bridge\Temporal\Codec\PayloadCodecInterface;
use Temporal\Api\Common\V1\Payload;

final class SodiumPayloadCodec implements PayloadCodecInterface
{
    private const ENCODING = 'binary/encrypted';
    private const NONCE_BYTES = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;

    /**
     * @param array<string, string> $keys        key id => 32-byte raw key, every key still needed to read history
     * @param string                $activeKeyId the key new payloads are sealed with
     */
    public function __construct(
        private readonly array $keys,
        private readonly string $activeKeyId,
    ) {
        if (!isset($keys[$activeKeyId])) {
            throw new \InvalidArgumentException(\sprintf('No active key "%s" in the keyring.', $activeKeyId));
        }
        foreach ($keys as $id => $key) {
            if (SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES !== \strlen($key)) {
                throw new \InvalidArgumentException(\sprintf('Key "%s" must be 32 raw bytes.', $id));
            }
        }
    }

    /**
     * @param array<string, string> $keys key id => base64-encoded key, as kept in secrets
     */
    public static function fromBase64(array $keys, string $activeKeyId): self
    {
        return new self(array_map(
            static fn (string $key): string => base64_decode($key, true) ?: throw new \InvalidArgumentException('A codec key is not valid base64.'),
            $keys,
        ), $activeKeyId);
    }

    public function encode(Payload $payload): Payload
    {
        $nonce = random_bytes(self::NONCE_BYTES);
        // The whole payload is sealed, its own metadata included, so decode() restores it exactly.
        $sealed = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $payload->serializeToString(),
            self::additionalData($this->activeKeyId),
            $nonce,
            $this->keys[$this->activeKeyId],
        );

        return new Payload([
            'metadata' => ['encoding' => self::ENCODING, 'encryption-key-id' => $this->activeKeyId],
            'data' => $nonce . $sealed,
        ]);
    }

    public function decode(Payload $payload): Payload
    {
        $metadata = iterator_to_array($payload->getMetadata());
        if (self::ENCODING !== ($metadata['encoding'] ?? null)) {
            return $payload; // written before the codec was enabled
        }
        $keyId = $metadata['encryption-key-id'] ?? '';
        $key = $this->keys[$keyId] ?? throw new \RuntimeException(\sprintf('No key "%s" in the keyring.', $keyId));
        $data = $payload->getData();
        if (\strlen($data) < self::NONCE_BYTES) {
            throw new \RuntimeException('The encrypted payload is truncated.');
        }
        $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            substr($data, self::NONCE_BYTES),
            self::additionalData($keyId),
            substr($data, 0, self::NONCE_BYTES),
            $key,
        );
        if (false === $plain) {
            throw new \RuntimeException(\sprintf('The payload does not authenticate with key "%s".', $keyId));
        }
        $decoded = new Payload();
        $decoded->mergeFromString($plain);

        return $decoded;
    }

    private static function additionalData(string $keyId): string
    {
        return self::ENCODING . "\0" . $keyId;
    }
}
```

What it does, byte for byte, so a peer written in another language can read the same history:

- **Plaintext**: the whole `Payload` message, serialized as protobuf. Its own metadata (`encoding`:
  `json/plain`, for instance) is sealed with its data, and `decode()` returns it exactly.
- **Outer metadata**: `encoding` = `binary/encrypted`, `encryption-key-id` = the key id.
- **Data**: a random 24-byte nonce, then the ciphertext and its 16-byte tag.
- **Associated data**: `binary/encrypted`, a NUL byte, then the key id. A payload whose key id is
  rewritten no longer authenticates. The associated data binds the key id and the encoding, not the
  workflow or the field: someone with access to the server could swap two payloads sealed with the
  same key, and each would still decode.

A random nonce makes two encodings of the same value differ. Replay is not affected: Durable compares
plain values, before encoding and after decoding.

### Keys and rotation

Generate a key and keep it as base64 in your secrets:

```bash
php -r 'echo base64_encode(sodium_crypto_aead_xchacha20poly1305_ietf_keygen()), PHP_EOL;'
```

The keyring maps key ids to keys: every key decrypts, only the active one seals. Rotate in two
deployments:

1. Add the new key to the keyring of every process, and keep the old key active. Deploy everywhere.
2. Only then, in a later deployment, make the new key the active one.

During a rolling deployment, old and new processes run side by side: a payload sealed with the new
key must meet no process that lacks it. And rolling back the second deployment keeps the new key in
the keyring, so what it sealed stays readable. **Never drop a key while a run whose payloads were
sealed with it is still within the namespace's retention**: that run can no longer be read, by a
worker or by a dashboard. Removing the codec altogether is worse: nothing decodes that
history any more, and its ciphertext fails wherever a JSON value is expected.

Every process that talks to the namespace needs the same codec in the same deployment: the workers,
whatever starts or signals workflows, and the dashboard.

A payload that cannot be decoded, under an unknown key for instance, throws from the call that read
it. A dashboard shows a read failure. A worker is hit harder: the decode runs on the poll response,
before any task handling, and nothing catches it there. The worker process stops without reporting
the task as failed, and Temporal hands the task out again only once its timeout expires, to a
worker that stops the same way. Run workers under a supervisor that restarts them (systemd,
Supervisor, Kubernetes), and alert on repeated exits. [#775](https://github.com/gplanchat/durable-dev/issues/775) tracks failing the task
instead. Neither a worker nor a dashboard shows ciphertext as if it were data.

---

## What stays in clear

The codec transforms payloads. Some values Temporal needs are not payloads, and the server still
sees them:

- **workflow ids, and the `DurableExecutionId` search attribute**, which carry your execution id;
- **workflow, activity, signal and update names**;
- **failure messages and stack traces**, as in Temporal's own default;
- **search attributes**, by design: the server must be able to index them.

So **keep personal data out of execution ids**. `order-8f3c2a`, or a UUID, rather than
`order-jane.doe@example.com`. The same goes for exception messages and search attributes.

---

## Wiring it

The codec is a service of your application; Durable reads no key itself. The three hosts share one
setting, the `temporal.payload_codec` row of the [host table](../configuration/#host-table).

**Symfony.** Name the service in `durable.temporal.payload_codec`. The keyring is an array, so
declare its arguments; the key comes from Symfony secrets (`bin/console secrets:set
DURABLE_CODEC_KEY_2026_09`) or the environment:

```yaml
services:
    App\Temporal\SodiumPayloadCodec:
        arguments:
            $keys: { '2026-09': '%env(base64:DURABLE_CODEC_KEY_2026_09)%' }
            $activeKeyId: '2026-09'

durable:
    temporal:
        payload_codec: App\Temporal\SodiumPayloadCodec
```

**Laravel.** Bind the codec in a service provider and name the binding in `temporal.payload_codec`
of `config/durable.php`. Read the key through `config()`, with `env()` in a config file only:

```php
// config/services.php: 'durable_codec' => ['keys' => ['2026-09' => env('DURABLE_CODEC_KEY_2026_09')], 'active' => '2026-09'],
$this->app->singleton(SodiumPayloadCodec::class, fn () => SodiumPayloadCodec::fromBase64(
    // An unset variable is left out, so the codec itself reports the missing key.
    array_filter((array) config('services.durable_codec.keys'), \is_string(...)),
    (string) config('services.durable_codec.active'),
));
// config/durable.php, under 'temporal': 'payload_codec' => SodiumPayloadCodec::class,
```

**Magento.** Name the codec in the `codec` argument of `RuntimeFactory`, in your module's
`di.xml`. Durable's module declares no `codec` argument, and Magento does not autowire an optional
one, so this line is required:

```xml
<type name="Gplanchat\DurableModule\Runtime\RuntimeFactory">
    <arguments>
        <argument name="codec" xsi:type="object">Vendor\Module\Temporal\PayloadCodec</argument>
    </arguments>
</type>
```

Make your module load after Durable's, in its `etc/module.xml`. Without that `<sequence>`, Magento
may merge Durable's `di.xml` after yours, your arguments for `RuntimeFactory` can be silently
replaced, and payloads leave in clear:

```xml
<module name="Vendor_Module">
    <sequence>
        <module name="Gplanchat_DurableModule"/>
    </sequence>
</module>
```

The keys live in `env.php`, under `'durable' => ['codec' => ['active' => '2026-09', 'keys' =>
['2026-09' => '<base64>']]]`. A small class reads them through `DeploymentConfig`, on first use
rather than in its constructor. Copy the example codec into the same namespace, beside it:

```php
namespace Vendor\Module\Temporal;

use Gplanchat\Bridge\Temporal\Codec\PayloadCodecInterface;
use Magento\Framework\App\DeploymentConfig;
use Temporal\Api\Common\V1\Payload;

final class PayloadCodec implements PayloadCodecInterface
{
    private ?SodiumPayloadCodec $codec = null;

    public function __construct(private readonly DeploymentConfig $config) {}

    public function encode(Payload $payload): Payload { return $this->codec()->encode($payload); }

    public function decode(Payload $payload): Payload { return $this->codec()->decode($payload); }

    private function codec(): SodiumPayloadCodec
    {
        return $this->codec ??= SodiumPayloadCodec::fromBase64(
            (array) $this->config->get('durable/codec/keys'),
            (string) $this->config->get('durable/codec/active'),
        );
    }
}
```

---

## Nexus peers share the codec

Nexus payloads are encoded like the rest. An application that calls an operation served by another
application must use the same codec, with the same keys, as that application; so must the
application that serves it. A peer written with another SDK needs a codec of its own that follows
the wire format above. See [Nexus operations](../nexus/).

## The Temporal Web UI shows ciphertext

The Web UI and `temporal workflow show` read history from the server, so they show
`binary/encrypted` payloads. Temporal's answer is a *codec server* the UI calls to decode;
Durable does not provide one. Durable's own dashboards go through the codec and show decoded
values, masked by the payload redactor as before.

---

## See also

- [Backends](../backends/) sets up the Temporal backend the codec applies to.
- [Configuration](../configuration/) lists every key of `durable.temporal`.
- [DUR055](https://github.com/gplanchat/durable-dev/blob/main/documentation/adr/DUR055-a-payload-codec-at-the-client-boundary.md) records the decision and what it leaves in clear.
