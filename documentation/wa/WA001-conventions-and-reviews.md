# Conventions et revues

WA001-conventions-and-reviews
===

Introduction
---

Ce **Working Agreement** définit les conventions de travail et les accords pour la gestion du projet Durable. Il s'applique à l'équipe de développement et aux contributeurs.

Conventions de nommage
---

### Branches Git

- `main` : branche principale
- `feature/{ticket-id}-{description}` : fonctionnalités
- `fix/{ticket-id}-{description}` : corrections
- `docs/{description}` : documentation

### Commits

- Messages en français ou anglais
- Format : `type(scope): description`
- Types : `feat`, `fix`, `docs`, `refactor`, `test`, `chore`

### Fichiers

- PHP : PascalCase pour les classes
- Tests : `*Test.php` ou `*TestCase.php`
- Configuration : kebab-case pour les fichiers YAML

Revue de code
---

- Les changements significatifs _DOIVENT_ faire l'objet d'une revue
- Les ADR et modifications de documentation _DOIVENT_ être relus avant merge
- Critères : conformité aux ADR, tests, clarté du code

Gestion des plans Cursor
---

- Les phases de conception documentées (ADR, WA, OST, PRD) suivent le [LIFECYCLE.md](../LIFECYCLE.md)
- Chaque nouveau document est indexé dans [INDEX.md](../INDEX.md)
- Les plans Cursor attachés sont documentés dans `documentation/`

Responsabilités
---

- **Mainteneurs** : validation finale des ADR, release
- **Contributeurs** : respect des conventions, mise à jour de la documentation
- **Revue** : au moins une approbation pour les PR impactant l'architecture

Références
---

- [INDEX.md](../INDEX.md)
- [LIFECYCLE.md](../LIFECYCLE.md)
- [ADR001 - Processus ADR](../adr/ADR001-adr-management-process.md)
