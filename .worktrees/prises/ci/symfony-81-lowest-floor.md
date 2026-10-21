# ci/symfony-81-lowest-floor

- **Scope**: the "Symfony · package (8.0 lowest)" lane no longer resolves: every 8.0.x of symfony/http-foundation carries a security advisory, so Composer refuses it (the 7.0 to 7.3 floors went the same way). The lane moves to 8.1.* and is renamed "8.1 lowest".
- **Entries**: .github/workflows/ci.yml (supervised, approved by the user on 2026-10-21), the matching line in the CI documentation if one names the lane.
- **State**: in progress.
