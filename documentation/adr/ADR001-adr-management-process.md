# Processus de gestion des ADR

ADR001-adr-management-process
===

Introduction
---

Ce **Architecture Decision Record** établit les fondations pour la gestion des Architecture Decision Records (ADR) au sein du projet Durable. Le projet Durable fournit un composant et un Bundle Symfony pour les exécutions durables (workflows et activités), sans dépendance RoadRunner. Ce processus garantit la cohérence, la traçabilité et une communication claire des décisions architecturales.

Ce document est le méta-ADR qui régit tous les autres ADR du projet Durable.

Structure et organisation des ADR
---

### Emplacement et nommage

Tous les ADR _DOIVENT_ être stockés dans le répertoire `documentation/adr/` à la racine du projet.

**Convention de nommage** : `ADR{number}-{short-title}.md`
- Numéros sur 3 chiffres (ex. `ADR001`, `ADR002`, `ADR042`)
- Titres courts en kebab-case (minuscules, tirets)
- Exemples : `ADR001-adr-management-process.md`, `ADR002-coding-standards.md`

### Numérotation

- **ADR001** : Réservé à ce document (processus ADR)
- **ADR002+** : Assignés séquentiellement pour chaque nouvelle décision
- **Numéros retirés** : Ne jamais réutiliser un numéro, même si l'ADR est superseded ou déprécié

### Structure des dossiers

```
documentation/
├── INDEX.md
├── LIFECYCLE.md
├── adr/
│   ├── ADR001-adr-management-process.md
│   ├── ADR002-coding-standards.md
│   └── ...
├── wa/
├── ost/
└── prd/
```

### Meta-documents

Lorsqu'un ADR nécessite une documentation complémentaire, des méta-documents _PEUVENT_ être créés dans un sous-dossier `ADR{number}-{short-title}/` avec le pattern `ADR{number}-META{nn}-{title}.md`.

Format et template des ADR
---

### Sections requises

Chaque ADR _DOIT_ inclure :
1. **Titre** suivi de `===`
2. **Introduction** : contexte et problème
3. **Sections de contenu** : décision et justification
4. **Références** : liens externes et documents connexes

### Standards rédactionnels

- Langage clair, concis et professionnel (français ou anglais)
- Public : développeurs travaillant sur le projet Durable
- Perspective : présent pour les décisions actuelles
- Objectivité : faits et justification

Cycle de vie des ADR
---

### Processus de création

1. Identifier le besoin d'une décision architecturale
2. Rédiger l'ADR selon le format établi
3. Assigner le prochain numéro séquentiel
4. Revue par l'équipe
5. Validation par les mainteneurs
6. Mise à jour de `documentation/INDEX.md`

### Processus de superseding

1. Créer un nouvel ADR avec la nouvelle décision
2. Indiquer dans l'ancien ADR qu'il est superseded
3. Référencer l'ADR qui le remplace
4. Conserver les deux documents pour la traçabilité

Maintenance
---

- **INDEX.md** : maintenir l'index de tous les documents
- **Statuts** : indiquer Active, Superseded ou Deprecated dans les ADR
- **Cohérence** : vérifier la conformité au processus lors des revues

Références
---

- [Architecture Decision Records](https://adr.github.io/)
- [Documenting Architecture Decisions](https://cognitect.com/blog/2011/11/15/documenting-architecture-decisions)
- [LIFECYCLE.md](../../LIFECYCLE.md)
- [INDEX.md](../../INDEX.md)
