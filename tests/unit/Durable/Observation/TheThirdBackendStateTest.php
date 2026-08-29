<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Observation;

use Gplanchat\Durable\Observation\BackendHealth;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Store\InMemoryWorkflowRunCatalog;
use PHPUnit\Framework\TestCase;

/**
 * « Joignable » et « injoignable » ne suffisent pas : il en existe un **troisième**.
 *
 * Un journal in-memory répond parfaitement, et sa réponse est vide — la requête qui rend le tableau
 * de bord n'a jamais exécuté le moindre workflow. Vide est donc la bonne réponse, pas une panne.
 * Rangé sous « joignable », ce cas apprend à l'exploitant qu'aucun workflow n'a tourné, ce qui est
 * faux ; rangé sous « injoignable », il l'envoie rallumer un serveur qui n'existe pas.
 *
 * Le fait était déjà dit — en prose, dans le message de santé du catalogue in-memory, et dans un
 * bandeau écrit à la main côté Magento. Une phrase n'est pas un état : une surface ne peut pas la
 * lire pour décider quoi afficher, et les deux autres ne l'avaient pas.
 */
final class TheThirdBackendStateTest extends TestCase
{
    public function testAJournalThatDiesWithItsProcessSaysSo(): void
    {
        $health = (new InMemoryWorkflowRunCatalog(new InMemoryEventStore()))->checkHealth();

        self::assertTrue($health->reachable, 'il répond : ce n\'est pas une panne');
        self::assertTrue($health->ephemeral);
    }

    public function testItSaysWhatToConfigureToReadAcrossProcesses(): void
    {
        // Sans cette moitié, l'exploitant sait que la liste ment sans savoir quoi y faire.
        $health = (new InMemoryWorkflowRunCatalog(new InMemoryEventStore()))->checkHealth();

        self::assertMatchesRegularExpression('/SQL|Temporal/', $health->message);
    }

    public function testABackendThatSaysNothingIsTakenToOutliveTheRequest(): void
    {
        // Le défaut couvre les trois catalogues qui écrivent hors du processus — SQL, Illuminate,
        // Temporal. Aucun n'a à déclarer ce qui est vrai de lui par construction.
        $health = new BackendHealth('SQL database', true, 'The SQL database answers.', new \DateTimeImmutable());

        self::assertFalse($health->ephemeral);
    }
}
