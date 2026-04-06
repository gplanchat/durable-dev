<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Durable\DurableSampleWorkflowRunner;
use App\Samples\SampleWorkflowCatalog;
use App\Samples\Workflow\ActivityRetry\ActivityRetryGreetingWorkflow;
use App\Samples\Workflow\BookingSaga\BookingSagaLightWorkflow;
use App\Samples\Workflow\CancellationScope\CancellationScopeRaceWorkflow;
use App\Samples\Workflow\Child\SamplesEchoChildWorkflow;
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
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Intégration Messenger in-memory (APP_ENV=test) : chaque workflow sous {@see \App\Samples\Workflow}
 * listé dans {@see SampleWorkflowCatalog} + {@see SamplesEchoChildWorkflow} enfant seul.
 *
 * Après modification du routage Messenger (`config/packages/messenger.yaml`), exécuter
 * `php bin/console cache:clear --env=test` (ou supprimer `var/cache/test`) pour que les
 * transports et le routage `FireWorkflowTimersMessage` soient à jour.
 *
 * @internal
 */
#[Group('integration')]
#[Group('sample-workflows')]
final class SampleWorkflowsIntegrationTest extends KernelTestCase
{
    private function runner(): DurableSampleWorkflowRunner
    {
        return self::getContainer()->get(DurableSampleWorkflowRunner::class);
    }

    private function aliasFor(string $workflowClass): string
    {
        return (new WorkflowDefinitionLoader())->workflowTypeForClass($workflowClass);
    }

    public function testSimpleActivityGreetingWorkflow(): void
    {
        self::bootKernel();
        $s = SampleWorkflowCatalog::findById('simple_activity');
        self::assertNotNull($s);
        $out = $this->runner()->runAndSettle($s['workflowType'], $s['defaultPayload']);
        self::assertSame('Hello, World!', $out['result']);
    }

    public function testActivityRetryGreetingWorkflow(): void
    {
        self::bootKernel();
        $s = SampleWorkflowCatalog::findById('activity_retry');
        self::assertNotNull($s);
        $out = $this->runner()->runAndSettle($s['workflowType'], $s['defaultPayload']);
        self::assertSame('Hello, World!', $out['result']);
    }

    public function testSamplesParentCallsEchoChildWorkflow(): void
    {
        self::bootKernel();
        $s = SampleWorkflowCatalog::findById('child');
        self::assertNotNull($s);
        $out = $this->runner()->runAndSettle($s['workflowType'], $s['defaultPayload']);
        self::assertSame('CHILD', $out['result']);
    }

    /**
     * Workflow enfant seul (hors entrée catalogue dédiée) : echoUpper via activité.
     */
    public function testSamplesEchoChildWorkflow(): void
    {
        self::bootKernel();
        $out = $this->runner()->runAndSettle(
            $this->aliasFor(SamplesEchoChildWorkflow::class),
            ['text' => 'hello'],
        );
        self::assertSame('HELLO', $out['result']);
    }

    public function testSamplesQueryWorkflow(): void
    {
        self::bootKernel();
        $s = SampleWorkflowCatalog::findById('query');
        self::assertNotNull($s);
        $out = $this->runner()->runAndSettle($s['workflowType'], $s['defaultPayload']);
        self::assertSame('Hello, World!', $out['result']);
    }

    public function testSamplesSignalWorkflow(): void
    {
        self::bootKernel();
        $s = SampleWorkflowCatalog::findById('signal');
        self::assertNotNull($s);
        self::assertArrayHasKey('autoSignal', $s);
        $out = $this->runner()->runAndSettleWithAutoSignal(
            $s['workflowType'],
            $s['defaultPayload'],
            $s['autoSignal']['name'],
            $s['autoSignal']['payload'],
        );
        self::assertSame('Hello, Temporal!', $out['result']);
    }

    public function testLocalActivityGreetingWorkflow(): void
    {
        self::bootKernel();
        $s = SampleWorkflowCatalog::findById('local_activity');
        self::assertNotNull($s);
        $out = $this->runner()->runAndSettle($s['workflowType'], $s['defaultPayload']);
        self::assertSame('Hello, World!', $out['result']);
    }

    public function testPolymorphicGreetingWorkflow(): void
    {
        self::bootKernel();
        $s = SampleWorkflowCatalog::findById('polymorphic_activity');
        self::assertNotNull($s);
        $out = $this->runner()->runAndSettle($s['workflowType'], $s['defaultPayload']);
        self::assertSame(['Hello, World!', 'Bye, World!'], $out['result']);
    }

    public function testPeriodicGreetingWorkflow(): void
    {
        self::bootKernel();
        $s = SampleWorkflowCatalog::findById('periodic');
        self::assertNotNull($s);
        $out = $this->runner()->runAndSettle($s['workflowType'], $s['defaultPayload']);
        self::assertSame(
            [
                'Hello, World #1!',
                'Hello, World #2!',
                'Hello, World #3!',
            ],
            $out['result'],
        );
    }

    public function testExceptionHandledWorkflow(): void
    {
        self::bootKernel();
        $s = SampleWorkflowCatalog::findById('exception');
        self::assertNotNull($s);
        $out = $this->runner()->runAndSettle($s['workflowType'], $s['defaultPayload']);
        self::assertIsString($out['result']);
        self::assertStringStartsWith('Caught: ', $out['result']);
        self::assertStringContainsString('Activity failed on purpose', $out['result']);
    }

    public function testMoneyBatchLightWorkflow(): void
    {
        self::bootKernel();
        $s = SampleWorkflowCatalog::findById('money_batch');
        self::assertNotNull($s);
        $out = $this->runner()->runAndSettle($s['workflowType'], $s['defaultPayload']);
        self::assertSame(600, $out['result']);
    }

    public function testAccountTransferWorkflow(): void
    {
        self::bootKernel();
        $s = SampleWorkflowCatalog::findById('money_transfer');
        self::assertNotNull($s);
        $out = $this->runner()->runAndSettle($s['workflowType'], $s['defaultPayload']);
        self::assertSame('transfer_ok', $out['result']);
    }

    public function testFileProcessingLightWorkflow(): void
    {
        self::bootKernel();
        $s = SampleWorkflowCatalog::findById('file_processing');
        self::assertNotNull($s);
        $out = $this->runner()->runAndSettle($s['workflowType'], $s['defaultPayload']);
        self::assertSame('OK:processed-file-data.bin→data.bin', $out['result']);
    }

    public function testBookingSagaLightWorkflowCompensatesWhenHotelFails(): void
    {
        self::bootKernel();
        $s = SampleWorkflowCatalog::findById('booking_saga');
        self::assertNotNull($s);
        $out = $this->runner()->runAndSettle($s['workflowType'], $s['defaultPayload']);
        self::assertIsString($out['result']);
        self::assertStringStartsWith('compensated: ', $out['result']);
        self::assertStringContainsString('Hotel unavailable', $out['result']);
    }

    public function testCancellationScopeRaceWorkflow(): void
    {
        self::bootKernel();
        $s = SampleWorkflowCatalog::findById('cancellation_scope');
        self::assertNotNull($s);
        $out = $this->runner()->runAndSettle($s['workflowType'], $s['defaultPayload']);
        self::assertContains(
            $out['result'],
            ['Hello, A!', 'Hello, B!', 'Hello, C!'],
        );
    }

    public function testMtlsHelloWorldWorkflow(): void
    {
        self::bootKernel();
        $s = SampleWorkflowCatalog::findById('mtls_hello_world');
        self::assertNotNull($s);
        $out = $this->runner()->runAndSettle($s['workflowType'], $s['defaultPayload']);
        self::assertSame('Hello, World!', $out['result']);
    }

    public function testSamplesUpdatesWorkflow(): void
    {
        self::bootKernel();
        $s = SampleWorkflowCatalog::findById('updates');
        self::assertNotNull($s);
        self::assertArrayHasKey('autoUpdate', $s);
        $out = $this->runner()->runAndSettleWithAutoUpdate(
            $s['workflowType'],
            $s['defaultPayload'],
            $s['autoUpdate']['name'],
            $s['autoUpdate']['arguments'] ?? [],
            $s['autoUpdate']['result'],
        );
        self::assertSame('Hello, Temporal!', $out['result']);
    }

    /**
     * Couverture explicite : chaque classe sous App\Samples\Workflow a un test ci-dessus ou ici (alias).
     */
    public function testWorkflowAliasesMatchCatalog(): void
    {
        self::bootKernel();
        $expected = [
            SimpleActivityGreetingWorkflow::class,
            ActivityRetryGreetingWorkflow::class,
            SamplesParentCallsEchoChildWorkflow::class,
            SamplesEchoChildWorkflow::class,
            SamplesQueryWorkflow::class,
            SamplesSignalWorkflow::class,
            LocalActivityGreetingWorkflow::class,
            PolymorphicGreetingWorkflow::class,
            PeriodicGreetingWorkflow::class,
            ExceptionHandledWorkflow::class,
            MoneyBatchLightWorkflow::class,
            AccountTransferWorkflow::class,
            FileProcessingLightWorkflow::class,
            BookingSagaLightWorkflow::class,
            CancellationScopeRaceWorkflow::class,
            MtlsHelloWorldWorkflow::class,
            SamplesUpdatesWorkflow::class,
        ];
        $loader = new WorkflowDefinitionLoader();
        foreach ($expected as $class) {
            $alias = $loader->workflowTypeForClass($class);
            self::assertTrue(
                $this->runner()->hasWorkflow($alias),
                \sprintf('Workflow type "%s" (%s) doit être enregistré.', $alias, $class),
            );
        }
    }
}
