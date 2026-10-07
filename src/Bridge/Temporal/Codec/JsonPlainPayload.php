<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal\Codec;

use Temporal\Api\Common\V1\Payload;
use Temporal\Api\Common\V1\Payloads;

/**
 * JSON payloads with metadata encoding=json/plain (Temporal default-style).
 *
 * @internal
 */
final class JsonPlainPayload
{
    private function __construct() {}

    private const ENCODING = 'json/plain';

    public static function encode(mixed $data): Payload
    {
        return self::encodeWithMetadata($data, []);
    }

    /**
     * Encode while adding metadata on top of the encoding's own.
     *
     * Used notably for search attributes, where every value announces its type.
     *
     * @param array<string, string> $extraMetadata
     */
    public static function encodeWithMetadata(mixed $data, array $extraMetadata): Payload
    {
        // Without the flag 30.0 goes out as 30 and reads back as an int (#826). A Double search
        // attribute accepts 30.0; an Int one only ever receives an int, whose bytes do not change.
        $json = json_encode($data, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION);

        return new Payload([
            'data' => $json,
            'metadata' => ['encoding' => self::ENCODING] + $extraMetadata,
        ]);
    }

    public static function decode(Payload $payload): mixed
    {
        $raw = $payload->getData();
        if ('' === $raw) {
            return null;
        }

        return json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
    }

    /**
     * @return list<mixed>
     */
    public static function decodePayloads(?Payloads $payloads): array
    {
        if (null === $payloads) {
            return [];
        }
        $out = [];
        foreach ($payloads->getPayloads() as $p) {
            $out[] = self::decode($p);
        }

        return $out;
    }

    public static function singlePayloads(Payload $p): Payloads
    {
        $ps = new Payloads();
        $ps->setPayloads([$p]);

        return $ps;
    }
}
