<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal;

/**
 * Every RPC is the same unary exchange with a different message pair: a transport implements
 * {@see call()} once and inherits the per-RPC methods from the two traits.
 */
abstract class AbstractWorkflowServiceClient implements WorkflowServiceClientInterface
{
    use ActivityRpcMethods;
    use WorkflowRpcMethods;
}
