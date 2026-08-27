#!/usr/bin/env python3
"""Convertit une page conçue dans claude.ai/design en gabarit Hugo.

    ./import-design.py <chemin>/variant-b-narrative.dc.html layouts/index.html

Pourquoi un script plutôt qu'un copier-coller : la page est reprise à chaque
tour de la boucle de design, et une extraction faite à la main serait à refaire
en entier à chaque fois. Ici on rejoue la commande.

Cinq choses séparent une page du canevas d'une page servie par Hugo :

1. `{{ … }}` — le canevas et Hugo partagent la syntaxe. Laissées en place,
   Hugo tenterait de les exécuter et la compilation échouerait. Il ne doit
   donc plus en rester une seule en sortie ; c'est vérifié.
2. La palette est interpolée dans un attribut `style` en ligne, donc
   impossible à surcharger par une feuille de style — un style en ligne
   l'emporte. On la sort du balisage et on l'écrit en `:root`, ce qui rend le
   thème sombre exprimable.
3. `style-hover` est un attribut du canevas, ignoré par les navigateurs. Sans
   conversion en `.classe:hover`, aucun survol de la page ne fonctionne.
4. `onClick` / `onMouseEnter` appellent des méthodes d'un composant qui
   n'existe pas hors du canevas. On les remplace par des attributs `data-` que
   reprend un script autonome.
5. Les logos sont chargés en `<img>`. Un SVG externe est un document isolé :
   il n'hérite pas de `currentColor` et ne peut donc pas suivre le thème. On
   les incorpore.
"""

from __future__ import annotations

import json
import pathlib
import re
import sys

HERE = pathlib.Path(__file__).parent

# Résolues depuis le composant du canevas. L'accent est recalculé par son
# propre algorithme : il part de la teinte choisie et descend en luminosité
# jusqu'à passer un contraste de 4,6 sur le fond du thème visé.
PALETTE = {
    "light": {
        "bg": "#f7f4ef", "bg2": "#efe9df", "fg": "#1d1a16", "fg2": "#6c6459",
        "line": "#ddd6ca", "accent": "#9e488b", "accent-fg": "#fdfaf6",
        "code-bg": "#ffffff", "code-fg": "#1d1a16",
        "ck": "#a1341f", "cs": "#6b7d1f", "cc": "#9a9184", "cv": "#1c6b8a",
    },
    "dark": {
        "bg": "#141310", "bg2": "#1c1a17", "fg": "#eae5dc", "fg2": "#a09a90",
        "line": "#2c2925", "accent": "#c27ab2", "accent-fg": "#170f0a",
        "code-bg": "#100f0d", "code-fg": "#eae5dc",
        "ck": "#e58a6a", "cs": "#a8bf62", "cc": "#6b665e", "cv": "#79b6cf",
    },
}

# `--ts` (échelle typographique) et `--sp` (densité) étaient des molettes du
# canevas. Elles se figent à 1 : plus personne ne les tourne.
SCALARS = {"ts": "1", "sp": "1"}

# Le design a longtemps posé 26 ou 30 px sur ses logos, illisibles à cette
# taille, et un plancher de 48 px était imposé ici. Le canevas pose maintenant
# 48 lui-même — et 22 sur les deux marques minuscules qui servent de puce
# « Symfony app » à côté d'un nom d'application. Un plancher les gonflerait à
# plus du double : la taille redevient un choix du canevas, entièrement.

# Les liens de cas d'usage pointaient vers des pages qui n'ont jamais existé
# (`/docs/use-cases/<slug>`). Chacun va vers la section qui traite réellement
# le sujet, ancre comprise ; les ancres sont vérifiées à l'exécution.
LINKS = {
    "/docs/use-cases/parallelism": "/docs/workflows/#waiting-versus-assembling",
    "/docs/use-cases/quorum": "/docs/workflows/#waiting-versus-assembling",
    "/docs/use-cases/deadlines": "/docs/workflows/#bounding-a-wait-in-time",
    "/docs/use-cases/retries-and-timeouts": "/docs/failures/",
    "/docs/use-cases/signals-queries-updates": "/docs/workflows/#entry-and-optional-handlers",
    "/docs/use-cases/cancellation-and-compensation": "/docs/cancellation/",
}


def die(message: str) -> None:
    sys.exit(f"import-design: {message}")


def extract_root(source: str) -> str:
    """Le `<div>` qui porte la palette, jusqu'à sa fermeture."""
    start = source.find('<div style="--bg:')
    if start < 0:
        die("racine introuvable : aucun <div style=\"--bg:…\"> dans la source")
    end = source.rfind("</div>")
    return source[start:end + len("</div>")]


def strip_palette_decls(root: str) -> str:
    """Sort les `--x: {{ y }}` du style en ligne : ils bloquent tout thème."""
    open_tag = re.match(r"<div style=\"([^\"]*)\">", root)
    if not open_tag:
        die("l'ouvrante de la racine n'a pas la forme attendue")
    kept = [
        decl.strip() for decl in open_tag.group(1).split(";")
        if decl.strip() and not decl.strip().startswith("--")
    ]
    return f'<div class="dz-root" style="{"; ".join(kept)};">' + root[open_tag.end():]


def convert_hovers(root: str) -> tuple[str, list[str]]:
    """`style-hover="…"` → une classe et une règle `:hover` réelle."""
    rules: list[str] = []

    def replace(match: re.Match[str]) -> str:
        index = len(rules)
        rules.append(f".dz-h{index}:hover{{{match.group(1)}}}")
        return f'data-dzh="{index}"'

    root = re.sub(r'style-hover="([^"]*)"', replace, root)

    # La classe doit exister sur l'élément, pas seulement l'attribut.
    def attach(match: re.Match[str]) -> str:
        return f'class="dz-h{match.group(1)}" ' + match.group(0)

    root = re.sub(r'data-dzh="(\d+)"', attach, root)
    return root, rules


def convert_handlers(root: str) -> str:
    """Les gestionnaires du composant deviennent des attributs `data-`."""
    root = root.replace('onClick="{{ toggleTheme }}"', "data-dz-theme-toggle")
    root = root.replace('onMouseLeave="{{ hClear }}"', "data-dz-note-clear")
    root = re.sub(r'onMouseEnter="\{\{\s*h(\d+)\s*\}\}"', r'data-dz-note="\1"', root)
    return root


def inline_logos(root: str) -> str:
    """Un SVG externe ne suit pas le thème ; incorporé, il l'hérite.

    Le design a déjà changé de mécanique une fois — d'un `<img>` vers un couple
    « emplacement à peindre + glyphe de repli », les deux masqués en attendant
    un script. Les deux formes sont donc reconnues, et l'absence de l'une comme
    de l'autre est une erreur : la fois où elle est passée inaperçue, ce sont
    les glyphes de repli qui se sont retrouvés dans le rendu.
    """
    def replace(match: re.Match[str]) -> str:
        name = match.group("name")
        box = match.groupdict().get("box")
        path = HERE / "assets" / "logos" / f"{name}.svg"
        if not path.exists():
            die(f"logo absent : {path} — voir assets/logos/")
        svg = path.read_text().strip()
        # La taille vient du `data-box` du design. Sylius est une signature
        # typographique, ratio 3,1:1 : lui imposer la même valeur dans les deux
        # sens l'étirerait. Il se cale sur la hauteur, sa largeur suit.
        wide = 'width="auto"' in svg
        px = int(box) if box else None
        size = f"{px}px" if px else "100%"
        sized = f'height="{size}" width="auto"' if wide else f'width="{size}" height="{size}"'
        svg = re.sub(r'width="[^"]*" height="[^"]*"', sized, svg, count=1)
        if "width=" not in svg:
            die(f"dimensions perdues sur {name}.svg")
        return svg

    # Forme actuelle : un emplacement à peindre suivi de son glyphe de repli.
    root, painted = re.subn(
        r'<span[^>]*data-paint[^>]*data-box="(?P<box>\d+)"[^>]*'
        r'data-src="logo-(?P<name>[a-z0-9-]+)\.svg"[^>]*>\s*</span>\s*'
        r"<svg[^>]*data-glyph.*?</svg>",
        replace, root, flags=re.S)

    # Forme précédente, gardée le temps que plus aucun design ne la porte.
    root, imaged = re.subn(
        r'<img[^>]*data-logo[^>]*src="logo-(?P<name>[a-z0-9-]+)\.svg"[^>]*/?>', replace, root)

    if not (painted or imaged):
        die("aucun logo reconnu : la mécanique du design a encore changé")

    # La garde est délibérément plus large que les deux motifs ci-dessus, et c'est
    # tout son travail : elle doit attraper les noms qu'ils ratent. Elle était aussi
    # étroite qu'eux, donc `logo-api-platform.svg` n'était ni incorporé ni signalé —
    # le glyphe de repli passait en production en silence. Élargir les trois de la
    # même façon aurait refermé le tiret et laissé la classe ouverte : un chiffre,
    # un underscore ou une capitale repassait pareil.
    orphans = sorted(set(re.findall(r"logo-[^\"'\s>]+\.svg", root)))
    if orphans:
        die(f"logos non incorporés, le repli s'afficherait à leur place : {orphans}")

    return root


def rewrite_links(root: str) -> str:
    for dead, live in LINKS.items():
        root = root.replace(f"https://durable.rocks{dead}", live)

    # L'ordre compte : la forme avec barre finale d'abord, sinon `…/docs` est
    # remplacé par `/docs/` au milieu de `…/docs/packages/` et laisse un double
    # slash. Il passerait sur la plupart des serveurs, et casserait la première
    # règle de réécriture stricte rencontrée.
    root = root.replace("https://durable.rocks/docs/", "/docs/")
    root = root.replace("https://durable.rocks/docs", "/docs/")
    root = root.replace("https://durable.rocks/", "/")

    # Le pied de page du canevas porte un lien « Variants » vers son autre planche,
    # `index.dc.html`. Le canevas sait le suivre ; le site servi rend un 404 — il l'a
    # rendu jusqu'au 2026-08-27. C'est le sixième écart entre une page de canevas et
    # une page servie, et le seul qui ne se voyait qu'en cliquant.
    # Le retrait est délibérément étroit — ce lien-là, sous ce libellé-là.
    root = re.sub(r'<a\s+href="[^"]*\.dc\.html"[^>]*>\s*Variants\s*</a>', "", root)

    # La garde compte plus que le retrait, et elle doit rester plus large que lui :
    # une planche renommée ou un libellé traduit rapporterait un `.dc.html` que le
    # retrait ci-dessus ne verrait pas. Le laisser passer en silence est ce qui a mis
    # un 404 en pied de page ; s'arrêter dessus est ce qui l'empêche de revenir.
    canvas = sorted(set(re.findall(r'href="([^"]*\.dc\.html[^"]*)"', root)))
    if canvas:
        die(f"lien vers une planche de canevas : {canvas}")

    doubled = sorted(set(re.findall(r'href="(/[^"]*//[^"]*)"', root)))
    if doubled:
        die(f"double barre dans un lien : {doubled}")

    return root


def line_notes(source: str) -> list[list[str]]:
    """Les annotations ligne à ligne, lues du composant du canevas."""
    match = re.search(r"lineNotes\(\)\s*\{\s*return\s*\[(.*?)\];\s*\}", source, re.S)
    if not match:
        die("lineNotes() introuvable : les annotations de l'exemple seraient perdues")
    pairs = re.findall(r"\[\s*'((?:[^'\\]|\\.)*)'\s*,\s*'((?:[^'\\]|\\.)*)'\s*\]", match.group(1))
    if not pairs:
        die("lineNotes() présente mais vide")
    unescape = lambda s: s.replace("\\'", "'").replace("\\\\", "\\")
    return [[unescape(a), unescape(b)] for a, b in pairs]


def chooser_script(source: str) -> str:
    """Le sélecteur composer, déjà en JS nu : il se transplante tel quel."""
    for block in re.findall(r"<script[^>]*>(.*?)</script>", source, re.S):
        if "composer require" in block:
            return block
    die("script du sélecteur introuvable")


def palette_css() -> str:
    def block(values: dict[str, str]) -> str:
        return "".join(f"--{k}:{v};" for k, v in values.items())

    scalars = "".join(f"--{k}:{v};" for k, v in SCALARS.items())
    return (
        f":root{{{block(PALETTE['light'])}{scalars}}}\n"
        f':root[data-theme="dark"]{{{block(PALETTE["dark"])}}}\n'
        f'@media (prefers-color-scheme: dark){{:root:not([data-theme="light"])'
        f'{{{block(PALETTE["dark"])}}}}}\n'
    )


def build(src_path: pathlib.Path, out_path: pathlib.Path) -> None:
    source = src_path.read_text()

    root = extract_root(source)
    # Claude Design consigne ses hypothèses en commentaire HTML au fil des
    # tours. Elles partiraient en production, et celle qui énumère les fichiers
    # de logo faisait crier la garde des orphelins sur des noms qui n'étaient
    # que cités. Une page générée n'a pas de commentaire à défendre.
    root = re.sub(r"<!--.*?-->", "", root, flags=re.S)
    root = strip_palette_decls(root)
    root, hover_rules = convert_hovers(root)
    root = convert_handlers(root)
    root = inline_logos(root)
    root = rewrite_links(root)

    notes = line_notes(source)
    default_note = (
        "Every line of this method is either recorded in the journal or "
        "replayed from it. Point at one to see which."
    )
    root = root.replace("{{ themeLabel }}", "Dark")

    # Le script a besoin d'une prise sur les deux éléments d'annotation ; il
    # n'en reste aucune trace une fois l'interpolation remplacée.
    root, hooked_title = re.subn(
        r"(<div\b(?![^>]*data-dz-note-title))([^>]*>)\s*\{\{\s*noteTitle\s*\}\}",
        r"\1 data-dz-note-title\2Hover any line", root, count=1)
    root, hooked_text = re.subn(
        r"(<p\b(?![^>]*data-dz-note-text))([^>]*>)\s*\{\{\s*noteText\s*\}\}",
        lambda m: m.group(1) + " data-dz-note-text" + m.group(2) + default_note,
        root, count=1)
    if not (hooked_title and hooked_text):
        die("les éléments d'annotation n'ont pas la forme attendue : le survol serait muet")

    leftovers = re.findall(r"\{\{[^}]*\}\}", root)
    if leftovers:
        die(f"interpolations non résolues, Hugo les exécuterait : {sorted(set(leftovers))[:6]}")
    if "style-hover" in root:
        die("style-hover subsiste : des survols seraient muets")
    for banned in ("x-dc", "support.js", "data-om-id", "DCLogic"):
        if banned in root:
            die(f"reste du canevas dans la sortie : {banned}")

    runtime = (HERE / "assets" / "landing.js").read_text()
    runtime = runtime.replace("__NOTES__", json.dumps(notes, ensure_ascii=False))
    runtime = runtime.replace("__DEFAULT_NOTE__", json.dumps(
        ["Hover any line", default_note], ensure_ascii=False))

    out_path.parent.mkdir(parents=True, exist_ok=True)
    out_path.write_text(
        (HERE / "layout-head.html").read_text()
        + "<style>\n" + palette_css() + "\n".join(hover_rules) + "\n</style>\n"
        + root + "\n"
        + "<script>\n" + runtime + "\n</script>\n"
        + "<script>\n" + chooser_script(source) + "\n</script>\n"
        + "</body>\n</html>\n"
    )

    print(f"écrit  {out_path}  ({out_path.stat().st_size} octets)")
    print(f"       {len(hover_rules)} règles :hover, {len(notes)} annotations, "
          f"{len(LINKS)} liens réécrits")
    check_commands_agree(source)


COMMANDS_REFERENCE = pathlib.Path("../documentation/user/packages/_index.md")


def check_commands_agree(source: str) -> None:
    """Le sélecteur et la page Packages listent les mêmes commandes.

    Elles vivent aux deux endroits, et une page d'accueil qui installe autre
    chose que sa documentation est pire qu'une page d'accueil muette : le
    lecteur suit la première et se fait démentir par la seconde. La ligne
    Sylius a déjà changé une fois en une journée — un lien entre les deux
    pages signale la référence, il n'empêche pas la dérive.

    Averti, pas fatal : la page Packages a le droit de documenter une commande
    que le sélecteur ne propose pas — « aucun framework », par exemple.
    """
    reference = HERE / COMMANDS_REFERENCE
    if not reference.exists():
        print(f"       ⚠ référence introuvable : {reference}")
        return

    def commands(text: str) -> set[str]:
        return {
            " ".join(m.split())
            for m in re.findall(r"composer require [a-z0-9/ -]+", text)
        }

    landing = commands(source)
    documented = commands(reference.read_text())
    missing = sorted(landing - documented)

    if missing:
        print("       ⚠ commandes du sélecteur absentes de la page Packages :")
        for command in missing:
            print(f"           {command}")
    else:
        print(f"       {len(landing)} commandes, toutes documentées dans Packages")


if __name__ == "__main__":
    if len(sys.argv) != 3:
        sys.exit(__doc__)
    build(pathlib.Path(sys.argv[1]), pathlib.Path(sys.argv[2]))
