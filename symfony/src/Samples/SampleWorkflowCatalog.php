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
 * `workflowType` est l’**alias** Temporal (1er argument de `#[AsWorkflow]`, sinon nom court de classe) :
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
                'description' => 'One composeGreeting(name) activity call — the samples-php SimpleActivity equivalent.',
                'defaultPayload' => ['name' => 'World'],
            ],
            [
                'id' => 'activity_retry',
                'sourceFolder' => 'ActivityRetry',
                'label' => 'ActivityRetry',
                'workflowType' => self::workflowAlias(ActivityRetryGreetingWorkflow::class),
                'description' => 'A retry policy (max 5, backoff, non-retryable exceptions) on composeGreeting.',
                'defaultPayload' => ['name' => 'World'],
            ],
            [
                'id' => 'child',
                'sourceFolder' => 'Child',
                'label' => 'Child (parent → enfant)',
                'workflowType' => self::workflowAlias(SamplesParentCallsEchoChildWorkflow::class),
                'description' => 'A parent workflow calling a SamplesEchoChildWorkflow (echoUpper through an activity).',
                'defaultPayload' => ['text' => 'child'],
            ],
            [
                'id' => 'query',
                'sourceFolder' => 'Query',
                'label' => 'Query (timer + salutation)',
                'workflowType' => self::workflowAlias(SamplesQueryWorkflow::class),
                'description' => 'A durable 2 s pause then a greeting (Temporal client-side queries are not reproduced here).',
                'defaultPayload' => ['name' => 'World'],
            ],
            [
                'id' => 'signal',
                'sourceFolder' => 'Signal',
                'label' => 'Signal (approve → salutation)',
                'workflowType' => self::workflowAlias(SamplesSignalWorkflow::class),
                'description' => 'Awaits the "approve" signal with a { name } payload, then composeGreeting. The UI sends the signal automatically once suspended.',
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
                'description' => 'A greeting with a short start-to-close timeout (the semantic equivalent of Temporal\'s "local" sample).',
                'defaultPayload' => ['name' => 'World'],
            ],
            [
                'id' => 'polymorphic_activity',
                'sourceFolder' => 'PolymorphicActivity',
                'label' => 'PolymorphicActivity',
                'workflowType' => self::workflowAlias(PolymorphicGreetingWorkflow::class),
                'description' => 'Two activity contracts (hello / bye) with distinct activity names.',
                'defaultPayload' => ['name' => 'World'],
            ],
            [
                'id' => 'periodic',
                'sourceFolder' => 'Periodic',
                'label' => 'Periodic (boucle + timer)',
                'workflowType' => self::workflowAlias(PeriodicGreetingWorkflow::class),
                'description' => 'Several greetings with a durable pause between iterations (no continue-as-new).',
                'defaultPayload' => ['name' => 'World', 'iterations' => 3],
            ],
            [
                'id' => 'exception',
                'sourceFolder' => 'Exception',
                'label' => 'Exception (activity + catch)',
                'workflowType' => self::workflowAlias(ExceptionHandledWorkflow::class),
                'description' => 'An activity that fails on purpose; the workflow returns a "Caught: …" message.',
                'defaultPayload' => ['shouldFail' => true],
            ],
            [
                'id' => 'money_batch',
                'sourceFolder' => 'MoneyBatch',
                'label' => 'MoneyBatch (light)',
                'workflowType' => self::workflowAlias(MoneyBatchLightWorkflow::class),
                'description' => 'The sum of a list of cents through an activity (a simplified variant of the Temporal batch).',
                'defaultPayload' => ['parts' => [100, 200, 300]],
            ],
            [
                'id' => 'money_transfer',
                'sourceFolder' => 'MoneyTransfer',
                'label' => 'MoneyTransfer',
                'workflowType' => self::workflowAlias(AccountTransferWorkflow::class),
                'description' => 'A withdrawal then a deposit on two accounts (withdraw / deposit activities).',
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
                'label' => 'FileProcessing (light)',
                'workflowType' => self::workflowAlias(FileProcessingLightWorkflow::class),
                'description' => 'A download → process → upload chain (no dynamic per-worker queue).',
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
                'description' => 'A flight then a hotel; if the hotel fails, the flight is cancelled (`failHotel` is true by default).',
                'defaultPayload' => ['failHotel' => true],
            ],
            [
                'id' => 'cancellation_scope',
                'sourceFolder' => 'CancellationScope',
                'label' => 'CancellationScope (race)',
                'workflowType' => self::workflowAlias(CancellationScopeRaceWorkflow::class),
                'description' => 'Three greetings in parallel; the result of the first to finish (`any`).',
                'defaultPayload' => [],
            ],
            [
                'id' => 'mtls_hello_world',
                'sourceFolder' => 'MtlsHelloWorld',
                'label' => 'MtlsHelloWorld',
                'workflowType' => self::workflowAlias(MtlsHelloWorldWorkflow::class),
                'description' => 'A plain greeting (mTLS lives on the client and the Temporal infrastructure, not in the workflow).',
                'defaultPayload' => ['name' => 'World'],
            ],
            [
                'id' => 'updates',
                'sourceFolder' => 'Updates',
                'label' => 'Updates (#[AsUpdateMethod] + greeting)',
                'workflowType' => self::workflowAlias(SamplesUpdatesWorkflow::class),
                'description' => 'An #[AsUpdateMethod] "greet" answers and unblocks the body, then composeGreeting; the UI delivers the update automatically once suspended.',
                'defaultPayload' => [],
                'autoUpdate' => [
                    'name' => 'greet',
                    // La réponse n'est plus fournie par l'appelant : le handler la produit à
                    // partir de ces arguments.
                    'arguments' => ['name' => 'Temporal'],
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
