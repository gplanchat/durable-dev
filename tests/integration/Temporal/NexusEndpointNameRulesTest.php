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
 * A probe, not a feature: the "temporal-nexus-support" change wants a `NexusEndpoint` value object
 * validated at construction, and §1.4 forbids writing into it any invariant that has not been
 * observed. Here is what was observed, against Temporal 1.31.2.
 *
 * The result overturns the lesson of {@see \Gplanchat\Durable\TaskQueue}: there, the server
 * accepted almost anything — `" "`, edge whitespace, tabs — and the value object had to be
 * STRICTER than it, because a badly named queue produces no error, just an execution waiting for a
 * worker that will never come. Here the server validates by itself, through an explicit regex, and
 * refuses at creation time. The silent failure mode that justified the severity of `TaskQueue` does
 * not exist for a Nexus endpoint: `NexusEndpoint` therefore has no rule to invent, it has to refuse
 * as early as possible what the server would refuse anyway, and nothing more.
 *
 * @see openspec/changes/temporal-nexus-support/tasks.md §1.1
 */
#[RequiresPhpExtension('grpc')]
final class NexusEndpointNameRulesTest extends TestCase
{
    /** Probed: 200 accepted, 201 refused ("endpoint name exceeds length limit of 200"). */
    private const MAX_LENGTH = 200;

    private OperatorServiceClient $operator;

    protected function setUp(): void
    {
        $address = getenv('DURABLE_TEMPORAL_ADDRESS');
        if (false === $address || '' === $address) {
            self::markTestSkipped('DURABLE_TEMPORAL_ADDRESS not set: no Temporal server.');
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

        self::assertNotNull($error, \sprintf('The server accepted "%s": the regex has changed.', addcslashes($name, "\0..\37")));
        self::assertStringContainsString(
            'must match the regex',
            $error,
            \sprintf('Refused for a reason other than the regex: %s', $error),
        );
    }

    public function testAnEmptyNameIsRefusedForBeingUnsetRatherThanMalformed(): void
    {
        // A useful distinction: the message does not mention a regex, so the value object can
        // return "empty" and "malformed" as two different faults without inventing the second.
        self::assertSame('endpoint name not set', $this->create(''));
    }

    public function testTwoCharactersIsTheShortestAcceptedName(): void
    {
        self::assertNull($this->create('ab'), 'Two characters should be enough.');
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
     * Creates the endpoint and deletes it at once. Returns the server's message, or null if it
     * accepted.
     *
     * The server is shared: an endpoint left behind would stay visible to every other session, and
     * its name is unique for the whole cluster.
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
     * A unique suffix, so that a valid name does not collide from one execution to the next.
     * It is only added to names the regex would accept: on a faulty name it would mask the fault
     * being measured, and on a name at the length limit it would push it past the bound.
     */
    private function suffix(string $name): string
    {
        $wouldBeValid = 1 === preg_match('/^[a-zA-Z][a-zA-Z0-9\-]*[a-zA-Z0-9]$/', $name);

        return $wouldBeValid && \strlen($name) < self::MAX_LENGTH - 8 ? '-' . bin2hex(random_bytes(3)) : '';
    }
}
