---
title: Chiffrer les payloads
weight: 31
---

# Chiffrer les payloads

Avec un serveur Temporal opéré par un tiers, Temporal Cloud par exemple, chaque payload envoyé par
Durable est stocké dans l'historique du fournisseur : entrées et résultats des workflows, arguments
et résultats des activités, signaux, mises à jour, requêtes, mémos et en-têtes. TLS protège le
transport, pas le stockage. Un numéro de commande ou une adresse e-mail dans un payload, ce sont
des données personnelles confiées à un sous-traitant.

Un **codec de payload** comble l'essentiel de cet écart. Il encode chaque payload avant qu'il ne
quitte l'application et décode chaque payload qui revient : le serveur ne stocke que du chiffré.
Durable l'applique au client du service de workflows, par où passe chaque appel à Temporal
([DUR055](https://github.com/gplanchat/durable-dev/blob/main/documentation/adr/DUR055-a-payload-codec-at-the-client-boundary.md)).

Le codec ne concerne que le **backend Temporal**. Les journaux en mémoire et SQL restent dans votre
propre base, et les messages Messenger ne passent jamais par le codec.

---

## Durable fournit l'interface, pas le chiffrement

`Gplanchat\Bridge\Temporal\Codec\PayloadCodecInterface` a deux méthodes, `encode(Payload): Payload`
et `decode(Payload): Payload`. Le contrat est celui de Temporal :

- un payload encodé se signale dans ses métadonnées ;
- `decode()` rend tel quel un payload qui ne porte pas cette marque, si bien que l'historique écrit
  avant l'activation du codec reste lisible ;
- `decode()` lève une exception sur un payload qu'il reconnaît mais ne sait pas décoder, par exemple
  à cause d'une clé inconnue.

Durable ne fournit aucune implémentation. L'algorithme, les clés et leur rotation vous
appartiennent. La classe ci-dessous est un **exemple** sur lequel vous appuyer, pas une classe que
Durable livre ou maintient. Elle demande l'extension `sodium` de PHP (`ext-sodium`).

## Un exemple de codec : XChaCha20-Poly1305 avec libsodium

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

Voici ce qu'il fait, octet par octet, pour qu'un pair écrit dans un autre langage puisse lire le
même historique :

- **Texte clair** : le message `Payload` entier, sérialisé en protobuf. Ses propres métadonnées
  (`encoding` : `json/plain`, par exemple) sont scellées avec ses données, et `decode()` les rend à
  l'identique.
- **Métadonnées extérieures** : `encoding` = `binary/encrypted`, `encryption-key-id` = l'id de la
  clé.
- **Données** : un nonce aléatoire de 24 octets, puis le chiffré et son tag de 16 octets.
- **Données associées** : `binary/encrypted`, un octet NUL, puis l'id de la clé. Un payload dont on
  réécrit l'id de clé ne s'authentifie plus. Les données associées lient l'id de clé et l'encodage,
  pas le workflow ni le champ : quelqu'un qui a accès au serveur pourrait échanger deux payloads
  scellés avec la même clé, et chacun se décoderait encore.

Avec un nonce aléatoire, deux encodages d'une même valeur diffèrent. Le rejeu n'en souffre pas :
Durable compare des valeurs en clair, avant l'encodage et après le décodage.

### Clés et rotation

Générez une clé et conservez-la en base64 dans vos secrets :

```bash
php -r 'echo base64_encode(sodium_crypto_aead_xchacha20poly1305_ietf_keygen()), PHP_EOL;'
```

Le trousseau associe des ids de clé à des clés : toutes les clés déchiffrent, seule la clé active
chiffre. Faites tourner les clés en deux déploiements :

1. Ajoutez la nouvelle clé au trousseau de chaque processus, en gardant l'ancienne active. Déployez
   partout.
2. Ensuite seulement, dans un déploiement ultérieur, faites de la nouvelle clé la clé active.

Pendant un déploiement progressif, anciens et nouveaux processus tournent côte à côte : un payload
chiffré avec la nouvelle clé ne doit tomber sur aucun processus qui l'ignore. Et revenir sur le
second déploiement laisse la nouvelle clé dans le trousseau, si bien que ce qu'elle a chiffré reste
lisible. **Ne retirez jamais une clé tant qu'une exécution dont les payloads ont été chiffrés avec
elle reste dans la durée de rétention du namespace** : cette exécution ne serait plus lisible, ni
par un worker ni par un tableau de bord. Retirer le codec
lui-même est pire : plus rien ne décode cet historique, et son chiffré échoue partout où une valeur
JSON est attendue.

Chaque processus qui parle au namespace a besoin du même codec, dans le même déploiement : les
workers, ce qui démarre ou signale des workflows, et le tableau de bord.

Un payload indéchiffrable, sous une clé inconnue par exemple, lève une exception dans l'appel qui
l'a lu. Un tableau de bord affiche une erreur de lecture. Un worker est plus durement touché : le
décodage a lieu sur la réponse du poll, avant tout traitement de la tâche, et rien ne l'y
intercepte. Le processus du worker s'arrête sans signaler l'échec de la tâche, et Temporal ne la
redistribue qu'à l'expiration de son délai, à un worker qui s'arrête de la même façon. Faites
tourner vos workers sous un superviseur qui les relance (systemd, Supervisor, Kubernetes), et
alertez sur les arrêts répétés. Le ticket [#775](https://github.com/gplanchat/durable-dev/issues/775) prévoit de faire échouer la tâche à
la place. Ni un worker ni un tableau de bord ne présente du chiffré comme s'il s'agissait de
données.

---

## Ce qui reste en clair

Le codec transforme des payloads. Certaines valeurs dont Temporal a besoin n'en sont pas, et le
serveur les voit toujours :

- **les ids de workflow, et l'attribut de recherche `DurableExecutionId`**, qui portent votre id
  d'exécution ;
- **les noms de workflows, d'activités, de signaux et de mises à jour** ;
- **les messages d'échec et les traces d'appel**, comme dans le comportement par défaut de Temporal ;
- **les attributs de recherche**, à dessein : le serveur doit pouvoir les indexer.

**Gardez donc les données personnelles hors des ids d'exécution** : `order-8f3c2a`, ou un UUID,
plutôt que `order-jane.doe@example.com`. Il en va de même pour les messages d'exception et les
attributs de recherche.

---

## Le brancher

Le codec est un service de votre application ; Durable ne lit lui-même aucune clé. Les trois hôtes
partagent un seul réglage, la ligne `temporal.payload_codec` du
[tableau des hôtes](../configuration/#host-table).

**Symfony.** Nommez le service dans `durable.temporal.payload_codec`. Le trousseau est un tableau ;
déclarez donc ses arguments. La clé vient des secrets Symfony (`bin/console secrets:set
DURABLE_CODEC_KEY_2026_09`) ou de l'environnement :

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

**Laravel.** Liez le codec dans un service provider et nommez cette liaison dans
`temporal.payload_codec` de `config/durable.php`. Lisez la clé avec `config()`, et réservez `env()`
aux fichiers de configuration :

```php
// config/services.php: 'durable_codec' => ['keys' => ['2026-09' => env('DURABLE_CODEC_KEY_2026_09')], 'active' => '2026-09'],
$this->app->singleton(SodiumPayloadCodec::class, fn () => SodiumPayloadCodec::fromBase64(
    // An unset variable is left out, so the codec itself reports the missing key.
    array_filter((array) config('services.durable_codec.keys'), \is_string(...)),
    (string) config('services.durable_codec.active'),
));
// config/durable.php, under 'temporal': 'payload_codec' => SodiumPayloadCodec::class,
```

**Magento.** Nommez le codec dans l'argument `codec` de `RuntimeFactory`, dans le `di.xml` de votre
module. Le module de Durable ne déclare aucun argument `codec`, et Magento n'injecte pas
automatiquement un argument facultatif ; cette ligne est donc indispensable :

```xml
<type name="Gplanchat\DurableModule\Runtime\RuntimeFactory">
    <arguments>
        <argument name="codec" xsi:type="object">Vendor\Module\Temporal\PayloadCodec</argument>
    </arguments>
</type>
```

Faites charger votre module après celui de Durable, dans le `etc/module.xml` du module de la
boutique. Sans cette
`<sequence>`, Magento peut fusionner le `di.xml` de Durable après le vôtre : vos arguments de
`RuntimeFactory` peuvent alors être remplacés sans bruit, et les payloads partent en clair.

```xml
<module name="Vendor_Module">
    <sequence>
        <module name="Gplanchat_DurableModule"/>
    </sequence>
</module>
```

Les clés vivent dans `env.php`, sous `'durable' => ['codec' => ['active' => '2026-09', 'keys' =>
['2026-09' => '<base64>']]]`. Une petite classe les lit par `DeploymentConfig`, au premier usage
plutôt que dans son constructeur. Copiez le codec d'exemple dans le même namespace, à côté d'elle :

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

## Les pairs Nexus partagent le codec

Les payloads Nexus sont encodés comme les autres. Une application qui appelle une opération servie
par une autre application doit utiliser le même codec, avec les mêmes clés, que cette application ;
et réciproquement pour celle qui la sert. Un pair écrit avec un autre SDK a besoin de son propre
codec, conforme au format décrit plus haut. Voir [Opérations Nexus](../nexus/).

## L'interface web de Temporal affiche du chiffré

L'interface web et `temporal workflow show` lisent l'historique sur le serveur : ils affichent donc
des payloads `binary/encrypted`. La réponse de Temporal est un *serveur de codec* que l'interface
appelle pour décoder ; Durable n'en fournit pas. Les tableaux de bord de Durable passent, eux, par
le codec et affichent les valeurs décodées, auxquelles le masquage des payloads s'applique comme
avant.

---

## Voir aussi

- [Backends](../backends/) met en place le backend Temporal auquel le codec s'applique.
- [Configuration](../configuration/) liste toutes les clés de `durable.temporal`.
- [DUR055](https://github.com/gplanchat/durable-dev/blob/main/documentation/adr/DUR055-a-payload-codec-at-the-client-boundary.md) consigne la décision et ce qu'elle laisse en clair.
