# gplanchat/durable-psalm-plugin

Optional **Psalm** package for projects using [**gplanchat/durable**](../durable/).

## Current scope

The plugin entry point (**`Gplanchat\Durable\Psalm\Plugin`**) is intentionally **empty**: Psalm’s provider APIs split **method existence**, **parameters**, and **return types** across separate events, and **`ActivityStub`** needs all three with access to the **bound `TActivity`** generic. A partial provider (e.g. existence only) **crashes** analysis when Psalm then asks for params.

**For `ActivityStub` typing, use [`gplanchat/durable-phpstan`](../durable-phpstan/)** (see **ADR012** in the monorepo). That extension uses PHPStan’s **`MethodsClassReflectionExtension`** with **`getPossiblyIncompleteActiveTemplateTypeMap()`**, which matches our runtime model.

Future work may register a coordinated set of Psalm hooks once we can thread template arguments consistently through params + return providers.

## Installation

```bash
composer require --dev gplanchat/durable-psalm-plugin
```

In **`psalm.xml`**:

```xml
<plugins>
    <pluginClass class="Gplanchat\Durable\Psalm\Plugin"/>
</plugins>
```

(Syntax may vary slightly by Psalm version — see Psalm’s “Plugins” documentation.)

## Development (monorepo)

```bash
cd src/DurablePsalmPlugin
composer install
./vendor/bin/psalm -c psalm.xml.dist
```

## Publishing

The Packagist repository matches the contents of **`packages/durable-psalm-plugin/`**.

## See also

- [ADR012 — Activity stub, PSR-6, warmup, static analysis](../../documentation/adr/ADR012-activity-stub-metadata-and-static-analysis.md)
