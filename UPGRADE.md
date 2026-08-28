# Monter de version

Toute rupture publique de ce dépôt vient avec sa procédure de migration : **Rector d'abord, un
script quand Rector ne peut pas, et de la documentation dans tous les cas.** Ce fichier est la
troisième moitié — il dit ce qui a bougé, et par quoi le rattraper.

```bash
composer require --dev gplanchat/durable-rector
```

```php
// rector.php
return Rector\Config\RectorConfig::configure()
    ->withImportNames()   // sans quoi les noms réécrits arrivent pleinement qualifiés, à côté d'un `use` périmé
    ->withSets([__DIR__ . '/vendor/gplanchat/durable-rector/config/sets/durable-upgrade.php']);
```

```bash
vendor/bin/rector process src
```

Le set est **cumulatif** : le passer une fois rattrape toutes les versions franchies d'un coup. Il
ne contient que ce que Rector sait faire sans deviner ; tout le reste est écrit à la main ci-dessous.

## 0.1.0-alpha8

### `PayloadToContractMethodInvoker` descend du bundle vers le cœur

`Gplanchat\Durable\Bundle\Activity\PayloadToContractMethodInvoker` devient
`Gplanchat\Durable\Activity\PayloadToContractMethodInvoker`.

**Pourquoi** — la classe adapte une charge utile (tableau, clés = noms des paramètres) vers la
méthode d'un contrat d'activité. Elle vivait dans le paquet du bundle Symfony **sans en importer une
ligne**, et l'intégration Magento en a besoin mot pour mot : son conteneur n'a pas les tags de
Symfony, mais une fois le contrat résolu l'adaptation est la même. Elle rejoint
`ActivityContractResolver`, qui la nourrit et qui était déjà dans le cœur.

**Ce que Rector fait** — le renommage, partout où le nom apparaît.

**⚠ Ce que Rector ne peut pas faire, et qu'il faut faire à la main** — vider le cache du conteneur :

```bash
bin/console cache:clear
```

Le nom pleinement qualifié est écrit dans le **conteneur compilé**. Sans ce vidage, une application
Symfony continue de demander l'ancien nom après la mise à jour, et la panne arrive au premier appel
d'activité — loin de sa cause, et sans que rien ne désigne le déplacement. C'est aussi pourquoi
Composer ne peut pas vous prévenir : il installe les deux paquets sans rien dire, et l'ancien nom
disparaît simplement.

**Si vous ne l'utilisiez pas directement**, vous n'aviez rien à faire dans votre code : la classe
n'était référencée que par la passe de compilation du bundle. Le vidage de cache, lui, reste
nécessaire.
