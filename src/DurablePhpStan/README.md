# gplanchat/durable-phpstan

Optional **PHPStan 2** extension for [**gplanchat/durable**](../durable/). It teaches PHPStan that:

- **`WorkflowEnvironment::activityStub(SomeContract::class)`** returns **`ActivityStub<SomeContract>`** (via aligned PHPDoc generics — see **ADR012** in the monorepo).
- Each **`#[ActivityMethod]`** method on the contract appears on the stub and returns **`Awaitable<R>`**, where **`R`** is the contract method’s return type.

## Requirements

- PHP **8.2+**
- **`phpstan/phpstan` ^2.0**
- **`gplanchat/durable`** (same major as your app)

## Installation

```bash
composer require --dev gplanchat/durable-phpstan
```

If you do not use [phpstan/extension-installer](https://github.com/phpstan/extension-installer), include the extension explicitly:

```neon
includes:
    - vendor/gplanchat/durable-phpstan/extension.neon
```

## Why PHPStan levels matter

From PHPStan level **1** onward, **`reportMagicMethods`** is enabled through the level chain. **`ActivityStub`** relies on “magic” **`__call`** for ergonomics, so **without this extension** you get **`method.notFound`** on **`$stub->greet()`** even though runtime is correct. This package fixes that by exposing virtual methods via **`MethodsClassReflectionExtension`**.

## Development (monorepo)

From **`src/DurablePhpStan/`** (monorepo) :

```bash
composer install
./vendor/bin/phpstan analyse -c phpstan.neon.dist
```

## Publishing

The Packagist payload is entirely under **`packages/durable-phpstan/`** (no `../../` paths).

## See also

- [ADR012 — Activity stub, PSR-6, warmup, static analysis](../../documentation/adr/ADR012-activity-stub-metadata-and-static-analysis.md)
- [OST003 — Activity call ergonomics](../../documentation/ost/OST003-activity-api-ergonomics.md)
