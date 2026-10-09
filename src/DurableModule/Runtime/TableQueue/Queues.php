<?php

declare(strict_types=1);

namespace Gplanchat\DurableModule\Runtime\TableQueue;

/**
 * The three queues of the SQL backend and the body format they share.
 *
 * A body is a PHP-serialized message of the core (`ResumeWorkflowMessage`, `ActivityMessage`,
 * `FireWorkflowTimersMessage`), as the Laravel and Messenger transports carry them. The table is
 * the journal's own, on its dedicated connection: nothing a user writes reaches `unserialize()`.
 */
final class Queues
{
    public const RESUME = 'durable_resume';
    public const ACTIVITY = 'durable_activity';
    public const TIMER = 'durable_timer';

    public static function encode(object $message): string
    {
        return serialize($message);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    public static function decode(string $body, string $class): object
    {
        $message = unserialize($body);
        if (!$message instanceof $class) {
            throw new \UnexpectedValueException(\sprintf('A queued body was to carry a %s, it carries %s.', $class, get_debug_type($message)));
        }

        return $message;
    }
}
