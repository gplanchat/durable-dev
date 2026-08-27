# Third-party marks

**These files are not covered by the repository's MIT licence.**

Every SVG in this directory reproduces a mark belonging to its owner. They are used **nominatively**
— to name the projects Durable integrates with, in a picker whose whole purpose is to say *which
stack are you on*. Naming a project that way is ordinary and expected. Shipping its mark under a
grant that says "do what you like with this" is a different act, and [WA004](../../../documentation/wa/WA004-mit-license-distribution.md)
declares the repository and its Composer packages MIT without carving anything out.

This file is the carve-out. **The MIT grant covers the code in this repository; it does not extend
to the marks below, which remain the property of their respective owners.**

## They are all modified, and that is the part to check

Not one of these files is the mark as its owner publishes it. Each has had its brand colour replaced
by `currentColor`, its background dropped, and its artwork cropped to a square 24 box. That is what
lets a mark follow the page's theme and accent instead of sitting on a white rectangle in dark mode —
and it is also precisely what a brand guideline is most likely to forbid.

## Provenance

| Mark | Where it came from |
|---|---|
| `php`, `doctrine`, `temporal`, `symfony`, `laravel`, `magento`, `shopware`, `pimcore`, `filament`, `statamic`, `typo3` | [Simple Icons](https://github.com/simple-icons/simple-icons) |
| `akeneo`, `sulu`, `api-platform`, `bagisto`, `aimeos` | extracted from each project's own published asset |
| `sylius` | drawn here from the published mark |
| `illuminate` | **not a mark.** A generic database glyph written for this repository — see below |

### Simple Icons is CC0, and that settles less than it sounds

Simple Icons' own disclaimer is explicit:

> Simple Icons is released under CC0 — though that doesn't mean to imply that all icons within the
> project are also CC0.

The CC0 dedication covers the collection. It does not dedicate the marks, and it grants no trademark
rights. Eleven of the marks here arrived through Simple Icons; that provenance makes them
convenient, not cleared.

### `illuminate` is ours

`Illuminate\Database\Connection` is Laravel's database layer and has no mark of its own. This file
was briefly a byte-for-byte copy of `laravel.svg`, which used Laravel's mark to label something that
is not the Laravel framework — and put two identical marks on one page. It is now a generic
three-tier database glyph written for this repository, reproducing nothing.

## What was checked, and what it said

Checked 2026-08-27. Findings, not legal advice.

| Project | Published policy | What it says |
|---|---|---|
| **API Platform** | [Trademark policy](https://api-platform.com/trademark-policy/) | Naming the product is permitted. But: *"Use or reproduction of Les-Tilleuls.coop's original works of authorship, including the API Platform 'Webby' spider design is prohibited without prior approval."* **`api-platform.svg` is Webby.** See below. |
| **TYPO3** | [Trademark Usage Policy](https://docs.typo3.org/m/typo3/guide-policy/main/en-us/Association/TrademarkUsagePolicy.html), [brand guidelines](https://typo3.com/typo3-cms/the-brand/brand-guidelines) | The shield is not a registered trademark but its use is governed by the brand guidelines; the figurative mark may be used without the wordmark as a design element. Modification is not addressed. Questions go to `trademark@typo3.org`. |
| **Akeneo** | Brand assets and a style guide, no usage policy located | Nothing found that permits or forbids modification. |
| **Sulu**, **Aimeos**, **Bagisto** | None located | Absence of a policy is not permission; it is absence of a policy. |
| Simple Icons sources | [Disclaimer](https://github.com/simple-icons/simple-icons/blob/develop/DISCLAIMER.md) | See above. Each brand's own terms still apply. |

## One mark needs a decision, not a notice

**`api-platform.svg` reproduces Webby**, and API Platform's policy names that design specifically as
requiring prior approval. We have not asked. Three ways out, and only the first keeps the mark:

1. ask Les-Tilleuls.coop (`contact@les-tilleuls.coop`) and keep the file if approval comes;
2. replace it with a non-reproducing glyph, the way `illuminate` was handled;
3. drop the mark and leave the chip with its label only, which is what Akeneo, Sulu and API Platform
   carried until #128.

A notice cannot fix this one: the policy asks for approval, not attribution.

## If you own one of these marks

If a mark here is yours and this use is not one you want — the modification, the placement, anything
— open an issue or write to the address in the repository's `composer.json` and it will be removed.
Nothing in this directory is worth an argument with the project it names.
