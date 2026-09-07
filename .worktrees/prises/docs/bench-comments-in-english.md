# docs/bench-comments-in-english

- **Work item**: the fifth comment slice, after `src/Durable` (#292), the bridges (#293), the host
  packages (#294) and `tests/` (#296). This one takes the four benches — the applications CI
  actually boots, and the first code someone clones this repository to read.
- **Entry points**: `symfony/{src,tests,config,docker}/**` and its `.env*`, `sylius/{config,tests}/**`,
  `laravel/{config,README.md,.env.example}`, `magento/app/code/Gplanchat/DurableProbe/**`,
  `magento/probe-*.php`, `magento/README.md`. Comments and docblocks, plus the assertion messages
  and the console output an operator reads. Fixture strings stay.
- **Careful**: none of the four is covered by `loop/guardrails/verify.sh` — `phpunit.xml` declares no
  bench suite and neither `phpstan.neon` nor `psalm.xml` lists their paths. CI is the vote:
  "App exemple Symfony", "Boutique Sylius", "Laravel x / PHP y", and the two Magento jobs. The
  Magento boot job greps `notify:charge:ORD-4242` and `durable.demo.charge` out of
  `bin/magento durable:demo`, and `SamplesControllerSmokeTest` asserts on rendered page text.
- **State**: in progress.
