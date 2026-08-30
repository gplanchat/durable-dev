# DUR048 — Audience measurement without a consent banner

## Status

Accepted and live: `durablerocks.matomo.cloud`, site 1. The partial still emits nothing when the two
parameters are empty, which keeps a fork or a local build silent.

## Context

`durable.rocks` had no audience measurement of any kind, and nothing recorded why. The site is
static — Hugo, deployed by SFTP onto OVH shared Apache hosting. There is no backend, no Node
runtime, no edge function, and the plan is capped at 100 MB.

The author set two conditions before any option was weighed: **the data is hosted in the EU**, and
**GDPR compliance is strict rather than defensible**. Scope: the landing page *and* the guide.

### ⚠ The CNIL's published list no longer exists

Until 2026-01-01 the CNIL ran an evaluation programme and published a list of audience-measurement
solutions it had assessed as exempt from consent. That programme is over; it is replaced by a
self-assessment tool. **The burden of proof moved to the publisher and the provider** — in a
pre-sales conversation as in an audit.

So "is it on the CNIL list?" is no longer a question anyone can answer, and choosing a vendor by
reputation proves nothing. What has to exist instead is a written record of the configuration that
carries the exemption. **This ADR is that record.** It is not documentation of a decision; it is the
artefact the decision rests on.

## Decision

**Matomo Cloud**, hosted in Germany, ISO 27001 certified, configured for consent-exempt audience
measurement.

### ⚠ The trap, and it is the whole reason this file is long

**Matomo's default mode uses cookies.** In that mode it collects personal data, and a consent banner
is due. It becomes exempt only in cookieless mode with IP anonymisation. Choosing Matomo and not
configuring it buys the higher price *and* the banner — the worst of both.

This is the general shape of the rule, and it is worth stating once: **the configuration grants the
exemption, not the brand.** Plausible, Pirsch and Simple Analytics are cookieless by construction
and cannot be mis-set this way; Matomo can. That is the cost of picking Matomo, and it was picked
anyway for the certification and the export.

### What the repository guarantees

`hugo-docs/layouts/_partials/matomo.html`, one source for both surfaces:

- `_paq.push(['disableCookies'])` is emitted **before** `trackPageView`. The order is load-bearing:
  reversed, the cookie is already set and nothing is protected.
- `enableLinkTracking` is **deliberately absent**. Outbound-click tracking is arguably within
  audience measurement; it is also more collection than the question "does anyone come" needs.

### The two events, and the question each answers

The rule set above says to add collection only when a question requires it, and to write down which.
Two do:

| Event | The question |
|---|---|
| `Install / Copy composer command / <the command>` | Which situation do people actually install for? The command encodes the chooser's combination, so the answer is the event name itself — Magento + Temporal, Laravel + Illuminate, and so on. |
| `Install / Copy skill commands / durable-skill` | Does the second door get used at all? The skill block was placed by a design decision; this is how that decision gets checked instead of assumed. |

Both are pushed **on success, not on click**: a clipboard write that fails is not an install intent
that succeeded, and counting it would inflate exactly the number being read.

Neither carries anything about the visitor. The event name is page content — a command string the
page itself displays.
- Nothing at all is emitted while `params.matomo.url` or `params.matomo.siteId` is empty.

Measured on a real build: empty parameters put the string `matomo` on **0 of 41 pages**; filled,
the tag lands on **40 of 41** — the exception being `en/index.html`, a Hugo-generated redirect stub
nobody reads.

⚠ **One defect only a real build could catch.** The first version passed both parameters through
`| jsonify`. Go already escapes a value for a JavaScript context, so the quoting happened twice and
the emitted code read `var u = ""https://durablerocks.matomo.cloud/"";`. The tag was present on
every page, `matomo.js` never loaded, and nothing said so — a review of the template would have
found it correct. It is fixed, and the partial carries the reason so it is not reintroduced.

### ⚠ What the repository cannot guarantee

Half of the exemption lives in the Matomo console, where no review of this repository will ever see
it. These must be set, and re-checked whenever the account changes hands:

| Setting | Why it is not optional |
|---|---|
| **IP anonymisation** (at least 2 bytes) | It is a server-side setting, not a JavaScript one. Without it the tag is cookieless and still processes personal data. |
| **Retention period**, bounded | Aggregated statistics are the exempt purpose; an unbounded raw log is not. |
| **No data sharing with third parties** | The exemption requires processing solely on the publisher's behalf. |
| **No cross-site tracking**, one site only | Global navigation tracking across properties is exactly what the exemption excludes. |
| **DoNotTrack honoured** | Not required for the exemption; cheap, and consistent with the rest. |

## Alternatives rejected

**Self-hosted Matomo on the existing OVH plan.** It was the obvious candidate — OVH gives PHP and
MySQL, which is all Matomo needs, unlike Plausible or Umami. Rejected on a measurement: the plan is
100 MB (`SITE_QUOTA_KB: 100000` in `docs-ovh.yml`, with the site at ~584 KB). A Matomo installation
does not fit. A second hosting plan would cost more than the cloud subscription.

**Google Analytics 4.** Free and complete, and it requires a consent banner in the EU. A banner on a
page whose argument is that it hides nothing is a poor trade, and the data would leave the EU.

**Server logs only** (GoAccess on the OVH logs). Genuinely attractive: nothing leaves the host, no
JavaScript, no third party. Kept as a possible complement rather than the answer, for two reasons —
no custom events, so the chooser's combinations and the skill block would stay invisible; and raw
Apache logs contain **full IP addresses**, so "no JavaScript" is not the same as "no personal data".
The compliance work does not disappear, it moves.

## Consequences

Two injection points, one partial, no duplication:

| Surface | File |
|---|---|
| The guide | `hugo-docs/layouts/_partials/docs/inject/head.html` — the hook hugo-book provides for this |
| The landing page | `hugo-docs/layout-head.html` — hand-written source, read by `import-design.py` |

⚠ **The site still has no Content-Security-Policy**, and adding a third-party host is the moment
that starts to matter. It is deliberately not bundled here: the landing page is built of inline
styles and two inline scripts, so a CSP would need `'unsafe-inline'` and its value would be limited
to pinning hosts. Worth doing, worth discussing on its own, not worth hiding inside an analytics
change.

Nothing is measured until someone fills two values. That is the intended state: the review can
happen before the account exists.
