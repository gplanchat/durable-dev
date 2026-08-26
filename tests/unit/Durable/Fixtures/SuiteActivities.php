<?php

declare(strict_types=1);

namespace unit\Durable\Fixtures;

use Gplanchat\Durable\Attribute\ActivityMethod;

/**
 * Les activités que la suite planifie, déclarées une fois.
 *
 * La suite nommait ses activités par des chaînes — `$env->activity('double', ['value' => 2])` —
 * la forme que la bibliothèque n'enseigne plus. Ces noms sont des accessoires de test, pas des
 * contrats métier, mais ce sont quand même du code de workflow : la règle vaut pour eux.
 *
 * **Le nom du paramètre est la clé de la charge.** `ActivityStub` construit la charge depuis les
 * paramètres déclarés ici, donc un paramètre renommé change ce que reçoit le double. C'est le
 * seul piège de ce fichier, et il est silencieux : la charge devient fausse, le test ne rougit
 * pas.
 *
 * Le nom transmis reste celui de l'attribut, ce qui laisse `task-a` et `task-b` s'écrire
 * `taskA()` et `taskB()` sans changer un octet de ce qui part sur le fil.
 */
interface SuiteActivities
{
    #[ActivityMethod('never')]
    public function never(): mixed;

    #[ActivityMethod('double')]
    public function double(int $value): int;

    #[ActivityMethod('append')]
    public function append(string $text): string;

    #[ActivityMethod('greet')]
    public function greet(string $name): string;

    #[ActivityMethod('echo')]
    public function echoValue(mixed $v = null): mixed;

    /** @param list<string> $lines */
    #[ActivityMethod('quote')]
    public function quote(array $lines): mixed;
}
