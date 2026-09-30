<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\User\AdminUser;
use Doctrine\ORM\EntityManagerInterface;
use Gplanchat\Durable\Event\ActivityScheduled;
use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\Event\WorkflowExecutionFailed;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Observation\RunDashboard;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Store\WorkflowMetadataStore;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The dashboard, rendered by a real Sylius application.
 *
 * The whole `backend-neutral-workflow-dashboard` change was verified at the unit and static level,
 * and its ADR says so: the page had never been rendered. This test is what lifts that limit. It
 * therefore does what no unit test can do — boot the Sylius kernel, authenticate an
 * administrator, and request the page over HTTP.
 */
final class DurableDashboardTest extends WebTestCase
{
    private const ROUTE = '/admin/durable/runs';

    public function testTheDashboardRendersForAnAdministrator(): void
    {
        $client = $this->authenticatedClient();

        $crawler = $client->request('GET', self::ROUTE);

        self::assertResponseIsSuccessful();
        // On the full HTML and not on `filter('h1')`: the Sylius admin layout lays down its own
        // headings, and aiming at the first `h1` would test their order rather than our page.
        self::assertStringContainsString('Durable Workflow Dashboard', $crawler->html());
    }

    public function testTheAdminHooksComposeThePageAroundTheDashboard(): void
    {
        // #383: sidebar, navbar, page wrapper and footer come from `sylius_admin.common.index`,
        // once each; the content hookable places the dashboard, and its heading is the only one.
        $client = $this->authenticatedClient();

        $crawler = $client->request('GET', self::ROUTE);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filterXPath("//aside[contains(concat(' ', normalize-space(@class), ' '), ' navbar-vertical ')]"), 'the sidebar, from the common hook');
        self::assertCount(1, $crawler->filterXPath("//header[contains(concat(' ', normalize-space(@class), ' '), ' navbar ')]"), 'the navbar, from the common hook');
        self::assertCount(1, $crawler->filterXPath("//*[contains(concat(' ', normalize-space(@class), ' '), ' page-wrapper ')]"), 'one page wrapper, not one per layer');
        self::assertCount(1, $crawler->filterXPath("//footer[contains(concat(' ', normalize-space(@class), ' '), ' footer ')]"), 'the footer, from the common hook');
        self::assertCount(1, $crawler->filterXPath('//h1')->reduce(static fn($h1): bool => str_contains($h1->text(), 'Durable Workflow Dashboard')));
    }

    public function testTheListPagesForwardAndLeadsBackToTheFirstPage(): void
    {
        // #383: Temporal cannot page backwards, so the list pages forward only, with the first page
        // as the way back, through real URLs. One run more than a page, so there is a second page.
        $client = $this->authenticatedClient();

        try {
            for ($i = 0; $i <= RunDashboard::PAGE_SIZE; ++$i) {
                $this->recordFailedRun('exec-page-' . $i, 'App\\PagedWorkflow');
            }

            $first = $client->request('GET', self::ROUTE);
            self::assertCount(0, $first->selectLink('First page'), 'the first page does not lead to itself');

            $second = $client->click($first->selectLink('Next page')->link());
            self::assertResponseIsSuccessful();
            self::assertCount(0, $second->selectLink('Previous page'), 'there is no previous page');

            $back = $client->click($second->selectLink('First page')->link());
            self::assertResponseIsSuccessful();
            self::assertCount(0, $back->selectLink('First page'));
            self::assertCount(1, $back->selectLink('Next page'));
        } finally {
            // The database outlives the test: a full page of runs would push the other tests' run
            // off the first page.
            $connection = static::getContainer()->get('doctrine.dbal.default_connection');
            foreach (['durable_events', 'durable_workflow_metadata', 'durable_workflow_runs'] as $table) {
                $connection->executeStatement("DELETE FROM {$table} WHERE execution_id LIKE 'exec-page-%'");
            }
        }
    }

    public function testALinkFromBeforeLandsOnTheFirstPage(): void
    {
        // Links bookmarked while the list had a previous page carried its way back in `back`.
        $client = $this->authenticatedClient();

        $client->request('GET', self::ROUTE . '?status=failed&cursor=abc&back=WyIiXQ');

        self::assertResponseRedirects(self::ROUTE . '?status=failed', 301);
    }

    public function testTheRunListIsASyliusGrid(): void
    {
        // #383, slice B: the list is the grid's, its rows rendered by its fields, its table the
        // admin's own markup; the pagination under it is the catalogue's cursor, not Pagerfanta.
        $client = $this->authenticatedClient();
        $this->recordFailedRun('exec-grid-1', 'App\\GridWorkflow');

        $crawler = $client->request('GET', self::ROUTE);

        self::assertResponseIsSuccessful();
        $table = $crawler->filterXPath('//*[@data-test-grid-table]');
        self::assertCount(1, $table, 'one grid table');
        self::assertStringContainsString('Execution', $table->filterXPath('//thead')->text());
        self::assertStringContainsString('exec-grid-1', $table->filterXPath('//tbody')->text());
        self::assertCount(1, $table->filterXPath("//a[contains(@href, '/admin/durable/runs/exec-grid-1')]"), 'a row leads to its run');
    }

    public function testTheListFiltersByWorkflowNameAndExecutionIdPrefix(): void
    {
        // #558, #557: the catalogue filters, so the page offers both filters and applies them.
        $client = $this->authenticatedClient();
        $this->recordFailedRun('exec-filter-a', 'App\\AlphaWorkflow');
        $this->recordFailedRun('exec-filter-b', 'App\\BetaWorkflow');

        $byName = $client->request('GET', self::ROUTE . '?workflowName=' . rawurlencode('App\\AlphaWorkflow'));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('exec-filter-a', $byName->filterXPath('//*[@data-test-grid-table]')->text());
        self::assertStringNotContainsString('exec-filter-b', $byName->filterXPath('//*[@data-test-grid-table]')->text());

        $byPrefix = $client->request('GET', self::ROUTE . '?executionIdPrefix=exec-filter-b');
        self::assertStringContainsString('exec-filter-b', $byPrefix->filterXPath('//*[@data-test-grid-table]')->text());
        self::assertStringNotContainsString('exec-filter-a', $byPrefix->filterXPath('//*[@data-test-grid-table]')->text());

        $form = $byPrefix->filterXPath('//form[@data-durable-run-filters]');
        self::assertCount(1, $form->filterXPath("//input[@name='workflowName']"));
        self::assertCount(1, $form->filterXPath("//input[@name='executionIdPrefix']"));
        self::assertCount(0, $form->filterXPath("//*[@name='cursor' or @name='back']"), 'a new filter starts from the first page');

        $nothing = $client->request('GET', self::ROUTE . '?executionIdPrefix=exec-nobody');
        self::assertCount(0, $nothing->filterXPath('//*[@data-test-grid-table]'));
        self::assertStringContainsString('No workflow run matches this filter.', $nothing->html(), 'nothing matches, and nothing comes after');
    }

    public function testPagingKeepsTheFilters(): void
    {
        // A cursor is only valid with the filters that produced it: Next carries them along.
        $client = $this->authenticatedClient();

        try {
            for ($i = 0; $i <= RunDashboard::PAGE_SIZE; ++$i) {
                $this->recordFailedRun('exec-page-' . $i, 'App\\PagedWorkflow');
            }

            $first = $client->request('GET', self::ROUTE . '?executionIdPrefix=exec-page-');
            $next = $first->selectLink('Next page')->link()->getUri();

            self::assertStringContainsString('executionIdPrefix=exec-page-', $next);
            $second = $client->request('GET', $next);
            self::assertResponseIsSuccessful();
            self::assertCount(1, $second->filterXPath('//*[@data-test-grid-table]//tbody/tr'), 'the one run left over');
        } finally {
            $connection = static::getContainer()->get('doctrine.dbal.default_connection');
            foreach (['durable_events', 'durable_workflow_metadata', 'durable_workflow_runs'] as $table) {
                $connection->executeStatement("DELETE FROM {$table} WHERE execution_id LIKE 'exec-page-%'");
            }
        }
    }

    public function testAnAnonymousVisitorDoesNotReachIt(): void
    {
        $client = static::createClient();

        $client->request('GET', self::ROUTE);

        self::assertResponseStatusCodeSame(302, 'the admin route must redirect to the login');
    }

    public function testAFailedRunIsListedWithItsNameAndOutcome(): void
    {
        $client = $this->authenticatedClient();
        $this->recordFailedRun('exec-render-1', 'App\\OrderWorkflow');

        $crawler = $client->request('GET', self::ROUTE);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('App\\OrderWorkflow', $crawler->html());
        self::assertStringContainsString('exec-render-1', $crawler->html());
        self::assertStringContainsString('FAILED', $crawler->html());
    }

    public function testTheSelectedRunShowsItsRecordedHistory(): void
    {
        $client = $this->authenticatedClient();
        $this->recordFailedRun('exec-render-2', 'App\\OrderWorkflow');

        $crawler = $client->request('GET', self::ROUTE . '/exec-render-2');

        self::assertResponseIsSuccessful();
        // The label is the name of the activity, not its identifier: that is what the history
        // reader promises, and the page is the only place where you actually see it.
        self::assertStringContainsString('SendWelcomeEmail', $crawler->html());
    }

    public function testARunIsReachedFromTheListAndAnUnknownOneIsNotFound(): void
    {
        // #264: the run has an address of its own, reached by the list's link.
        $client = $this->authenticatedClient();
        $this->recordFailedRun('exec-render-3', 'App\\OrderWorkflow');

        $list = $client->request('GET', self::ROUTE);
        $client->click($list->filterXPath("//a[contains(@href, '/admin/durable/runs/exec-render-3')]")->link());
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('SendWelcomeEmail', (string) $client->getResponse()->getContent());

        $client->request('GET', self::ROUTE . '/exec-nobody');
        self::assertResponseStatusCodeSame(404);
    }

    public function testARunWhoseIdHoldsASlashHasAnAddressToo(): void
    {
        // An execution id is any string the application chose; the route takes it whole.
        $client = $this->authenticatedClient();
        $this->recordFailedRun('exec-render/slash-4', 'App\\OrderWorkflow');

        $list = $client->request('GET', self::ROUTE);
        $client->click($list->filterXPath("//a[contains(@href, '/admin/durable/runs/exec-render/slash-4')]")->link());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('exec-render/slash-4', (string) $client->getResponse()->getContent());
    }

    public function testTheFormerDashboardLinkStillLeadsToTheRun(): void
    {
        $client = $this->authenticatedClient();

        $client->request('GET', '/admin/durable/dashboard?run=exec-render-2');

        self::assertResponseRedirects('/admin/durable/runs/exec-render-2', 301);
    }

    public function testThePageNamesTheBackendItRead(): void
    {
        $client = $this->authenticatedClient();

        $crawler = $client->request('GET', self::ROUTE);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('SQL database', $crawler->html());
        self::assertStringNotContainsString('Temporal', $crawler->html());
    }

    private function authenticatedClient(): KernelBrowser
    {
        $client = static::createClient();

        $container = static::getContainer();
        $manager = $container->get(EntityManagerInterface::class);

        $admin = $manager->getRepository(AdminUser::class)->findOneBy(['username' => 'durable-admin']);
        if (null === $admin) {
            $admin = new AdminUser();
            $admin->setEmail('durable-admin@example.com');
            $admin->setUsername('durable-admin');
            $admin->setPlainPassword('durable');
            $admin->setEnabled(true);
            $admin->setLocaleCode('en_US');
            $admin->addRole('ROLE_ADMINISTRATION_ACCESS');
            $manager->persist($admin);
            $manager->flush();
        }

        $client->loginUser($admin, 'admin');

        return $client;
    }

    private function recordFailedRun(string $executionId, string $workflowType): void
    {
        $container = static::getContainer();

        // The name comes from the metadata store, the outcome from the journal: the two pens of
        // DUR035, exercised here through the real container rather than assembled by hand.
        $container->get(WorkflowMetadataStore::class)->save(ExecutionId::fromString($executionId), $workflowType, []);

        $journal = $container->get(EventStoreInterface::class);
        $journal->append(new ExecutionStarted(ExecutionId::fromString($executionId), []));
        $journal->append(new ActivityScheduled(ExecutionId::fromString($executionId), 'act-1', 'SendWelcomeEmail', []));
        $journal->append(WorkflowExecutionFailed::unhandledDeclaredActivityFailure(
            ExecutionId::fromString($executionId),
            new \RuntimeException('le fournisseur a refusé la charge'),
        ));
    }
}
