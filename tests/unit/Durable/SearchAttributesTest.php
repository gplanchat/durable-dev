<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable;

use Gplanchat\Durable\ChildWorkflowOptions;
use Gplanchat\Durable\SearchAttributes;
use Gplanchat\Durable\SearchAttributeType;
use Gplanchat\Durable\WorkflowStartOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Trois règles serveur, sondées une par une ; deux sont vérifiables ici, la troisième non.
 *
 * @see \integration\Temporal\SearchAttributesTest
 */
final class SearchAttributesTest extends TestCase
{
    public function testEachTypeNormalisesItsValue(): void
    {
        $attributes = SearchAttributes::none()
            ->keyword('DurableOrderId', 'ORD-1')
            ->text('DurableNote', 'un commentaire')
            ->int('DurableAmount', 42)
            ->double('DurableRatio', 3)
            ->bool('DurablePaid', true)
            ->keywordList('DurableTags', ['a', 'b'])
            ->datetime('DurableDueAt', new \DateTimeImmutable('2026-01-01 00:00:00', new \DateTimeZone('UTC')));

        self::assertSame([
            'DurableOrderId' => 'ORD-1',
            'DurableNote' => 'un commentaire',
            'DurableAmount' => 42,
            'DurableRatio' => 3.0,
            'DurablePaid' => true,
            'DurableTags' => ['a', 'b'],
            'DurableDueAt' => '2026-01-01T00:00:00.000+00:00',
        ], $attributes->toValues());
    }

    #[DataProvider('mismatchedValues')]
    public function testAValueThatDoesNotMatchItsTypeIsRejected(SearchAttributeType $type, mixed $value): void
    {
        // Le serveur refuse au démarrage (« invalid value for search attribute … of type Int ») ;
        // autant le voir à l'écriture.
        $this->expectExceptionMessageMatches('/is of type .* and needs/');

        SearchAttributes::none()->with('DurableThing', $type, $value);
    }

    /**
     * @return iterable<string, array{SearchAttributeType, mixed}>
     */
    public static function mismatchedValues(): iterable
    {
        yield 'Int recevant une chaîne' => [SearchAttributeType::Int, 'quarante-deux'];
        yield 'Keyword recevant un entier' => [SearchAttributeType::Keyword, 42];
        yield 'Bool recevant une chaîne' => [SearchAttributeType::Bool, 'true'];
        yield 'Double recevant une chaîne' => [SearchAttributeType::Double, '3.5'];
        yield 'KeywordList recevant une chaîne' => [SearchAttributeType::KeywordList, 'a'];
        yield 'KeywordList contenant un entier' => [SearchAttributeType::KeywordList, ['a', 2]];
        yield 'Datetime recevant du charabia' => [SearchAttributeType::Datetime, 'pas une date'];
    }

    #[DataProvider('readOnlyAttributes')]
    public function testServerMaintainedAttributesAreRefused(string $name): void
    {
        // Relevé nom par nom : « … attribute can't be set in SearchAttributes ».
        $this->expectExceptionMessageMatches('/maintained by the server/');

        SearchAttributes::none()->keyword($name, 'x');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function readOnlyAttributes(): iterable
    {
        foreach (SearchAttributes::READ_ONLY as $name) {
            yield $name => [$name];
        }
    }

    public function testAttributesTheServerLetsYouWriteAreAccepted(): void
    {
        // Sondés acceptés en écriture, contrairement aux précédents.
        foreach (['BuildIds', 'BinaryChecksums', 'TemporalChangeVersion'] as $name) {
            self::assertTrue(SearchAttributes::none()->keywordList($name, ['x'])->has($name));
        }
    }

    public function testABlankNameIsRejected(): void
    {
        $this->expectExceptionMessageMatches('/cannot be blank/');

        SearchAttributes::none()->keyword('  ', 'x');
    }

    public function testSettingTheSameNameTwiceKeepsTheLastValue(): void
    {
        $attributes = SearchAttributes::none()->keyword('DurableOrderId', 'first')->keyword('DurableOrderId', 'second');

        self::assertSame(['DurableOrderId' => 'second'], $attributes->toValues());
    }

    public function testTheObjectIsImmutable(): void
    {
        $empty = SearchAttributes::none();
        $empty->keyword('DurableOrderId', 'x');

        self::assertTrue($empty->isEmpty());
    }

    public function testMetadataRoundTripKeepsTypes(): void
    {
        $attributes = SearchAttributes::none()->int('DurableAmount', 42)->keyword('DurableOrderId', 'ORD-1');
        $decoded = SearchAttributes::fromMetadata($attributes->toMetadata());

        self::assertSame(SearchAttributeType::Int, $decoded->typeOf('DurableAmount'));
        self::assertSame(42, $decoded->toValues()['DurableAmount']);
    }

    public function testBothOptionClassesCarryThem(): void
    {
        $attributes = SearchAttributes::none()->keyword('DurableOrderId', 'ORD-1');

        self::assertArrayHasKey('search_attributes', (new WorkflowStartOptions(searchAttributes: $attributes))->toStartMetadata());
        self::assertArrayHasKey('search_attributes', (new ChildWorkflowOptions(searchAttributes: $attributes))->toSchedulingMetadata());
        self::assertArrayNotHasKey('search_attributes', WorkflowStartOptions::defaults()->toStartMetadata());
    }
}
