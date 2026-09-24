<?php

declare(strict_types=1);

// ponytail: a hand-written protobuf wire codec for the handful of Restate messages the spike uses.
// It answers "how much protocol does PHP need" without protoc; a real bridge would generate code
// from service-protocol/dev/restate/service/protocol.proto like Bridge/Temporal does.
final class Wire
{
    /** Frame = 16-bit type, 16-bit flags, 32-bit length, then the protobuf message. */
    public static function frame(int $type, string $message): string
    {
        return pack('nnN', $type, 0, \strlen($message)).$message;
    }

    /** @return list<array{int, string}> [type, message] in stream order */
    public static function frames(string $stream): array
    {
        $frames = [];
        for ($at = 0; $at < \strlen($stream); $at += 8 + $length) {
            ['type' => $type, 'length' => $length] = unpack('ntype/nflags/Nlength', $stream, $at);
            $frames[] = [$type, substr($stream, $at + 8, $length)];
        }

        return $frames;
    }

    public static function varint(int $field, int $value): string
    {
        return 0 === $value ? '' : self::rawVarint($field << 3).self::rawVarint($value);
    }

    public static function bytes(int $field, string $value): string
    {
        return self::rawVarint(($field << 3) | 2).self::rawVarint(\strlen($value)).$value;
    }

    /** @param list<int> $values */
    public static function packed(int $field, array $values): string
    {
        return [] === $values ? '' : self::bytes($field, implode('', array_map(self::rawVarint(...), $values)));
    }

    /** @return array<int, list<int|string>> field number => values (varints as int, length-delimited as string) */
    public static function decode(string $message): array
    {
        $fields = [];
        $at = 0;
        while ($at < \strlen($message)) {
            $key = self::readVarint($message, $at);
            $fields[$key >> 3][] = match ($key & 7) {
                0 => self::readVarint($message, $at),
                1 => substr($message, ($at += 8) - 8, 8),
                2 => substr($message, ($at += $length = self::readVarint($message, $at)) - $length, $length),
                5 => substr($message, ($at += 4) - 4, 4),
                default => throw new UnexpectedValueException('Unsupported wire type '.($key & 7)),
            };
        }

        return $fields;
    }

    private static function rawVarint(int $value): string
    {
        $out = '';
        do {
            $byte = $value & 0x7F;
            $value = ($value >> 7) & (\PHP_INT_MAX >> 6);
            $out .= \chr($value ? $byte | 0x80 : $byte);
        } while ($value);

        return $out;
    }

    private static function readVarint(string $message, int &$at): int
    {
        $value = 0;
        for ($shift = 0; ; $shift += 7) {
            $byte = \ord($message[$at++]);
            $value |= ($byte & 0x7F) << $shift;
            if ($byte < 0x80) {
                return $value;
            }
        }
    }
}
