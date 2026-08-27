# Prises en cours

Plusieurs sessions partagent cette copie. Une tranche se prend en **créant son fichier** dans
`prises/`, **avant** de commencer, et ce fichier se supprime à la fusion. Son absence est ce qui a
permis à deux sessions de se croiser sur `workflow-conditions-and-handler-dispatch` le 2026-08-26.

Le chemin du fichier **est** le nom de la branche : `prises/test/bundle-integration-suite.md` pour
la branche `test/bundle-integration-suite`. Rien à choisir, rien à écrire deux fois.

## Pourquoi un fichier et non une ligne

Le registre a été une table dans ce fichier jusqu'au 2026-08-27, et la table était un aimant à
conflits : trois rebasages en une heure sur une seule PR, à chaque fois parce qu'une autre session
avait touché **une autre ligne** de la même table. Deux sessions n'écrivent jamais la même prise —
c'est tout l'objet du registre — mais git ne voit qu'un fichier, et deux lignes voisines suffisent
à le faire trancher.

Un fichier par prise supprime le conflit par construction : ajouter une prise crée un fichier, la
retirer le supprime. Deux sessions ne se marchent dessus que si elles prennent **la même branche** —
et là, git s'arrête sur un conflit ajout/ajout, ce qui est exactement le service qu'on attend du
registre.

## Ce que la mécanique ne fait pas toute seule

**Une prise se pousse sur `main` avant de commencer, et s'y retire en refermant.** Un fichier créé
dans un worktree n'existe que là : personne ne le voit avant la fusion, c'est-à-dire une fois le
travail fait. Le 2026-08-26, neuf tranches ont été construites en double pour cette raison — le
bloc 4 entier de `temporal-nexus-support` deux fois, la même classe sous le même nom, la même garde
écrite à l'identique. Le registre n'empêche une collision que s'il est lu **et écrit** sur `main`.

> **Et le dépôt le permet.** La protection de `main` n'exige que des status checks
> (`QA (CS + tests)` 8.2→8.5, `Analyse statique`, `strict: true`) et laisse `enforce_admins` à
> `false` : le propriétaire pousse en direct. Les commits `docs(prises):` de l'historique sont là
> pour le prouver — `d0f614d`, `8180b45`, `a04420b` n'ont pas de commit de fusion.
>
> Passer une prise par une PR la rend visible **après** coup, plusieurs minutes plus tard. C'est
> exactement le retard que le paragraphe ci-dessus accuse d'avoir produit neuf tranches en double.
> Une session qui s'interdit le push direct par convention doit savoir ce qu'elle échange contre
> quoi, plutôt que de croire à une limite technique.

Et une prise qu'on ne retire pas ment aussi longtemps qu'elle reste. Le 2026-08-27, le registre
annonçait encore quatre tranches « en cours » ou « en relecture » sur un chantier **archivé**, dont
les quatre branches avaient disparu du distant depuis longtemps. Un registre périmé est pire qu'un
registre vide : il fait renoncer à une tranche libre. **Retirer sa prise fait partie de la
fusion**, au même titre que supprimer sa branche et démonter son worktree.

## Le contrôle, et ce qu'il ne couvre pas

`bin/prises-check.sh` attrape les prises périmées. Il tourne sur chaque PR qui touche au registre,
et une fois par jour pour le reste — un retrait oublié n'apparaît dans aucune PR, par définition.

**Le critère est la PR, pas la branche.** Une prise se pose *avant* que la branche existe sur le
distant : la comparer aux branches vivantes la ferait rougir sur le cas normal, et un contrôle qui
rougit sur le cas normal se fait désarmer dans la semaine. Une prise est périmée quand sa branche a
au moins une PR fermée et **aucune** PR ouverte.

Il vérifie aussi que le titre du fichier dit la même branche que son chemin, parce que le registre
se lit à l'œil autant qu'avec un script.

**Ce qu'il ne voit pas :** une prise vivante supprimée par mégarde. Le 2026-08-27, un rebasage plus
vieux qu'une prise a bien failli l'emporter — la table conflictait, mais la résolution était
devenue machinale à force d'être la même. Rien n'aurait rougi, et c'est la session qui la tenait
qui l'a vu. Si tu supprimes une prise, regarde `main` au moment de refermer plutôt que de le
supposer.

## La forme d'un fichier de prise

```markdown
# <branche>

- **Chantier** : ce qu'on fait, en une ligne
- **Entrées** : les fichiers ou dossiers touchés
- **État** : en cours | en relecture
```

## Lire le registre

Le chemin porte le slash de la branche, donc un répertoire par préfixe (`change/`, `docs/`,
`fix/`…). `ls` ne montre alors que les préfixes ; c'est `find` qui rend la vue d'ensemble que la
table donnait d'un coup d'œil :

```
find .worktrees/prises -name '*.md'
```
