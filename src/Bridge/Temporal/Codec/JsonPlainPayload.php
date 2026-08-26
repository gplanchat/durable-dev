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
    private const ENCODING = 'json/plain';

    public static function encode(mixed $data): Payload
    {
        return self::encodeWithMetadata($data, []);
    }

    /**
     * Encode en ajoutant des métadonnées à celles de l'encodage.
     *
     * Sert notamment aux attributs de recherche, dont chaque valeur annonce son type.
     *
     * @param array<string, string> $extraMetadata
     */
    public static function encodeWithMetadata(mixed $data, array $extraMetadata): Payload
    {
        $json = json_encode($data, \JSON_THROW_ON_ERROR);

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
