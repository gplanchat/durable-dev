# DUR000 — Processus de gestion des ADR

## Statut

Accepté

## Contexte

Le composant **Durable** nécessite un cadre clair pour documenter les décisions d’architecture, assurer la traçabilité et aligner les évolutions du code sur des choix explicites.

## Décision

Les **Architecture Decision Records (ADR)** du projet Durable sont numérotées avec le préfixe **`DUR`**, suivies d’un identifiant numérique sur trois chiffres et d’un titre court en kebab-case.

### Emplacement

- Répertoire : `documentation/adr/`
- Fichiers : `DUR{NNN}-{titre-court}.md` (ex. `DUR001-event-store-cursor.md`)

### Numérotation

- **DUR000** : ce document (méta-processus)
- **DUR001 et suivants** : numérotation séquentielle, sans réutilisation d’un numéro retiré ou obsolète

### Documents META

Lorsqu’une décision exige des détails trop longs pour un seul fichier, des documents complémentaires peuvent être placés dans un sous-dossier :

- `documentation/adr/DUR{NNN}-{titre-court}/`
- Fichiers : `DUR{NNN}-META{MM}-{sujet}.md` (META numérotés sur deux chiffres)

### Structure recommandée d’un ADR

1. Titre et identifiant
2. Statut (brouillon, proposé, accepté, déprécié, remplacé)
3. Contexte
4. Décision
5. Conséquences (positives, négatives, suivi éventuel)

### Index

La liste à jour des ADR est maintenue dans `documentation/INDEX.md`.

## Conséquences

- Toute nouvelle décision architecturale majeure du périmètre Durable devrait être reflétée par un ADR ou la mise à jour d’un ADR existant.
- Les implémentations futures pourront s’y référer sans dépendre de documents externes au dépôt.
