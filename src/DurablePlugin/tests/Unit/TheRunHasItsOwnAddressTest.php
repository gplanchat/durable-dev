<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Plugin\Tests\Unit;

use Gplanchat\Durable\Observation\RunDashboard;
use Gplanchat\Durable\Plugin\Controller\AdminDashboardController;
use Gplanchat\Durable\Port\WorkflowRunCatalogInterface;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Store\InMemoryWorkflowRunCatalog;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * A list page and a run page (#264). The run's URL is its id and nothing else, so it can be pasted
 * into a ticket and still resolve after the list has moved on.
 */
final class TheRunHasItsOwnAddressTest extends TestCase
{
    public function testTheListIsAPageOfItsOwn(): void
    {
        $model = $this->render('index', new Request(['status' => 'running']), $this->twoRuns());

        self::assertSame(['run-2', 'run-1'], array_column($model['runs'], 'runId'));
        self::assertArrayNotHasKey('selectedRun', $model, 'no history read for a run nobody opened');
    }

    public function testARunIsFoundByTheIdInItsUrlAndKeepsItsWayBackToTheList(): void
    {
        $model = $this->render('show', new Request(['status' => 'failed', 'cursor' => 'c1', 'back' => 'WyIiXQ']), $this->twoRuns(), 'run-2');

        self::assertSame('run-2', $model['selectedRun']['runId']);
        self::assertArrayNotHasKey('runs', $model);
        self::assertSame(['status' => 'failed', 'cursor' => 'c1', 'back' => 'WyIiXQ'], $model['list']);
    }

    public function testAnUnknownRunIsNotFound(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->render('show', new Request(), $this->twoRuns(), 'run-nobody');
    }

    public function testABackendThatDoesNotAnswerIsSaidOnTheRunPageRatherThanANotFound(): void
    {
        $model = $this->render('show', new Request(), null, 'run-1');

        self::assertFalse($model['backend']['available']);
    }

    public function testTheOldDashboardLinkLeadsToTheRunItNamed(): void
    {
        $response = (new AdminDashboardController(new Environment(new ArrayLoader())))->dashboard(new Request(['run' => 'run-2', 'status' => 'failed', 'cursor' => '']), $this->urls());

        self::assertSame(301, $response->getStatusCode());
        self::assertSame('gplanchat_durable_plugin_admin_run_show?runId=run-2&status=failed', $response->getTargetUrl());
    }

    public function testTheOldDashboardLinkWithoutARunLeadsToTheListItShowed(): void
    {
        $response = (new AdminDashboardController(new Environment(new ArrayLoader())))->dashboard(new Request(['status' => 'failed', 'cursor' => 'c1', 'back' => 'WyIiXQ']), $this->urls());

        self::assertSame('gplanchat_durable_plugin_admin_run_index?status=failed&cursor=c1&back=WyIiXQ', $response->getTargetUrl());
    }

    /**
     * Read as text: the plugin does not require `symfony/yaml` (#520).
     */
    public function testTheRunRouteTakesAnyExecutionId(): void
    {
        $routes = (string) file_get_contents(\dirname(__DIR__, 2) . '/config/routes.yaml');

        self::assertMatchesRegularExpression('~^gplanchat_durable_plugin_admin_run_index:\n\s+path: /%sylius_admin\.path_name%/durable/runs$~m', $routes);
        self::assertMatchesRegularExpression('~^gplanchat_durable_plugin_admin_run_show:\n\s+path: /%sylius_admin\.path_name%/durable/runs/\{runId\}\n(?:\s+.+\n)*?\s+requirements: \{ runId: \'\.\+\' \}$~m', $routes, 'an execution id may hold a slash');
    }

    /**
     * @return array<string, mixed>
     */
    private function render(string $action, Request $request, ?WorkflowRunCatalogInterface $catalog, ?string $runId = null): array
    {
        $controller = new AdminDashboardController(new Environment(new ArrayLoader([
            '@SyliusAdmin/shared/layout/base.html.twig' => '',
            '@DurablePlugin/admin/dashboard/index.html.twig' => '{{ _context|json_encode|raw }}',
        ])));
        $view = new RunDashboard($catalog);
        $response = 'show' === $action ? $controller->show((string) $runId, $request, $view) : $controller->index($request, $view);

        return json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }

    private function twoRuns(): WorkflowRunCatalogInterface
    {
        $catalog = new InMemoryWorkflowRunCatalog(new InMemoryEventStore());
        $catalog->recordStart('run-1', 'App\\OrderWorkflow');
        $catalog->recordStart('run-2', 'App\\OrderWorkflow');

        return $catalog;
    }

    private function urls(): UrlGeneratorInterface
    {
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturnCallback(static fn(string $route, array $parameters = []): string => $route . '?' . http_build_query($parameters));

        return $urls;
    }
}
