## Why

`temporal-nexus-support` a livré l'appel d'une opération Nexus depuis un workflow : identité,
bornes, annulation, échecs classés, et le tout vérifié contre un vrai serveur. Une chose y est
restée ouverte, délibérément.

`ScheduleNexusOperationCommandAttributes` porte un `NexusHeader`, et la spec publiée dit qu'un
appel **MAY** en porter. Rien ne le construit aujourd'hui, et le tampon Temporal ne l'écrit pas.

Ce n'était pas un oubli. Les en-têtes n'ont **aucune source côté domaine** : ni
`NexusOperationTimeouts` ni le port `scheduleNexusOperation()` de §3.4 ne les transportent. Les
écrire dans le tampon sans rien pour les alimenter aurait produit un champ toujours vide — une
fonctionnalité en apparence, rien en pratique.

Et elles n'ont pas non plus de consommateur : un en-tête Nexus sert à porter une corrélation, une
authentification ou un contexte jusqu'au **handler**, qui n'existe pas encore de ce côté-ci.

## What Changes

- Un porteur d'en-têtes côté domaine, construit et validé comme les autres objets-valeurs du
  composant, avec les règles que le serveur applique réellement — **à sonder avant d'en écrire
  aucune**, selon la discipline du chantier précédent.
- Le port `WorkflowCommandBufferInterface::scheduleNexusOperation()` les transporte. **BREAKING**
  pour les implémentations tierces, comme DUR031 l'a été.
- Le tampon Temporal les écrit dans la commande, et l'historique les relit.
- Un test d'intégration établissant qu'un en-tête envoyé revient inchangé dans
  `NEXUS_OPERATION_SCHEDULED`.

## Capabilities

### Modified Capabilities

- `nexus-operations` : l'exigence de planification passe les en-têtes de `MAY` à une capacité
  réellement offerte.

## Impact

- **Domain** (`src/Durable/Nexus`) : un objet-valeur de plus.
- **Port** : une signature élargie, donc une rupture pour qui l'implémente.
- **Backends** : Temporal écrit et relit ; le backend journal refuse déjà toute opération Nexus et
  n'a rien à changer.
- **Dépendances** : aucune.
