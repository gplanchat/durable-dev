<?php

declare(strict_types=1);

namespace unit\Durable\Fixtures;

use Gplanchat\Durable\Attribute\AsActivityMethod;

/**
 * Les activités que la suite planifie, déclarées une fois.
 *
 * La suite nommait ses activités par des chaînes — `$env->activity('double', ['value' => 2])`, sans contrat —
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
    #[AsActivityMethod('never')]
    public function never(): mixed;

    #[AsActivityMethod('double')]
    public function double(int $value): int;

    #[AsActivityMethod('append')]
    public function append(string $text): string;

    #[AsActivityMethod('greet')]
    public function greet(string $name): string;

    #[AsActivityMethod('echo')]
    public function echoValue(mixed $v = null): mixed;

    /** @param list<string> $lines */
    #[AsActivityMethod('quote')]
    public function quote(array $lines): mixed;

    // Accessoires d'ordonnancement : ces noms ne décrivent aucun métier, ils servent à faire
    // gagner ou perdre une branche dans une course. Ils n'ont donc pas d'argument.
    #[AsActivityMethod('fast')]
    public function fast(): mixed;

    #[AsActivityMethod('slow')]
    public function slow(): mixed;

    #[AsActivityMethod('work')]
    public function work(): mixed;

    /** Rend une valeur vide : `empty` est une construction du langage, pas un nom de méthode. */
    #[AsActivityMethod('empty')]
    public function emptyResult(): mixed;

    /** Échoue puis réussit : sert aux tests de retentative. */
    #[AsActivityMethod('flaky')]
    public function flaky(): mixed;

    #[AsActivityMethod('charge')]
    public function charge(mixed $o = null): mixed;

    #[AsActivityMethod('refund')]
    public function refund(mixed $order = null): mixed;

    #[AsActivityMethod('doWork')]
    public function doWork(): mixed;

    // Le trait d'union n'est pas un nom de méthode PHP ; l'attribut porte le nom transmis, donc
    // rien ne bouge sur le fil.
    #[AsActivityMethod('task-a')]
    public function taskA(): mixed;

    #[AsActivityMethod('task-b')]
    public function taskB(): mixed;

    #[AsActivityMethod('ping')]
    public function ping(): mixed;

    /** Échoue toujours : sert aux tests de retentative sans issue. */
    #[AsActivityMethod('always')]
    public function always(): mixed;

    #[AsActivityMethod('square')]
    public function square(int $value): int;

    #[AsActivityMethod('add')]
    public function add(int $a, int $b): int;

    #[AsActivityMethod('task')]
    public function task(string $name): mixed;

    /** Rend ce qu'on lui donne : sert à distinguer deux branches d'une même course. */
    #[AsActivityMethod('id')]
    public function id(mixed $v): mixed;

    #[AsActivityMethod('validate')]
    public function validate(string $data): mixed;

    #[AsActivityMethod('explode')]
    public function explodeNow(): never;

    /** Une étape d'une séquence : appelée plusieurs fois, elle rend un résultat différent. */
    #[AsActivityMethod('step')]
    public function step(): mixed;

    #[AsActivityMethod('compute')]
    public function compute(int $a, int $b): int;

    // Branches nues d'une composition : `a`, `b`, `c` ne nomment rien d'autre que leur place
    // dans un assemblage. Le contrat ne les rend pas plus expressives — il les rend seulement
    // atteignables sans nommer une chaîne.
    #[AsActivityMethod('a')]
    public function a(): mixed;

    #[AsActivityMethod('b')]
    public function b(): mixed;

    #[AsActivityMethod('c')]
    public function c(): mixed;

    #[AsActivityMethod('price')]
    public function price(int $n): mixed;

    #[AsActivityMethod('ok')]
    public function ok(mixed $n = null): mixed;

    /** Échoue : sert aux compositions où une branche doit tomber. */
    #[AsActivityMethod('boom')]
    public function boom(): never;
}
