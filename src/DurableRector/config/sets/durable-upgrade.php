<?php

declare(strict_types=1);

use Gplanchat\Durable\Activity\PayloadToContractMethodInvoker;
use Gplanchat\Durable\Timer\TimerWakeDelayCalculator;
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
        // 0.1.0-alpha8 — le calcul du prochain réveil de minuterie descend lui aussi au cœur, et
        // pour une raison plus grave : `InMemoryWorkflowRunner`, qui **est** du cœur, l'appelait.
        // Un hôte qui n'installe pas le bundle prenait une erreur fatale à la première reprise.
        'Gplanchat\Durable\Bundle\Messenger\TimerWakeDelayCalculator' => TimerWakeDelayCalculator::class,

        // 0.1.0-alpha8 — tout attribut de déclaration prend le préfixe `As`. Le dépôt en portait
        // deux conventions : le cœur nommait ses attributs sans préfixe, le bundle Symfony en avait
        // un seul, préfixé, et ni le pont Illuminate ni le module Magento n'en avaient. Servir Nexus
        // demandait d'en ajouter, donc de choisir. Les attributs de méthode suivent, pour qu'il n'y
        // ait qu'une règle à retenir plutôt qu'une règle et son exception.
        'Gplanchat\Durable\Attribute\Workflow' => 'Gplanchat\Durable\Attribute\AsWorkflow',
        'Gplanchat\Durable\Attribute\Activity' => 'Gplanchat\Durable\Attribute\AsActivity',
        'Gplanchat\Durable\Attribute\WorkflowMethod' => 'Gplanchat\Durable\Attribute\AsWorkflowMethod',
        'Gplanchat\Durable\Attribute\ActivityMethod' => 'Gplanchat\Durable\Attribute\AsActivityMethod',
        'Gplanchat\Durable\Attribute\QueryMethod' => 'Gplanchat\Durable\Attribute\AsQueryMethod',
        'Gplanchat\Durable\Attribute\SignalMethod' => 'Gplanchat\Durable\Attribute\AsSignalMethod',
        'Gplanchat\Durable\Attribute\UpdateMethod' => 'Gplanchat\Durable\Attribute\AsUpdateMethod',

        // Et le second déplacement entre paquets de cette version, exactement le même piège que
        // celui du dessus : l'attribut de déclaration d'une implémentation d'activité quitte le
        // bundle pour le cœur, sous le nom qui le met en paire avec `AsNexusServiceHandler`.
        // Il est **lu par une passe de compilation**, donc le conteneur compilé le garde.
        'Gplanchat\Durable\Bundle\Attribute\AsDurableActivity' => 'Gplanchat\Durable\Attribute\AsActivityHandler',
    ]);
