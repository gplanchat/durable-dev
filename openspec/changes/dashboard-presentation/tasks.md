## 1. The projection moves to the component

- [x] 1.1 A timeline projection beside the observation model: actions grouped by `actionKey`, one
      segment per interval between consecutive events, a segment marked as a wait when the event
      that closes it starts the work, a mark per event. It is Magento's
      `ProcessDetail::getTimeline()` — the richer of the two — moved
- [x] 1.2 **Not moved as-is: the percentages.** `scale()` returns 0–100 floats, which is a drawing,
      and the component would then be emitting CSS widths to a surface that renders no CSS. The
      projection carries an offset from the run's first event and a length, both in seconds; each
      host scales them, and the rule that a four-millisecond wait does not draw wider than six
      milliseconds of work binds whoever scales
- [x] 1.3 Its unit tests move with it, plus the three cases neither surface covers today: a single
      event action, a run whose events all fall in the same microsecond, and a run still going
- [x] 1.4 A run-list projection beside it: description, outcome counters over the page, paging state
      and the backend state — `RunDashboardView` minus everything Sylius-shaped, which is nothing
- [x] 1.5 The backend state becomes three cases rather than a boolean; the ephemeral case carries
      what to configure, without the host having to word it

## 2. Sylius renders the projection instead of deriving its own

- [x] 2.1 `RunDashboardView` builds on the promoted projection; its private `actions()` goes —
      done with 1.4: the class was **moved**, not copied, and leaving two copies in the tree for
      the length of a slice would have cost more than rewiring three lines
- [x] 2.2 The detail panel positions actions in time and hatches a wait, which it does not do today
- [x] 2.3 `RecordedDetails` in the core: the Sylius template called `json_encode` **without**
      tolerance and rendered an empty disclosure as soon as one byte was not UTF-8. Measured before
      writing, and the scenario corrected along with it: partial output **never** returns `false` —
      not on an invalid byte, not on a resource, not on six hundred levels of nesting. The right
      degradation is therefore not the single line but the whole payload with only the offending
      value as `null`, which is better than what the spec asked for. The `false` guard stays,
      defensively
- [x] 2.4 A **real** render of the template, not a reading of its text: the other assertions in the
      folder read the file, and none exercised `action.events` → `mark.event.label`. A misnamed
      property in that chain renders an empty page on the very screen one came to look at.
      Verified by mutation. Since 2.2/2.3 it covers a hatched wait, placement in time and a
      payload with an invalid byte

## 3. Magento renders the projection instead of deriving its own

- [x] 3.1 `ProcessDetail` consumes the promoted projection. What must **disappear**, not merely
      coexist: `getTimeline()`, `segments()`, `scale()`, the composition of the segment and mark
      tooltips (a duplicate of `TimelineSegment::$title` / `TimelineEvent::$title`) and
      `formatDetails()` (a duplicate of `RecordedDetails::of()`). Leaving them side by side would
      bring back exactly the divergence slice 1 went after
- [x] 3.2 The listing reports backend health, which it never probes today
- [x] 3.3 The counters cover **the window the screen reads**, and say so. Not "the page": Magento's
      grid pages by offset *inside* that window, so the set the operator browses is the window,
      not the current page. The author's decision — a scope owned and named — holds for both; it
      is the scope that differs, because the paging differs. `RunDashboard::outcomeCounters()`
      becomes public: counting by hand in the host would dig the forgotten-bucket hole again
- [x] 3.4 The ceiling is announced as soon as the window is full, and the window is **a single
      constant** — `RuntimeFactory::OBSERVATION_WINDOW`. They were two literals with the same
      value, which made it possible to be listed on one side and unfindable on the other the
      first time someone changed one

## 4. Counters and absences say what they mean

- [x] 4.1 The label names the scope on both surfaces: "Outcomes across the N runs on this
      page" on Sylius, "across the N most recent runs this screen reads" on Magento — the
      scope differs because the paging differs, and each says so
- [x] 4.2 The Magento grid rendered `''` for an absent date — an empty cell reads as a render
      that failed. A named em dash, the same one as on the detail screen. The Sylius list has no
      fixed columns: they are cards, and an absent fact is omitted there, which remains the
      right rendering — the em dash rule applies to tables

## 5. Sweep the drift lanes left behind

- [x] 5.1 `WorkflowRunEventKind` no longer describes a lane but a **kind**: the row comes from
      the action, and the enumeration now serves only for colour. Also swept in the two history
      readers and in the CSS classes of the Sylius template (`durable-lane` → `durable-action`)
- [x] 5.2 The plugin README describes the timeline by action, and its "Lane kind" table becomes
      "Event kind"
- [x] 5.3 Both READMEs carry the **same section** "The panels, and why they are the same
      everywhere" — four panels, the three backend states, the counter scope, the timeline
      by action. The site's package pages follow, in both languages; the site mounts
      `documentation/user` directly, so there is no copy to maintain

## 6. Leave the decision behind

- [x] 6.1 `DUR049 — One projection, two chromes`, indexed in `documentation/INDEX.md`. It carries
      the four defects **measured** rather than assumed (health never probed, the empty disclosure
      on an invalid byte, two times for the same event on one page, the timeline without any test)
      and the four rejected alternatives — among them "promote the Sylius model", which would
      have levelled down
- [x] 6.2 A `documentation/user/dashboard/` page, in both languages: the four panels, the three
      backend states, the timeline by action, the counter scope, the two absences. The
      `durable-plugin` and `durable-magento` sections of the packages page no longer each describe
      their timeline — they describe their **chrome** and point to it

## Notes de la tranche 1

`ProcessDetail::getTimeline()` n'avait **aucun test** — `tests/unit/DurableModule/` ne contient que
`DeclaredRuntimeTest` et `RuntimeFactoryTest`. Les onze cas de
`TheRunTimelinePositionsActionsInTimeTest` sont donc la première couverture de cette logique, pas un
déménagement de tests existants ; trois d'entre eux (action d'un seul événement, run tenant dans une
microseconde, run encore en cours) sont ceux que la §1.3 réclamait et qu'aucune surface ne couvrait.

Le troisième état de backend n'a coûté qu'un paramètre à défaut : `BackendHealth::$ephemeral`, à
`false`. Les trois catalogues qui écrivent hors du processus — SQL, Illuminate, Temporal — n'ont rien
à déclarer, seul `InMemoryWorkflowRunCatalog` passe `true`.

## Notes de la tranche 2

Deux choses sont montées dans la projection plutôt que d'être écrites deux fois : les **infobulles**
(`TimelineSegment::$title`, `TimelineEvent::$title`) et la **mise en forme de la charge utile**
(`TimelineEvent::$renderedDetails`). Magento les composait en PHP, Sylius ne les avait pas ; les
laisser à l'hôte aurait fait diverger les mots que deux surfaces disent de la même seconde. Le fait
brut reste sur `$event->details` pour une surface qui sert des données plutôt qu'une page.

La règle « une attente de quatre millisecondes ne dessine pas plus large que six millisecondes de
travail » est tenue par un `min-width` **uniforme** : sous le seuil les deux barres sont égales,
jamais inversées. Elle vit chez l'hôte, avec les pourcentages, comme la §1.2 l'a décidé.

⚠ **Un fuseau attrapé au passage.** L'infobulle de la frise est composée dans le cœur, avec le
fuseau que porte l'événement ; le filtre `date` de Twig applique celui du **serveur**. Sur une
machine à Paris, le même événement se lisait 22:13:20 au survol et 23:13:20 dans la ligne juste
dessous — dans une page dont toute la raison d'être est qu'un exploitant n'ait rien à convertir de
tête. `date(..., false)` garde le fuseau de la date. Le test tourne sous `Europe/Paris` : sous UTC,
la divergence est invisible, et c'est sous UTC que tourne la CI.

## Notes de la tranche 3

Ce qui a **disparu** de `ProcessDetail`, et c'était le but : `getTimeline()`, `segments()`,
`getEvents()`, `actionLabel()`, `formatDetails()`, et les deux compositions d'infobulle. Reste
`scale()` — des secondes vers un pourcentage de piste — qui appartient bien à l'hôte : mettre à
l'échelle demande de connaître une largeur de colonne. `RuntimeFactory::hasCluster()` part aussi :
l'éphémérité vient du port, c'est le catalogue in-memory qui sait qu'il l'est, pas l'hôte qui le
devine à l'absence d'un DSN.

⚠ **Aucun outil de la CI n'analyse un `.phtml`.** PHPStan et Psalm tournent contre les vraies classes
de Magento dans le job qui installe la distribution, mais les gabarits leur échappent — et ces deux-là
venaient d'être réécrits sur une API d'objets là où ils lisaient des tableaux. Deux tests de rendu
les couvrent désormais, avec un double de bloc et un `__()` global ; ils ne demandent ni Magento ni
base, donc ils tournent dans la suite ordinaire. Vérifié par mutation.

Deux appels à `listRuns()` par affichage de la grille — la bannière compte, le fournisseur liste.
Assumé et commenté : l'alternative serait un couplage entre la bannière et le fournisseur de la
grille, ou un cache de requête autour du catalogue. Le second est la sortie si ça pèse.

## Notes des tranches 4 et 5

La règle du tiret cadratin ne s'applique qu'aux **tableaux**. La liste Sylius est faite de cartes :
un fait absent y est omis, et c'est le bon rendu — il n'y a pas de colonne à laisser vide. C'est la
grille Magento qui rendait `''`, et une case vide s'y lit comme un rendu qui a échoué.

Une section identique dans les deux README plutôt qu'un renvoi de l'un vers l'autre : ils sont
publiés dans deux paquets satellites distincts, et un lecteur de `durable-magento` sur Packagist n'a
pas celui de `durable-plugin` sous la main.

Ce qui restait de « voies » ailleurs était du français ordinaire — « se voient », « deux voies »
— et n'a pas été touché. `DUR037` en garde aussi : c'est un ADR, il dit ce qui a été décidé à sa
date, et c'est la §6.1 qui le complète plutôt que de le réécrire.

## Notes de la tranche 6

`DUR049` a été relu contre le code avant d'être figé, et deux chiffres corrigés : `ProcessDetail`
passe de onze méthodes à sept — il garde `getTimeline()` comme accesseur mémoïsé, il n'en perd pas
six — et la frise a dix-huit cas de test, pas onze. Un ADR qui compte faux se relit une fois puis
plus jamais.

Sur le site, la duplication n'avait pas la même excuse que dans les README : deux paquets satellites
justifient une section répétée, un site non. D'où **une** page, et deux sections d'habillage qui y
renvoient.

⚠ **La page d'accueil n'est pas touchée, à dessein.** `hugo-docs/layouts/index{,.fr}.html` est
**engendré** depuis `variant-b-narrative{,-fr}.dc.html` : les phrases du sélecteur d'hôtes s'y
modifient par le canevas, jamais dans le fichier engendré. Elles ne contredisent rien aujourd'hui —
« Sylius admin gains a dashboard », « Filament … the dashboard side » restent vraies — mais la ligne
Magento ne mentionne pas son écran, et c'est une omission à reprendre au canevas.

⚠ **Le hugo installé est un snap** : il ne lit ni `/tmp` ni les dossiers cachés du `$HOME`. Vérifier
un build depuis un worktree de scratchpad demande donc de recopier `hugo-docs/` et `documentation/`
(2 Mo) sous un chemin visible du `$HOME`. Build `--minify` servi en HTTP, les deux langues et les
liens relatifs vérifiés à 200.
