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
 * Le tableau de bord, rendu par une vraie application Sylius.
 *
 * Tout le change `backend-neutral-workflow-dashboard` a été vérifié au niveau unitaire et statique,
 * et son ADR le dit : la page n'avait jamais été rendue. Ce test est ce qui lève cette limite. Il
 * fait donc ce qu'aucun test unitaire ne peut faire — monter le noyau Sylius, authentifier un
 * administrateur, et demander la page par HTTP.
 */
final class DurableDashboardTest extends WebTestCase
{
    private const ROUTE = '/admin/durable/dashboard';

    public function testTheDashboardRendersForAnAdministrator(): void
    {
        $client = $this->authenticatedClient();

        $crawler = $client->request('GET', self::ROUTE);

        self::assertResponseIsSuccessful();
        // Sur le HTML complet et non sur `filter('h1')` : la mise en page de l'admin Sylius pose
        // ses propres titres, et viser le premier `h1` testerait leur ordre plutôt que notre page.
        self::assertStringContainsString('Durable Workflow Dashboard', $crawler->html());
    }

    public function testAnAnonymousVisitorDoesNotReachIt(): void
    {
        $client = static::createClient();

        $client->request('GET', self::ROUTE);

        self::assertResponseStatusCodeSame(302, 'la route admin doit renvoyer vers la connexion');
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
        // L'étiquette est le nom de l'activité, pas son identifiant : c'est ce que le lecteur
        // d'historique promet, et la page est le seul endroit où on le voit vraiment.
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

        // Le nom vient du magasin de métadonnées, l'issue du journal : les deux plumes de DUR035,
        // exercées ici à travers le conteneur réel plutôt qu'assemblées à la main.
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
