# Cycle de vie et organisation des documents

Ce document décrit comment les documents d'architecture sont créés, organisés et reliés entre eux.

---

## Vue d'ensemble

```
Plan Cursor (phase de conception)
         │
         ▼
    ┌────────────┐
    │ Quel type? │
    └─────┬──────┘
          │
    ┌─────┼─────┬─────────────┐
    ▼     ▼     ▼             ▼
  ADR   WA    OST           PRD
  (tech) (org) (futur)    (déjà fait)
```

---

## Types de documents et leur usage

### ADR — Architecture Decision Record

**Quand** : Une décision technique impacte l'architecture (choix de librairie, pattern, stack).

**Contenu typique** :
- Contexte et problème
- Options envisagées
- Décision prise
- Conséquences

**Exemple** : Choix de Symfony Messenger pour le transport des activités.

---

### WA — Working Agreement

**Quand** : Un accord sur la manière de travailler ou de gérer le projet.

**Contenu typique** :
- Accord ou convention
- Rôles et responsabilités
- Processus ou workflow

**Exemple** : Convention de nommage des branches, fréquence des revues, gestion des plans Cursor.

---

### OST — Opportunity Solution Tree

**Quand** : Exploration d'une fonctionnalité future, avant développement.

**Contenu typique** :
- Opportunité ou objectif utilisateur
- Solutions envisagées
- Hypothèses à valider
- Arbre de décision

**Exemple** : Réflexion sur Temporal comme driver optionnel, multi-transport.

---

### PRD — Product Requirements Document

**Quand** : Une fonctionnalité est déjà développée et doit être documentée.

**Contenu typique** :
- Objectifs et périmètre
- Spécifications fonctionnelles
- Critères d'acceptation
- État d'implémentation

**Exemple** : Documentation du système de workflows durables après implémentation.

---

## Cycle de vie typique

### Pour une nouvelle fonctionnalité

```
1. OST (exploration)
   → Réflexion sur l'opportunité, solutions possibles

2. ADR (si décisions techniques)
   → Choix techniques liés à la fonctionnalité

3. Développement
   → Implémentation

4. PRD (documentation a posteriori)
   → Spécifications et état de la fonctionnalité livrée
```

### Pour une décision technique isolée

```
ADR uniquement
→ Pas de lien obligatoire avec OST ou PRD
```

### Pour un accord de travail

```
WA uniquement
→ Indépendant du cycle des fonctionnalités
```

---

## Organisation des dossiers

```
documentation/
├── INDEX.md          ← Index de tous les documents (à maintenir)
├── LIFECYCLE.md      ← Ce document
├── adr/              ← ADR001-xxx.md, ADR002-xxx.md, ...
├── wa/               ← WA001-xxx.md, WA002-xxx.md, ...
├── ost/              ← OST001-xxx.md, OST002-xxx.md, ...
└── prd/              ← PRD001-xxx.md, PRD002-xxx.md, ...
```

---

## Numérotation

- **Séquentielle par type** : ADR001, ADR002, ADR003...
- **Sans trou** : Ne pas réutiliser un numéro supprimé
- **Description courte** : En minuscules, tirets, descriptif (ex. `choix-babylonjs`)

---

## Liens entre documents

Les documents peuvent se référencer :

- **OST → ADR** : Un ADR peut documenter une décision technique issue d'un OST
- **OST → PRD** : Un PRD documente la fonctionnalité explorée dans un OST
- **ADR → ADR** : Un ADR peut remplacer ou compléter un autre (statut "Superseded by ADR002")

---

## Maintenance

1. **À chaque nouveau document** : Mettre à jour `documentation/INDEX.md`
2. **Lors d'une décision obsolète** : Mettre à jour l'ADR avec le statut "Superseded"
3. **Lorsqu'une fonctionnalité OST est livrée** : Créer le PRD correspondant et mettre à jour l'OST si besoin
