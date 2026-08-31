## Why

La démonstration à trois applications a prouvé une moitié, et l'a dite : **appeler ne demande rien à
l'hôte, servir se câble une fois par hôte**. La maquette Magento appelait sans qu'une ligne soit
ajoutée nulle part — et ne servait rien, précisément parce que servir demandait un registre de
gestionnaires et une file Nexus que son module n'a pas.

L'autre moitié n'a donc jamais tourné ailleurs que dans le conteneur de Symfony. Les deux
gestionnaires de la démonstration — `stock` et `facturation` — sont enregistrés par
`NexusHandlerPass`, une passe de compilation Symfony, et pollés par un transport Messenger. Un
lecteur pouvait raisonnablement conclure que la moitié servante **est** du Symfony.

Or `gplanchat/durable-laravel` livre déjà tout ce qu'il faut pour la servir ailleurs :
`DeclaredNexusOperations` lit `config/durable.php` là où Symfony lit des balises,
`durable:nexus-worker` poll la file, et `#[FulfilsNexusOperation]` marche par le même chemin.
**Rien de tout cela n'avait jamais tourné contre une vraie grappe** : la matrice `laravel` de la CI
éprouve la résolution et une suite unitaire, et la seule preuve du chemin servant restait Symfony.

## What Changes

Une quatrième maquette, `laravel/` — une application Laravel 12 ordinaire — qui **sert** un
troisième service, `livraison` :

- `planifier`, répondue sur la tâche par `LivraisonHandler`, déclaré dans `config/durable.php` ;
- `expedier`, remplie par `ExpedierWorkflow`, déclaré dans la même liste.

Et ce workflow **appelle pendant qu'il sert** : avant de sortir la marchandise, il redemande son
verdict à la boutique Sylius par `stock/reserver`, sur un endpoint qui n'est pas le sien. Une même
exécution porte donc une opération Nexus servie et une opération Nexus appelée, dans le même
journal, chez un hôte qui n'est ni Symfony ni Magento.

Le workflow appelant du banc Magento gagne les deux appels correspondants : une commande y est
désormais vérifiée, planifiée, réservée, encaissée, puis expédiée — cinq opérations, trois
endpoints, quatre applications.

## Impact

- `laravel/` : une application neuve, 59 fichiers suivis, `vendor/` ignoré comme pour les trois
  autres maquettes. Deux classes et six lignes de configuration sont tout ce qui la relie à Durable.
- `src/DurableDemoContracts/` : le contrat `livraison`, en deux interfaces comme les deux autres.
- `bin/demo-nexus` : quatre namespaces, **trois** endpoints — la maquette Laravel sert, donc elle en
  a un ; la maquette Magento n'en a toujours pas.
- `demo/lancer.sh` : huit processus, et une seconde variable de binaire PHP.
- `magento/` : le workflow appelant et sa commande, dont les mesures sont refaites.
- `documentation/user/nexus/` et `demo/README.md`.
- **Aucun changement aux paquets publiés.** Si l'un d'eux devait changer pour que Laravel serve,
  c'est que `gplanchat/durable-laravel` promettait ce qu'il ne tenait pas ; la §0 le dit avant que
  quoi que ce soit ne soit écrit.
