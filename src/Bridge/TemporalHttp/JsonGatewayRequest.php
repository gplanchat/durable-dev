<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\TemporalHttp;

/**
 * How a protobuf request, in its JSON form, becomes a gateway URL: path placeholders are
 * proto field paths in snake_case (`{execution.workflow_id}`), the JSON fields are camelCase,
 * and a GET route carries the remaining fields as dotted query parameters.
 */
final class JsonGatewayRequest
{
    private function __construct() {}

    /**
     * @param array<string, mixed> $fields
     */
    public static function path(string $template, array $fields): string
    {
        return (string) preg_replace_callback('/\{([a-z_.]+)\}/', static function (array $match) use ($fields): string {
            $value = $fields;
            foreach (explode('.', $match[1]) as $segment) {
                $key = self::camel($segment);
                if (!\is_array($value) || !\array_key_exists($key, $value)) {
                    return '';
                }
                $value = $value[$key];
            }

            return rawurlencode(\is_scalar($value) ? (string) $value : '');
        }, $template);
    }

    /**
     * @param array<string, mixed> $fields
     */
    public static function query(array $fields): string
    {
        $pairs = [];
        self::flatten($fields, '', $pairs);

        return implode('&', $pairs);
    }

    /**
     * @param array<array-key, mixed> $fields
     * @param list<string>            $pairs
     */
    private static function flatten(array $fields, string $prefix, array &$pairs): void
    {
        foreach ($fields as $key => $value) {
            $name = '' === $prefix ? (string) $key : $prefix . '.' . $key;
            if (\is_array($value)) {
                if (array_is_list($value)) {
                    foreach ($value as $item) {
                        if (\is_scalar($item)) {
                            $pairs[] = rawurlencode($name) . '=' . rawurlencode(self::scalar($item));
                        }
                    }
                } else {
                    self::flatten($value, $name, $pairs);
                }
                continue;
            }
            if (\is_scalar($value)) {
                $pairs[] = rawurlencode($name) . '=' . rawurlencode(self::scalar($value));
            }
        }
    }

    private static function scalar(bool|int|float|string $value): string
    {
        return \is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
    }

    private static function camel(string $snake): string
    {
        return lcfirst(str_replace('_', '', ucwords($snake, '_')));
    }
}
