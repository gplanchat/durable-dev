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
COPY --from=ext /usr/local/etc/php/conf.d/docker-php-ext-grpc.ini /usr/local/etc/php/conf.d/
COPY --from=ext /usr/local/etc/php/conf.d/docker-php-ext-protobuf.ini /usr/local/etc/php/conf.d/
COPY --from=ext /usr/local/lib/php/extensions/no-debug-non-zts-20230831/grpc.so /usr/local/lib/php/extensions/no-debug-non-zts-20230831/
COPY --from=ext /usr/local/lib/php/extensions/no-debug-non-zts-20230831/protobuf.so /usr/local/lib/php/extensions/no-debug-non-zts-20230831/

RUN php -m | grep -qx grpc && php -m | grep -qx protobuf
```

Le chemin est nommé en entier plutôt que copié en bloc, à dessein : `COPY` du dossier
`extensions/` réussit même quand le nom du dossier de la base est différent — le `.so` atterrit dans
un dossier que PHP ne lit pas, et la panne attend l'exécution. Le chemin explicite échoue au
`docker build`.

Trois choses doivent correspondre entre les deux images : la **version mineure** de PHP, la
**thread-safety**, et la **libc**. Les deux premières sont dans le nom du dossier, donc un écart
casse la construction. La troisième n'y est pas : Debian et Alpine partagent le même chemin, un
`.so` musl se copie sans un mot dans une base glibc et refuse de se charger ensuite. D'où le
`RUN php -m` final, qui rattrape ce cas-là et lui seul.

Une quatrième condition ne se voit nulle part dans les chemins : grpc est du C++ et réclame
`libstdc++`. Les bases Debian l'embarquent toutes ; les bases Alpine, pas toutes — l'image Sylius
ci-dessus l'a, `php:8.3-fpm-alpine` ne l'a pas et demande un `apk add --no-cache libstdc++`. Là
encore, c'est le `RUN php -m` qui le dit.

**Le guide utilisateur détaille tout cela** — recettes pour php-fpm derrière Nginx ou Caddy, pour
Apache avec mod_php, et pour FrankenPHP (qui est thread-safe, donc `zts`) :
<https://durable.rocks/docs/container-images/>.

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
