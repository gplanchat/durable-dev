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
      fait avec 1.4 : la classe a été **déplacée**, pas recopiée, et laisser deux exemplaires dans
      l'arbre le temps d'une tranche aurait coûté plus que de recâbler trois lignes
- [x] 2.2 The detail panel positions actions in time and hatches a wait, which it does not do today
- [x] 2.3 `RecordedDetails` dans le cœur : le gabarit Sylius appelait `json_encode` **sans**
      tolérance et rendait un dépliant vide dès qu'un octet n'était pas de l'UTF-8. Mesuré avant
      d'écrire, et le scénario corrigé avec : la sortie partielle ne rend **jamais** `false` — ni
      sur un octet invalide, ni sur une ressource, ni sur six cents niveaux d'imbrication. La bonne
      dégradation n'est donc pas la ligne simple mais la charge utile entière avec la seule valeur
      fautive en `null`, ce qui est mieux que ce que la spec demandait. La garde `false` reste,
      défensive
- [x] 2.4 Un rendu **réel** du gabarit, et non une lecture de son texte : les autres assertions du
      dossier lisent le fichier, et aucune n'éprouvait `action.events` → `mark.event.label`. Une
      propriété mal nommée dans cette chaîne rend une page vide sur l'écran qu'on est venu regarder.
      Vérifié par mutation. Couvre depuis 2.2/2.3 une attente hachurée, le placement dans le temps
      et une charge utile à l'octet invalide

## 3. Magento renders the projection instead of deriving its own

- [ ] 3.1 `ProcessDetail` consumes the promoted projection. Ce qui doit **disparaître**, et pas
      seulement cohabiter : `getTimeline()`, `segments()`, `scale()`, la composition des infobulles
      de segment et de repère (doublon de `TimelineSegment::$title` / `TimelineEvent::$title`) et
      `formatDetails()` (doublon de `RecordedDetails::of()`). Les laisser côte à côte remettrait
      exactement la divergence que la tranche 1 est allée chercher
- [ ] 3.2 The listing reports backend health, which it never probes today
- [ ] 3.3 The listing shows counters over the page it displays
- [ ] 3.4 The window ceiling is stated on screen, and the detail screen resolves a run without
      re-scanning a second, differently sized window

## 4. Counters and absences say what they mean

- [ ] 4.1 The `Total` heading becomes a heading that names the page, on both surfaces
- [ ] 4.2 A fact absent for this run renders as an em dash where columns are fixed; a fact the
      backend has no notion of still shows no column at all

## 5. Sweep the drift lanes left behind

- [ ] 5.1 `WorkflowRunEventKind`'s documentation stops describing lanes — actions replaced them
- [ ] 5.2 `src/DurablePlugin/README.md` stops advertising history "grouped in lanes" and its lane
      source table
- [ ] 5.3 `src/DurableModule/README.md` and `magento/README.md` describe the same panels

## 6. Leave the decision behind

- [ ] 6.1 An ADR: why the timeline projection lives in the component beside the observation model
      rather than in each surface or in a shared dashboard package, with `ReadableDuration` as the
      precedent it follows
- [ ] 6.2 The documentation site's dashboard pages describe one dashboard with several chromes,
      rather than one per host

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
