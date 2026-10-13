<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Transport;

use Gplanchat\Durable\Activity\ActivityOptions;
use Gplanchat\Durable\Activity\ActivityTimeouts;
use Gplanchat\Durable\Activity\RetryLimit;
use Gplanchat\Durable\Bundle\Serializer\DurationNormalizer;
use Gplanchat\Durable\Bundle\Serializer\RetryLimitNormalizer;
use Gplanchat\Durable\Bundle\Serializer\TaskQueueNormalizer;
use Gplanchat\Durable\Duration;
use Gplanchat\Durable\TaskQueue;
use Gplanchat\Durable\Transport\ActivityMessage;
use Gplanchat\Durable\Transport\AwaitedFact;
use Gplanchat\Durable\Transport\DeliverWorkflowSignalMessage;
use Gplanchat\Durable\Transport\DeliverWorkflowUpdateMessage;
use Gplanchat\Durable\Transport\FireWorkflowTimersMessage;
use Gplanchat\Durable\Transport\ResumeWorkflowMessage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\Serializer;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ArrayDenormalizer;
use Symfony\Component\Serializer\Normalizer\BackedEnumNormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer as SymfonySerializer;

/**
 * #643: an application that sets `messenger.transport.symfony_serializer` sends every Durable
 * message as JSON, through its Symfony Serializer, not only the resume #627 covered. The serializer
 * here has the normalizers FrameworkBundle registers that these messages reach (backed enums,
 * arrays, objects) and the three DurableBundle adds: without them the ObjectNormalizer reads
 * `Duration::zero()` and `RetryLimit::unlimited()` as properties and recurses without end, and
 * rebuilds a TaskQueue without its name.
 */
final class EveryMessageCrossesMessengersJsonSerializerTest extends TestCase
{
    /**
     * Each message filled in as far as it goes, value objects included: an empty option is no
     * proof that the object behind it crosses.
     *
     * @return iterable<class-string, array{object}>
     */
    public static function messages(): iterable
    {
        yield ResumeWorkflowMessage::class => [new ResumeWorkflowMessage(
            'exec-1',
            [['name' => 'approve', 'arguments' => ['by' => 'ops']]],
            AwaitedFact::timers(['timer-1', 'timer-2']),
        )];
        yield FireWorkflowTimersMessage::class => [new FireWorkflowTimersMessage('exec-1')];
        yield DeliverWorkflowSignalMessage::class => [new DeliverWorkflowSignalMessage('exec-1', 'approved', ['by' => 'ops'], 'request-1')];
        yield DeliverWorkflowUpdateMessage::class => [new DeliverWorkflowUpdateMessage('exec-1', 'setLimit', ['limit' => 3], 'update-1')];
        yield ActivityMessage::class => [new ActivityMessage(
            'exec-1',
            'act-1',
            'quote',
            ['sku' => 'A-1'],
            new ActivityOptions(
                retryLimit: RetryLimit::ofAttempts(5),
                initialInterval: Duration::seconds(2.0),
                backoffCoefficient: 1.5,
                maximumInterval: Duration::minutes(1.0),
                nonRetryableExceptions: [\DomainException::class],
                taskQueue: TaskQueue::named('quotes'),
                activityId: 'quote-1',
                timeouts: new ActivityTimeouts(startToClose: Duration::seconds(30.0), heartbeat: Duration::seconds(5.0)),
                summary: 'Quote A-1',
            ),
            2,
            1_790_000_000.5,
            Duration::milliseconds(250.0),
        )];
    }

    #[DataProvider('messages')]
    public function testAMessageReadsBackEqual(object $message): void
    {
        $serializer = new Serializer(new SymfonySerializer(
            [new DurationNormalizer(), new RetryLimitNormalizer(), new TaskQueueNormalizer(), new BackedEnumNormalizer(), new ArrayDenormalizer(), new ObjectNormalizer(propertyTypeExtractor: new ReflectionExtractor())],
            [new JsonEncoder()],
        ));

        $read = $serializer->decode($serializer->encode(new Envelope($message)))->getMessage();

        self::assertEquals($message, $read);
    }

    /**
     * A message is what a handler takes: a new handler brings a new message, and it has to join the
     * list above. Every `__invoke()` of a `*Handler` class under src/ counts, whichever package
     * it ships in.
     */
    public function testEveryHandledMessageIsCovered(): void
    {
        $handled = [];
        foreach (new \RegexIterator(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(\dirname(__DIR__, 4) . '/src')), '/Handler\.php$/') as $file) {
            if (!preg_match('/^namespace (.+);$/m', (string) file_get_contents($file->getPathname()), $namespace)) {
                continue;
            }
            $class = $namespace[1] . '\\' . $file->getBasename('.php');
            if (!class_exists($class) || !method_exists($class, '__invoke')) {
                continue;
            }
            $type = ((new \ReflectionMethod($class, '__invoke'))->getParameters()[0] ?? null)?->getType();
            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                $handled[$type->getName()] = $class;
            }
        }
        ksort($handled);

        $covered = array_keys(iterator_to_array(self::messages()));
        sort($covered);

        self::assertNotEmpty($handled);
        self::assertSame(array_keys($handled), $covered, 'every message a handler takes needs a case in messages()');
    }
}
