<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Magento\Runtime;

use Gplanchat\Bridge\Temporal\Grpc\TemporalHistoryCursor;
use Gplanchat\Bridge\Temporal\Store\TemporalWorkflowRunCatalog;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Bridge\Temporal\TemporalJournalEventStore;
use Gplanchat\Bridge\Temporal\Worker\WorkflowTaskProcessor;
use Gplanchat\Bridge\Temporal\Worker\WorkflowTaskRunner;
use Gplanchat\Bridge\Temporal\WorkflowServiceClientFactory;
use Gplanchat\Durable\Activity\ActivityContractResolver;
use Gplanchat\Durable\Activity\PayloadToContractMethodInvoker;
use Gplanchat\Durable\InMemoryWorkflowRunner;
use Gplanchat\Durable\Port\WorkflowRunCatalogInterface;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Store\InMemoryWorkflowRunCatalog;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;
use Gplanchat\Durable\WorkflowRegistry;
use Magento\Framework\App\DeploymentConfig;

/**
 * Assemble le moteur pour un processus Magento.
 *
 * Cinq objets, aucun framework : c'est exactement ce que `WorkflowTestEnvironment`
 * assemble pour un test, et c'est ce qui rend un hôte de palier 1 possible du tout.
 * Le composant ne demande ni conteneur ni bus ; ce qu'un hôte doit fournir, c'est
 * un endroit où poser ces cinq-là et de quoi les atteindre.
 *
 * Cette fabrique est délibérément un objet Magento ordinaire plutôt qu'un
 * `di.xml` qui câblerait les cinq : le runner prend deux scalaires en plus des
 * quatre dépendances, et les exprimer en `<argument>` les aurait éloignés de la
 * seule ligne qui explique ce qu'ils bornent.
 */
/*
 * Pas `final` : Magento engendre un `Interceptor` qui étend toute classe que son
 * conteneur instancie, pour porter les plugins. Une classe finale fait échouer la
 * compilation du conteneur — « cannot extend final class » — et le message ne dit
 * pas que c'est la faute du mot-clé. C'est la maison qui écrit `final` partout ;
 * ici l'hôte l'interdit, et le dire vaut mieux que de le laisser deviner.
 */
class RuntimeFactory
{
    /**
     * @param list<class-string> $workflowClasses    Les classes portant `#[Workflow]`, déclarées
     *                                              nommément : le conteneur de Magento n'a pas les
     *                                              tags de Symfony, donc rien ne les ramasse seul.
     * @param list<object>       $activityHandlers   Les gestionnaires d'activités. **Leur contrat
     *                                              ne se déclare pas** : on lit leurs interfaces et
     *                                              on garde celles qui portent des
     *                                              `#[ActivityMethod]`. Une déclaration de moins
     *                                              est une déclaration qu'on ne peut pas écrire de
     *                                              travers.
     * @param int                $maxActivityRetries Plafond quand une activité n'en fixe pas. `0`
     *                                              ne plafonne rien — et une activité sans
     *                                              `RetryLimit` réessaie indéfiniment, ce qui est
     *                                              le défaut de Temporal.
     * @param float              $budgetSeconds      Borne globale d'une exécution. Elle existe
     *                                              parce que le point précédent rend « ça ne finit
     *                                              jamais » atteignable sans erreur.
     */
    private const TEMPORAL_DSN_CONFIG_PATH = 'durable/temporal/dsn';

    public function __construct(
        private readonly array $workflowClasses = [],
        private readonly array $activityHandlers = [],
        private readonly ?string $temporalDsn = null,
        /**
         * Lu depuis `env.php`, à côté de `lock` et `queue` : c'est là que Magento range ce qui
         * doit être lisible avant qu'une base réponde. Nullable et par défaut absent pour que la
         * fabrique reste construisible **sans Magento** — c'est ce qui met la décision de backend
         * sous la garde de la CI, là où le reste du module demande un banc.
         */
        private readonly ?DeploymentConfig $deploymentConfig = null,
        private readonly int $maxActivityRetries = 0,
        private readonly float $budgetSeconds = InMemoryWorkflowRunner::DEFAULT_BUDGET_SECONDS,
    ) {}

    public function create(): MagentoRuntime
    {
        $eventStore = $this->eventStore();
        $transport = new InMemoryActivityTransport();
        $activities = new RegistryActivityExecutor();
        $workflows = new WorkflowRegistry();

        $runtime = new MagentoRuntime(
            $eventStore,
            $activities,
            $workflows,
            new InMemoryWorkflowRunner(
                $eventStore,
                $transport,
                $activities,
                $this->maxActivityRetries,
                $workflows,
                $this->budgetSeconds,
            ),
        );

        foreach ($this->workflowClasses as $workflowClass) {
            $runtime->registerWorkflow($workflowClass);
        }

        foreach ($this->activityHandlers as $handler) {
            $this->registerContractsOf($runtime, $handler);
        }

        return $runtime;
    }

    /**
     * Où vit le journal, et qui le décide.
     *
     * La 2.3 a retiré la surface de configuration du backend : ce n'est donc pas un nom recopié
     * qui choisit, c'est **la présence d'un DSN** sous `durable/temporal/dsn` dans `env.php`.
     * Absent, le journal vit dans ce processus et meurt avec lui — ce qui est un choix légitime
     * pour une commande, et ruineux pour un consommateur. Présent, il vit dans le cluster, et
     * c'est le seul journal persistant que Magento atteigne : l'hôte ne livre aucun des deux
     * types de connexion auxquels les ponts SQL se lient.
     */
    private function eventStore(): EventStoreInterface
    {
        $settings = $this->temporalSettings();

        return $settings === null
            ? new InMemoryEventStore()
            : new TemporalJournalEventStore(WorkflowServiceClientFactory::create($settings), $settings);
    }

    /**
     * Ce que l'écran d'administration interroge, et pourquoi ce n'est pas le magasin d'événements.
     *
     * Un catalogue ne se **dérive pas** d'un journal : `InMemoryWorkflowRunCatalog` tient sa propre
     * carte, alimentée par `recordStart()`/`recordOutcome()` dans le processus qui exécute. Une
     * requête d'administration n'exécute rien — elle n'a donc rien à y lire, et une grille bâtie
     * dessus est vide sans être en panne. Lister les exécutions d'une grappe, c'est demander à la
     * grappe, et le pont livre déjà la classe qui sait le faire.
     */
    public function catalog(): WorkflowRunCatalogInterface
    {
        $settings = $this->temporalSettings();

        if ($settings === null) {
            return new InMemoryWorkflowRunCatalog(new InMemoryEventStore());
        }

        $client = WorkflowServiceClientFactory::create($settings);

        // Le curseur d'historique n'est pas décoratif : `listRuns()` ne rend que le statut du
        // workflow Temporal — celui du journal, qui est **long par construction** et donc
        // éternellement `running`. Ce qui distingue une exécution finie d'une exécution en cours se
        // lit dans ses événements, et c'est le curseur qui les donne.
        return new TemporalWorkflowRunCatalog(
            $client,
            $settings,
            new TemporalHistoryCursor($client, $settings->namespace->name()),
        );
    }

    /**
     * Le worker qui répond aux tâches de la file du journal.
     *
     * Sans lui, une exécution appendue au cluster y reste `running` pour toujours : le journal
     * existe, son historique se remplit, et personne ne le fait avancer. C'est exactement ce que
     * la grille du back-office montrait — et elle avait raison de le montrer.
     *
     * Les quatre objets viennent du pont, et l'assemblage est le même que celui du transport
     * Messenger côté Symfony. Ce qui change ici, c'est seulement qui tourne la boucle : une
     * commande `bin/magento`, drainée par ce qu'un exploitant supervise déjà, plutôt qu'un
     * `messenger:consume`.
     */
    public function journalWorker(): WorkflowTaskProcessor
    {
        $settings = $this->temporalSettings();

        if ($settings === null) {
            throw new \RuntimeException(
                'A journal worker needs a cluster to poll. Set durable/temporal/dsn in app/etc/env.php first — without it the journal lives in the process that writes it, and a worker would poll a queue that does not exist while looking perfectly healthy.',
            );
        }

        $client = WorkflowServiceClientFactory::create($settings);
        $registry = new WorkflowRegistry();
        foreach ($this->workflowClasses as $workflowClass) {
            $registry->registerClass($workflowClass);
        }

        return new WorkflowTaskProcessor(
            $client,
            $settings,
            new WorkflowTaskRunner(
                new TemporalHistoryCursor($client, $settings->namespace->name()),
                $registry,
                $settings,
            ),
        );
    }

    /**
     * Le journal de cet hôte vit-il dans une grappe ?
     */
    public function hasCluster(): bool
    {
        return $this->temporalSettings() !== null;
    }

    /**
     * `null` quand aucun DSN n'est configuré : le journal vit alors dans ce processus.
     */
    private function temporalSettings(): ?TemporalConnection
    {
        $dsn = $this->temporalDsn ?? $this->configuredDsn();

        if ($dsn === null || $dsn === '') {
            return null;
        }

        if (!\class_exists(TemporalConnection::class)) {
            throw new \RuntimeException(\sprintf(
                'A Temporal DSN is configured under durable/temporal/dsn, but %s is not installed. Run `composer require gplanchat/durable-bridge-temporal`, or remove the DSN to keep the journal in the process.',
                'gplanchat/durable-bridge-temporal',
            ));
        }

        return TemporalConnection::fromDsn($dsn);
    }

    private function configuredDsn(): ?string
    {
        $configured = $this->deploymentConfig?->get(self::TEMPORAL_DSN_CONFIG_PATH);

        return \is_string($configured) ? $configured : null;
    }

    /**
     * Le pendant Magento de la passe de compilation du bundle : mêmes deux objets du cœur,
     * `ActivityContractResolver` pour les noms et `PayloadToContractMethodInvoker` pour l'appel.
     * Ce qui change est seulement d'où vient la liste — un argument de `di.xml` plutôt qu'un tag.
     */
    private function registerContractsOf(MagentoRuntime $runtime, object $handler): void
    {
        $resolver = new ActivityContractResolver();

        foreach (\class_implements($handler) ?: [] as $contract) {
            foreach ($resolver->resolveActivityMethods($contract) as $method => $activityName) {
                $runtime->registerActivity(
                    $activityName,
                    new PayloadToContractMethodInvoker($handler, $contract, $method),
                );
            }
        }
    }
}
