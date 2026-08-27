<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Illuminate;

use Gplanchat\Bridge\Illuminate\Queue\ResumeLock;
use Illuminate\Cache\ArrayStore;
use Illuminate\Contracts\Cache\LockTimeoutException;
use PHPUnit\Framework\TestCase;

/**
 * Ce que le verrou doit garantir, et ce qu'on peut en prouver dans un seul processus.
 *
 * On ne peut pas y lancer deux workers. Ce qui **se** prouve est ce qui compte : qu'une seconde
 * prise sur la même exécution ne passe pas tant que la première tient, et que deux exécutions
 * différentes ne s'attendent jamais. Le reste — qu'un worker distant respecte le même verrou —
 * tient au nom, et le nom est donc figé par un test plutôt que deviné à deux endroits.
 */
final class ResumeLockTest extends TestCase
{
    public function testTheWorkRunsAndItsValueComesBack(): void
    {
        self::assertSame('repris', $this->lock()->around('exec-1', static fn(): string => 'repris'));
    }

    public function testASecondTakeOnTheSameExecutionDoesNotGetThrough(): void
    {
        $lock = $this->lock(waitSeconds: 0);
        $reentered = false;

        $lock->around('exec-1', function () use ($lock, &$reentered): void {
            try {
                $lock->around('exec-1', static function () use (&$reentered): void {
                    $reentered = true;
                });
            } catch (LockTimeoutException) {
                // Attendu : c'est exactement ce que le verrou existe pour faire.
            }
        });

        self::assertFalse($reentered, 'deux reprises de la même exécution se seraient croisées');
    }

    public function testTwoDifferentExecutionsNeverWaitOnEachOther(): void
    {
        $lock = $this->lock(waitSeconds: 0);
        $inner = null;

        $lock->around('exec-1', function () use ($lock, &$inner): void {
            $inner = $lock->around('exec-2', static fn(): string => 'passé');
        });

        self::assertSame('passé', $inner, 'le verrou est par exécution, pas global');
    }

    public function testTheLockIsReleasedWhenTheWorkThrows(): void
    {
        $lock = $this->lock(waitSeconds: 0);

        try {
            $lock->around('exec-1', static fn() => throw new \RuntimeException('boum'));
        } catch (\RuntimeException) {
            // On veut la suite, pas l'exception.
        }

        self::assertSame(
            'repris',
            $lock->around('exec-1', static fn(): string => 'repris'),
            'une reprise qui échoue ne doit pas condamner l\'exécution',
        );
    }

    /**
     * Le nom traverse les processus : un worker et une commande qui reprennent la même exécution
     * doivent poser le **même** verrou, et le deviner chacun de son côté est la façon dont deux
     * processus croient s'exclure sans le faire.
     */
    public function testTheLockNameIsPerExecutionAndStable(): void
    {
        self::assertSame('durable-resume-exec-1', ResumeLock::nameFor('exec-1'));
        self::assertNotSame(ResumeLock::nameFor('exec-1'), ResumeLock::nameFor('exec-2'));
    }

    private function lock(int $waitSeconds = 5): ResumeLock
    {
        return new ResumeLock(new ArrayStore(), ttlSeconds: 300, waitSeconds: $waitSeconds);
    }
}
