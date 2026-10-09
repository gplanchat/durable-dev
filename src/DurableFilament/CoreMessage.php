<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Filament;

use Gplanchat\Durable\Observation\Message;

/**
 * A text the core words as a key and parameters (#850), translated from this package's lang files.
 * A key no locale has shows the core's English string.
 */
final class CoreMessage
{
    private function __construct() {}

    public static function say(?Message $message, string $english): string
    {
        if (!$message instanceof Message) {
            return $english;
        }

        $id = 'durable-filament::durable.' . $message->key;
        $text = __($id, $message->params);

        return \is_string($text) && $text !== $id ? $text : $english;
    }
}
