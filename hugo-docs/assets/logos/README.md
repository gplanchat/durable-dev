# Third-party marks

**These files are not covered by the repository's MIT licence.**

Every SVG in this directory but two (`api-platform` and `illuminate`, see below) reproduces a mark
belonging to its owner. They are used **nominatively**, to name the projects Durable integrates
with, in a picker whose whole purpose is to say *which stack are you on*. Naming a project that way is ordinary and expected. Shipping its mark under a
grant that says "do what you like with this" is a different act, and [WA004](../../../documentation/wa/WA004-mit-license-distribution.md)
declares the repository and its Composer packages MIT without carving anything out.

This file is the carve-out. **The MIT grant covers the code in this repository; it does not extend
to the marks below, which remain the property of their respective owners.**

## They are all modified, and that is the part to check

Not one of the marks here is as its owner publishes it (the two glyphs of our own are not marks at
all). Each has had its brand colour replaced
by `currentColor`, its background dropped, and its artwork cropped to a square 24 box. That is what
lets a mark follow the page's theme and accent instead of sitting on a white rectangle in dark mode,
and it is also precisely what a brand guideline is most likely to forbid.

## Provenance

| Mark | Where it came from |
|---|---|
| `php`, `doctrine`, `temporal`, `symfony`, `laravel`, `magento`, `filament`, `typo3` | [Simple Icons](https://github.com/simple-icons/simple-icons) |
| `api-platform` | **not a mark.** A pair of curly braces written for this repository; see below |
| `sylius` | drawn here from the published mark |
| `illuminate` | **not a mark.** A generic database glyph written for this repository; see below |

Seven marks no page displayed (`aimeos`, `akeneo`, `bagisto`, `pimcore`, `shopware`, `statamic`,
`sulu`) were deleted on 2026-09-28 (#370).

### Simple Icons is CC0, and that settles less than it sounds

Simple Icons' own disclaimer is explicit:

> Simple Icons is released under CC0 — though that doesn't mean to imply that all icons within the
> project are also CC0.

The CC0 dedication covers the collection. It does not dedicate the marks, and it grants no trademark
rights. Eight of the marks here arrived through Simple Icons; that provenance makes them
convenient, not cleared.

### `illuminate` is ours

`Illuminate\Database\Connection` is Laravel's database layer and has no mark of its own. This file
was briefly a byte-for-byte copy of `laravel.svg`, which used Laravel's mark to label something that
is not the Laravel framework, and put two identical marks on one page. It is now a generic
three-tier database glyph written for this repository, reproducing nothing.

### `api-platform` is ours too

The chip says "API Platform", which the project's policy permits in as many words (below). Its
icon is a pair of curly braces, the shape a reader associates with an API payload, drawn here and
reproducing nothing of API Platform's artwork. It replaced Webby on 2026-08-28 (164ad79b); see
"Webby: asked for, not shipped" below for why, and for the request that could bring Webby back.

## What was checked, and what it said

Checked 2026-08-27. Findings, not legal advice.

| Project | Published policy | What it says |
|---|---|---|
| **API Platform** | [Trademark and logo policy](https://api-platform.com/trademark-policy/) | Permits *"use of our Marks on websites to name or accurately describe Les-Tilleuls.coop's products, services or technology"*, which covers the **name**. It grants nothing further for the logo, and names the drawing separately: *"Use or reproduction of Les-Tilleuls.coop's original works of authorship, including the API Platform 'Webby' spider design is prohibited without prior approval from Les-Tilleuls.coop."* **`api-platform.svg` does not reproduce Webby.** See below. |
| **TYPO3** | [Trademark Usage Policy](https://docs.typo3.org/m/typo3/guide-policy/main/en-us/Association/TrademarkUsagePolicy.html), [brand guidelines](https://typo3.com/typo3-cms/the-brand/brand-guidelines) | The shield is not a registered trademark but its use is governed by the brand guidelines; the figurative mark may be used without the wordmark as a design element. Modification is not addressed. Questions go to `trademark@typo3.org`. |
| Simple Icons sources | [Disclaimer](https://github.com/simple-icons/simple-icons/blob/develop/DISCLAIMER.md) | See above. Each brand's own terms still apply. |

## Webby: asked for, not shipped

API Platform's policy names its spider design, Webby, as requiring prior approval. Webby shipped
here for one day: it came in on 2026-08-27 (324f50b0) and was replaced by the braces glyph on
2026-08-28 (164ad79b), because approval had not been asked. Nothing in this directory reproduces
it today.

Two things set it apart from every other row above:

- **It is not a trademark question.** Nominative use, naming a project you integrate with, is the
  defence that carries the rest of this directory, and the policy grants it in as many words. Webby
  is claimed as an *original work of authorship*: a drawing, under copyright, where "we are only
  naming you" is not an answer.
- **A downloads page is not a licence.** [`/resources/logos/`](https://api-platform.com/resources/logos/)
  offers fifteen variations in PNG and SVG, and says one thing before them: *"Before using the API
  Platform logos, read our Trademark and Logo Policy."* Files being available to fetch is not
  permission to reproduce them.

On 2026-09-28 I asked Les-Tilleuls.coop to approve Webby as a small single-colour icon in the chip,
following the page's colour like the other marks here (#370). The answer is pending. If approval comes, record
its terms here before `api-platform.svg` changes; if it does not, the braces stay. A notice cannot
stand in for either: the policy asks for approval, not attribution.

## If you own one of these marks

If a mark here is yours and this use is not one you want (the modification, the placement, anything),
open an issue or write to the address in the repository's `composer.json` and it will be removed.
Nothing in this directory is worth an argument with the project it names.
