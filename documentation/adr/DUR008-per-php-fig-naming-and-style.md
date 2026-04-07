# DUR008 — PER (PHP-FIG) style and naming

## Status

Accepted

## Context

Durable component code must stay **readable**, **consistent**, and **aligned** with widely accepted PHP community practice. The **PER Coding Style** published by PHP-FIG is the style reference that succeeds and extends PSR-12 for modern PHP (attributes, enums, compound types, etc.).

## Decision

**All Durable component PHP code** (including tests, unless a specific ADR says otherwise) **must** comply with PHP-FIG’s **PER Coding Style** **in the latest stable version** published on the official PHP-FIG site at the time of the development branch.

**Reference**: [PER Coding Style](https://www.php-fig.org/per/coding-style/) (PHP-FIG).

### Class and identifier naming

- **Classes**: `StudlyCaps` / `PascalCase` per PER (including rules for acronyms and compound words as defined in the document).
- **Interfaces, traits, enums**: PER conventions for type identifiers.
- **Methods and properties**: `camelCase` unless PER explicitly covers an exception.
- **Class constants**: per PER (uppercase with separators).

### Tooling

- The project **should** apply formatting and checks via a style tool (PHP-CS-Fixer, PHP_CodeSniffer with PER rules, or equivalent) configured for the **PER revision** tracked by the repository.

### Evolution

- When a new major PER version is published, **plan an update** of CI rules and code; document gaps in ADRs or release notes.

## Consequences

- Code reviews can cite PER explicitly instead of implicit local conventions.
- External contributors rely on a public, versioned standard.
