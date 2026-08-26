<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable;

use Gplanchat\Durable\CronSchedule;
use Gplanchat\Durable\Duration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Une expression cron est une grammaire, pas une chaîne : une faute de frappe ne se manifestait
 * qu'au retour du serveur, c'est-à-dire en production.
 *
 * Les cas ci-dessous sont ceux dont le verdict a été **sondé sur un vrai serveur Temporal** ; le
 * validateur doit rendre le même.
 *
 * @see \integration\Temporal\CronScheduleTest
 */
final class CronScheduleTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function acceptedByTheServer(): iterable
    {
        foreach ([
            '0 9 * * 1-5',
            '*/15 * * * *',
            '@daily',
            '@every 90s',
            '@every 1h30m',
            'CRON_TZ=Europe/Paris 0 9 * * 1-5',
            '0 0 * JAN *',
            '0 0 * * MON',
            '0 0 * * SUN-SAT',
            '? * * * *',
            '0 0 ? * *',
            '0 0 * * ?',
            '0 0 31 1 *',
            '0 0 29 2 *',
            '0 0 31 2,3 *',
        ] as $expression) {
            yield $expression => [$expression];
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rejectedByTheServer(): iterable
    {
        foreach ([
            '0 0 30 2 *',
            '0 0 31 4 *',
            '0 0 31 2 *',
            '70 * * * *',
            '* * * * * *',
            '@bogus',
            '0 0 * * 7',
            '0 0 * * 1-7',
            '0 0 JAN * MON',
            '',
        ] as $expression) {
            yield ('' === $expression ? '(vide)' : $expression) => [$expression];
        }
    }

    #[DataProvider('acceptedByTheServer')]
    public function testExpressionsTheServerAcceptsAreAccepted(string $expression): void
    {
        self::assertSame($expression, CronSchedule::parse($expression)->toExpression());
    }

    #[DataProvider('rejectedByTheServer')]
    public function testExpressionsTheServerRejectsAreRejected(string $expression): void
    {
        $this->expectException(\InvalidArgumentException::class);

        CronSchedule::parse($expression);
    }

    public function testSixFieldExpressionsAreNamedInTheError(): void
    {
        // L'erreur la plus fréquente : un cron Quartz, avec les secondes, copié d'ailleurs.
        $this->expectExceptionMessageMatches('/Six-field expressions \(Quartz, with seconds\)/');

        CronSchedule::parse('0 0 12 * * ?');
    }

    public function testUnreachableSchedulesAreNamedInTheError(): void
    {
        $this->expectExceptionMessageMatches('/No time can satisfy/');

        CronSchedule::parse('0 0 31 4 *');
    }

    public function testShortcutsAndIntervals(): void
    {
        self::assertSame('@hourly', CronSchedule::hourly()->toExpression());
        self::assertSame('@daily', CronSchedule::daily()->toExpression());
        self::assertSame('@every 1m30s', CronSchedule::every(Duration::seconds(90))->toExpression());
        self::assertSame('@every 2h', CronSchedule::every(Duration::hours(2))->toExpression());
        self::assertSame('@every 30s', CronSchedule::every(Duration::seconds(30))->toExpression());
    }

    public function testAnIntervalBelowASecondIsRejected(): void
    {
        $this->expectExceptionMessageMatches('/at least one second/');

        CronSchedule::every(Duration::milliseconds(200));
    }

    public function testDailyAtBuildsTheFiveFieldForm(): void
    {
        self::assertSame('30 9 * * *', CronSchedule::dailyAt(9, 30)->toExpression());
        self::assertSame('0 0 * * *', CronSchedule::dailyAt(0)->toExpression());
    }

    public function testAnHourOutsideTheDayIsRejected(): void
    {
        $this->expectExceptionMessageMatches('/hour must be between 0 and 23/');

        CronSchedule::dailyAt(25);
    }

    public function testTimeZoneIsCarriedAsThePrefixTheServerExpects(): void
    {
        // Sans fuseau, le serveur lit l'expression en UTC — presque jamais ce qu'on veut d'un
        // « tous les jours à 9 h ».
        $schedule = CronSchedule::dailyAt(9)->inTimeZone(new \DateTimeZone('Europe/Paris'));

        self::assertSame('CRON_TZ=Europe/Paris 0 9 * * *', $schedule->toExpression());
        self::assertSame('Europe/Paris', $schedule->timeZone());
        self::assertNull(CronSchedule::dailyAt(9)->timeZone());
    }

    public function testTimeZoneCanBeReplaced(): void
    {
        $schedule = CronSchedule::parse('CRON_TZ=UTC 0 9 * * *')->inTimeZone('Asia/Tokyo');

        self::assertSame('CRON_TZ=Asia/Tokyo 0 9 * * *', $schedule->toExpression());
    }

    public function testBoundaryCoercionAcceptsWhatTheCallerHas(): void
    {
        self::assertSame('@daily', CronSchedule::from('@daily')->toExpression());
        self::assertSame('@daily', CronSchedule::from(CronSchedule::daily())->toExpression());
    }
}
