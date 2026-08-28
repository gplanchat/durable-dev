#!/usr/bin/env python3
"""Décide quelles versions du paquet `php-grpc` sur GHCR peuvent être supprimées.

Le workflow reconstruit seize images chaque lundi et pose, à chaque publication, une étiquette
glissante (`8.4-zts`) et une étiquette datée (`8.4-zts-20260828`). Les glissantes sont seize pour
toujours ; les datées, elles, s'accumulent — environ huit cents par an, et rien ne les élague.

Ce script ne supprime rien : il lit la liste des versions et écrit les identifiants à supprimer. Ce
qui décide se relit en une phrase, et c'est voulu, parce que se tromper ici retire des images
publiées.

**Une version est protégée dès qu'elle porte une étiquette qui n'est pas datée.** Une version est un
manifeste, et un manifeste porte souvent plusieurs étiquettes : le lundi de la publication,
`8.4-zts` et `8.4-zts-20260828` désignent le même. Supprimer « l'étiquette datée » reviendrait à
supprimer l'image que `8.4-zts` désigne. La règle est donc formulée sur la version, jamais sur
l'étiquette.

**Une version sans aucune étiquette est protégée aussi.** Elle n'est pas orpheline : buildx publie
les attestations de provenance en manifestes non étiquetés, référencés par l'index qui, lui, porte
l'étiquette. Les supprimer casse l'image publiée.
"""

from __future__ import annotations

import argparse
import json
import re
import sys

# `8.4-zts-alpine-20260828` : version mineure, forme, puis la date de publication.
DATEE = re.compile(r'^(?P<serie>\d+\.\d+-[a-z-]+)-(?P<date>\d{8})$')


def a_supprimer(versions: list[dict], garder: int) -> list[dict]:
    """Les versions dont toutes les étiquettes sont datées, au-delà des `garder` plus récentes
    de leur série. Renvoie des dicts `{id, tags, serie, date}`, les plus anciennes d'abord."""
    candidates: dict[str, list[dict]] = {}

    for version in versions:
        etiquettes = version.get('tags') or []
        if not etiquettes:
            continue  # attestations buildx — voir le docstring
        correspondances = [DATEE.match(e) for e in etiquettes]
        if not all(correspondances):
            continue  # au moins une étiquette glissante : la version est en service
        # Une version peut porter plusieurs dates si deux publications ont donné le même manifeste.
        # C'est la plus récente qui décide de son rang.
        recente = max(correspondances, key=lambda m: m.group('date'))
        candidates.setdefault(recente.group('serie'), []).append({
            'id': version['id'],
            'tags': etiquettes,
            'serie': recente.group('serie'),
            'date': recente.group('date'),
        })

    surnuméraires = []
    for serie in candidates.values():
        serie.sort(key=lambda v: v['date'], reverse=True)
        surnuméraires.extend(serie[garder:])
    surnuméraires.sort(key=lambda v: (v['serie'], v['date']))
    return surnuméraires


def _autotest() -> None:
    protegee_car_glissante = {'id': 1, 'tags': ['8.4-zts', '8.4-zts-20260828']}
    datee_seule = {'id': 2, 'tags': ['8.4-zts-20260821']}
    plus_ancienne = {'id': 3, 'tags': ['8.4-zts-20260814']}
    sans_etiquette = {'id': 4, 'tags': []}
    autre_serie = {'id': 5, 'tags': ['8.2-cli-alpine-20260814']}

    toutes = [protegee_car_glissante, datee_seule, plus_ancienne, sans_etiquette, autre_serie]

    # Le cas qui compte : la version que `8.4-zts` désigne n'est jamais candidate, même si elle
    # porte aussi la plus vieille des étiquettes datées.
    assert [v['id'] for v in a_supprimer(toutes, garder=99)] == []
    assert [v['id'] for v in a_supprimer(toutes, garder=1)] == [3]
    assert [v['id'] for v in a_supprimer(toutes, garder=0)] == [5, 3, 2]

    # `garder` compte par série, pas globalement : deux séries de deux gardent une chacune.
    deux_series = [
        {'id': 10, 'tags': ['8.4-zts-20260828']}, {'id': 11, 'tags': ['8.4-zts-20260821']},
        {'id': 12, 'tags': ['8.2-cli-20260828']}, {'id': 13, 'tags': ['8.2-cli-20260821']},
    ]
    assert sorted(v['id'] for v in a_supprimer(deux_series, garder=1)) == [11, 13]

    # Une version qui porte deux dates est classée sur la plus récente.
    deux_dates = [
        {'id': 20, 'tags': ['8.4-zts-20260828', '8.4-zts-20260821']},
        {'id': 21, 'tags': ['8.4-zts-20260814']},
    ]
    assert [v['id'] for v in a_supprimer(deux_dates, garder=1)] == [21]

    # Une étiquette inattendue protège : mieux vaut garder une image de trop qu'en retirer une
    # que quelqu'un utilise.
    inattendue = [{'id': 30, 'tags': ['latest']}, {'id': 31, 'tags': ['8.4-zts-experimental']}]
    assert a_supprimer(inattendue, garder=0) == []

    print('autotest : ok')


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--keep', type=int, default=8,
                        help='étiquettes datées conservées par série (défaut : 8, soit deux mois)')
    parser.add_argument('--self-test', action='store_true')
    args = parser.parse_args()

    if args.self_test:
        _autotest()
        return 0

    versions = json.load(sys.stdin)
    surnuméraires = a_supprimer(versions, args.keep)

    print(f'{len(versions)} version(s) publiée(s), {len(surnuméraires)} à retirer '
          f'(on garde les {args.keep} plus récentes de chaque série)', file=sys.stderr)
    for v in surnuméraires:
        print(f"  {v['id']}  {', '.join(v['tags'])}", file=sys.stderr)

    for v in surnuméraires:
        print(v['id'])
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
