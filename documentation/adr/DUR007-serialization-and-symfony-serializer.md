# DUR007 — Sérialisation et composant Serializer de Symfony

## Statut

Accepté

## Contexte

Les **payloads** échangés entre workflows, activités et l’orchestrateur (entrées/sorties d’activités, événements pertinents pour le composant, etc.) doivent être **sérialisés** de façon **stable** et **interopérable**. Le composant ne doit pas réinventer une pile ad hoc si un standard de l’écosystème Symfony est adapté.

## Décision

La couche de **sérialisation** du composant Durable **s’appuie sur le composant Serializer de Symfony** (`symfony/serializer`) pour :

- sérialiser et désérialiser les arguments et valeurs de retour des **activités** (DUR004) ;
- tout autre besoin de transformation structurée ↔ représentation transportable (JSON ou autre format retenu par le backend) dans le périmètre du composant.

### Principes

- **Normalizers** et **encoders** Symfony : conventions du Serializer (contexte, groupes, types) pour contrôler les formats et l’évolution des schémas.
- **Types** : les types utilisés dans les signatures d’activités et les modèles exposés à la sérialisation doivent être **compatibles** avec le pipeline (objets sans ressources, DTO / value objects, collections typées, etc.) — détails précisés dans l’implémentation et les tests.
- **Déterminisme** : le workflow reste déterministe (DUR003) ; la sérialisation **ne** sert **pas** à contourner l’interdiction d’I/O dans le workflow — elle s’applique aux frontières activités / orchestrateur et aux couches d’adaptation.

### Couplage

- Le composant Durable peut exposer des **factories** ou une **configuration** recommandée du Serializer (normalizers, encoders) pour les usages hôtes Symfony.
- Les **ports** du domaine Durable ne dépendent pas de types internes du Serializer comme **contrat public** : les interfaces restent exprimées en types PHP du composant ; Symfony Serializer est un **mécanisme d’implémentation** choisi.

## Conséquences

- La dépendance `symfony/serializer` est attendue dans le graphe Composer du composant (ou du bundle intégrateur), dans une version compatible avec la branche PHP supportée.
- Les évolutions de format (nouveaux champs, versions) sont gérées via les capacités du Serializer (groupes, `@SerializedName`, etc.) et la politique de compatibilité documentée avec les releases.
