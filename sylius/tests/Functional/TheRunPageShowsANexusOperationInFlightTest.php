<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\User\AdminUser;
use Doctrine\ORM\EntityManagerInterface;
use Gplanchat\Bridge\Temporal\Grpc\TemporalHistoryCursor;
use Gplanchat\Bridge\Temporal\Grpc\WorkflowServiceExecutionRpc;
use Gplanchat\Bridge\Temporal\Store\TemporalWorkflowRunCatalog;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Bridge\Temporal\Worker\TemporalWorkflowCommandBuffer;
use Gplanchat\Bridge\Temporal\WorkflowClient;
use Gplanchat\Bridge\Temporal\WorkflowServiceClientFactory;
use Gplanchat\Durable\Duration;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Nexus\NexusEndpoint;
use Gplanchat\Durable\Nexus\NexusOperationHeaders;
use Gplanchat\Durable\Nexus\NexusOperationName;
use Gplanchat\Durable\Nexus\NexusOperationTimeouts;
use Gplanchat\Durable\Nexus\NexusService;
use Gplanchat\Durable\Observation\RunDashboard;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Temporal\Api\Common\V1\WorkflowExecution;
use Temporal\Api\Nexus\V1\EndpointSpec;
use Temporal\Api\Nexus\V1\EndpointTarget;
use Temporal\Api\Nexus\V1\EndpointTarget\Worker;
use Temporal\Api\Operatorservice\V1\CreateNexusEndpointRequest;
use Temporal\Api\Operatorservice\V1\CreateNexusEndpointResponse;
use Temporal\Api\Operatorservice\V1\DeleteNexusEndpointRequest;
use Temporal\Api\Operatorservice\V1\DeleteNexusEndpointResponse;
use Temporal\Api\Taskqueue\V1\TaskQueue;
use Temporal\Api\Workflowservice\V1\PollWorkflowTaskQueueRequest;
use Temporal\Api\Workflowservice\V1\RespondWorkflowTaskCompletedRequest;
use Temporal\Api\Workflowservice\V1\TerminateWorkflowExecutionRequest;

/**
 * The shop's admin shows a run's Nexus operation in flight, read from a real Temporal server (#671).
 *
 * A journal cannot hold Nexus (DUR036), so the page reads the Temporal catalog of the server in
 * DURABLE_TEMPORAL_DSN rather than the shop's SQL one. The run is real: started on the server, its
 * workflow task answered with a Nexus operation toward an endpoint whose queue nobody polls, so the
 * operation stays in flight while the real Sylius kernel renders the page.
 */
final class TheRunPageShowsANexusOperationInFlightTest extends WebTestCase
{
    private const OPERATOR = '/temporal.api.operatorservice.v1.OperatorService/';

    public function testTheRunPageShowsWhereTheOperationWaitsAndThatItIsInFlight(): void
    {
        $dsn = (string) (getenv('DURABLE_TEMPORAL_DSN') ?: '');
        if ('' === $dsn) {
            self::markTestSkipped('Set DURABLE_TEMPORAL_DSN to a Temporal server with Nexus on.');
        }
        $server = TemporalConnection::fromDsn($dsn);
        $suffix = bin2hex(random_bytes(4));
        $connection = new TemporalConnection(target: $server->target, namespace: $server->namespace, identity: 'sylius-nexus-page', workflowTaskQueue: 'nexus-page-' . $suffix, transport: $server->transport);
        $grpc = WorkflowServiceClientFactory::create($connection);
        $transport = WorkflowServiceClientFactory::createTransport($connection);
        $endpointName = 'nexus-page-' . $suffix;
        $executionId = 'nexus-page/' . $suffix;

        $endpoint = $transport->unary(self::OPERATOR . 'CreateNexusEndpoint', new CreateNexusEndpointRequest(['spec' => new EndpointSpec([
            'name' => $endpointName,
            'target' => new EndpointTarget(['worker' => new Worker(['namespace' => $connection->namespace->name(), 'task_queue' => 'nobody-polls-' . $suffix])]),
        ])]), CreateNexusEndpointResponse::class, [], 10_000)->getEndpoint();

        try {
            (new WorkflowClient($grpc, $connection, new TemporalHistoryCursor($grpc, $connection), new WorkflowServiceExecutionRpc($grpc)))
                ->startAsync('NexusPageProbe', [], ExecutionId::fromString($executionId));
            $task = $grpc->PollWorkflowTaskQueue(new PollWorkflowTaskQueueRequest([
                'namespace' => $connection->namespace->name(),
                'task_queue' => new TaskQueue(['name' => $connection->workflowTaskQueue->name()]),
                'identity' => $connection->identity,
            ]), [], ['timeout' => 30_000_000]);
            self::assertNotSame('', (string) $task->getTaskToken(), 'no workflow task came for the run within the poll');
            $buffer = new TemporalWorkflowCommandBuffer($connection, ExecutionId::fromString($executionId));
            $buffer->scheduleNexusOperation('op-1', NexusEndpoint::named($endpointName), NexusService::named('stock'), NexusOperationName::named('reserve'), ['order' => 'ORD-1'], new NexusOperationTimeouts(scheduleToClose: Duration::minutes(5)), NexusOperationHeaders::none());
            $grpc->RespondWorkflowTaskCompleted(new RespondWorkflowTaskCompletedRequest([
                'namespace' => $connection->namespace->name(),
                'task_token' => $task->getTaskToken(),
                'identity' => $connection->identity,
                'commands' => $buffer->flush(),
            ]), [], ['timeout' => 30_000_000]);

            $client = static::createClient();
            $manager = static::getContainer()->get(EntityManagerInterface::class);
            $admin = $manager->getRepository(AdminUser::class)->findOneBy(['username' => 'durable-admin']) ?? self::newAdmin($manager);
            $client->loginUser($admin, 'admin');
            // The Temporal catalog of the same server, in place of the shop's SQL one.
            static::getContainer()->set(RunDashboard::class, new RunDashboard(new TemporalWorkflowRunCatalog($grpc, $connection, new TemporalHistoryCursor($grpc, $connection))));

            $crawler = $client->request('GET', '/admin/durable/runs/' . $executionId);

            self::assertResponseIsSuccessful();
            $table = $crawler->filterXPath('//table[.//th[normalize-space()="Endpoint"]]');
            self::assertCount(1, $table, 'the Nexus operations table');
            self::assertSame([$endpointName, 'stock', 'reserve', 'in flight'], $table->filterXPath('//tbody/tr/td')->each(static fn($cell): string => trim($cell->text())));
        } finally {
            // Each on its own: a run that never started must not hide the real error, nor leave the
            // endpoint behind on a shared server.
            try {
                $grpc->TerminateWorkflowExecution(new TerminateWorkflowExecutionRequest([
                    'namespace' => $connection->namespace->name(),
                    'workflow_execution' => new WorkflowExecution(['workflow_id' => WorkflowClient::workflowIdOf($executionId)]),
                    'reason' => 'end of test',
                ]), [], ['timeout' => 10_000_000]);
            } catch (\RuntimeException) {
            }

            try {
                $transport->unary(self::OPERATOR . 'DeleteNexusEndpoint', new DeleteNexusEndpointRequest(['id' => (string) $endpoint?->getId(), 'version' => (int) $endpoint?->getVersion()]), DeleteNexusEndpointResponse::class, [], 10_000);
            } catch (\RuntimeException) {
            }
        }
    }

    private static function newAdmin(EntityManagerInterface $manager): AdminUser
    {
        $admin = new AdminUser();
        $admin->setEmail('durable-admin@example.com');
        $admin->setUsername('durable-admin');
        $admin->setPlainPassword('durable');
        $admin->setEnabled(true);
        $admin->setLocaleCode('en_US');
        $admin->addRole('ROLE_ADMINISTRATION_ACCESS');
        $manager->persist($admin);
        $manager->flush();

        return $admin;
    }
}
