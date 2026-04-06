# DUR008 — Style PER (PHP-FIG) et nommage des classes

## Statut

Accepté

## Contexte

Le code du composant Durable doit rester **lisible**, **homogène** et **aligné** sur les pratiques reconnues de la communauté PHP. Le **PER Coding Style** publié par PHP-FIG est le référentiel de style qui succède et étend PSR-12 pour le PHP moderne (attributs, enums, types composés, etc.).

## Décision

**Tout le code PHP du composant Durable** (y compris tests, sauf mention contraire dans une ADR spécifique) **doit** respecter le **PER Coding Style** de PHP-FIG **dans sa dernière version stable** publiée sur le site officiel PHP-FIG au moment de la branche de développement.

**Référence** : [PER Coding Style](https://www.php-fig.org/per/coding-style/) (PHP-FIG).

### Nommage des classes et des identifiants

- **Classes** : noms en `StudlyCaps` / `PascalCase` conformément au PER (y compris règles pour acronymes et mots composés telles que définies par le document).
- **Interfaces, traits, enums** : conventions du PER pour les identifiants de type.
- **Méthodes et propriétés** : `camelCase` sauf exceptions explicitement couvertes par le PER.
- **Constantes de classe** : selon le PER (convention en majuscules avec séparateurs).

### Outils

- Le projet **devrait** appliquer le formatage et les vérifications via un outil de style (PHP-CS-Fixer, PHP_CodeSniffer avec règle PER, ou équivalent) configuré pour la **révision PER** suivie par le dépôt.

### Évolution

- Lorsqu’une nouvelle version majeure du PER est publiée, **une mise à jour** des règles de CI et du code est planifiée ; les écarts documentés dans les ADR ou les notes de version.

## Conséquences

- Les revues de code peuvent se référer explicitement au PER plutôt qu’à des conventions locales implicites.
- Les contributions externes s’appuient sur une norme publique et versionnée.
