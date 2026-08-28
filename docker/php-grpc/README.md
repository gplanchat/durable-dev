# `ghcr.io/gplanchat/php-grpc`

Des images PHP qui portent déjà **`grpc`** et **`protobuf`**, construites une fois en CI plutôt
qu'à chaque `docker build` d'un projet.

```
ghcr.io/gplanchat/php-grpc:8.3-cli          ghcr.io/gplanchat/php-grpc:8.3-zts
ghcr.io/gplanchat/php-grpc:8.3-cli-alpine   ghcr.io/gplanchat/php-grpc:8.3-zts-alpine
```

PHP 8.2, 8.3, 8.4 et 8.5, en Debian et en Alpine, **avec et sans thread-safety**. Chaque
publication pose aussi une étiquette datée (`8.3-cli-alpine-20260828`) pour qui veut épingler.

Les variantes `zts` ne sont pas un raffinement : une extension compilée pour un PHP non thread-safe
refuse de se charger dans un PHP thread-safe, et réciproquement. Un runtime qui exécute plusieurs
workers dans un même processus réclame `zts` ; le reste du monde PHP tourne en `cli`. Aucune des
deux ne couvre l'autre, d'où les quatre déclinaisons.

## Ce que ces images ne sont pas

**Pas des images d'application.** Ni votre code, ni Composer, ni serveur web. Elles servent de base,
ou de source dans un montage multi-étapes :

```dockerfile
FROM ghcr.io/gplanchat/php-grpc:8.3-cli-alpine AS ext

FROM ghcr.io/sylius/sylius-php:8.3-alpine
COPY --from=ext /usr/local/lib/php/extensions/ /usr/local/lib/php/extensions/
COPY --from=ext /usr/local/etc/php/conf.d/docker-php-ext-grpc.ini /usr/local/etc/php/conf.d/
COPY --from=ext /usr/local/etc/php/conf.d/docker-php-ext-protobuf.ini /usr/local/etc/php/conf.d/
```

Le `COPY --from` demande que les deux images partagent la même version de PHP **et** la même
distribution : une extension compilée pour Alpine ne se charge pas sur une base Debian, et une
extension de PHP 8.3 ne se charge pas sous 8.4. C'est la contrainte de tout partage de binaire, pas
une particularité d'ici.

## Ce que ces images ne servent pas à résoudre

**GitHub Actions n'en a pas besoin.** Mesuré sur ce dépôt : `shivammathur/setup-php` installe
`grpc` en **cinq secondes**, binaire préconstruit à l'appui. Le job « Tests d'intégration Temporal »,
qui demande l'extension, démarre PHP aussi vite que celui de Sylius, qui ne la demande pas :

| job | étape « Setup PHP » | demande `grpc` |
|---|---|---|
| Tests d'intégration Temporal | 5 s | oui |
| Boutique Sylius | 6 s | non |

Dans un workflow, écrivez donc `extensions: …, grpc` et n'y pensez plus. Ces images sont pour
**Docker**, où `install-php-extensions` compile depuis les sources à chaque construction.

**Combien de temps, exactement.** Mesuré en construisant `8.3-cli-alpine` sur une machine de
développement ordinaire :

```
real    6m58s      image finale : 126 Mo
```

Sept minutes par construction, par version de PHP, par distribution, sur chaque machine et dans
chaque pipeline qui en a besoin. C'est ce que publier l'image supprime — grpc 1.83 traînant derrière
lui abseil, boringssl, re2 et upb, et se compilant en C++17.

## Comment elles sont construites

`install-php-extensions` de mlocati plutôt qu'un `pecl install` écrit à la main : il connaît les
paquets système de chaque distribution, nettoie derrière lui, et suit les sorties de PHP. Publier
l'image ne remplace pas cet outil, elle en amortit le coût.

Deux vérifications, et elles ne disent pas la même chose. Le `Dockerfile` échoue si l'extension
n'est pas chargeable **dans la couche construite** ; le workflow relance ensuite `php -m` sur
**l'image publiée**. Une image peut être construite juste et poussée mal.

## Reconstruire

Quatre façons de le déclencher :

| Déclencheur | Quand |
| --- | --- |
| `workflow_dispatch` | à la main, depuis l'onglet Actions |
| `repository_dispatch` | par webhook : `gh api repos/:owner/:repo/dispatches -f event_type=php-grpc-images` |
| `push` sur `main` | le `Dockerfile` ou le workflow ont changé |
| `schedule` | tous les lundis |

La cadence hebdomadaire n'est pas du zèle. Deux choses vieillissent dans ces images, et pas au même
rythme : les paquets système de la base, et PHP lui-même, dont les versions correctives sortent
chaque mois. Reconstruire chaque semaine garde l'écart avec `php:8.4-cli` à quelques jours, et le
cache Buildx rend la reconstruction bon marché quand rien n'a bougé en amont.

La publication n'a lieu que depuis `main`. Sur une pull request, le workflow construit sans pousser :
c'est la compilation qu'on veut vérifier, et une étiquette glissante ne doit pas désigner un essai
non relu.

`arm64` est proposé en option et pas par défaut : il passe par QEMU, et émuler une compilation C++
coûte bien plus que de la compiler.
