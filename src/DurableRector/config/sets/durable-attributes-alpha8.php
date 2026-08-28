<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Renaming\Rector\Name\RenameClassRector;

/**
 * L'alignement des attributs de déclaration, pour v0.1.0-alpha8.
 *
 * Le dépôt portait deux conventions. Le cœur nommait ses attributs de classe sans préfixe
 * (`#[Workflow]`, `#[Activity]`) ; le bundle Symfony en avait un seul, préfixé
 * (`#[AsDurableActivity]`), et ni le pont Illuminate ni le module Magento n'en avaient. Servir
 * Nexus demandait d'en ajouter, donc de choisir : `As*` l'emporte, et il dit ce qu'il dit — « cette
 * classe est enregistrée comme X ».
 *
 * Les attributs de **méthode** suivent. Le dépôt n'a plus qu'une convention : tout attribut de
 * déclaration porte `As`, qu'il marque une classe ou une méthode. Une seule règle à retenir vaut
 * mieux qu'une règle et son exception, même quand l'exception se lit un peu mieux.
 *
 * `AsDurableActivity` quitte le bundle pour le cœur au passage. Il déclarait une implémentation,
 * ce qu'aucun framework ne rend spécifique — et le laisser côté Symfony obligeait le pont
 * Illuminate à en inventer un autre pour dire la même chose.
 *
 * Usage, dans le `rector.php` d'un projet qui consomme Durable :
 *
 *     return Rector\Config\RectorConfig::configure()
 *         ->withImportNames()   // sans quoi les noms réécrits arrivent pleinement qualifiés
 *         ->withSets([__DIR__ . '/vendor/gplanchat/durable-rector/config/sets/durable-attributes-alpha8.php']);
 */
return RectorConfig::configure()
    ->withConfiguredRule(RenameClassRector::class, [
        // Déclaration de classe : le préfixe entre.
        'Gplanchat\Durable\Attribute\Workflow' => 'Gplanchat\Durable\Attribute\AsWorkflow',
        'Gplanchat\Durable\Attribute\Activity' => 'Gplanchat\Durable\Attribute\AsActivity',

        // Et l'isolé du bundle rejoint le cœur, sous le nom qui le met en paire avec
        // `AsNexusHandler` : les deux déclarent une implémentation par son contrat.
        'Gplanchat\Durable\Bundle\Attribute\AsDurableActivity' => 'Gplanchat\Durable\Attribute\AsActivityHandler',

        // Déclaration de méthode : le préfixe entre aussi.
        'Gplanchat\Durable\Attribute\ActivityMethod' => 'Gplanchat\Durable\Attribute\AsActivityMethod',
        'Gplanchat\Durable\Attribute\WorkflowMethod' => 'Gplanchat\Durable\Attribute\AsWorkflowMethod',
        'Gplanchat\Durable\Attribute\QueryMethod' => 'Gplanchat\Durable\Attribute\AsQueryMethod',
        'Gplanchat\Durable\Attribute\SignalMethod' => 'Gplanchat\Durable\Attribute\AsSignalMethod',
        'Gplanchat\Durable\Attribute\UpdateMethod' => 'Gplanchat\Durable\Attribute\AsUpdateMethod',
    ]);
