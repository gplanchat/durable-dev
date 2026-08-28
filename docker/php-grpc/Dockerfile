# syntax=docker/dockerfile:1

# Une image PHP qui porte déjà `grpc` et `protobuf`.
#
# Ces deux extensions se compilent depuis les sources. Mesuré pour `8.3-cli-alpine` sur une machine
# de développement : **6 min 58 s**, pour 126 Mo d'image — grpc 1.83 traîne abseil, boringssl, re2 et
# upb, et se compile en C++17. `install-php-extensions` ne fait pas de miracle : il fait le travail
# correctement, pas instantanément. Le payer une fois en CI et le publier, c'est tout l'objet ici.
#
# **Ce n'est pas une image d'application.** Elle ne contient ni votre code, ni Composer, ni un
# serveur : elle sert de base, ou de source pour un `COPY --from` dans un montage multi-étapes.

ARG PHP_VERSION=8.3
ARG FLAVOUR=cli

FROM php:${PHP_VERSION}-${FLAVOUR}

# Le script de mlocati plutôt qu'un `pecl install` écrit à la main : il connaît les paquets système
# de chaque distribution, nettoie derrière lui, et suit les versions de PHP au fil des sorties.
COPY --from=mlocati/php-extension-installer:latest /usr/bin/install-php-extensions /usr/local/bin/

# `protobuf` n'est pas décoratif à côté de `grpc` : sans lui, la sérialisation retombe sur
# l'implémentation PHP pure, correcte mais nettement plus lente sur des messages volumineux.
RUN install-php-extensions grpc protobuf

# Une image dont on ne peut pas vérifier le contenu ne sert à rien : ces deux lignes échouent au
# build si l'extension n'est pas réellement chargeable, plutôt que de laisser le défaut au
# consommateur.
RUN php -m | grep -qx grpc && php -m | grep -qx protobuf

LABEL org.opencontainers.image.title="php-grpc" \
      org.opencontainers.image.description="PHP with the grpc and protobuf extensions, built once so nobody rebuilds them." \
      org.opencontainers.image.source="https://github.com/gplanchat/durable-dev" \
      org.opencontainers.image.licenses="MIT"
