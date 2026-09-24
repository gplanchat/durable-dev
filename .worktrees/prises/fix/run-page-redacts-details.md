# fix/run-page-redacts-details

- **Scope**: #507 — the run pages of the Sylius plugin and Magento print each event's details
  through `RunTimeline::of()`, unmasked. `RunTimeline::of()` masks them with #488's
  `PayloadRedactorInterface` (default `KeyPatternPayloadRedactor`), once in the core, so every
  host gets it; no second redactor, no template change. A raw opt-in waits for a host that has a
  permission for it.
- **Entries**: `src/Durable/Observation/RunTimeline.php`, its unit test, the plugin's
  `TheDashboardRendersARunHistoryTest`, `tests/unit/DurableModule/TheDetailTemplateRendersARunHistoryTest.php`,
  `documentation/user/dashboard/_index{,.fr}.md`, `UPGRADE.md`.
- **Order**: sabrina's #332 plugin column shares the plugin render test (one method each), not the
  template.
- **State**: in progress — vera; reviewers sabrina, then bob (docs).
