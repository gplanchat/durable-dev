---
title: gRPC dans votre image de conteneur
weight: 16
---

# gRPC dans votre image de conteneur

Le backend Temporal (celui qui garde le journal dans un cluster Temporal ; voir le
[glossaire](../glossary/)) parle au cluster en gRPC, il lui faut donc `ext-grpc`, et `ext-grpc` ne
fournit aucun binaire préconstruit. `install-php-extensions grpc protobuf` la compile depuis les
sources : mesuré sur ce dépôt, **6 min 58 s** pour `php:8.3-cli-alpine`, parce que grpc entraîne
abseil, boringssl, re2 et upb, et se compile en C++17. Votre construction d'image y passe ce temps à
chaque fois, sur chaque branche.

Les extensions compilées sont donc publiées. Copiez-les dans votre image depuis :

```
ghcr.io/gplanchat/php-grpc:8.4-cli
```

Les images existent pour PHP 8.2, 8.3, 8.4 et 8.5, chacun en quatre formes : `cli`, `cli-alpine`,
`zts`, `zts-alpine`. Elles sont publiques et ne demandent aucune authentification.

Les recettes ci-dessous utilisent les étiquettes glissantes, **reconstruites tous les lundis** pour
suivre les versions correctives de PHP et les mises à jour de sécurité de leur base. Chaque
publication pose aussi une étiquette datée comme `8.4-cli-20260828`, qui n'est jamais reconstruite.
Pour que votre construction ne bouge pas sous vos pieds, épinglez cette étiquette : le changement
tient en un mot.

Les étiquettes datées sont élaguées. Seules les **huit plus récentes** sont conservées pour chaque
couple version-et-forme, soit environ deux mois de points d'épinglage. Épinglez une étiquette datée
pour une version que vous êtes sur le point de livrer. Pour une image de base dont vous partirez
encore l'an prochain, prenez l'étiquette glissante, qui continue de fonctionner.

---

## Choisir l'étiquette selon votre PHP et votre libc {#choisir-létiquette--trois-choses-doivent-correspondre}

Une extension est un objet partagé compilé pour un PHP précis. Trois propriétés de ce PHP doivent
correspondre, et chaque écart échoue d'une façon différente :

| Doit correspondre | Ce qui se passe sinon | Quand vous l'apprenez |
|---|---|---|
| **Version mineure de PHP** | le dossier d'extensions n'existe pas dans votre base, le `COPY` ne trouve rien | au `docker build`, tout de suite |
| **Thread-safety** (ZTS / NTS) | pareil, le nom du dossier la porte | au `docker build`, tout de suite |
| **libc** (glibc / musl) | **le chemin est identique**, le fichier se copie sans broncher, et PHP échoue à le charger | à l'exécution, sauf si vous vérifiez |

La version corrective, elle, n'a *pas* besoin de correspondre : `php:8.4.22` charge une extension
construite contre `8.4.25`. La mineure, si : 8.3 et 8.4 sont deux ABI différentes.

Le nom du dossier encode les deux premières :

| PHP | NTS | ZTS |
|---|---|---|
| 8.2 | `no-debug-non-zts-20220829` | `no-debug-zts-20220829` |
| 8.3 | `no-debug-non-zts-20230831` | `no-debug-zts-20230831` |
| 8.4 | `no-debug-non-zts-20240924` | `no-debug-zts-20240924` |
| 8.5 | `no-debug-non-zts-20250925` | `no-debug-zts-20250925` |

Il n'encode **pas** la libc. Debian et Alpine utilisent exactement le même chemin : copier un
`grpc.so` construit sur Alpine dans une base Debian réussit sans le moindre avertissement, et la
panne n'apparaît que bien plus tard, sous cette forme :

```
Unable to load dynamic library 'grpc.so' … Error loading shared library libstdc++.so.6
```

C'est pour cela que chaque recette ci-dessous se termine par la même ligne :

```dockerfile
RUN php -m | grep -qx grpc && php -m | grep -qx protobuf
```

Elle ne coûte rien, et un écart de libc fait alors échouer la construction au lieu de se révéler
en production.

Choisissez l'étiquette d'après votre image de base :

| Votre image de base | Copier depuis |
|---|---|
| `php:8.4-cli`, `php:8.4-fpm`, `php:8.4-apache` | `8.4-cli` |
| `php:8.4-cli-alpine`, `php:8.4-fpm-alpine` | `8.4-cli-alpine` |
| `dunglas/frankenphp:1-php8.4` | `8.4-zts` |
| `dunglas/frankenphp:1-php8.4-alpine` | `8.4-zts-alpine` |

---

## Les recettes par serveur web {#cinq-montages-trois-recettes}

**Nginx + php-fpm, Caddy + php-fpm et Apache en FastCGI (`mod_proxy_fcgi`) partagent une seule
recette.** Dans les trois cas, PHP tourne dans son propre conteneur `php:X-fpm` et le serveur web
dans un autre, qui ne charge jamais la moindre extension PHP. Rien ne change dans votre
`nginx.conf`, votre `Caddyfile` ou votre hôte virtuel.

Deux montages diffèrent, ceux où PHP vit *dans* l'image du serveur : `mod_php`, qui est NTS, et
FrankenPHP, qui est ZTS.

### php-fpm, derrière Nginx, Caddy ou Apache en FastCGI

```dockerfile
FROM ghcr.io/gplanchat/php-grpc:8.4-cli AS ext

FROM php:8.4-fpm
COPY --from=ext /usr/local/etc/php/conf.d/docker-php-ext-grpc.ini /usr/local/etc/php/conf.d/
COPY --from=ext /usr/local/etc/php/conf.d/docker-php-ext-protobuf.ini /usr/local/etc/php/conf.d/
COPY --from=ext /usr/local/lib/php/extensions/no-debug-non-zts-20240924/grpc.so /usr/local/lib/php/extensions/no-debug-non-zts-20240924/
COPY --from=ext /usr/local/lib/php/extensions/no-debug-non-zts-20240924/protobuf.so /usr/local/lib/php/extensions/no-debug-non-zts-20240924/

RUN php -m | grep -qx grpc && php -m | grep -qx protobuf
```

**Sur Alpine, vérifiez `libstdc++`.** grpc est du C++ et a besoin de cette bibliothèque au
chargement. Toutes les bases Debian l'embarquent. Les bases Alpine varient : `php:8.4-fpm-alpine`
ne l'a pas, et l'image Alpine de FrankenPHP l'a. La recette php-fpm sur Alpine l'installe :

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

Sans ce `apk add`, la construction échoue sur la dernière ligne, la vérification `php -m`, avec
`Error loading shared library libstdc++.so.6`.

### Apache avec mod_php

Ici PHP est dans l'image du serveur web, et `php:X-apache` est NTS, comme les images fpm. La
recette reprend le même dossier d'extensions et la même étiquette source :

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

FrankenPHP embarque PHP dans le processus du serveur et fait tourner plusieurs workers dans un même
processus : il est donc construit **thread-safe**. Une extension NTS ne s'y charge pas ; les images
`zts` existent pour ce cas. Les chemins contiennent `zts` : `no-debug-zts-20240924`, et non
`no-debug-non-zts-20240924` :

```dockerfile
FROM ghcr.io/gplanchat/php-grpc:8.4-zts AS ext

FROM dunglas/frankenphp:1-php8.4
COPY --from=ext /usr/local/etc/php/conf.d/docker-php-ext-grpc.ini /usr/local/etc/php/conf.d/
COPY --from=ext /usr/local/etc/php/conf.d/docker-php-ext-protobuf.ini /usr/local/etc/php/conf.d/
COPY --from=ext /usr/local/lib/php/extensions/no-debug-zts-20240924/grpc.so /usr/local/lib/php/extensions/no-debug-zts-20240924/
COPY --from=ext /usr/local/lib/php/extensions/no-debug-zts-20240924/protobuf.so /usr/local/lib/php/extensions/no-debug-zts-20240924/

RUN php -m | grep -qx grpc && php -m | grep -qx protobuf
```

Sur `dunglas/frankenphp:1-php8.4-alpine`, copiez plutôt depuis `8.4-zts-alpine`. Cette image
embarque déjà `libstdc++` et n'a donc pas besoin d'`apk add`. Les bases Alpine varient sur ce point,
et la ligne `RUN php -m` vous indique laquelle vous avez.

---

## Une image de base absente du tableau

Avant d'écrire le moindre `COPY`, interrogez directement l'image de base :

```bash
docker run --rm --entrypoint php <votre-image-de-base> -r \
  'printf("%s %s %s\n", PHP_VERSION, PHP_ZTS ? "ZTS" : "NTS", ini_get("extension_dir"));'

docker run --rm --entrypoint sh <votre-image-de-base> -c \
  '[ -f /etc/alpine-release ] && echo musl || echo glibc'
```

Les réponses donnent les trois propriétés du premier tableau plus haut. Si aucune étiquette publiée
ne correspond aux trois, compilez l'extension comme le montre la section suivante.

---

## Compiler l'extension dans votre propre image {#si-vous-préférez-ne-rien-copier}

La copie est une optimisation. Compiler dans votre propre image fonctionne aussi, et prend plus de
temps :

```dockerfile
COPY --from=mlocati/php-extension-installer:latest /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions grpc protobuf
```

Compilez quand votre base n'a aucune étiquette publiée qui lui corresponde, ou quand une
construction d'image de sept minutes vous convient. Les images publiées plus haut sont construites
de la même façon.

> [!NOTE]
> **Sur GitHub Actions, vous n'avez besoin de rien de tout cela.** `shivammathur/setup-php`
> installe `grpc` depuis un binaire préconstruit en cinq secondes environ. Cette page traite des
> images de conteneurs, où un tel binaire n'existe pas.

Les images et leur workflow de construction vivent dans [`docker/php-grpc/`](https://github.com/gplanchat/durable-dev/tree/main/docker/php-grpc).
