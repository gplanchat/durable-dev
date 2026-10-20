<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Observation;

/**
 * What a suspended run waits on (#324), kept as a kind and its parameters so a dashboard words it in
 * its own language and formats a deadline in its own way (#850).
 *
 * The stored form is one string, in the column and the memo that already held the English text: a
 * timer, an activity or an attempt is a small JSON object, and a condition, or any text written
 * before the kinds existed, is stored as it is. {@see fromStorage()} reads both, so a row written by
 * an earlier version keeps its words and nothing has to be migrated.
 */
final readonly class Wait
{
    private const TIMER = 'timer';
    private const ACTIVITY = 'activity';
    private const ATTEMPT = 'attempt';

    private function __construct(
        private ?string $kind,
        private string $name,
        private ?int $number = null,
    ) {}

    /** A condition, or text written before the kinds existed. */
    public static function text(string $reason): self
    {
        return new self(null, $reason);
    }

    public static function timer(string $summary, int $dueAt): self
    {
        return new self(self::TIMER, $summary, $dueAt);
    }

    public static function activity(string $name): self
    {
        return new self(self::ACTIVITY, $name);
    }

    public static function attempt(string $name, int $attempt): self
    {
        return new self(self::ATTEMPT, $name, $attempt);
    }

    public static function fromStorage(string $stored): self
    {
        $decoded = str_starts_with($stored, '{') ? json_decode($stored, true) : null;
        if (\is_array($decoded) && \is_string($decoded['name'] ?? null)) {
            $number = $decoded['number'] ?? null;
            $kind = $decoded['kind'] ?? null;
            // A timer and an attempt carry a number, an activity does not: anything else is text.
            if ((self::ACTIVITY === $kind && null === $number) || (\in_array($kind, [self::TIMER, self::ATTEMPT], true) && \is_int($number))) {
                return new self($kind, $decoded['name'], $number);
            }
        }

        return self::text($stored);
    }

    public function toStorage(): string
    {
        return null === $this->kind
            ? $this->name
            : json_encode(['kind' => $this->kind, 'name' => $this->name] + (null === $this->number ? [] : ['number' => $this->number]), \JSON_THROW_ON_ERROR);
    }

    /** The English words every surface has always shown. */
    public function toString(): string
    {
        return match ($this->kind) {
            self::TIMER => \sprintf(
                'timer %sdue at %s',
                '' === $this->name ? '' : '"' . $this->name . '" ',
                $this->deadline()->format(\DATE_ATOM),
            ),
            self::ACTIVITY => 'activity ' . $this->name,
            self::ATTEMPT => \sprintf('activity %s attempt %d in flight', $this->name, $this->number),
            default => $this->name,
        };
    }

    /** The same words as a key and parameters; a deadline is `Y-m-d H:i:s` in UTC, as the dashboards print dates. */
    public function message(): Message
    {
        return match ($this->kind) {
            self::TIMER => '' === $this->name
                ? new Message('run.waiting_on.timer_unnamed', ['due' => $this->deadline()->format('Y-m-d H:i:s')])
                : new Message('run.waiting_on.timer', ['name' => $this->name, 'due' => $this->deadline()->format('Y-m-d H:i:s')]),
            self::ACTIVITY => new Message('run.waiting_on.activity', ['name' => $this->name]),
            self::ATTEMPT => new Message('run.waiting_on.attempt', ['name' => $this->name, 'attempt' => $this->number]),
            default => new Message('run.waiting_on', ['reason' => $this->name]),
        };
    }

    private function deadline(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('@' . (int) $this->number);
    }
}
