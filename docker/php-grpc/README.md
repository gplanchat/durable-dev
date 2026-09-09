# `ghcr.io/gplanchat/php-grpc`

PHP images that already carry **`grpc`** and **`protobuf`**, built once in CI rather than on every
`docker build` of a project.

```
ghcr.io/gplanchat/php-grpc:8.3-cli          ghcr.io/gplanchat/php-grpc:8.3-zts
ghcr.io/gplanchat/php-grpc:8.3-cli-alpine   ghcr.io/gplanchat/php-grpc:8.3-zts-alpine
```

PHP 8.2, 8.3, 8.4 and 8.5, on Debian and on Alpine, **with and without thread-safety**. Each
publication also places a dated tag (`8.3-cli-alpine-20260828`) for whoever wants to pin.

The `zts` variants are not a refinement: an extension compiled for a non-thread-safe PHP refuses to
load into a thread-safe PHP, and the other way round. A runtime that runs several workers in a
single process demands `zts`; the rest of the PHP world runs on `cli`. Neither of the two covers the
other, hence the four variants.

## What these images are not

**Not application images.** Neither your code, nor Composer, nor a web server. They serve as a base,
or as a source in a multi-stage build:

```dockerfile
FROM ghcr.io/gplanchat/php-grpc:8.3-cli-alpine AS ext

FROM ghcr.io/sylius/sylius-php:8.3-alpine
COPY --from=ext /usr/local/etc/php/conf.d/docker-php-ext-grpc.ini /usr/local/etc/php/conf.d/
COPY --from=ext /usr/local/etc/php/conf.d/docker-php-ext-protobuf.ini /usr/local/etc/php/conf.d/
COPY --from=ext /usr/local/lib/php/extensions/no-debug-non-zts-20230831/grpc.so /usr/local/lib/php/extensions/no-debug-non-zts-20230831/
COPY --from=ext /usr/local/lib/php/extensions/no-debug-non-zts-20230831/protobuf.so /usr/local/lib/php/extensions/no-debug-non-zts-20230831/

RUN php -m | grep -qx grpc && php -m | grep -qx protobuf
```

The path is named in full rather than copied wholesale, and deliberately so: a `COPY` of the
`extensions/` directory succeeds even when the base's directory name differs — the `.so` lands in a
directory PHP does not read, and the failure waits for runtime. The explicit path fails at
`docker build`.

Three things must match between the two images: PHP's **minor version**, the **thread-safety**, and
the **libc**. The first two are in the directory name, so a mismatch breaks the build. The third is
not there: Debian and Alpine share the same path, a musl `.so` copies without a word into a glibc
base and then refuses to load. Hence the final `RUN php -m`, which catches that case and only that
one.

A fourth condition shows up nowhere in the paths: grpc is C++ and demands `libstdc++`. The Debian
bases all ship it; the Alpine bases, not all — the Sylius image above has it, `php:8.3-fpm-alpine`
does not and needs an `apk add --no-cache libstdc++`. There again, it is the `RUN php -m` that says
so.

**The user guide covers all of this** — recipes for php-fpm behind Nginx or Caddy, for Apache with
mod_php, and for FrankenPHP (which is thread-safe, hence `zts`):
<https://durable.rocks/docs/container-images/>.

## What these images are not there to solve

**GitHub Actions does not need them.** Measured on this repository: `shivammathur/setup-php`
installs `grpc` in **five seconds**, on the strength of a prebuilt binary. The "Temporal
integration tests" job, which asks for the extension, starts PHP as fast as the Sylius one, which
does not:

| job | "Setup PHP" step | asks for `grpc` |
|---|---|---|
| Temporal integration tests | 5 s | yes |
| Sylius shop | 6 s | no |

In a workflow, then, write `extensions: …, grpc` and think no more of it. These images are for
**Docker**, where `install-php-extensions` compiles from source on every build.

**How long, exactly.** Measured by building `8.3-cli-alpine` on an ordinary development machine:

```
real    6m58s      image finale : 126 Mo
```

Seven minutes per build, per PHP version, per distribution, on every machine and in every pipeline
that needs it. That is what publishing the image removes — grpc 1.83 dragging abseil, boringssl, re2
and upb behind it, and compiling as C++17.

## How they are built

mlocati's `install-php-extensions` rather than a hand-written `pecl install`: it knows each
distribution's system packages, cleans up behind itself, and follows PHP releases. Publishing the
image does not replace that tool, it amortises its cost.

Two checks, and they do not say the same thing. The `Dockerfile` fails if the extension is not
loadable **in the built layer**; the workflow then runs `php -m` again on **the published image**.
An image can be built right and pushed wrong.

## Rebuilding

Four ways to trigger it:

| Trigger | When |
| --- | --- |
| `workflow_dispatch` | by hand, from the Actions tab |
| `repository_dispatch` | by webhook: `gh api repos/:owner/:repo/dispatches -f event_type=php-grpc-images` |
| `push` on `main` | the `Dockerfile` or the workflow changed |
| `schedule` | every Monday |

The weekly cadence is not zeal. Two things age in these images, and not at the same rate: the base's
system packages, and PHP itself, whose patch releases come out every month. Rebuilding every week
keeps the gap with `php:8.4-cli` down to a few days, and the Buildx cache makes the rebuild cheap
when nothing has moved upstream.

Publication only happens from `main`. On a pull request the workflow builds without pushing: the
compilation is what we want to check, and a rolling tag must not designate an unreviewed experiment.

## Pruning the dated tags

Sixteen images × one dated tag × every Monday makes about **eight hundred tags a year**. Storage is
free on a public package, but the version list becomes unreadable well before it costs anything.

The `retention` job runs after every successful publication and keeps, **per series**
(`8.4-zts`, `8.2-cli-alpine`, …), the **eight most recent dated tags** on top of the current
image — that is two months of pinning points. Simulated over sixty weeks: 144 versions and 160 tags
at steady state, against 960 and 976 with nothing done.

What the script protects, and it is not obvious: **a version is spared as soon as it carries a tag
that is not dated.** On publication Monday, `8.4-zts` and `8.4-zts-20260828` designate the same
manifest; "deleting the dated tag" would remove the image the rolling tag designates. The rule
therefore bears on the version, never on the tag. Versions with **no tag at all** are spared too:
these are not orphans but buildx's provenance attestations, referenced by the index which, itself,
is tagged.

`retention.py --self-test` checks these two cases, and the job runs it before calling anything that
deletes.

> **A secret is required.** The package deletion API does not accept the `GITHUB_TOKEN`: it asks for
> a *classic* personal token carrying `read:packages` and `delete:packages`, to be placed in the
> `GHCR_RETENTION_TOKEN` secret. Without it, the job posts a warning and stops without failing — a
> weekly failure would end up ignored, and the real ones with it.

A dry run is available: `workflow_dispatch` with `dry_run` (on by default) lists what would be
removed without removing anything.

`arm64` is offered as an option and not by default: it goes through QEMU, and emulating a C++
compilation costs far more than compiling it.
