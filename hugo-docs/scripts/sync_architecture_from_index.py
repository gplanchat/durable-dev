#!/usr/bin/env python3
"""
Régénère les pages Hugo architecture/adr.md, wa.md, ost.md, prd.md
à partir des tableaux de documentation/INDEX.md (source de vérité).

Usage (depuis la racine du dépôt) :
  python3 hugo-docs/scripts/sync_architecture_from_index.py
"""
from __future__ import annotations

import re
import sys
from pathlib import Path


def find_repo_root() -> Path:
    here = Path(__file__).resolve()
    # hugo-docs/scripts/this.py -> racine = parents[2]
    root = here.parent.parent.parent
    if (root / "documentation" / "INDEX.md").exists():
        return root
    if (Path.cwd() / "documentation" / "INDEX.md").exists():
        return Path.cwd()
    raise SystemExit("Impossible de trouver documentation/INDEX.md (lancez depuis la racine du dépôt).")


ROW_RE = re.compile(
    r"^\|\s*\[([^\]]+)\]\(([^)]+)\)\s*\|\s*([^|]*)\s*\|\s*([^|]*)\s*\|\s*$"
)


def parse_table_rows(lines: list[str], section_prefix: str) -> list[tuple[str, str, str, str]]:
    """Retourne (code, chemin relatif depuis documentation/, title, description) par ligne."""
    rows: list[tuple[str, str, str, str]] = []
    in_section = False
    for line in lines:
        if line.strip().startswith("## "):
            in_section = line.strip().startswith(f"## {section_prefix}")
            continue
        if not in_section:
            continue
        if not line.strip().startswith("|"):
            continue
        if "|---" in line.replace(" ", ""):
            continue
        if "Title" in line and "Description" in line:
            continue
        m = ROW_RE.match(line.rstrip())
        if not m:
            continue
        code, relpath, title, desc = m.groups()
        rows.append((code.strip(), relpath.strip(), title.strip(), desc.strip()))
    return rows


def gh_shortcode(relpath: str, label: str) -> str:
    """Produit le shortcode Hugo {{< ghdoc ... >}} sans f-string (accolades)."""
    return "{{< ghdoc \"" + relpath + "\" \"" + label + "\" >}}"


def render_page(
    *,
    title: str,
    weight: int,
    intro: str,
    table_header: str,
    rows: list[tuple[str, str, str, str]],
    footer_link: str,
) -> str:
    header_row = "| " + table_header + " |"
    sep = "|-----|------------|-------------|"

    body_lines = [header_row, sep]
    for code, relpath, title_en, desc in rows:
        gh = gh_shortcode(relpath, code)
        t = title_en.replace("|", "\\|")
        d = desc.replace("|", "\\|")
        body_lines.append(f"| {gh} | {t} | {d} |")

    parts = [
        "---",
        f"title: {title}",
        f"weight: {weight}",
        "---",
        "",
        intro.strip(),
        "",
        *body_lines,
        "",
        footer_link,
        "",
    ]
    return "\n".join(parts)


def main() -> None:
    root = find_repo_root()
    index_path = root / "documentation" / "INDEX.md"
    out_dir = root / "hugo-docs" / "content" / "docs" / "architecture"

    text = index_path.read_text(encoding="utf-8")
    lines = text.splitlines()

    adr_rows = parse_table_rows(lines, "ADR —")
    wa_rows = parse_table_rows(lines, "WA —")
    ost_rows = parse_table_rows(lines, "OST —")
    prd_rows = parse_table_rows(lines, "PRD —")

    if not adr_rows:
        print("Aucune ligne ADR parsée — vérifiez le format de INDEX.md", file=sys.stderr)
        sys.exit(1)

    footer = '[← Retour à l’architecture]({{< relref "/docs/architecture/" >}})'

    intro_adr = (
        "*Architecture Decision Records* — décisions techniques tracées avec contexte et conséquences. "
        "L’index et les intitulés ci-dessous proviennent de `documentation/INDEX.md` (synchronisé par script). "
        'Meta : {{< ghdoc "adr/ADR001-adr-management-process.md" "ADR001 — processus" >}}.'
    )

    pages: list[tuple[str, str]] = [
        (
            "adr.md",
            render_page(
                title="ADR",
                weight=46,
                intro=intro_adr,
                table_header="ADR | Titre (INDEX) | Description",
                rows=adr_rows,
                footer_link=footer,
            ),
        ),
        (
            "wa.md",
            render_page(
                title="WA",
                weight=47,
                intro=(
                    "*Working Agreements* — conventions d’équipe (branches, commits, revues). "
                    "Tableau synchronisé depuis `documentation/INDEX.md`."
                ),
                table_header="WA | Titre | Description",
                rows=wa_rows,
                footer_link=footer,
            ),
        ),
        (
            "ost.md",
            render_page(
                title="OST",
                weight=48,
                intro=(
                    "*Opportunity Solution Trees* — explorations et opportunités. "
                    "Tableau synchronisé depuis `documentation/INDEX.md`."
                ),
                table_header="OST | Titre | Description",
                rows=ost_rows,
                footer_link=footer,
            ),
        ),
        (
            "prd.md",
            render_page(
                title="PRD",
                weight=49,
                intro=(
                    "*Product Requirements Documents* — état du produit, scénarios, CI, recettes. "
                    "Tableau synchronisé depuis `documentation/INDEX.md`."
                ),
                table_header="PRD | Titre | Description",
                rows=prd_rows,
                footer_link=footer,
            ),
        ),
    ]

    out_dir.mkdir(parents=True, exist_ok=True)
    for name, content in pages:
        (out_dir / name).write_text(content, encoding="utf-8")
        print(f"Écrit {out_dir / name}")

    print("OK — relancez `hugo` pour valider.")


if __name__ == "__main__":
    main()
