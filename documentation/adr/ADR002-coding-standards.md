# Standards de code

ADR002-coding-standards
===

Introduction
---

Ce **Architecture Decision Record** définit les standards de code à appliquer à l'ensemble du dépôt du projet Durable. Ces standards assurent la cohérence, la maintenabilité et la compatibilité avec l'écosystème PHP/Symfony.

PHP-CS-Fixer
---

Les règles suivantes _DOIVENT_ être appliquées :

- `@PHPUnit84Migration:risky`
- `@PSR1`
- `@PSR12`
- `@PhpCsFixer`
- `@Symfony`
- `ternary_to_elvis_operator`
- `set_type_to_cast`
- `self_accessor`
- `psr_autoloading`
- `php_unit_test_annotation`, avec option `['style' => 'annotation']`
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

- **Version minimale** : PHP 8.2+
- **Strict types** : `declare(strict_types=1)` en tête de chaque fichier PHP
- **Typage** : paramètres et retours typés lorsque possible

Symfony et PSR
---

- Respecter PSR-4 pour l'autoloading
- Respecter PSR-12 pour le style de code
- Suivre les conventions Symfony pour les services et la configuration

Références
---

- [PHP-CS-Fixer](https://github.com/FriendsOfPHP/PHP-CS-Fixer)
- [PSR-1](https://www.php-fig.org/psr/psr-1/)
- [PSR-12](https://www.php-fig.org/psr/psr-12/)
- [ADR001 - Processus ADR](ADR001-adr-management-process.md)
