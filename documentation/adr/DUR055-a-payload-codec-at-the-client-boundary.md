# DUR055: A payload codec at the client boundary

## Status

Accepted — approved by the user on 2026-09-30. The shape (one codec at the workflow service
client, an interface and no shipped cipher, the key delivered by the host, Nexus encoded like the
rest, the plaintext remainder documented rather than closed) is the user's decision of the same day.

## Context

With a Temporal server run by a third party (Temporal Cloud), every payload the bridge sends is
stored in clear in the provider's history. This covers workflow inputs and results, activity
arguments and results, signals, updates, queries, memos and headers. TLS protects the transport,
not the storage. A European merchant who passes an order number or an e-mail address in a payload
hands personal data to a processor outside its own account. A partner evaluating the Magento module
named this as the condition for using Temporal Cloud.

Temporal answers this with a *payload codec*: a client-side transform, typically encryption, applied
to payloads before they leave and after they come back. The bridge has no such point. Payloads are
built by `JsonPlainPayload`, a static converter called directly at 59 places in `src/`, and nothing
between those calls and the wire can be substituted.

Two facts shape the answer:

- **Every RPC goes through one method.** The two workflow service clients, gRPC and the JSON
  gateway, extend `AbstractWorkflowServiceClient`, and every RPC reaches the transport through its
  `call()`. The three hosts build their client with `WorkflowServiceClientFactory::create()`: the
  Symfony bundle, the Laravel service provider and the Magento `RuntimeFactory`. The activity,
  execution and Nexus RPC helpers, and the history cursor, take `WorkflowServiceClientInterface`.
  `createTransport()` has no caller, and nothing requests raw history. A transform at the client
  sees every payload the application sends or reads.
- **Temporal places its own codec there.** The Go SDK's gRPC codec interceptor walks every `Payload`
  of a request and of a response, and skips search attributes, which the server must be able to
  index.

### Not the serializer

A codec is not a serializer, and does not compete with Symfony's:

- **A serializer turns objects into data.** In Durable the Symfony serializer serves Messenger
  alone: three normalizers (`Duration`, `RetryLimit`, `TaskQueue`), registered when the
  application has `symfony/serializer`, carry activity messages on the DBAL and Messenger
  backends. The Temporal bridge does not use it: `JsonPlainPayload` builds its payloads, with no
  framework involved.
- **A codec turns bytes into bytes, after that conversion.** It transforms a payload already
  built, just before it leaves for the server. Temporal separates the two stages the same way: a
  *payload converter* for the data, a *payload codec* for the bytes. This ADR is about the second.
- **Messenger is outside its scope.** The codec sits in the Temporal workflow service client, and
  Messenger messages never pass through it. They stay in the application's own infrastructure,
  its database or its broker. Encrypting them for a broker run by a third party would belong to
  Messenger's transport serializer, and would be another decision.
- **Symfony's secrets meet it at one point only:** they are how a Symfony application hands the
  codec its key.

## Decision

1. **The bridge defines `PayloadCodecInterface`**: `encode(Payload): Payload` and
   `decode(Payload): Payload`. The contract is Temporal's:
   - an encoded payload marks itself in its metadata;
   - `decode()` returns a payload without its codec's mark unchanged, so history written before the
     codec was enabled stays readable;
   - `decode()` throws on a payload it recognises but cannot decode, for example an unknown key id.
2. **A decorator applies it at the client boundary.** `PayloadCodecWorkflowServiceClient` extends
   `AbstractWorkflowServiceClient` and wraps the transport's client. On each call:
   - it walks a copy of the request, never the caller's object, so a request sent twice is not
     encoded twice;
   - it encodes every `Payload`, calls the inner client, then decodes every `Payload` of the
     response.

   The walk reads the generated descriptors, so a message added by a later API version is covered
   without a list to maintain. It skips `temporal.api.common.v1.SearchAttributes` by message type.
   It visits singular, repeated and map fields, which covers `Header.fields` and `Memo.fields`.
   It unpacks a `google.protobuf.Any`, walks it and packs it again: the update protocol carries its
   requests and results that way. An `Any` of an unknown type fails rather than pass in clear.
   `WorkflowServiceClientFactory::create()` takes an optional codec and wraps its client when one is
   given. Without a codec, nothing changes.
3. **Durable ships the interface, not a cipher.** Key handling, algorithm and rotation are the
   application's. The documentation shows a codec built on libsodium's XChaCha20-Poly1305, with a
   random nonce, the key id and encoding bound as associated data, and a keyring for decryption. It
   is an example, not a supported class.
4. **The host delivers the codec as a service.** Symfony names it in the bundle configuration, and
   the key comes from the application's secrets or environment. Laravel binds it in its container.
   Magento declares it in `di.xml`, with the key in `env.php`. Durable reads no key itself.
5. **Nexus payloads are encoded like the rest.** An application that calls a Nexus operation served
   by another application shares the codec, and its key, with it.
6. **What stays in clear is documented, not closed.** A boundary codec transforms payloads, and some
   values are not payloads:
   - workflow ids, and the `DurableExecutionId` search attribute, which carry the application's
     execution id;
   - workflow, activity, signal and update names;
   - failure messages and stack traces, as in Temporal's default, which encodes them only when a
     failure converter moves them into `encoded_attributes`;
   - search attributes, by design.

   The documentation says so, and advises execution ids without personal data.

## Consequences

- **Replay is unaffected.** Commands are built from plain payloads and encoded only on the way out.
  History is decoded on the way in. The divergence guard (DUR042) compares plain values, and a
  codec with a random nonce does not make replay diverge.
- **The dashboards read decoded history.** The run catalogs use the same client. The payload
  redactor still masks what it masked, on decoded values.
- **An unknown key fails loudly.** A worker whose codec cannot decode a task's history fails that
  task, and Temporal retries it. A dashboard that cannot decode a run shows a read failure, as for
  any backend error. Neither shows ciphertext as if it were data.
- **The Temporal Web UI shows ciphertext.** A codec server that would let it decode is out of scope.
- **`WorkflowServiceClientFactory::create()` gains an optional argument.** It is not a break.
- **Delivery comes in slices:**
  1. the bridge: interface, decorator, walker, and a test that reads the history back through an
     undecorated client and finds no plaintext;
  2. host wiring, one pull request per host;
  3. the documentation, with the example codec and the plaintext remainder.
