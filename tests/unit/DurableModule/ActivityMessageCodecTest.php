<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Magento;

use Gplanchat\Durable\Activity\ActivityOptions;
use Gplanchat\Durable\Duration;
use Gplanchat\Durable\Magento\Queue\ActivityMessageCodec;
use Gplanchat\Durable\Magento\Queue\UncarryableMessageException;
use Gplanchat\Durable\Transport\ActivityMessage;
use PHPUnit\Framework\TestCase;

/**
 * Ce que la file de Magento peut porter, et ce qu'elle doit refuser plutôt que perdre.
 *
 * Le §4.1 a mesuré pourquoi le module encode lui-même : donné un objet de transport, l'encodeur de
 * Magento rend `[]` **sans lever** — le publieur réussit, la charge est vide, et l'identifiant
 * d'exécution a disparu avant que le consommateur échoue. Le codec existe pour que cela ne puisse
 * pas arriver, et sa règle est la même dans l'autre sens : ce qu'il ne sait pas porter, il le
 * **nomme** au lieu de le laisser tomber.
 */
final class ActivityMessageCodecTest extends TestCase
{
    public function testAnOrdinaryActivityMakesTheRoundTrip(): void
    {
        $message = new ActivityMessage(
            executionId: 'magento-abc123',
            activityId: 'act-1',
            activityName: 'durable.demo.charge',
            payload: ['orderId' => 'ORD-4242', 'lines' => [['sku' => 'A', 'qty' => 2]]],
            attempt: 3,
            firstQueuedAt: 1787910907.5,
        );

        $back = (new ActivityMessageCodec())->decode((new ActivityMessageCodec())->encode($message));

        self::assertSame($message->executionId, $back->executionId);
        self::assertSame($message->activityId, $back->activityId);
        self::assertSame($message->activityName, $back->activityName);
        self::assertSame($message->payload, $back->payload);
        self::assertSame($message->attempt, $back->attempt);
        self::assertSame($message->firstQueuedAt, $back->firstQueuedAt);
    }

    /**
     * Une charge imbriquée est exactement ce que `string[]` déformait — clés associatives perdues,
     * `Array to string conversion` au décodage. Le codec la rend telle quelle.
     */
    public function testTheEncodedFormIsAStringMagentoCanCarry(): void
    {
        $encoded = (new ActivityMessageCodec())->encode(new ActivityMessage(
            'e',
            'a',
            'n',
            ['nested' => ['deep' => ['x' => 1]]],
        ));

        self::assertJson($encoded);
    }

    /**
     * @return iterable<string, array{ActivityMessage, string}>
     */
    public static function unrepresentable(): iterable
    {
        yield 'options' => [
            new ActivityMessage('e', 'a', 'n', [], new ActivityOptions()),
            'options',
        ];
        yield 'retryDelay' => [
            new ActivityMessage('e', 'a', 'n', [], null, 1, null, Duration::seconds(5)),
            'retryDelay',
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unrepresentable')]
    public function testWhatItCannotCarryIsRefusedByName(ActivityMessage $message, string $named): void
    {
        $this->expectException(UncarryableMessageException::class);
        $this->expectExceptionMessageMatches('/' . $named . '/');

        (new ActivityMessageCodec())->encode($message);
    }
}
