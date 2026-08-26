<?php

declare(strict_types=1);

namespace integration\Temporal;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Temporal\Api\Nexus\V1\EndpointSpec;
use Temporal\Api\Nexus\V1\EndpointTarget;
use Temporal\Api\Nexus\V1\EndpointTarget\Worker;
use Temporal\Api\Operatorservice\V1\CreateNexusEndpointRequest;
use Temporal\Api\Operatorservice\V1\DeleteNexusEndpointRequest;
use Temporal\Api\Operatorservice\V1\OperatorServiceClient;

/**
 * Sonde, et non fonctionnalité : le change « temporal-nexus-support » veut un objet-valeur
 * `NexusEndpoint` validé à la construction, et §1.4 interdit d'y écrire un invariant qui n'a pas
 * été observé. Voici ce qui a été observé, contre Temporal 1.31.2.
 *
 * Le résultat renverse la leçon de {@see \Gplanchat\Durable\TaskQueue} : là, le serveur acceptait
 * presque tout — `" "`, les blancs en bord, les tabulations — et l'objet-valeur devait être PLUS
 * STRICT que lui, parce qu'une file mal nommée ne produit aucune erreur, juste une exécution qui
 * attend un worker qui ne viendra pas. Ici le serveur valide lui-même, par une regex explicite, et
 * refuse à la création. Le mode de défaillance silencieux qui justifiait la sévérité de `TaskQueue`
 * n'existe pas pour un endpoint Nexus : `NexusEndpoint` n'a donc pas à inventer de règle, il a à
 * refuser au plus tôt ce que le serveur refuserait de toute façon, et rien de plus.
 *
 * @see openspec/changes/temporal-nexus-support/tasks.md §1.1
 */
#[RequiresPhpExtension('grpc')]
final class NexusEndpointNameRulesTest extends TestCase
{
    /** Sondée : 200 accepté, 201 refusé (« endpoint name exceeds length limit of 200 »). */
    private const MAX_LENGTH = 200;

    private OperatorServiceClient $operator;

    protected function setUp(): void
    {
        $address = getenv('DURABLE_TEMPORAL_ADDRESS');
        if (false === $address || '' === $address) {
            self::markTestSkipped('DURABLE_TEMPORAL_ADDRESS non défini : pas de serveur Temporal.');
        }

        $this->operator = new OperatorServiceClient($address, ['credentials' => \Grpc\ChannelCredentials::createInsecure()]);
    }

    /** @return iterable<string, array{string}> */
    public static function refusedNames(): iterable
    {
        yield 'un espace' => [' '];
        yield 'espace en tête' => [' probe-lead'];
        yield 'espace en fin' => ['probe-trail '];
        yield 'tabulation interne' => ["probe\ttab"];
        yield 'saut de ligne interne' => ["probe\nnl"];
        yield 'caractère de contrôle' => ["probe\x01ctl"];
        yield 'underscore' => ['probe_under'];
        yield 'point' => ['probe.dot'];
        yield 'commence par un chiffre' => ['9probe'];
        yield 'tiret en tête' => ['-probe'];
        yield 'tiret en fin' => ['probe-'];
        yield 'accentué' => ['probé-nexus'];
        yield 'slash' => ['probe/slash'];
        yield 'une seule lettre' => ['a'];
    }

    #[DataProvider('refusedNames')]
    public function testTheServerRefusesWhatIsNotItsRegex(string $name): void
    {
        $error = $this->create($name);

        self::assertNotNull($error, \sprintf('Le serveur a accepté "%s" : la regex a changé.', addcslashes($name, "\0..\37")));
        self::assertStringContainsString(
            'must match the regex',
            $error,
            \sprintf('Refusé pour un autre motif que la regex : %s', $error),
        );
    }

    public function testAnEmptyNameIsRefusedForBeingUnsetRatherThanMalformed(): void
    {
        // Distinction utile : le message ne parle pas de regex, donc l'objet-valeur peut rendre
        // « vide » et « mal formé » comme deux fautes différentes sans inventer la seconde.
        self::assertSame('endpoint name not set', $this->create(''));
    }

    public function testTwoCharactersIsTheShortestAcceptedName(): void
    {
        self::assertNull($this->create('ab'), 'Deux caractères devraient suffire.');
    }

    public function testLettersDigitsAndInnerHyphensAreAccepted(): void
    {
        self::assertNull($this->create('Probe-Nexus-42'));
    }

    public function testTheLengthLimitIsTwoHundred(): void
    {
        self::assertNull($this->create(str_pad('p', self::MAX_LENGTH, 'x')));

        $error = $this->create(str_pad('p', self::MAX_LENGTH + 1, 'x'));
        self::assertNotNull($error);
        self::assertStringContainsString('exceeds length limit of ' . self::MAX_LENGTH, $error);
    }

    /**
     * Crée l'endpoint et le supprime aussitôt. Rend le message du serveur, ou null s'il a accepté.
     *
     * Le serveur est partagé : un endpoint laissé derrière soi resterait visible de toutes les
     * autres sessions, et son nom est unique pour le cluster entier.
     */
    private function create(string $name): ?string
    {
        $worker = new Worker();
        $worker->setNamespace(getenv('DURABLE_TEMPORAL_NAMESPACE') ?: 'durable-test');
        $worker->setTaskQueue('durable-nexus-probe');

        $target = new EndpointTarget();
        $target->setWorker($worker);

        $spec = new EndpointSpec();
        $spec->setName('' === $name ? $name : $name . $this->suffix($name));
        $spec->setTarget($target);

        $req = new CreateNexusEndpointRequest();
        $req->setSpec($spec);

        /** @var array{0: \Temporal\Api\Operatorservice\V1\CreateNexusEndpointResponse|null, 1: \stdClass} $pair */
        $pair = $this->operator->CreateNexusEndpoint($req, [], ['timeout' => 10_000_000])->wait();
        [$resp, $status] = $pair;

        if (0 !== (int) ($status->code ?? -1)) {
            return (string) ($status->details ?? '');
        }

        $endpoint = $resp?->getEndpoint();
        if (null !== $endpoint) {
            $del = new DeleteNexusEndpointRequest();
            $del->setId($endpoint->getId());
            $del->setVersion($endpoint->getVersion());
            $this->operator->DeleteNexusEndpoint($del, [], ['timeout' => 10_000_000])->wait();
        }

        return null;
    }

    /**
     * Un suffixe unique, pour qu'un nom valide ne collisionne pas d'une exécution à l'autre.
     * Il n'est ajouté qu'aux noms que la regex accepterait : sur un nom fautif il masquerait la
     * faute qu'on mesure, et sur un nom de longueur limite il ferait franchir la borne.
     */
    private function suffix(string $name): string
    {
        $wouldBeValid = 1 === preg_match('/^[a-zA-Z][a-zA-Z0-9\-]*[a-zA-Z0-9]$/', $name);

        return $wouldBeValid && \strlen($name) < self::MAX_LENGTH - 8 ? '-' . bin2hex(random_bytes(3)) : '';
    }
}
