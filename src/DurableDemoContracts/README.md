# Contrats Nexus de la démonstration

Les contrats que les deux maquettes du dépôt se partagent : `sylius/` sert `stock` dans le
namespace `demo-boutique`, `symfony/` sert `facturation` dans `demo-metier`, et chacune appelle
l'autre. Un contrat Nexus s'écrit une fois et se lit des deux côtés de la frontière — il lui faut
donc un endroit qui n'appartienne à aucune des deux.

## Délibérément non publié

Ce paquet n'a pas de ligne dans `bin/splitsh-publish.sh`, pas de dépôt satellite, pas de jeton.
C'est une décision, pas un oubli.

Publier coûte une entrée dans la liste de contrôle des splits, un dépôt à créer, un PAT à
étendre — et surtout une promesse de compatibilité. Ses deux seuls consommateurs sont dans ce
dépôt, versionnés avec lui, mis à jour dans le même commit. Rien de tout cela n'achèterait quoi
que ce soit.

Les maquettes le déclarent donc en dépôt `path`, comme elles le font déjà pour les paquets du
cœur, et la racine le mappe dans son `autoload` sans le lister dans son `require`.

Si un jour un projet extérieur en a besoin, c'est qu'il n'était plus un contrat de démonstration.
L'ordre est alors celui de tous les autres satellites : créer le dépôt, étendre la portée du jeton,
et seulement ensuite ajouter la ligne dans `bin/splitsh-publish.sh` — l'inverse donne un 404 puis
un 403.

## La charge voyage telle qu'elle est écrite

Les opérations ne prennent et ne rendent que des scalaires et des tableaux. Ce n'est pas une
timidité de typage : la charge Nexus est du JSON simple, décodée en tableau associatif de l'autre
côté. Un objet passé en paramètre arriverait en tableau, et le gestionnaire lèverait un
`TypeError` — ce qui est aussi ce qui permet à un gestionnaire écrit en Go ou en TypeScript de
lire les mêmes champs.

## Les noms de paramètres sont l'interface

`NexusStub::argumentsToPayload()` clé la charge **par nom de paramètre** ; du côté servi,
`WorkflowDefinitionLoader::mapInputToArguments()` la relit par nom. Renommer un paramètre d'un
seul côté ne casse rien de visible : le workflow reçoit `null`, en silence.

Un paramètre de ce fichier se renomme donc des deux côtés, ou pas du tout.
