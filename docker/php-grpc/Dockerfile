# syntax=docker/dockerfile:1

# A PHP image that already carries `grpc` and `protobuf`.
#
# Both extensions compile from source. Measured for `8.3-cli-alpine` on a development machine:
# **6 min 58 s**, for a 126 MB image — grpc 1.83 drags abseil, boringssl, re2 and upb along, and
# compiles as C++17. `install-php-extensions` works no miracle: it does the job properly, not
# instantly. Paying for it once in CI and publishing the result is the whole point here.
#
# **This is not an application image.** It contains neither your code, nor Composer, nor a
# server: it serves as a base, or as the source of a `COPY --from` in a multi-stage build.

ARG PHP_VERSION=8.3
ARG FLAVOUR=cli

FROM php:${PHP_VERSION}-${FLAVOUR}

# mlocati's script rather than a hand-written `pecl install`: it knows each distribution's system
# packages, cleans up behind itself, and follows PHP versions as they are released.
COPY --from=mlocati/php-extension-installer:latest /usr/bin/install-php-extensions /usr/local/bin/

# `protobuf` is not decorative next to `grpc`: without it, serialisation falls back on the pure
# PHP implementation, correct but markedly slower on large messages.
RUN install-php-extensions grpc protobuf

# An image whose contents cannot be checked is of no use: these two lines fail the build if the
# extension is not genuinely loadable, rather than leaving the fault to the consumer.
RUN php -m | grep -qx grpc && php -m | grep -qx protobuf

# And thread-safety must match the tag we are about to place. An extension compiled for a
# non-thread-safe PHP refuses to load into a thread-safe PHP: publishing an NTS binary under
# `…-zts` because the base resolved wrongly would give a green, unusable image. `php -m` says
# nothing about that axis, it has to be asked separately.
ARG FLAVOUR
RUN attendu="$(case "$FLAVOUR" in zts*) echo enabled;; *) echo disabled;; esac)" \
 && php -i | grep -q "Thread Safety => $attendu"

LABEL org.opencontainers.image.title="php-grpc" \
      org.opencontainers.image.description="PHP with the grpc and protobuf extensions, built once so nobody rebuilds them." \
      org.opencontainers.image.source="https://github.com/gplanchat/durable-dev" \
      org.opencontainers.image.licenses="MIT"
