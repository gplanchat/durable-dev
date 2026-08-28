<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Nexus\Serving;

use Gplanchat\Durable\Nexus\Serving\NexusHandlerErrorType;
use Gplanchat\Durable\Nexus\Serving\NexusOperationResponse;
use PHPUnit\Framework\TestCase;

final class NexusOperationResponseTest extends TestCase
{
    public function testAnImmediateAnswerCarriesItsResult(): void
    {
        $response = NexusOperationResponse::completed(['greeting' => 'hello ada']);

        self::assertTrue($response->isImmediate);
        self::assertSame(['greeting' => 'hello ada'], $response->result);
        self::assertNull($response->workflowType);
    }

    public function testADeferredAnswerNamesTheWorkflowThatFulfilsIt(): void
    {
        // Le gestionnaire ne rend pas un jeton : la sonde 3.1 a montré que ce qui corrèle est le
        // callback de la tâche attaché au workflow, et qu'il ne s'attache qu'au démarrage.
        $response = NexusOperationResponse::fulfilledByWorkflow('GreetingWorkflow', ['name' => 'ada'], 'greet-1');

        self::assertFalse($response->isImmediate);
        self::assertSame('GreetingWorkflow', $response->workflowType);
        self::assertSame(['name' => 'ada'], $response->workflowInput);
        self::assertSame('greet-1', $response->workflowId);
        self::assertNull($response->result);
    }

    public function testAWorkflowWithoutATypeIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        NexusOperationResponse::fulfilledByWorkflow('   ');
    }

    public function testTheRetryTableIsTheNexusRpcOne(): void
    {
        foreach ([
            NexusHandlerErrorType::BadRequest,
            NexusHandlerErrorType::Unauthenticated,
            NexusHandlerErrorType::Unauthorized,
            NexusHandlerErrorType::NotFound,
            NexusHandlerErrorType::NotImplemented,
            NexusHandlerErrorType::Conflict,
        ] as $terminal) {
            self::assertFalse($terminal->isRetryable(), $terminal->value . ' doit être terminale.');
        }

        foreach ([
            NexusHandlerErrorType::ResourceExhausted,
            NexusHandlerErrorType::Internal,
            NexusHandlerErrorType::Unavailable,
            NexusHandlerErrorType::UpstreamTimeout,
            NexusHandlerErrorType::RequestTimeout,
        ] as $retryable) {
            self::assertTrue($retryable->isRetryable(), $retryable->value . ' doit être réessayable.');
        }
    }
}
