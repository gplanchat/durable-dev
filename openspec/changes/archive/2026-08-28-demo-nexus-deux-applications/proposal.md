## Why

Nexus est livré et documenté : appeler, servir, les deux formes de réponse, l'annulation. La page
d'accueil l'annonce, le comparatif l'oppose au SDK, la page utilisateur montre huit classes.

**Rien de tout cela ne tourne.** Les preuves du chantier `nexus-handler-side` sont des tests
d'intégration : un appelant et un gestionnaire dans le même processus PHP, pilotés à la main. La
réserve est écrite noir sur blanc dans son §3.4 — *« pas encore observé de bout en bout depuis
PHP »* pour ce qui est du second processus.

Or c'est précisément ce que Nexus prétend permettre : **deux applications, deux équipes, deux
espaces de noms**. Une démonstration qui n'en met en scène qu'une ne démontre rien de ce qui
distingue Nexus d'un appel d'activité.

Les deux maquettes du dépôt sont les candidates naturelles. Elles existent, elles sont différentes
— une boutique et une application métier —, et elles n'ont jamais communiqué.

## What Changes

Les deux maquettes se parlent **dans les deux sens**, sur un cluster Temporal et deux namespaces :

- `sylius/` (boutique) appelle `facturation/encaisser`, servie par `symfony/` **par un workflow** —
  la forme différée, celle qui dure des minutes sans que la boutique tienne rien d'ouvert ;
- `symfony/` appelle `stock/reserver`, servie par `sylius/` **immédiatement** — la forme qui répond
  sur la tâche.

Chaque application est donc à la fois appelante et servante, ce qui est exactement ce que la page
d'accueil affirme.

Un **contrat partagé** en dépôt path, déclaré par les deux maquettes : c'est la seule chose
qu'elles partagent, et c'est le point.

### Ce que la maquette Sylius doit gagner

Elle est en DBAL par choix documenté — une boutique n'a pas de cluster, et le plugin n'impose ni le
pont Temporal ni `ext-grpc` ([DUR037](../../../documentation/adr/DUR037-run-observation-as-a-projection.md)).
**Ce choix ne change pas** : le plugin continue de n'exiger rien de tout cela. C'est la maquette,
application de démonstration, qui opte pour Temporal — et le dira.

## Impact

- `sylius/` : le pont Temporal, un profil Temporal, un contrat servi, un workflow appelant.
- `symfony/` : son DSN Temporal activé, un contrat servi, un workflow qui remplit l'opération différée.
- Un contrat partagé, non publié.
- Deux endpoints Nexus stables, nommés `demo-*`.
- Aucun changement aux paquets : ni `durable-plugin`, ni `durable-bundle`, ni le cœur.
