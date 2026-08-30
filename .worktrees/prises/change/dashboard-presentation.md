# change/dashboard-presentation

Worktree : `<scratchpad de session>/wt-dashboard-presentation`.

**Chantier** : un format de présentation commun aux tableaux de bord Durable de
tous les hôtes — Sylius (`durable-plugin`) et Magento (`durable-magento`)
aujourd'hui, Filament et API Platform demain.

**Entrées** : le delta de spec sur `workflow-run-observation`, et la promotion de
`RunDashboardView` — aujourd'hui dans `src/DurablePlugin/Dashboard/` — vers le
cœur, d'où Magento et les surfaces suivantes le consomment.

Trois décisions tranchées par l'auteur avant d'écrire :
1. **trois** états de backend, pas deux — le troisième est *joignable mais
   structurellement éphémère*, que seul Magento sait dire aujourd'hui ;
2. les compteurs portent sur **la page courante**, assumé et **nommé** comme tel ;
3. le **tiret cadratin** est le rendu légitime de l'absence dans une table à
   colonnes fixes.

✅ **Les six tâches sont faites — PR #228.** Le contrat de présentation (delta de
spec sur `workflow-run-observation`), la projection au cœur, les deux hôtes qui la
consomment, les compteurs et les absences, le balayage du vocabulaire « voies »,
`DUR049` et la page `documentation/user/dashboard/`.

⚠ **L'ADR est `DUR049`, pas `DUR048`** : une autre session a pris le 048 pour la
mesure d'audience et l'a fait atterrir la première. Le numéro se réserve à la
fusion, pas à l'écriture — c'est le seul conflit qu'ait produit cette branche.

Ce que la branche a appris, et qui vaut d'être relu :

⚠ La projection rend des **secondes**, jamais des pourcentages : le bloc Magento
émettait des largeurs CSS, et le cœur se serait mis à dessiner pour une surface
qui ne rend aucun balisage. Mettre à l'échelle reste chez l'hôte.

⚠ `ProcessDetail::getTimeline()` n'avait **aucun test** — les dix-huit cas sont sa
première couverture, pas un déménagement. Et **aucun outil de la CI n'analyse un
`.phtml`** : trois suites de rendu couvrent désormais les gabarits, vérifiées par
mutation.

⚠ Le worktree n'a pas de `vendor` : bootstrap dans le scratchpad de session, qui
reprend l'autoloader de la copie principale et rebranche **tous** les espaces
`Gplanchat\` vers le worktree. Les échecs `DurablePhpstan`, `DurableLaravel` et
`Bridge/Illuminate` sont de l'environnement (illuminate absent du vendor
principal), pas du change — la CI les couvre.

**État** : en relecture
