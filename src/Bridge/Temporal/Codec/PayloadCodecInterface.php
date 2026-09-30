<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal\Codec;

use Temporal\Api\Common\V1\Payload;

/**
 * A client-side transform of every payload the bridge sends and reads, typically encryption for a
 * server run by a third party (DUR055). The application provides it; Durable ships none.
 *
 * The contract is Temporal's:
 * - an encoded payload marks itself in its metadata;
 * - `decode()` returns a payload without its mark unchanged, so history written before the codec
 *   was enabled stays readable;
 * - `decode()` throws on a payload it recognises but cannot decode, an unknown key for instance.
 */
interface PayloadCodecInterface
{
    public function encode(Payload $payload): Payload;

    public function decode(Payload $payload): Payload;
}
