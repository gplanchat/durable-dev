<?php

declare(strict_types=1);

/*
 * Style du dépôt : PER Coding Style, décidé par l'ADR DUR008 — la référence PHP-FIG qui succède
 * à PSR-12.
 *
 * La révision est **épinglée**, pas suivie au fil de l'eau : l'ADR demande de « planifier une mise
 * à jour » quand une nouvelle version majeure paraît. L'alias `@PER-CS` ferait arriver cette mise
 * à jour sans prévenir, sous forme de CI rouge après un `composer update`, sans qu'une ligne de
 * code ait bougé. Monter le numéro ci-dessous est l'acte délibéré que l'ADR réclame.
 *
 * PER impose des espaces autour de la concaténation (`.` est un opérateur de chaîne) et le corps
 * vide sur une ligne. Ce dépôt suivait la convention Symfony sur ces deux points ; l'ADR tranche.
 *
 * Les règles ajoutées ensuite ne contredisent pas PER, elles décident là où il se tait.
 */

$finder = (new PhpCsFixer\Finder())
    ->in([__DIR__ . '/src', __DIR__ . '/tests'])
    // Souches protobuf : réécrites à chaque `protoc`, les reformater ne survivrait pas.
    ->exclude(['Bridge/Temporal/Api', 'Bridge/Temporal/Generated']);

return (new PhpCsFixer\Config())
    ->setFinder($finder)
    ->setRiskyAllowed(true)
    ->setRules([
        '@PER-CS3.0' => true,

        // Chaque fichier l'a déjà ; la règle empêche qu'un nouveau l'oublie.
        'declare_strict_types' => true,

        // Un ordre unique rend les conflits de fusion sur les `use` locaux au lieu d'être diffus.
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'no_unused_imports' => true,

        // Le noyau écrit `\Throwable`, `\DateTimeImmutable` en toutes lettres : les classes
        // globales ne s'importent pas, on les reconnaît à la barre oblique.
        'global_namespace_import' => [
            'import_classes' => false,
            'import_constants' => false,
            'import_functions' => false,
        ],

        'single_quote' => true,
        'array_syntax' => ['syntax' => 'short'],
        'blank_line_before_statement' => ['statements' => ['return', 'throw', 'try']],

        // Un bloc de doc vidé de ses annotations ne doit pas rester en place.
        'no_empty_phpdoc' => true,
        'phpdoc_trim' => true,
    ]);
