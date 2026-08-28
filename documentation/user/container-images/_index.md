---
title: gRPC in your container image
weight: 16
---

# gRPC in your container image

The Temporal backend talks to the cluster over gRPC, so it needs `ext-grpc` — and `ext-grpc` ships
no prebuilt binary. `install-php-extensions grpc protobuf` compiles it from source: measured on this
repository, **6 min 58 s** for `php:8.3-cli-alpine`, because grpc pulls in abseil, boringssl, re2 and
upb and compiles as C++17. Your image build pays that every time, on every branch.

So the compiled extensions are published, and you copy them into your image:

```
ghcr.io/gplanchat/php-grpc:8.4-cli
```

PHP 8.2, 8.3, 8.4 and 8.5, each in four forms — `cli`, `cli-alpine`, `zts`, `zts-alpine`. Public, no
authentication needed.

The recipes below use the rolling tags, which are **rebuilt every Monday** so that they follow PHP's
own patch releases and their base's security updates. If you would rather your build not move under
you, every publication also lays a dated tag — `8.4-cli-20260828` — and pinning it is a one-word
change. A dated tag is never rebuilt.

---

## Picking the tag: three things have to match

An extension is a shared object compiled for one particular PHP. Three properties of that PHP have
to line up — and they fail in three different ways, which is the part worth knowing:

| Must match | What happens if it doesn't | When you find out |
|---|---|---|
| **PHP minor version** | the extension directory doesn't exist in your base image, `COPY` finds nothing | `docker build`, immediately |
| **Thread-safety** (ZTS / NTS) | same — the directory name carries it | `docker build`, immediately |
| **libc** (glibc / musl) | **the path is identical**, the file copies cleanly, and PHP refuses to load it | at run time, unless you check |

The patch version does *not* have to match: `php:8.4.22` loads an extension built against `8.4.25`.
The minor does — 8.3 and 8.4 are a different ABI.

The directory name encodes the first two:

| PHP | NTS | ZTS |
|---|---|---|
| 8.2 | `no-debug-non-zts-20220829` | `no-debug-zts-20220829` |
| 8.3 | `no-debug-non-zts-20230831` | `no-debug-zts-20230831` |
| 8.4 | `no-debug-non-zts-20240924` | `no-debug-zts-20240924` |
| 8.5 | `no-debug-non-zts-20250925` | `no-debug-zts-20250925` |

It does **not** encode libc. Debian and Alpine use the exact same path, so copying an Alpine-built
`grpc.so` into a Debian base succeeds without a word, and the failure surfaces much later as:

```
Unable to load dynamic library 'grpc.so' … Error loading shared library libstdc++.so.6
```

That is why every recipe below ends with the same line:

```dockerfile
RUN php -m | grep -qx grpc && php -m | grep -qx protobuf
```

It costs nothing and it turns a production incident into a failed build.

Which tag for which base:

| Your base image | Copy from |
|---|---|
| `php:8.4-cli`, `php:8.4-fpm`, `php:8.4-apache` | `8.4-cli` |
| `php:8.4-cli-alpine`, `php:8.4-fpm-alpine` | `8.4-cli-alpine` |
| `dunglas/frankenphp:1-php8.4` | `8.4-zts` |
| `dunglas/frankenphp:1-php8.4-alpine` | `8.4-zts-alpine` |

---

## Five stacks, three recipes

**Nginx + php-fpm, Caddy + php-fpm and Apache over FastCGI (`mod_proxy_fcgi`) are one recipe, not
three.** In all three, PHP runs in its own `php:X-fpm` container and the web server runs in another one that never
loads a PHP extension. Nothing in your `nginx.conf`, your `Caddyfile` or your virtual host changes.

The two setups that genuinely differ are the ones where PHP lives *inside* the server image:
`mod_php`, which is NTS, and FrankenPHP, which is ZTS.

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
base carries it; Alpine bases are inconsistent — `php:8.4-fpm-alpine` does not have it, the
FrankenPHP Alpine image does:

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

Without that `apk add`, the build fails on the last line with `Error loading shared library
libstdc++.so.6` — which is the check doing its job.

### Apache with mod_php

Here PHP is inside the web server image, and `php:X-apache` is NTS, like the fpm images. Same
extension directory, same source tag:

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
**thread-safe**. An NTS extension will not load in it — this is the case the `zts` images exist for.
Note the `zts` in the paths — `no-debug-zts-20240924`, not `no-debug-non-zts-20240924`:

```dockerfile
FROM ghcr.io/gplanchat/php-grpc:8.4-zts AS ext

FROM dunglas/frankenphp:1-php8.4
COPY --from=ext /usr/local/etc/php/conf.d/docker-php-ext-grpc.ini /usr/local/etc/php/conf.d/
COPY --from=ext /usr/local/etc/php/conf.d/docker-php-ext-protobuf.ini /usr/local/etc/php/conf.d/
COPY --from=ext /usr/local/lib/php/extensions/no-debug-zts-20240924/grpc.so /usr/local/lib/php/extensions/no-debug-zts-20240924/
COPY --from=ext /usr/local/lib/php/extensions/no-debug-zts-20240924/protobuf.so /usr/local/lib/php/extensions/no-debug-zts-20240924/

RUN php -m | grep -qx grpc && php -m | grep -qx protobuf
```

On `dunglas/frankenphp:1-php8.4-alpine`, copy from `8.4-zts-alpine` instead. That one already ships
`libstdc++`, so it needs no `apk add` — Alpine bases differ on this, and the `RUN php -m` line is
what tells you which kind you have.

---

## A base image that isn't in the table

Ask it directly, before writing any `COPY`:

```bash
docker run --rm --entrypoint php <your-base-image> -r \
  'printf("%s %s %s\n", PHP_VERSION, PHP_ZTS ? "ZTS" : "NTS", ini_get("extension_dir"));'

docker run --rm --entrypoint sh <your-base-image> -c \
  '[ -f /etc/alpine-release ] && echo musl || echo glibc'
```

Three answers, three columns of the table above. If no published tag matches all three, compile —
which is the next section.

---

## If you would rather not copy anything

Copying is an optimisation, not a requirement. Compiling in your own image is correct, just slow:

```dockerfile
COPY --from=mlocati/php-extension-installer:latest /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions grpc protobuf
```

This is the right answer when your base has no matching published tag, or when a seven-minute image
build is not a problem you have. It is also what builds the images above.

> [!NOTE]
> **GitHub Actions doesn't need any of this.** `shivammathur/setup-php` installs `grpc` from a
> prebuilt binary in about five seconds. This page is about container images, where no such binary
> exists.

The images and their build workflow live in [`docker/php-grpc/`](https://github.com/gplanchat/durable-dev/tree/main/docker/php-grpc).
