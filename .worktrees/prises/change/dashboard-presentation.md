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

**État** : en cours
