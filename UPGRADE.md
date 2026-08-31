# Monter de version

Toute rupture publique de ce dépôt vient avec sa procédure de migration : **Rector d'abord, un
script quand Rector ne peut pas, et de la documentation dans tous les cas.** Ce fichier est la
troisième moitié — il dit ce qui a bougé, et par quoi le rattraper.

```bash
composer require --dev gplanchat/durable-rector
```

```php
// rector.php
return Rector\Config\RectorConfig::configure()
    ->withImportNames()   // sans quoi les noms réécrits arrivent pleinement qualifiés, à côté d'un `use` périmé
    ->withSets([__DIR__ . '/vendor/gplanchat/durable-rector/config/sets/durable-upgrade.php']);
```

```bash
vendor/bin/rector process src
```

Le set est **cumulatif** : le passer une fois rattrape toutes les versions franchies d'un coup. Il
ne contient que ce que Rector sait faire sans deviner ; tout le reste est écrit à la main ci-dessous.

## 0.1.0-alpha8

### Laravel refuse au démarrage un workflow dont les noms de paramètres divergent du contrat

`gplanchat/durable-laravel` enregistrait sans vérifier. Un workflow portant
`#[FulfilsNexusOperation]` dont un paramètre **obligatoire** ne correspond à aucun paramètre du
contrat fait désormais échouer l'enregistrement, en nommant les deux signatures — le même refus que
`NexusHandlerPass` produit côté Symfony depuis toujours, et par la même classe :
`Gplanchat\Durable\Nexus\Serving\NexusFulfilmentParameterNames`.

**Pourquoi** — la charge d'une opération Nexus est clée **par nom** aux deux bouts. Un paramètre
renommé d'un seul côté ne casse rien à l'écriture, ne lève rien à l'exécution, et arrive à `null` :
le workflow démarre, s'exécute et rend un résultat calculé sur du vide. L'enregistrement est le
dernier moment où quelqu'un regarde.

**Ce que Rector ne peut pas faire** — rien à renommer mécaniquement : le bon nom est celui du
contrat, et seul l'auteur sait lequel des deux côtés porte la faute de frappe. Le message du refus
imprime les deux listes de paramètres, ce qui est exactement l'information qu'il faudrait à Rector
pour choisir.

**Qui est concerné** — aucune application dont les opérations Nexus fonctionnent : le refus ne
frappe que des configurations qui rendaient déjà `null` en silence. Si le démarrage échoue après la
montée de version, la panne existait avant, sans le dire.

### Un workflow qui remplit une opération Nexus doit porter sa balise

`NexusHandlerPass` lisait les `#[FulfilsNexusOperation]` en **balayant toutes les définitions du
conteneur** et en appelant `class_exists()` sur chacune. Il lit désormais la balise
`durable.nexus_fulfilment`, que `DurableBundle::build()` pose depuis l'attribut.

**Pourquoi** — le balayage chargeait chaque classe du conteneur pour lire ses attributs. Il suffit
qu'une seule étende un parent absent — un bundle de développement à moitié installé, et
`Symfony\Bundle\MakerBundle\Maker\AbstractMaker` est le cas réel qui l'a montré — pour que le
chargement fasse une **erreur fatale** dans une passe de compilation qui n'avait rien à y voir. La
balise dit exactement ce qu'on cherche, et elle existait déjà pour ça.

**Ce que Rector ne peut pas faire** — rien à renommer : la rupture est de configuration.

⚠ **Ce que vous avez à faire, si et seulement si** un de vos workflows portant
`#[FulfilsNexusOperation]` est déclaré avec `autoconfigure: false`, ou monté à la main comme
`Definition`. L'ancien balayage le voyait quand même ; la balise, non. Le symptôme est un refus au
démarrage, et il vous nomme l'opération :

```
durable.nexus_handler: operation "encaisser" of contract … is served by nobody
```

Deux façons de le rattraper, selon ce que vous vouliez :

```yaml
services:
    App\Workflow\Encaissement:
        autoconfigure: true          # la balise revient toute seule
```

```yaml
services:
    App\Workflow\Encaissement:
        tags:
            - name: durable.nexus_fulfilment
              contract: 'App\Contract\FacturationContract'
              operation: 'encaisser'
```

### Les noms de paramètres d'un workflow qui remplit une opération sont vérifiés

Un workflow portant `#[FulfilsNexusOperation]` dont un paramètre **sans valeur par défaut** ne
correspond à aucun paramètre de la méthode de contrat fait maintenant échouer la compilation du
conteneur.

**Pourquoi** — c'est le mode d'échec le plus silencieux de Nexus. La charge est clée par nom à
l'écriture et relue par nom à l'arrivée : un paramètre qui ne correspond à rien recevait `null`, et
le workflow démarrait, s'exécutait et rendait un résultat calculé sur du vide.

**Ce que vous avez à faire** — si le refus se déclenche, un des deux côtés a une faute de frappe.
Le message donne les deux signatures. Un paramètre que le contrat ignore volontairement passe s'il
a une valeur par défaut : l'absence est alors une décision, pas un oubli.


### L'orchestration de reprise descend du bundle vers le cœur

- `Gplanchat\Durable\Bundle\Handler\ResumeWorkflowHandler` → `Gplanchat\Durable\Handler\ResumeWorkflowHandler`
- `Gplanchat\Durable\Bundle\Handler\FireWorkflowTimersHandler` → `Gplanchat\Durable\Handler\FireWorkflowTimersHandler`
- `Gplanchat\Durable\Bundle\Support\AsyncChildWorkflowFailureProjector` → `Gplanchat\Durable\Workflow\AsyncChildWorkflowFailureProjector`

**Pourquoi** — ce n'était pas un adaptateur d'hôte. Sur **279 lignes, 21 touchaient Symfony**
(imports compris), et ces 21 ne servaient qu'à deux choses : un identifiant v7, que
`ExecutionId::generate()` fabrique déjà dans le cœur, et « publier le réveil des minuteries après
l'unité de travail courante ». La seconde est devenue le port
`Gplanchat\Durable\Port\WorkflowTimerDispatcher`, dont le bundle fournit l'implémentation
Messenger. Six hôtes du sélecteur ne passent pas par le bundle : les y laisser aurait voulu dire
autant de copies de la sémantique de reprise, divergentes à la première correction.

**Ce que Rector fait** — les trois renommages. **Ce que vous avez à faire** — rien de plus, si vous
utilisiez ces classes indirectement : le bundle les câble toujours, aux mêmes identifiants de
service. Le `cache:clear` reste nécessaire, pour la raison ci-dessous.

⚠ **Si vous aviez votre propre implémentation** de `WorkflowTimerDispatcher` avant qu'il existe —
c'est impossible, il est neuf — rien à faire. Mais si vous injectiez un `MessageBusInterface` dans
un décorateur de ces gestionnaires, le septième argument de `ResumeWorkflowHandler` et le quatrième
de `FireWorkflowTimersHandler` sont désormais un `WorkflowTimerDispatcher`, pas un bus.

### `TimerWakeDelayCalculator` descend du bundle vers le cœur

`Gplanchat\Durable\Bundle\Messenger\TimerWakeDelayCalculator` devient
`Gplanchat\Durable\Timer\TimerWakeDelayCalculator`.

**Pourquoi, et pourquoi ça compte plus que le déplacement suivant** — cette classe n'importait rien
de Symfony (des événements de minuterie et le port du magasin d'événements), et
`InMemoryWorkflowRunner`, qui **est** du cœur, l'appelait. `gplanchat/durable` ne requiert pas
`gplanchat/durable-bundle` : sur tout hôte qui n'installe pas le bundle, une reprise qui devait
sauter au prochain minuteur levait une **erreur fatale de classe introuvable**. Sous Symfony rien ne
se voyait, le bundle étant toujours là.

Trouvé en rejouant sur Magento une commande tuée pendant sa réservation. Une garde le tient
désormais : aucun fichier de `src/Durable` n'importe un hôte ni un pont.

**Ce que Rector fait** — le renommage. **Ce qu'il ne peut pas faire** — le même `cache:clear` que
ci-dessous, pour la même raison.

### `PayloadToContractMethodInvoker` descend du bundle vers le cœur

`Gplanchat\Durable\Bundle\Activity\PayloadToContractMethodInvoker` devient
`Gplanchat\Durable\Activity\PayloadToContractMethodInvoker`.

**Pourquoi** — la classe adapte une charge utile (tableau, clés = noms des paramètres) vers la
méthode d'un contrat d'activité. Elle vivait dans le paquet du bundle Symfony **sans en importer une
ligne**, et l'intégration Magento en a besoin mot pour mot : son conteneur n'a pas les tags de
Symfony, mais une fois le contrat résolu l'adaptation est la même. Elle rejoint
`ActivityContractResolver`, qui la nourrit et qui était déjà dans le cœur.

**Ce que Rector fait** — le renommage, partout où le nom apparaît.

**⚠ Ce que Rector ne peut pas faire, et qu'il faut faire à la main** — vider le cache du conteneur :

```bash
bin/console cache:clear
```

Le nom pleinement qualifié est écrit dans le **conteneur compilé**. Sans ce vidage, une application
Symfony continue de demander l'ancien nom après la mise à jour, et la panne arrive au premier appel
d'activité — loin de sa cause, et sans que rien ne désigne le déplacement. C'est aussi pourquoi
Composer ne peut pas vous prévenir : il installe les deux paquets sans rien dire, et l'ancien nom
disparaît simplement.

**Si vous ne l'utilisiez pas directement**, vous n'aviez rien à faire dans votre code : la classe
n'était référencée que par la passe de compilation du bundle. Le vidage de cache, lui, reste
nécessaire.

### Tout attribut de déclaration prend le préfixe `As`

Le dépôt portait deux conventions. Le cœur nommait ses attributs sans préfixe (`#[Workflow]`,
`#[Activity]`) ; le bundle Symfony en avait un seul, préfixé (`#[AsDurableActivity]`) ; ni le pont
Illuminate ni le module Magento n'en avaient. Servir des opérations Nexus demandait d'en ajouter,
donc de choisir. `As*` l'emporte, et il dit ce qu'il dit : *cette déclaration enregistre un X*.

Les attributs de **méthode** suivent la même règle, pour qu'il n'y en ait qu'une à retenir plutôt
qu'une règle et son exception.

| avant | après |
|---|---|
| `#[Workflow]` | `#[AsWorkflow]` |
| `#[Activity]` | `#[AsActivity]` |
| `#[WorkflowMethod]` | `#[AsWorkflowMethod]` |
| `#[ActivityMethod]` | `#[AsActivityMethod]` |
| `#[QueryMethod]` | `#[AsQueryMethod]` |
| `#[SignalMethod]` | `#[AsSignalMethod]` |
| `#[UpdateMethod]` | `#[AsUpdateMethod]` |

**Ce que Rector fait** — le renommage, partout où l'attribut apparaît. Rien d'autre ne bouge : les
arguments, les cibles et le sens de chaque attribut sont inchangés. Le set est donc rejouable sans
dommage.

### `AsDurableActivity` descend du bundle vers le cœur, sous le nom `AsActivityHandler`

`Gplanchat\Durable\Bundle\Attribute\AsDurableActivity` devient
`Gplanchat\Durable\Attribute\AsActivityHandler`.

Deux changements en un, et ils se justifient ensemble. Le déplacement d'abord : cet attribut
déclarait qu'une classe implémente un contrat d'activité, ce qu'aucun framework ne rend spécifique.
Le laisser côté Symfony aurait obligé le pont Illuminate et le module Magento à en inventer chacun
un autre pour dire la même chose. Le nom ensuite : `AsActivityHandler` le met en paire avec
`AsNexusServiceHandler`, les deux déclarant une implémentation par son contrat.

**⚠ Ce que Rector ne peut pas faire, et qu'il faut faire à la main** — vider le cache du conteneur :

```bash
bin/console cache:clear
```

C'est le même piège que pour `PayloadToContractMethodInvoker`, en pire : cet attribut est **lu par
une passe de compilation**. Le conteneur compilé garde le nom pleinement qualifié, et une
application qui monte de version sans vider son cache continue de chercher un attribut qui n'existe
plus — sans que rien ne désigne le déplacement.

**Si vous n'utilisiez pas `#[AsDurableActivity]`**, vous n'avez rien à faire ; le vidage de cache
reste néanmoins recommandé, l'autre entrée de cette version l'exigeant.

### `JournalExecutionIdResolver::MEMO_KEY_JOURNAL_BOOTSTRAP` est retirée

La constante nommait un mémo qu'un **bootstrap natif par le journal** aurait posé — un pan de code
qui n'a jamais atteint `main`. Six tests d'intégration le décrivaient, quatre des cinq classes qu'ils
appelaient n'ont jamais existé, et ces tests ont été supprimés avec leur constat consigné. La
constante leur avait survécu : plus rien ne la lisait, et son docblock décrivait `workflowType`,
qui n'a jamais été son contenu.

**Ce que Rector fait** — rien. Il n'y a pas de nom de remplacement : ce n'est pas un renommage mais
une suppression, et inventer une cible serait pire que se taire.

**Ce que vous avez à faire** — presque certainement rien. Cette constante n'était lue par aucun code
du dépôt. Si vous la référencez, c'est que vous parliez à un mémo que Durable n'a jamais écrit :
`MEMO_KEY_DURABLE_EXECUTION_ID`, elle, reste et est bien celle que `WorkflowClient` pose au
démarrage.
