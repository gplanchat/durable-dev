<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Observation;

use Gplanchat\Durable\Observation\PayloadRedactorInterface;
use Gplanchat\Durable\Observation\RunTimeline;
use Gplanchat\Durable\Observation\WorkflowRunEvent;
use Gplanchat\Durable\Observation\WorkflowRunEventKind;
use PHPUnit\Framework\TestCase;

/**
 * Every host's run page prints `renderedDetails`: masking it here masks the Sylius plugin, Magento
 * and whatever reads the timeline next, with #488's redactor and no second one (#507).
 */
final class TheRunTimelineMasksSecretsTest extends TestCase
{
    private const DETAILS = ['email' => 'ada@example.com', 'password' => 'hunter2', 'api_key' => 'sk-live-123'];

    public function testTheDetailsAreMaskedByDefault(): void
    {
        $rendered = self::rendered(RunTimeline::of([self::event()]));

        self::assertStringNotContainsString('hunter2', $rendered);
        self::assertStringNotContainsString('sk-live-123', $rendered);
        self::assertStringContainsString('ada@example.com', $rendered, 'the rest is what the operator came to read');
    }

    public function testTheRedactorAHostHandsIsTheOneApplied(): void
    {
        $raw = new class implements PayloadRedactorInterface {
            public function redact(mixed $payload): mixed
            {
                return $payload;
            }
        };

        self::assertStringContainsString('hunter2', self::rendered(RunTimeline::of([self::event()], $raw)));
    }

    private static function event(): WorkflowRunEvent
    {
        return new WorkflowRunEvent(1, new \DateTimeImmutable('@1700000000'), WorkflowRunEventKind::Activity, 'Signup', self::DETAILS, 'activity:act-1');
    }

    private static function rendered(RunTimeline $timeline): string
    {
        return (string) $timeline->actions[0]->events[0]->renderedDetails;
    }
}
