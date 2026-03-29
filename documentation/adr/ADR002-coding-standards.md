# Coding standards

ADR002-coding-standards
===

Introduction
---

This **Architecture Decision Record** defines the coding standards applied across the Durable project repository. These standards ensure consistency, maintainability, and compatibility with the PHP/Symfony ecosystem.

PHP-CS-Fixer
---

The following rules **MUST** be applied:

- `@PHPUnit84Migration:risky`
- `@PSR1`
- `@PSR12`
- `@PhpCsFixer`
- `@Symfony`
- `ternary_to_elvis_operator`
- `set_type_to_cast`
- `self_accessor`
- `psr_autoloading`
- `php_unit_test_annotation`, with option `['style' => 'annotation']`
- `php_unit_set_up_tear_down_visibility`
- `php_unit_construct`
- `no_useless_sprintf`
- `no_homoglyph_names`
- `native_function_invocation`
- `native_constant_invocation`
- `modernize_types_casting`
- `logical_operators`
- `is_null`
- `function_to_constant`
- `fopen_flag_order`
- `error_suppression`
- `ereg_to_preg`
- `dir_constant`

PHP
---

- **Minimum version**: PHP 8.2+
- **Strict types**: `declare(strict_types=1)` at the top of each PHP file
- **Typing**: typed parameters and return types when possible

Symfony and PSR
---

- Follow PSR-4 for autoloading
- Follow PSR-12 for code style
- Follow Symfony conventions for services and configuration

References
---

- [PHP-CS-Fixer](https://github.com/FriendsOfPHP/PHP-CS-Fixer)
- [PSR-1](https://www.php-fig.org/psr/psr-1/)
- [PSR-12](https://www.php-fig.org/psr/psr-12/)
- [ADR001 - ADR process](ADR001-adr-management-process.md)
