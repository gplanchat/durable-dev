#!/usr/bin/env python3
"""Decides which versions of the `php-grpc` package on GHCR may be deleted.

The workflow rebuilds sixteen images every Monday and places, on each publication, a rolling tag
(`8.4-zts`) and a dated tag (`8.4-zts-20260828`). The rolling ones are sixteen forever; the dated
ones accumulate — about eight hundred a year, and nothing prunes them.

This script deletes nothing: it reads the version list and writes the identifiers to delete. What
decides reads back in a single sentence, and that is deliberate, because being wrong here removes
published images.

**A version is protected as soon as it carries a tag that is not dated.** A version is a manifest,
and a manifest often carries several tags: on publication Monday, `8.4-zts` and `8.4-zts-20260828`
designate the same one. Deleting "the dated tag" would amount to deleting the image `8.4-zts`
designates. The rule is therefore stated on the version, never on the tag.

**A version with no tag at all is protected too.** It is not an orphan: buildx publishes provenance
attestations as untagged manifests, referenced by the index which does carry the tag. Deleting them
breaks the published image.
"""

from __future__ import annotations

import argparse
import json
import re
import sys

# `8.4-zts-alpine-20260828`: minor version, flavour, then the publication date.
DATED = re.compile(r'^(?P<series>\d+\.\d+-[a-z-]+)-(?P<date>\d{8})$')


def to_delete(versions: list[dict], keep: int) -> list[dict]:
    """The versions whose tags are all dated, beyond the `keep` most recent of their series.
    Returns dicts `{id, tags, series, date}`, oldest first."""
    candidates: dict[str, list[dict]] = {}

    for version in versions:
        tags_of = version.get('tags') or []
        if not tags_of:
            continue  # buildx attestations — see the docstring
        matches = [DATED.match(e) for e in tags_of]
        if not all(matches):
            continue  # at least one rolling tag: the version is in service
        # A version can carry several dates if two publications produced the same manifest.
        # It is the most recent one that decides its rank.
        most_recent = max(matches, key=lambda m: m.group('date'))
        candidates.setdefault(most_recent.group('series'), []).append({
            'id': version['id'],
            'tags': tags_of,
            'series': most_recent.group('series'),
            'date': most_recent.group('date'),
        })

    surplus = []
    for series in candidates.values():
        series.sort(key=lambda v: v['date'], reverse=True)
        surplus.extend(series[keep:])
    surplus.sort(key=lambda v: (v['series'], v['date']))
    return surplus


def _self_test() -> None:
    protected_by_rolling = {'id': 1, 'tags': ['8.4-zts', '8.4-zts-20260828']}
    dated_only = {'id': 2, 'tags': ['8.4-zts-20260821']}
    older = {'id': 3, 'tags': ['8.4-zts-20260814']}
    untagged = {'id': 4, 'tags': []}
    other_series = {'id': 5, 'tags': ['8.2-cli-alpine-20260814']}

    all_of_them = [protected_by_rolling, dated_only, older, untagged, other_series]

    # The case that matters: the version `8.4-zts` designates is never a candidate, even when it
    # also carries the oldest of the dated tags.
    assert [v['id'] for v in to_delete(all_of_them, keep=99)] == []
    assert [v['id'] for v in to_delete(all_of_them, keep=1)] == [3]
    assert [v['id'] for v in to_delete(all_of_them, keep=0)] == [5, 3, 2]

    # `keep` counts per series, not globally: two series of two keep one each.
    two_series = [
        {'id': 10, 'tags': ['8.4-zts-20260828']}, {'id': 11, 'tags': ['8.4-zts-20260821']},
        {'id': 12, 'tags': ['8.2-cli-20260828']}, {'id': 13, 'tags': ['8.2-cli-20260821']},
    ]
    assert sorted(v['id'] for v in to_delete(two_series, keep=1)) == [11, 13]

    # A version that carries two dates is ranked on the most recent one.
    two_dates = [
        {'id': 20, 'tags': ['8.4-zts-20260828', '8.4-zts-20260821']},
        {'id': 21, 'tags': ['8.4-zts-20260814']},
    ]
    assert [v['id'] for v in to_delete(two_dates, keep=1)] == [21]

    # An unexpected tag protects: better to keep one image too many than remove one that
    # somebody uses.
    unexpected = [{'id': 30, 'tags': ['latest']}, {'id': 31, 'tags': ['8.4-zts-experimental']}]
    assert to_delete(unexpected, keep=0) == []

    print('self-test: ok')


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--keep', type=int, default=8,
                        help='dated tags kept per series (default: 8, i.e. two months)')
    parser.add_argument('--self-test', action='store_true')
    args = parser.parse_args()

    if args.self_test:
        _self_test()
        return 0

    versions = json.load(sys.stdin)
    surplus = to_delete(versions, args.keep)

    print(f'{len(versions)} version(s) published, {len(surplus)} to remove '
          f'(keeping the {args.keep} most recent of each series)', file=sys.stderr)
    for v in surplus:
        print(f"  {v['id']}  {', '.join(v['tags'])}", file=sys.stderr)

    for v in surplus:
        print(v['id'])
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
