<?php

declare(strict_types=1);

/*
 * Repository style: PER Coding Style, decided by ADR DUR008 — the PHP-FIG reference that
 * succeeds PSR-12.
 *
 * The revision is **pinned**, not tracked as it evolves: the ADR asks to "plan an update" when a
 * new major version is published. The `@PER-CS` alias would bring that update in unannounced, as
 * red CI after a `composer update`, without a line of code having moved. Raising the number below
 * is the deliberate act the ADR calls for.
 *
 * PER mandates spaces around concatenation (`.` is a string operator) and the empty body on one
 * line. This repository followed the Symfony convention on those two points; the ADR settles it.
 *
 * The rules added afterwards do not contradict PER, they decide where it stays silent.
 */

$finder = (new PhpCsFixer\Finder())
    ->in([__DIR__ . '/src', __DIR__ . '/tests'])
    // Protobuf stubs: rewritten by every `protoc`, so reformatting them would not survive.
    ->exclude(['Bridge/Temporal/Api', 'Bridge/Temporal/Generated']);

return (new PhpCsFixer\Config())
    ->setFinder($finder)
    ->setRiskyAllowed(true)
    ->setRules([
        '@PER-CS3.0' => true,

        // Every file already has it; the rule stops a new one from forgetting it.
        'declare_strict_types' => true,

        // A single order makes merge conflicts on `use` local instead of diffuse.
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'no_unused_imports' => true,

        // The core writes `\Throwable`, `\DateTimeImmutable` in full: global classes are not
        // imported, you recognize them by the leading slash.
        'global_namespace_import' => [
            'import_classes' => false,
            'import_constants' => false,
            'import_functions' => false,
        ],

        'single_quote' => true,
        'array_syntax' => ['syntax' => 'short'],
        'blank_line_before_statement' => ['statements' => ['return', 'throw', 'try']],

        // A doc block emptied of its annotations must not stay in place.
        'no_empty_phpdoc' => true,
        'phpdoc_trim' => true,
    ]);
