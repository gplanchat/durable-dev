<?php

declare(strict_types=1);

use Gplanchat\Durable\Activity\PayloadToContractMethodInvoker;
use Rector\Config\RectorConfig;
use Rector\Renaming\Rector\Name\RenameClassRector;

/**
 * Ce qui bouge **à l'intérieur** de Durable, d'une version à la suivante.
 *
 * `temporal-sdk.php` fait entrer un projet dans Durable ; celui-ci l'y fait avancer. Ils sont
 * séparés parce qu'on ne les charge pas au même moment : le premier une fois, le second à chaque
 * montée de version.
 *
 * La règle du dépôt est explicite — **toute rupture publique vient avec sa procédure de
 * migration** : Rector d'abord, un script quand Rector ne peut pas, et de la documentation dans
 * tous les cas. Un renommage de classe est précisément le cas où Rector peut, donc il n'y a pas
 * d'excuse à laisser un projet le découvrir par une erreur d'autochargement.
 *
 * ⚠ Les déplacements de classe **entre paquets** ne se voient pas à l'installation : Composer
 * installe les deux paquets sans rien dire, et l'ancien nom disparaît simplement. Pire pour un
 * projet Symfony, dont le **conteneur compilé** garde le nom pleinement qualifié : la panne arrive
 * au premier appel après une mise à jour sans vidage de cache, loin de sa cause.
 */
return RectorConfig::configure()
    ->withConfiguredRule(RenameClassRector::class, [
        // 0.1.0-alpha8 — l'adaptateur charge utile → méthode de contrat descend du paquet du
        // bundle vers le cœur : il n'importait rien de Symfony, et Magento en a besoin mot pour
        // mot. Après cette montée, vider le cache du conteneur (`bin/console cache:clear`), sans
        // quoi le conteneur compilé continue de demander l'ancien nom.
        'Gplanchat\Durable\Bundle\Activity\PayloadToContractMethodInvoker' => PayloadToContractMethodInvoker::class,
    ]);
