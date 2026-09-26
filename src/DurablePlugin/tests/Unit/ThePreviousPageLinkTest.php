<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Plugin\Tests\Unit;

use Gplanchat\Durable\Observation\BackendHealth;
use Gplanchat\Durable\Observation\RunDashboard;
use Gplanchat\Durable\Observation\WorkflowRunDescription;
use Gplanchat\Durable\Observation\WorkflowRunPage;
use Gplanchat\Durable\Observation\WorkflowRunStatus;
use Gplanchat\Durable\Plugin\Controller\AdminDashboardController;
use Gplanchat\Durable\Port\WorkflowRunCatalogInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * The catalog pages forward only: Temporal's visibility API has no reverse cursor. The way back is
 * the stack of cursors the operator came through, carried in the URL (#383).
 */
final class ThePreviousPageLinkTest extends TestCase
{
    public function testTheFirstPageHasNoWayBack(): void
    {
        $pagination = $this->pagination([]);

        self::assertNull($pagination['previous']);
        self::assertSame([''], self::stack($pagination['nextBack']), 'the next page remembers the first one');
    }

    public function testTheSecondPageLeadsBackToTheFirst(): void
    {
        $pagination = $this->pagination(['cursor' => 'c1', 'back' => self::encode([''])]);

        self::assertSame(['cursor' => '', 'back' => self::encode([])], $pagination['previous']);
        self::assertSame(['', 'c1'], self::stack($pagination['nextBack']));
    }

    public function testTheThirdPageLeadsBackToTheSecond(): void
    {
        $pagination = $this->pagination(['cursor' => 'c2', 'back' => self::encode(['', 'c1'])]);

        self::assertSame(['cursor' => 'c1', 'back' => self::encode([''])], $pagination['previous']);
        self::assertSame(self::encode(['', 'c1']), $pagination['back'], 'a run opened on this page keeps the way back');
    }

    public function testAStackThatDoesNotDecodeIsNoWayBackRatherThanAnError(): void
    {
        $pagination = $this->pagination(['cursor' => 'c2', 'back' => '%%%not-base64']);

        self::assertNull($pagination['previous']);
    }

    /**
     * @param array<string, string> $query
     *
     * @return array<string, mixed>
     */
    private function pagination(array $query): array
    {
        $twig = new Environment(new ArrayLoader([
            '@SyliusAdmin/shared/layout/base.html.twig' => '',
            '@DurablePlugin/admin/dashboard/index.html.twig' => '{{ pagination|json_encode|raw }}',
        ]));
        $response = (new AdminDashboardController($twig))->index(new Request($query), new RunDashboard(new OnePageCatalog()));

        return json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }

    /** @param list<string> $cursors */
    private static function encode(array $cursors): string
    {
        return rtrim(strtr(base64_encode(json_encode($cursors, \JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    /** @return list<string> */
    private static function stack(string $encoded): array
    {
        return json_decode((string) base64_decode(strtr($encoded, '-_', '+/'), true), true, flags: \JSON_THROW_ON_ERROR);
    }
}

final class OnePageCatalog implements WorkflowRunCatalogInterface
{
    public function listRuns(?WorkflowRunStatus $status = null, ?string $cursor = null, int $limit = 20): WorkflowRunPage
    {
        return new WorkflowRunPage([], 'next');
    }

    public function findRun(string $executionId): ?WorkflowRunDescription
    {
        return null;
    }

    public function readHistory(WorkflowRunDescription $run): array
    {
        return [];
    }

    public function checkHealth(): BackendHealth
    {
        return new BackendHealth('Test backend', true, 'It answers.', new \DateTimeImmutable('@1700000000'), false);
    }
}
