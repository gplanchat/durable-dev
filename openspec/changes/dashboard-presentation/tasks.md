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

- [x] 3.1 `ProcessDetail` consumes the promoted projection. Ce qui doit **disparaître**, et pas
      seulement cohabiter : `getTimeline()`, `segments()`, `scale()`, la composition des infobulles
      de segment et de repère (doublon de `TimelineSegment::$title` / `TimelineEvent::$title`) et
      `formatDetails()` (doublon de `RecordedDetails::of()`). Les laisser côte à côte remettrait
      exactement la divergence que la tranche 1 est allée chercher
- [x] 3.2 The listing reports backend health, which it never probes today
- [x] 3.3 Les compteurs portent sur **la fenêtre que l'écran lit**, et le disent. Pas « la page » :
      la grille de Magento pagine par décalage *dans* cette fenêtre, donc l'ensemble que
      l'exploitant parcourt est la fenêtre, pas la page courante. La décision de l'auteur — portée
      assumée et nommée — vaut pour les deux ; c'est la portée qui diffère, parce que la pagination
      diffère. `RunDashboard::outcomeCounters()` devient publique : compter à la main chez l'hôte
      recreuserait le trou d'un seau oublié
- [x] 3.4 Le plafond est annoncé dès que la fenêtre est pleine, et la fenêtre est **une seule
      constante** — `RuntimeFactory::OBSERVATION_WINDOW`. Elles étaient deux littéraux de même
      valeur, ce qui rendait possible d'être listé d'un côté et introuvable de l'autre au premier
      qui bougerait

## 4. Counters and absences say what they mean

- [x] 4.1 L'intitulé nomme la portée sur les deux surfaces : « Outcomes across the N runs on this
      page » chez Sylius, « across the N most recent runs this screen reads » chez Magento — la
      portée diffère parce que la pagination diffère, et chacune le dit
- [x] 4.2 La grille Magento rendait `''` pour une date absente — une case vide se lit comme un rendu
      qui a échoué. Un tiret cadratin nommé, le même que celui de l'écran de détail. La liste Sylius
      n'a pas de colonnes fixes : ce sont des cartes, et un fait absent y est omis, ce qui reste le
      bon rendu — la règle du tiret porte sur les tableaux

## 5. Sweep the drift lanes left behind

- [x] 5.1 `WorkflowRunEventKind` ne décrit plus une voie mais une **nature** : la ligne vient de
      l'action, et l'énumération n'y sert plus qu'à colorer. Balayé aussi dans les deux lecteurs
      d'historique et dans les classes CSS du gabarit Sylius (`durable-lane` → `durable-action`)
- [x] 5.2 Le README du greffon décrit la frise par actions, et sa table « Lane kind » devient
      « Event kind »
- [x] 5.3 Les deux README portent la **même section** « The panels, and why they are the same
      everywhere » — quatre panneaux, les trois états du backend, la portée des compteurs, la frise
      par actions. Les pages paquets du site suivent, dans les deux langues ; le site monte
      `documentation/user` directement, il n'y a donc pas de copie à tenir

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
