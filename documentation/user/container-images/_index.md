---
title: gRPC in your container image
weight: 16
---

# gRPC in your container image

The Temporal backend (the backend that keeps the journal in a Temporal cluster; see the
[glossary](../glossary/)) talks to the cluster over gRPC, so it needs `ext-grpc`, and `ext-grpc`
ships no prebuilt binary. `install-php-extensions grpc protobuf` compiles it from source: measured
on this repository, **6 min 58 s** for `php:8.3-cli-alpine`, because grpc pulls in abseil,
boringssl, re2 and upb and compiles as C++17. Your image build spends that time on every build, on
every branch.

The compiled extensions are published instead. Copy them into your image from:

```
ghcr.io/gplanchat/php-grpc:8.4-cli
```

The images exist for PHP 8.2, 8.3, 8.4 and 8.5, each in four forms: `cli`, `cli-alpine`, `zts`,
`zts-alpine`. They are public and need no authentication.

The recipes below use the rolling tags, which are **rebuilt every Monday** so that they follow PHP's
own patch releases and their base's security updates. Every publication also lays a dated tag such
as `8.4-cli-20260828`, which is never rebuilt. To keep your build from moving under you, pin that
tag; it is a one-word change.

Dated tags are pruned. Only the **eight most recent** are kept for each PHP-and-flavour pair,
roughly two months of pinning points. Pin a dated tag for a release you are about to cut. For a
base image you will still build from next year, use the rolling tag, which keeps working.

---

## Pick the tag that matches your PHP {#picking-the-tag-three-things-have-to-match}

An extension is a shared object compiled for one particular PHP. Three properties of that PHP have
to match, and each mismatch fails in a different way:

| Must match | What happens if it doesn't | When you find out |
|---|---|---|
| **PHP minor version** | the extension directory doesn't exist in your base image, `COPY` finds nothing | `docker build`, immediately |
| **Thread-safety** (ZTS / NTS) | same, the directory name carries it | `docker build`, immediately |
| **libc** (glibc / musl) | **the path is identical**, the file copies cleanly, and PHP fails to load it | at run time, unless you check |

The patch version does *not* have to match: `php:8.4.22` loads an extension built against `8.4.25`.
The minor does: 8.3 and 8.4 are a different ABI.

The directory name encodes the first two:

| PHP | NTS | ZTS |
|---|---|---|
| 8.2 | `no-debug-non-zts-20220829` | `no-debug-zts-20220829` |
| 8.3 | `no-debug-non-zts-20230831` | `no-debug-zts-20230831` |
| 8.4 | `no-debug-non-zts-20240924` | `no-debug-zts-20240924` |
| 8.5 | `no-debug-non-zts-20250925` | `no-debug-zts-20250925` |

It does **not** encode libc. Debian and Alpine use the exact same path, so copying an Alpine-built
`grpc.so` into a Debian base succeeds without any warning, and the failure only appears much
later:

```
Unable to load dynamic library 'grpc.so' … Error loading shared library libstdc++.so.6
```

That is why every recipe below ends with the same line:

```dockerfile
RUN php -m | grep -qx grpc && php -m | grep -qx protobuf
```

It costs nothing, and a libc mismatch then fails the build instead of surfacing in production.

Pick the tag for your base image:

| Your base image | Copy from |
|---|---|
| `php:8.4-cli`, `php:8.4-fpm`, `php:8.4-apache` | `8.4-cli` |
| `php:8.4-cli-alpine`, `php:8.4-fpm-alpine` | `8.4-cli-alpine` |
| `dunglas/frankenphp:1-php8.4` | `8.4-zts` |
| `dunglas/frankenphp:1-php8.4-alpine` | `8.4-zts-alpine` |

---

## Recipes per web server stack {#five-stacks-three-recipes}

**Nginx + php-fpm, Caddy + php-fpm and Apache over FastCGI (`mod_proxy_fcgi`) share one recipe.**
In all three, PHP runs in its own `php:X-fpm` container and the web server runs in another one that
never loads a PHP extension. Nothing in your `nginx.conf`, your `Caddyfile` or your virtual host
changes.

Two setups differ, the ones where PHP lives *inside* the server image: `mod_php`, which is NTS, and
FrankenPHP, which is ZTS.

### php-fpm, behind Nginx, Caddy or Apache-over-FastCGI

```dockerfile
FROM ghcr.io/gplanchat/php-grpc:8.4-cli AS ext

FROM php:8.4-fpm
COPY --from=ext /usr/local/etc/php/conf.d/docker-php-ext-grpc.ini /usr/local/etc/php/conf.d/
COPY --from=ext /usr/local/etc/php/conf.d/docker-php-ext-protobuf.ini /usr/local/etc/php/conf.d/
COPY --from=ext /usr/local/lib/php/extensions/no-debug-non-zts-20240924/grpc.so /usr/local/lib/php/extensions/no-debug-non-zts-20240924/
COPY --from=ext /usr/local/lib/php/extensions/no-debug-non-zts-20240924/protobuf.so /usr/local/lib/php/extensions/no-debug-non-zts-20240924/

RUN php -m | grep -qx grpc && php -m | grep -qx protobuf
```

**On Alpine, check `libstdc++`.** grpc is C++, and it needs that library at load time. Every Debian
base carries it. Alpine bases differ: `php:8.4-fpm-alpine` does not have it, and the FrankenPHP
Alpine image does. The php-fpm Alpine recipe installs it:

```dockerfile
FROM ghcr.io/gplanchat/php-grpc:8.4-cli-alpine AS ext

FROM php:8.4-fpm-alpine
RUN apk add --no-cache libstdc++
COPY --from=ext /usr/local/etc/php/conf.d/docker-php-ext-grpc.ini /usr/local/etc/php/conf.d/
COPY --from=ext /usr/local/etc/php/conf.d/docker-php-ext-protobuf.ini /usr/local/etc/php/conf.d/
COPY --from=ext /usr/local/lib/php/extensions/no-debug-non-zts-20240924/grpc.so /usr/local/lib/php/extensions/no-debug-non-zts-20240924/
COPY --from=ext /usr/local/lib/php/extensions/no-debug-non-zts-20240924/protobuf.so /usr/local/lib/php/extensions/no-debug-non-zts-20240924/

RUN php -m | grep -qx grpc && php -m | grep -qx protobuf
```

Without that `apk add`, the build fails on the last line, the `php -m` check, with `Error loading
shared library libstdc++.so.6`.

### Apache with mod_php

Here PHP is inside the web server image, and `php:X-apache` is NTS, like the fpm images. The recipe
uses the same extension directory and the same source tag:

```dockerfile
FROM ghcr.io/gplanchat/php-grpc:8.4-cli AS ext

FROM php:8.4-apache
COPY --from=ext /usr/local/etc/php/conf.d/docker-php-ext-grpc.ini /usr/local/etc/php/conf.d/
COPY --from=ext /usr/local/etc/php/conf.d/docker-php-ext-protobuf.ini /usr/local/etc/php/conf.d/
COPY --from=ext /usr/local/lib/php/extensions/no-debug-non-zts-20240924/grpc.so /usr/local/lib/php/extensions/no-debug-non-zts-20240924/
COPY --from=ext /usr/local/lib/php/extensions/no-debug-non-zts-20240924/protobuf.so /usr/local/lib/php/extensions/no-debug-non-zts-20240924/

RUN php -m | grep -qx grpc && php -m | grep -qx protobuf
```

### FrankenPHP

FrankenPHP embeds PHP in the server process and runs several workers in one process, so it is built
**thread-safe**. An NTS extension does not load in it; the `zts` images exist for this case. The
paths contain `zts`: `no-debug-zts-20240924`, not `no-debug-non-zts-20240924`:

```dockerfile
FROM ghcr.io/gplanchat/php-grpc:8.4-zts AS ext

FROM dunglas/frankenphp:1-php8.4
COPY --from=ext /usr/local/etc/php/conf.d/docker-php-ext-grpc.ini /usr/local/etc/php/conf.d/
COPY --from=ext /usr/local/etc/php/conf.d/docker-php-ext-protobuf.ini /usr/local/etc/php/conf.d/
COPY --from=ext /usr/local/lib/php/extensions/no-debug-zts-20240924/grpc.so /usr/local/lib/php/extensions/no-debug-zts-20240924/
COPY --from=ext /usr/local/lib/php/extensions/no-debug-zts-20240924/protobuf.so /usr/local/lib/php/extensions/no-debug-zts-20240924/

RUN php -m | grep -qx grpc && php -m | grep -qx protobuf
```

On `dunglas/frankenphp:1-php8.4-alpine`, copy from `8.4-zts-alpine` instead. That image already
ships `libstdc++`, so it needs no `apk add`. Alpine bases differ on this point, and the
`RUN php -m` line tells you which kind you have.

---

## A base image that isn't in the table

Before you write any `COPY`, query the base image directly:

```bash
docker run --rm --entrypoint php <your-base-image> -r \
  'printf("%s %s %s\n", PHP_VERSION, PHP_ZTS ? "ZTS" : "NTS", ini_get("extension_dir"));'

docker run --rm --entrypoint sh <your-base-image> -c \
  '[ -f /etc/alpine-release ] && echo musl || echo glibc'
```

The answers give the three properties from the first table above. If no published tag matches all
three, compile the extension as the next section shows.

---

## Compile the extension in your own image {#if-you-would-rather-not-copy-anything}

Copying is an optimisation. Compiling in your own image also works, and takes longer:

```dockerfile
COPY --from=mlocati/php-extension-installer:latest /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions grpc protobuf
```

Compile when your base has no matching published tag, or when a seven-minute image build is
acceptable to you. The published images above are built the same way.

> [!NOTE]
> **On GitHub Actions, you need none of this.** `shivammathur/setup-php` installs `grpc` from a
> prebuilt binary in about five seconds. This page covers container images, where no such binary
> exists.

The images and their build workflow live in [`docker/php-grpc/`](https://github.com/gplanchat/durable-dev/tree/main/docker/php-grpc).
