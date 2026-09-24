#!/usr/bin/env python3
"""Converts a page designed in claude.ai/design into a Hugo template.

    ./import-design.py <path>/variant-b-narrative.dc.html layouts/index.html

Why a script rather than a copy-paste: the page is picked up again on every
turn of the design loop, and an extraction done by hand would have to be redone
in full every time. Here we replay the command.

Five things separate a canvas page from a page served by Hugo:

1. `{{ … }}`: the canvas and Hugo share the syntax. Left in place,
   Hugo would try to execute them and the build would fail. Not a single
   one must remain in the output; this is checked.
2. The palette is interpolated into an inline `style` attribute, so it
   cannot be overridden by a stylesheet, because an inline style
   wins. We take it out of the markup and write it under `:root`, which makes
   the dark theme expressible.
3. `style-hover` is a canvas attribute, ignored by browsers. Without
   conversion to `.class:hover`, no hover on the page works.
4. `onClick` / `onMouseEnter` call methods of a component that
   does not exist outside the canvas. We replace them with `data-` attributes
   that a standalone script picks up.
5. The logos are loaded as `<img>`. An external SVG is an isolated document:
   it does not inherit `currentColor` and so cannot follow the theme. We
   inline them.
"""

from __future__ import annotations

import difflib
import hashlib
import json
import pathlib
import re
import sys

HERE = pathlib.Path(__file__).parent

# Resolved from the canvas component. The accent is recomputed by its
# own algorithm: it starts from the chosen hue and lowers the lightness
# until it passes a contrast of 4.6 against the background of the target theme.
#
# The chosen hue is emerald `#1f6f5c`, one of the four accents the canvas
# offers (`#b4552f` terracotta, `#1f6f5c` emerald, `#2d5bb9` blue,
# `#8a3f7a` plum). The canvas derives `#207460` for light and `#68d5bb` for
# dark; those two values, not the seed, are what is written here.
# Switching variant means replaying the canvas's `themeAccent(seed, dark)` on
# the new seed, and remembering the `--dz-accent` in `assets/_custom.scss`, which
# carries the same pair for the documentation pages.
#
# `accent2` is the second accent: the "Nexus · works today" and
# "bundle today · plugin planned" pills, the border of the "With it" box. The canvas
# freezes it (it does not follow the accent variant) and it did not go through this
# table: the 24 `var(--accent2, #2f6f6b)` in the markup all fell back to
# their fallback value, dark theme included, where that green-blue only reached
# a contrast of 2.98 on `bg2`. It goes in here, so it now has its two
# values. The hue comes from the canvas blue (`#2d5bb9`): emerald sits at
# 166°, the old green-blue at 176°, the two were indistinguishable.
PALETTE = {
    "light": {
        "bg": "#f7f4ef", "bg2": "#efe9df", "fg": "#1d1a16", "fg2": "#6c6459",
        "line": "#ddd6ca", "accent": "#207460", "accent-fg": "#fdfaf6",
        "accent2": "#2e5dbd",
        "code-bg": "#ffffff", "code-fg": "#1d1a16",
        "ck": "#a1341f", "cs": "#6b7d1f", "cc": "#9a9184", "cv": "#1c6b8a",
    },
    "dark": {
        "bg": "#141310", "bg2": "#1c1a17", "fg": "#eae5dc", "fg2": "#a09a90",
        "line": "#2c2925", "accent": "#68d5bb", "accent-fg": "#170f0a",
        "accent2": "#638ad9",
        "code-bg": "#100f0d", "code-fg": "#eae5dc",
        "ck": "#e58a6a", "cs": "#a8bf62", "cc": "#6b665e", "cv": "#79b6cf",
    },
}

# `--ts` (type scale) and `--sp` (density) were canvas knobs.
# They are frozen at 1: nobody turns them any more.
SCALARS = {"ts": "1", "sp": "1"}

# For a long time the design set 26 or 30 px on its logos, unreadable at that
# size, and a 48 px floor was enforced here. The canvas now sets
# 48 itself, and 22 on the two tiny marks that serve as a
# "Symfony app" chip next to an application name. A floor would inflate them to
# more than double: the size is once again entirely the canvas's choice.

# The use-case links pointed to pages that never existed
# (`/docs/use-cases/<slug>`). Each one goes to the section that actually covers
# the topic, anchor included; the anchors are checked at run time.
# Four strings the script writes itself: they do not come from the
# canvas, so nothing translated them. They landed in English in the
# middle of the French page: the theme button said "Dark" and the
# annotation panel "Hover any line".
#
# The language is inferred from the source file name (`…-fr.dc.html`), and the output
# must carry it too: otherwise nothing would prevent writing the French
# page into `layouts/index.html`, that is, at the root of the site.
STRINGS = {
    "en": {
        "theme_dark": "Dark",
        "theme_light": "Light",
        "note_title": "Hover any line",
        "note_text": "Every line of this method is either recorded in the "
                     "journal or replayed from it. Point at one to see which.",
    },
    "fr": {
        "theme_dark": "Sombre",
        "theme_light": "Clair",
        "note_title": "Survolez une ligne",
        "note_text": "Chaque ligne de cette méthode est soit inscrite au "
                     "journal, soit rejouée depuis lui. Pointez-en une pour "
                     "voir laquelle.",
    },
}


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
    """The `<div>` that carries the palette, up to its closing tag."""
    start = source.find('<div style="--bg:')
    if start < 0:
        die("root not found: no <div style=\"--bg:…\"> in the source")
    end = source.rfind("</div>")
    return source[start:end + len("</div>")]


def strip_palette_decls(root: str) -> str:
    """Takes the `--x: {{ y }}` out of the inline style: they block any theme."""
    open_tag = re.match(r"<div style=\"([^\"]*)\">", root)
    if not open_tag:
        die("the root opening tag does not have the expected shape")
    kept = [
        decl.strip() for decl in open_tag.group(1).split(";")
        if decl.strip() and not decl.strip().startswith("--")
    ]
    return f'<div class="dz-root" style="{"; ".join(kept)};">' + root[open_tag.end():]


def convert_hovers(root: str) -> tuple[str, list[str]]:
    """`style-hover="…"` → a class and a real `:hover` rule."""
    rules: list[str] = []

    def replace(match: re.Match[str]) -> str:
        index = len(rules)
        rules.append(f".dz-h{index}:hover{{{match.group(1)}}}")
        return f'data-dzh="{index}"'

    root = re.sub(r'style-hover="([^"]*)"', replace, root)

    # The class must exist on the element, not just the attribute.
    def attach(match: re.Match[str]) -> str:
        return f'class="dz-h{match.group(1)}" ' + match.group(0)

    root = re.sub(r'data-dzh="(\d+)"', attach, root)
    return root, rules


def convert_handlers(root: str) -> str:
    """The component's handlers become `data-` attributes."""
    root = root.replace('onClick="{{ toggleTheme }}"', "data-dz-theme-toggle")
    root = root.replace('onMouseLeave="{{ hClear }}"', "data-dz-note-clear")
    root = re.sub(r'onMouseEnter="\{\{\s*h(\d+)\s*\}\}"', r'data-dz-note="\1"', root)
    return root


def inline_logos(root: str) -> str:
    """An external SVG does not follow the theme; inlined, it inherits it.

    The design has already changed mechanism once, from an `<img>` to a
    "slot to paint + fallback glyph" pair, both hidden while waiting for
    a script. Both forms are therefore recognised, and the absence of either
    one is an error: the time it went unnoticed, it was
    the fallback glyphs that ended up in the rendering.
    """
    def replace(match: re.Match[str]) -> str:
        name = match.group("name")
        box = match.groupdict().get("box")
        path = HERE / "assets" / "logos" / f"{name}.svg"
        if not path.exists():
            die(f"logo missing: {path}, see assets/logos/")
        svg = path.read_text().strip()
        # The size comes from the design's `data-box`. Sylius is a typographic
        # signature, ratio 3.1:1: forcing the same value on it in both
        # directions would stretch it. It fits to the height, its width follows.
        wide = 'width="auto"' in svg
        px = int(box) if box else None
        size = f"{px}px" if px else "100%"
        sized = f'height="{size}" width="auto"' if wide else f'width="{size}" height="{size}"'
        svg = re.sub(r'width="[^"]*" height="[^"]*"', sized, svg, count=1)
        if "width=" not in svg:
            die(f"dimensions lost on {name}.svg")
        return svg

    # Current form: a slot to paint followed by its fallback glyph.
    root, painted = re.subn(
        r'<span[^>]*data-paint[^>]*data-box="(?P<box>\d+)"[^>]*'
        r'data-src="logo-(?P<name>[a-z0-9-]+)\.svg"[^>]*>\s*</span>\s*'
        r"<svg[^>]*data-glyph.*?</svg>",
        replace, root, flags=re.S)

    # Previous form, kept until no design carries it any more.
    root, imaged = re.subn(
        r'<img[^>]*data-logo[^>]*src="logo-(?P<name>[a-z0-9-]+)\.svg"[^>]*/?>', replace, root)

    if not (painted or imaged):
        die("no logo recognised: the design mechanism has changed again")

    # The guard is deliberately wider than the two patterns above, and that is
    # its whole job: it must catch the names they miss. It used to be as
    # narrow as they are, so `logo-api-platform.svg` was neither inlined nor reported:
    # the fallback glyph went to production silently. Widening all three the
    # same way would have closed the hyphen gap and left the class open: a digit,
    # an underscore or a capital letter would slip through just the same.
    orphans = sorted(set(re.findall(r"logo-[^\"'\s>]+\.svg", root)))
    if orphans:
        die(f"logos not inlined, the fallback would show in their place: {orphans}")

    return root


def rewrite_links(root: str, lang: str) -> str:
    for dead, live in LINKS.items():
        root = root.replace(f"https://durable.rocks{dead}", live)

    # Order matters: the form with the trailing slash first, otherwise `…/docs` is
    # replaced by `/docs/` in the middle of `…/docs/packages/` and leaves a double
    # slash. It would get through on most servers, and break the first
    # règle de réécriture stricte rencontrée.
    root = root.replace("https://durable.rocks/docs/", "/docs/")
    root = root.replace("https://durable.rocks/docs", "/docs/")
    root = root.replace("https://durable.rocks/", "/")

    # Le guide existe en français depuis la PR #147, aux mêmes ancres. Une page
    # française qui renvoie vers `/docs/` envoie son lecteur sur l'anglais alors
    # que la traduction est là, et les ancres qu'elle cite
    # (`#bounding-a-wait-in-time`…) sont précisément celles qui ont été épinglées
    # pour survivre à la traduction.
    if lang != "en":
        root = re.sub(r'href="/(docs/)', rf'href="/{lang}/\1', root)

    # Le pied de page du canevas porte un lien « Variants » vers son autre planche,
    # `index.dc.html`. Le canevas sait le suivre ; le site servi rend un 404, et il l'a
    # rendu jusqu'au 2026-08-27. C'est le sixième écart entre une page de canevas et
    # une page servie, et le seul qui ne se voyait qu'en cliquant.
    # Le retrait vise le lien, pas son libellé : la page française dit
    # « Variantes », et s'accrocher au mot anglais laissait passer la version
    # traduite : c'est la garde ci-dessous qui l'a rattrapée.
    root = re.sub(r'<a\b[^>]*href="[^"]*\.dc\.html"[^>]*>.*?</a>', "", root, flags=re.S)

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


def paint_initial_command(root: str, script: str) -> str:
    """Le balisage statique doit porter la commande de l'état initial.

    Le canevas y laisse une valeur figée, `composer require gplanchat/durable-bundle`,
    alors que l'état de départ est `Symfony · Temporal`. Avant que `paint()` ne
    tourne, la page affichait donc la commande d'un autre choix que celui qu'elle
    montrait comme sélectionné, et un lecteur qui copie vite emporte la mauvaise.

    Elle est recalculée depuis les mêmes données que `paint()` (`state`, `BASE`,
    `DIST_BASE`, `BRIDGE`, `TWO_ECO`) plutôt que corrigée à l'œil, pour qu'un
    changement de défaut dans le canevas la suive tout seul.
    """
    def table(name: str) -> dict[str, str]:
        match = re.search(rf"var {name} = (\{{.*?\}});", script, re.S)
        if not match:
            die(f"{name} introuvable dans le script du sélecteur")
        return json.loads(re.sub(r"(\w+):", r'"\1":', match.group(1)).replace("'", '"'))

    state = re.search(r"state = \{([^}]*)\}", script)
    if not state:
        die("l'état initial du sélecteur est introuvable")
    initial = dict(re.findall(r"(\w+):\s*'([^']*)'", state.group(1)))

    two_eco = re.search(r"var TWO_ECO = \[([^\]]*)\]", script)
    wide = re.findall(r"'([^']+)'", two_eco.group(1)) if two_eco else []

    base_table = table("DIST_BASE") if initial.get("fw") in wide else table("BASE")
    key = initial.get("dist") if initial.get("fw") in wide else initial.get("fw")
    parts = [base_table.get(key, "")] + [table("BRIDGE").get(initial.get("be", ""), "")]
    command = "composer require " + " ".join(p for p in parts if p)

    root, painted = re.subn(
        r'(<code[^>]*\bdata-cmd\b[^>]*>)[^<]*', lambda m: m.group(1) + command, root, count=1)
    if not painted:
        die("l'emplacement de la commande statique est introuvable")
    return root

def language_of(src_path: pathlib.Path, out_path: pathlib.Path) -> str:
    """La langue vient du nom de la source, et la sortie doit la porter."""
    stem = src_path.name.split(".")[0]
    lang = stem.rsplit("-", 1)[-1] if stem.rsplit("-", 1)[-1] in STRINGS else "en"
    # Hugo choisit le gabarit par l'infixe : `index.fr.html` sert /fr/,
    # `index.html` sert la racine. Une source française écrite dans le second
    # publierait la page française à la racine du site, sans que rien ne le
    # signale avant la mise en ligne.
    parts = out_path.name.split(".")
    out_lang = parts[1] if len(parts) > 2 else "en"
    if out_lang != lang:
        wanted = "index.html" if lang == "en" else f"index.{lang}.html"
        die(f"source en « {lang} » écrite dans {out_path.name}, qui sert « {out_lang} » "
            f"(attendu {wanted})")
    return lang


def build(src_path: pathlib.Path, out_path: pathlib.Path, force: bool = False) -> None:
    source = src_path.read_text()
    words = STRINGS[language_of(src_path, out_path)]

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
    root = rewrite_links(root, language_of(src_path, out_path))

    notes = line_notes(source)
    default_note = words["note_text"]
    root = root.replace("{{ themeLabel }}", words["theme_dark"])

    # Le script a besoin d'une prise sur les deux éléments d'annotation ; il
    # n'en reste aucune trace une fois l'interpolation remplacée.
    root, hooked_title = re.subn(
        r"(<div\b(?![^>]*data-dz-note-title))([^>]*>)\s*\{\{\s*noteTitle\s*\}\}",
        lambda m: m.group(1) + " data-dz-note-title" + m.group(2) + words["note_title"],
        root, count=1)
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

    root = paint_initial_command(root, chooser_script(source))

    runtime = (HERE / "assets" / "landing.js").read_text()
    runtime = runtime.replace("__NOTES__", json.dumps(notes, ensure_ascii=False))
    runtime = runtime.replace("__DEFAULT_NOTE__", json.dumps(
        [words["note_title"], default_note], ensure_ascii=False))
    # Le bouton de thème est réétiqueté au chargement : le libellé statique ne
    # se voit jamais, c'est celui-ci qui s'affiche.
    runtime = runtime.replace("__THEME_LABELS__", json.dumps(
        {"dark": words["theme_dark"], "light": words["theme_light"]}, ensure_ascii=False))
    # Les gabarits sont en capitales ; `__construct(…)`, qui vit dans les
    # annotations PHP de la page, ne doit pas les faire passer pour oubliés.
    left = re.findall(r"__[A-Z][A-Z_]*__", runtime)
    if left:
        die(f"gabarit du script de page non remplacé : {sorted(set(left))}")

    page = (
        (HERE / "layout-head.html").read_text()
        + "<style>\n" + palette_css() + "\n".join(hover_rules) + "\n</style>\n"
        + root + "\n"
        + "<script>\n" + runtime + "\n</script>\n"
        + "<script>\n" + chooser_script(source) + "\n</script>\n"
        + "</body>\n</html>\n"
    )

    guard_hand_edits(out_path, page, force)
    out_path.parent.mkdir(parents=True, exist_ok=True)
    out_path.write_text(page)
    remember_import(out_path)

    print(f"écrit  {out_path}  ({out_path.stat().st_size} octets)")
    print(f"       {len(hover_rules)} règles :hover, {len(notes)} annotations, "
          f"{len(LINKS)} liens réécrits")
    check_packages_resolve(root, chooser_script(source))
    check_commands_agree(source)


COMMANDS_REFERENCE = pathlib.Path("../documentation/user/packages/_index.md")



# Le manifeste des sorties : pour chaque gabarit engendré, l'empreinte de ce que
# le dernier import y a écrit.
IMPORTED = HERE / "imported.json"


def imported_hashes() -> dict[str, str]:
    if not IMPORTED.exists():
        return {}
    return json.loads(IMPORTED.read_text())


def remember_import(out_path: pathlib.Path) -> None:
    known = imported_hashes()
    known[out_path.name] = hashlib.sha256(out_path.read_bytes()).hexdigest()
    IMPORTED.write_text(json.dumps(dict(sorted(known.items())), indent=2) + "\n")


def guard_hand_edits(out_path: pathlib.Path, new_text: str, force: bool) -> None:
    """Refuse d'écraser une sortie modifiée à la main depuis le dernier import.

    C'est la panne que WA005 raconte : trois correctifs écrits dans
    `layouts/index.html` et perdus, deux à une régénération qui ne les
    connaissait pas, un à un rebasage. Aucun n'a fait de bruit, et c'est tout le
    problème. Une régénération qui ne sait pas ce qu'elle détruit le détruit en
    silence.

    La garde ne demande pas que le canevas soit dans le dépôt ; elle demande
    seulement de savoir ce que le dernier import avait écrit. Si le fichier ne
    porte plus cette empreinte, quelqu'un l'a corrigé à la main, et cette
    correction n'est pas dans le canevas, sinon elle serait dans la sortie.

    ponytail: une empreinte plutôt qu'une copie de l'ancienne sortie. Le diff
    montré compare l'état courant à ce qui va être écrit, ce qui est exactement
    ce que la régénération changerait, les corrections perdues comprises.
    """
    if not out_path.exists():
        return

    known = imported_hashes().get(out_path.name)
    current = hashlib.sha256(out_path.read_bytes()).hexdigest()
    if known == current:
        return

    if known is None:
        if force:
            return
        die(
            f"aucune empreinte connue pour {out_path.name} : ce fichier existe, et rien ne dit\n"
            "ce que le dernier import y avait écrit. Il peut donc porter des corrections faites à\n"
            "la main, absentes du canevas : c'est l'état dans lequel WA005 a trouvé le dépôt.\n"
            "Relisez-le, reportez ce qui manque dans le canevas, puis `--force` une fois : "
            "l'empreinte\nsera enregistrée et les imports suivants sauront quoi comparer."
        )

    diff = list(difflib.unified_diff(
        out_path.read_text().splitlines(keepends=True),
        new_text.splitlines(keepends=True),
        fromfile=f"{out_path.name} (dépôt, modifié à la main)",
        tofile=f"{out_path.name} (ce que l'import écrirait)",
        n=1,
    ))

    if force:
        print(f"⚠ {out_path.name} a été modifié à la main depuis le dernier import : "
              f"{sum(1 for l in diff if l.startswith(('+', '-')) and not l.startswith(('+++', '---')))} "
              f"lignes écrasées, --force donné.")
        return

    sys.stderr.write("".join(diff[:400]))
    die(
        f"{out_path.name} a été modifié à la main depuis le dernier import.\n"
        "Ces lignes ne sont pas dans le canevas : les écraser les perdrait, comme les\n"
        "trois correctifs de WA005. Reportez-les dans le canevas, puis relancez, ou\n"
        "`--force` si vous savez qu'elles sont déjà dedans."
    )


def self_test() -> None:
    """Un aller-retour complet de la garde, sans canevas ni réseau."""
    import tempfile

    global IMPORTED
    original = IMPORTED
    with tempfile.TemporaryDirectory() as tmp:
        tmpdir = pathlib.Path(tmp)
        IMPORTED = tmpdir / "imported.json"
        out = tmpdir / "index.html"

        # 1. Une sortie qui n'existe pas encore ne déclenche rien : rien à perdre.
        guard_hand_edits(out, "un\ndeux\n", force=False)

        # 1bis. Une sortie qui existe sans empreinte connue est refusée : c'est
        #       exactement l'état du dépôt le jour où cette garde est écrite.
        out.write_text("un\ndeux\n")
        try:
            guard_hand_edits(out, "un\ndeux\n", force=False)
        except SystemExit:
            pass
        else:
            raise AssertionError("la garde a jugé un fichier dont elle ignore l'origine")
        guard_hand_edits(out, "un\ndeux\n", force=True)

        # 2. Après un import, la sortie porte son empreinte : réimporter la même
        #    chose ne dérange personne.
        out.write_text("un\ndeux\n")
        remember_import(out)
        guard_hand_edits(out, "un\ndeux\ntrois\n", force=False)

        # 3. Une correction à la main, et l'import suivant refuse.
        out.write_text("un\ndeux corrigé à la main\n")
        try:
            guard_hand_edits(out, "un\ndeux\n", force=False)
        except SystemExit:
            pass
        else:
            raise AssertionError("la garde a laissé passer une correction à la main")

        # 4. `--force` passe outre, délibérément.
        guard_hand_edits(out, "un\ndeux\n", force=True)

        # 5. Et la garde ne juge jamais un fichier qui n'existe pas encore : la
        #    refuser serait bloquer le premier import d'une nouvelle langue.
        jamais_ecrit = tmpdir / "index.de.html"
        guard_hand_edits(jamais_ecrit, "neu\n", force=False)

    IMPORTED = original
    print("garde de l'import : 6 cas, tous verts")


def declared_packages() -> set[str]:
    """Les paquets que ce dépôt publie, lus dans leurs `composer.json`.

    Source de vérité plutôt que liste blanche : un paquet existe si le monorepo
    le déclare, et le jour où `gplanchat/durable-bridge-illuminate` sera écrit,
    la garde ci-dessous s'ouvrira toute seule.
    """
    names = set()
    for manifest in sorted((HERE.parent / "src").glob("*/composer.json")) + sorted(
            (HERE.parent / "src" / "Bridge").glob("*/composer.json")):
        name = json.loads(manifest.read_text()).get("name")
        if name:
            names.add(name)
    if not names:
        die("aucun composer.json trouvé sous src/ : la garde des paquets serait aveugle")
    return names


def check_packages_resolve(root: str, script: str) -> None:
    """Aucun chemin du sélecteur ne doit nommer un paquet que personne ne publie.

    C'est la même faute que le logo non incorporé, un étage plus haut : la page
    donne une instruction, un lecteur la copie, et elle échoue. Elle est arrivée
    trois fois : `gplanchat/durable-laravel`, `gplanchat/durable-bridge-illuminate`,
    et quatre noms portant un `?` de brouillon, dont une atteignable en deux clics.

    La garde énumère ce que le sélecteur laisse réellement atteindre : une puce
    `planned` est refusée par le gestionnaire de clic, donc elle ne compte pas. Ce
    qui reste doit résoudre.

    Fatal pour un chemin atteignable, **averti** pour un nom qui dort derrière une
    puce fermée : celui-là n'est pas encore un mensonge, mais il le deviendra le
    jour où la puce s'ouvre, et c'est ce jour-là qu'il faut être prévenu.
    """
    def table(name: str) -> dict:
        match = re.search(rf"var {name} = (\{{.*?\}});", script, re.S)
        if not match:
            die(f"{name} introuvable : la garde des paquets ne peut pas énumérer")
        return json.loads(re.sub(r"(\w+):", r'"\1":', match.group(1)).replace("'", '"'))

    def chips(axis: str) -> dict[str, str]:
        found = {}
        for tag in re.findall(rf'<button[^>]*data-axis="{axis}"[^>]*>', root):
            value = re.search(r'data-val="([^"]+)"', tag)
            state = re.search(r'data-state="([^"]+)"', tag)
            if value:
                found[value.group(1)] = state.group(1) if state else "ok"
        return found

    base, dist_base, bridge = table("BASE"), table("DIST_BASE"), table("BRIDGE")
    allowed, dist_allowed = table("ALLOWED"), table("DIST_ALLOWED")
    eco = table("ECO_OPTIONS")
    frameworks, distributions = chips("fw"), chips("dist")
    if not frameworks:
        die("aucune puce de framework : la garde des paquets ne peut pas énumérer")

    published = declared_packages()
    reachable: set[str] = set()
    sleeping: set[str] = set()

    def collect(base_name: str, backend: str, awake: bool) -> None:
        parts = f"{base_name} {bridge.get(backend, '')}".split()
        (reachable if awake else sleeping).update(parts)

    for framework, state in frameworks.items():
        awake = state != "planned"
        if framework in eco:
            for distribution in eco[framework]:
                open_here = awake and distributions.get(distribution, "ok") != "planned"
                for backend in dist_allowed.get(distribution, []):
                    collect(dist_base.get(distribution, ""), backend, open_here)
        else:
            for backend in allowed.get(framework, []):
                collect(base.get(framework, ""), backend, awake)

    missing = sorted(p for p in reachable if p not in published)
    if missing:
        die("le sélecteur propose d'installer des paquets que ce dépôt ne publie pas : "
            + ", ".join(missing))

    dormant = sorted(p for p in sleeping - reachable if p not in published)
    if dormant:
        print("       ⚠ noms non publiés derrière une puce fermée, fatals le jour où elle s'ouvre :")
        for name in dormant:
            print(f"           {name}")
    print(f"       {len(reachable)} paquets atteignables, tous publiés")


def check_commands_agree(source: str) -> None:
    """Le sélecteur et la page Packages listent les mêmes commandes.

    Elles vivent aux deux endroits, et une page d'accueil qui installe autre
    chose que sa documentation est pire qu'une page d'accueil muette : le
    lecteur suit la première et se fait démentir par la seconde. La ligne
    Sylius a déjà changé une fois en une journée ; un lien entre les deux
    pages signale la référence, il n'empêche pas la dérive.

    Averti, pas fatal : la page Packages a le droit de documenter une commande
    que le sélecteur ne propose pas : « aucun framework », par exemple.
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
    args = sys.argv[1:]
    if args == ["--self-test"]:
        self_test()
        sys.exit(0)
    force = "--force" in args
    args = [a for a in args if a != "--force"]
    if len(args) != 2:
        sys.exit(__doc__)
    build(pathlib.Path(args[0]), pathlib.Path(args[1]), force)
