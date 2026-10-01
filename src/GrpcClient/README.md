# gplanchat/grpc-client

gRPC unary calls over HTTP/2 without `ext-grpc`. One call is one POST: the length-prefixed protobuf
frame in the body, the status in the response trailers.

## Requirements

- PHP 8.2 or newer and `google/protobuf`.
- `ext-curl` built with HTTP/2 for `CurlGrpcTransport`, or Guzzle 7.14 or newer on its cURL handler
  for `GuzzleGrpcTransport`. Only the cURL handler exposes trailers.

## Use

```php
use Gplanchat\GrpcClient\CurlGrpcTransport;
use Gplanchat\GrpcClient\GrpcEndpoint;
use Gplanchat\GrpcClient\GrpcException;

$transport = new CurlGrpcTransport(new GrpcEndpoint('127.0.0.1:50051'));

try {
    $reply = $transport->unary('/helloworld.Greeter/SayHello', $request, HelloReply::class, [], 2_000);
} catch (GrpcException $e) {
    $e->getCode();      // the gRPC status code, 5 for NOT_FOUND
    $e->statusMessage;  // grpc-message, percent-decoded
}
```

## Reference

| Class | Role |
|---|---|
| `GrpcTransport` | `unary(string $method, Message $request, string $responseClass, array $metadata, ?int $timeoutMs): Message`. `$method` is the full path. `$timeoutMs` becomes the `grpc-timeout` header; `null` sends none |
| `GrpcEndpoint` | `host:port`, `tls`, CA, client certificate and key, metadata sent with every call, user agent. The metadata is kept out of dumps |
| `CurlGrpcTransport` | One HTTP/2 POST through curl. Plain-text endpoints use HTTP/2 prior knowledge, TLS ones negotiate it |
| `GuzzleGrpcTransport` | The same exchange through a Guzzle client |
| `GrpcWire` | Framing, status mapping and header helpers, free of any HTTP client |
| `GrpcException` | A call that did not end in OK; the gRPC status code is the exception code |

Compressed frames are refused with `UNIMPLEMENTED`. Streaming calls are not supported.
