<?php

declare(strict_types=1);

namespace Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Gplanchat\Durable\Event\ActivityScheduled;
use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\Event\WorkflowExecutionFailed;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Store\WorkflowMetadataStore;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use App\Entity\User\AdminUser;

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
    private const ROUTE = '/admin/durable/dashboard';

    public function testTheDashboardRendersForAnAdministrator(): void
    {
        $client = $this->authenticatedClient();

        $crawler = $client->request('GET', self::ROUTE);

        self::assertResponseIsSuccessful();
        // On the full HTML and not on `filter('h1')`: the Sylius admin layout lays down its own
        // headings, and aiming at the first `h1` would test their order rather than our page.
        self::assertStringContainsString('Durable Workflow Dashboard', $crawler->html());
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

        $crawler = $client->request('GET', self::ROUTE . '?run=exec-render-2');

        self::assertResponseIsSuccessful();
        // The label is the name of the activity, not its identifier: that is what the history
        // reader promises, and the page is the only place where you actually see it.
        self::assertStringContainsString('SendWelcomeEmail', $crawler->html());
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
        $container->get(WorkflowMetadataStore::class)->save($executionId, $workflowType, []);

        $journal = $container->get(EventStoreInterface::class);
        $journal->append(new ExecutionStarted($executionId, []));
        $journal->append(new ActivityScheduled($executionId, 'act-1', 'SendWelcomeEmail', []));
        $journal->append(WorkflowExecutionFailed::unhandledDeclaredActivityFailure(
            $executionId,
            new \RuntimeException('le fournisseur a refusé la charge'),
        ));
    }
}
