<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\TemporalHttp;

/**
 * The HTTP bindings of the RPCs the bridge uses, as declared in the WorkflowService protobuf
 * (google.api.http annotations, `/api/v1/` form). A POST route carries the whole request as its
 * JSON body; a GET route carries it as query parameters.
 *
 * The RPCs missing here have no HTTP binding on the server: the task queue polls and the
 * workflow/Nexus task responses. That is the server's choice, and the reason the JSON gateway
 * serves clients but not workers. `JsonGatewayRoutesTest` holds this table to the descriptor.
 */
final class JsonGatewayRoutes
{
    /** @var array<string, array{0: string, 1: string}> */
    public const ROUTES = [
        'CountActivityExecutions' => ['GET', '/api/v1/namespaces/{namespace}/activity-count'],
        'DescribeActivityExecution' => ['GET', '/api/v1/namespaces/{namespace}/activities/{activity_id}'],
        'DescribeWorkflowExecution' => ['GET', '/api/v1/namespaces/{namespace}/workflows/{execution.workflow_id}'],
        'GetWorkflowExecutionHistory' => ['GET', '/api/v1/namespaces/{namespace}/workflows/{execution.workflow_id}/history'],
        'ListActivityExecutions' => ['GET', '/api/v1/namespaces/{namespace}/activities'],
        'ListWorkflowExecutions' => ['GET', '/api/v1/namespaces/{namespace}/workflows'],
        'PauseActivity' => ['POST', '/api/v1/namespaces/{namespace}/activities-deprecated/pause'],
        'PollActivityExecution' => ['GET', '/api/v1/namespaces/{namespace}/activities/{activity_id}/outcome'],
        'QueryWorkflow' => ['POST', '/api/v1/namespaces/{namespace}/workflows/{execution.workflow_id}/query/{query.query_type}'],
        'RecordActivityTaskHeartbeat' => ['POST', '/api/v1/namespaces/{namespace}/activity-heartbeat'],
        'RecordActivityTaskHeartbeatById' => ['POST', '/api/v1/namespaces/{namespace}/activities/{activity_id}/heartbeat'],
        'RequestCancelActivityExecution' => ['POST', '/api/v1/namespaces/{namespace}/activities/{activity_id}/cancel'],
        'RequestCancelWorkflowExecution' => ['POST', '/api/v1/namespaces/{namespace}/workflows/{workflow_execution.workflow_id}/cancel'],
        'ResetActivity' => ['POST', '/api/v1/namespaces/{namespace}/activities-deprecated/reset'],
        'RespondActivityTaskCanceled' => ['POST', '/api/v1/namespaces/{namespace}/activity-resolve-as-canceled'],
        'RespondActivityTaskCanceledById' => ['POST', '/api/v1/namespaces/{namespace}/activities/{activity_id}/resolve-as-canceled'],
        'RespondActivityTaskCompleted' => ['POST', '/api/v1/namespaces/{namespace}/activity-complete'],
        'RespondActivityTaskCompletedById' => ['POST', '/api/v1/namespaces/{namespace}/activities/{activity_id}/complete'],
        'RespondActivityTaskFailed' => ['POST', '/api/v1/namespaces/{namespace}/activity-fail'],
        'RespondActivityTaskFailedById' => ['POST', '/api/v1/namespaces/{namespace}/activities/{activity_id}/fail'],
        'SignalWithStartWorkflowExecution' => ['POST', '/api/v1/namespaces/{namespace}/workflows/{workflow_id}/signal-with-start/{signal_name}'],
        'SignalWorkflowExecution' => ['POST', '/api/v1/namespaces/{namespace}/workflows/{workflow_execution.workflow_id}/signal/{signal_name}'],
        'StartActivityExecution' => ['POST', '/api/v1/namespaces/{namespace}/activities/{activity_id}'],
        'StartWorkflowExecution' => ['POST', '/api/v1/namespaces/{namespace}/workflows/{workflow_id}'],
        'TerminateActivityExecution' => ['POST', '/api/v1/namespaces/{namespace}/activities/{activity_id}/terminate'],
        'TerminateWorkflowExecution' => ['POST', '/api/v1/namespaces/{namespace}/workflows/{workflow_execution.workflow_id}/terminate'],
        'UnpauseActivity' => ['POST', '/api/v1/namespaces/{namespace}/activities-deprecated/unpause'],
        'UpdateActivityOptions' => ['POST', '/api/v1/namespaces/{namespace}/activities-deprecated/update-options'],
        'UpdateWorkflowExecution' => ['POST', '/api/v1/namespaces/{namespace}/workflows/{workflow_execution.workflow_id}/update/{request.input.name}'],
    ];

    private function __construct() {}
}
