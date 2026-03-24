<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__ . '/src')
    ->in(__DIR__ . '/tests')
    ->name('*.php')
    ->notPath('vendor')
;

return (new PhpCsFixer\Config())
    ->setRules([
        '@PHP82Migration' => true,
        '@PSR1' => true,
        '@PSR12' => true,
        '@PhpCsFixer' => true,
        '@Symfony' => true,
        'ternary_to_elvis_operator' => true,
        'no_useless_sprintf' => true,
        'no_homoglyph_names' => true,
        'native_function_invocation' => true,
        'native_constant_invocation' => true,
        'modernize_types_casting' => true,
        'logical_operators' => true,
        'is_null' => true,
        'function_to_constant' => true,
        'fopen_flag_order' => true,
        'php_unit_test_annotation' => ['style' => 'annotation'],
        'php_unit_set_up_tear_down_visibility' => true,
        'php_unit_construct' => true,
    ])
    ->setFinder($finder)
    ->setRiskyAllowed(true)
;
