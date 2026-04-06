<?php

declare(strict_types=1);

namespace App\Samples;

use App\Samples\Workflow\ActivityRetry\ActivityRetryGreetingWorkflow;
use App\Samples\Workflow\BookingSaga\BookingSagaLightWorkflow;
use App\Samples\Workflow\CancellationScope\CancellationScopeRaceWorkflow;
use App\Samples\Workflow\Child\SamplesParentCallsEchoChildWorkflow;
use App\Samples\Workflow\Exception\ExceptionHandledWorkflow;
use App\Samples\Workflow\FileProcessing\FileProcessingLightWorkflow;
use App\Samples\Workflow\LocalActivity\LocalActivityGreetingWorkflow;
use App\Samples\Workflow\MoneyBatch\MoneyBatchLightWorkflow;
use App\Samples\Workflow\MoneyTransfer\AccountTransferWorkflow;
use App\Samples\Workflow\MtlsHelloWorld\MtlsHelloWorldWorkflow;
use App\Samples\Workflow\Periodic\PeriodicGreetingWorkflow;
use App\Samples\Workflow\PolymorphicActivity\PolymorphicGreetingWorkflow;
use App\Samples\Workflow\Query\SamplesQueryWorkflow;
use App\Samples\Workflow\Signal\SamplesSignalWorkflow;
use App\Samples\Workflow\SimpleActivity\SimpleActivityGreetingWorkflow;
use App\Samples\Workflow\Updates\SamplesUpdatesWorkflow;
use Gplanchat\Durable\Workflow\WorkflowDefinitionLoader;

/**
 * Scenarios ported from temporalio/samples-php — metadata for the Symfony samples UI.
 *
 * `workflowType` est l’**alias** Temporal (1er argument de `#[Workflow]`, sinon nom court de classe) :
 * c’est ce qui est envoyé au serveur Temporal et stocké dans le journal ; le {@see WorkflowRegistry}
 * accepte aussi le FQCN pour le dispatch.
 *
 * @phpstan-type Scenario array{
 *     id: string,
 *     sourceFolder: string,
 *     label: string,
 *     workflowType: string,
 *     description: string,
 *     defaultPayload: array<string, mixed>,
 *     autoSignal?: array{name: string, payload: array<string, mixed>},
 *     autoUpdate?: array{name: string, arguments?: array<string, mixed>, result?: mixed}
 * }
 */
final class SampleWorkflowCatalog
{
    /**
     * @return list<Scenario>
     */
    public static function scenarios(): array
    {
        return [
            [
                'id' => 'simple_activity',
                'sourceFolder' => 'SimpleActivity',
                'label' => 'SimpleActivity (Greeting)',
                'workflowType' => self::workflowAlias(SimpleActivityGreetingWorkflow::class),
                'description' => 'Un appel d’activité composeGreeting(name) — équivalent samples-php SimpleActivity.',
                'defaultPayload' => ['name' => 'World'],
            ],
            [
                'id' => 'activity_retry',
                'sourceFolder' => 'ActivityRetry',
                'label' => 'ActivityRetry',
                'workflowType' => self::workflowAlias(ActivityRetryGreetingWorkflow::class),
                'description' => 'Politique de retry (max 5, backoff, exceptions non réessayables) sur composeGreeting.',
                'defaultPayload' => ['name' => 'World'],
            ],
            [
                'id' => 'child',
                'sourceFolder' => 'Child',
                'label' => 'Child (parent → enfant)',
                'workflowType' => self::workflowAlias(SamplesParentCallsEchoChildWorkflow::class),
                'description' => 'Workflow parent qui appelle un SamplesEchoChildWorkflow (echoUpper via activité).',
                'defaultPayload' => ['text' => 'child'],
            ],
            [
                'id' => 'query',
                'sourceFolder' => 'Query',
                'label' => 'Query (timer + salutation)',
                'workflowType' => self::workflowAlias(SamplesQueryWorkflow::class),
                'description' => 'Pause durable 2 s puis salutation (les « queries » Temporal côté client ne sont pas reproduites ici).',
                'defaultPayload' => ['name' => 'World'],
            ],
            [
                'id' => 'signal',
                'sourceFolder' => 'Signal',
                'label' => 'Signal (approve → salutation)',
                'workflowType' => self::workflowAlias(SamplesSignalWorkflow::class),
                'description' => 'Attend le signal « approve » avec payload { name }, puis composeGreeting. L’UI envoie le signal automatiquement après suspension.',
                'defaultPayload' => [],
                'autoSignal' => [
                    'name' => 'approve',
                    'payload' => ['name' => 'Temporal'],
                ],
            ],
            [
                'id' => 'local_activity',
                'sourceFolder' => 'LocalActivity',
                'label' => 'LocalActivity',
                'workflowType' => self::workflowAlias(LocalActivityGreetingWorkflow::class),
                'description' => 'Salutation avec timeout start-to-close court (équivalent sémantique de l’exemple « local » Temporal).',
                'defaultPayload' => ['name' => 'World'],
            ],
            [
                'id' => 'polymorphic_activity',
                'sourceFolder' => 'PolymorphicActivity',
                'label' => 'PolymorphicActivity',
                'workflowType' => self::workflowAlias(PolymorphicGreetingWorkflow::class),
                'description' => 'Deux contrats d’activité (hello / bye) avec noms d’activité distincts.',
                'defaultPayload' => ['name' => 'World'],
            ],
            [
                'id' => 'periodic',
                'sourceFolder' => 'Periodic',
                'label' => 'Periodic (boucle + timer)',
                'workflowType' => self::workflowAlias(PeriodicGreetingWorkflow::class),
                'description' => 'Plusieurs salutations avec pause durable entre les itérations (sans continue-as-new).',
                'defaultPayload' => ['name' => 'World', 'iterations' => 3],
            ],
            [
                'id' => 'exception',
                'sourceFolder' => 'Exception',
                'label' => 'Exception (activité + catch)',
                'workflowType' => self::workflowAlias(ExceptionHandledWorkflow::class),
                'description' => 'Activité volontairement en échec ; le workflow retourne un message « Caught: … ».',
                'defaultPayload' => ['shouldFail' => true],
            ],
            [
                'id' => 'money_batch',
                'sourceFolder' => 'MoneyBatch',
                'label' => 'MoneyBatch (léger)',
                'workflowType' => self::workflowAlias(MoneyBatchLightWorkflow::class),
                'description' => 'Somme d’une liste de centimes via une activité (variante simplifiée du batch Temporal).',
                'defaultPayload' => ['parts' => [100, 200, 300]],
            ],
            [
                'id' => 'money_transfer',
                'sourceFolder' => 'MoneyTransfer',
                'label' => 'MoneyTransfer',
                'workflowType' => self::workflowAlias(AccountTransferWorkflow::class),
                'description' => 'Retrait puis dépôt sur deux comptes (activités withdraw / deposit).',
                'defaultPayload' => [
                    'fromAccountId' => 'from',
                    'toAccountId' => 'to',
                    'referenceId' => 'ref-demo',
                    'amountCents' => 100,
                ],
            ],
            [
                'id' => 'file_processing',
                'sourceFolder' => 'FileProcessing',
                'label' => 'FileProcessing (léger)',
                'workflowType' => self::workflowAlias(FileProcessingLightWorkflow::class),
                'description' => 'Chaîne download → process → upload (sans file d’attente dynamique par worker).',
                'defaultPayload' => [
                    'sourceUrl' => 'https://example.com/in/data.bin',
                    'destinationUrl' => 'https://example.com/out/data.bin',
                ],
            ],
            [
                'id' => 'booking_saga',
                'sourceFolder' => 'BookingSaga',
                'label' => 'BookingSaga (compensation)',
                'workflowType' => self::workflowAlias(BookingSagaLightWorkflow::class),
                'description' => 'Vol puis hôtel ; si l’hôtel échoue, annulation du vol (par défaut `failHotel` true).',
                'defaultPayload' => ['failHotel' => true],
            ],
            [
                'id' => 'cancellation_scope',
                'sourceFolder' => 'CancellationScope',
                'label' => 'CancellationScope (race)',
                'workflowType' => self::workflowAlias(CancellationScopeRaceWorkflow::class),
                'description' => 'Trois salutations en parallèle ; résultat du premier terminé (`any`).',
                'defaultPayload' => [],
            ],
            [
                'id' => 'mtls_hello_world',
                'sourceFolder' => 'MtlsHelloWorld',
                'label' => 'MtlsHelloWorld',
                'workflowType' => self::workflowAlias(MtlsHelloWorldWorkflow::class),
                'description' => 'Salutation simple (le mTLS est côté client / infra Temporal, pas dans le workflow).',
                'defaultPayload' => ['name' => 'World'],
            ],
            [
                'id' => 'updates',
                'sourceFolder' => 'Updates',
                'label' => 'Updates (waitUpdate + greeting)',
                'workflowType' => self::workflowAlias(SamplesUpdatesWorkflow::class),
                'description' => 'Attend l’update « greet » puis composeGreeting ; l’UI livre l’update automatiquement après suspension.',
                'defaultPayload' => [],
                'autoUpdate' => [
                    'name' => 'greet',
                    'arguments' => [],
                    'result' => 'Temporal',
                ],
            ],
        ];
    }

    /**
     * @param class-string $workflowClass
     */
    private static function workflowAlias(string $workflowClass): string
    {
        return (new WorkflowDefinitionLoader())->workflowTypeForClass($workflowClass);
    }

    /**
     * @return Scenario|null
     */
    public static function findById(string $id): ?array
    {
        foreach (self::scenarios() as $scenario) {
            if ($scenario['id'] === $id) {
                return $scenario;
            }
        }

        return null;
    }
}
