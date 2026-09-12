<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\TemporalHttp;

use Gplanchat\Bridge\TemporalHttp\JsonGatewayRequest;
use PHPUnit\Framework\TestCase;

final class JsonGatewayRequestTest extends TestCase
{
    public function testPlaceholdersAreProtoPathsReadFromTheCamelCaseJson(): void
    {
        $fields = ['namespace' => 'durable test', 'execution' => ['workflowId' => 'order/42', 'runId' => 'r1']];

        self::assertSame(
            '/api/v1/namespaces/durable%20test/workflows/order%2F42/history',
            JsonGatewayRequest::path('/api/v1/namespaces/{namespace}/workflows/{execution.workflow_id}/history', $fields),
        );
    }

    public function testAMissingPlaceholderFieldBecomesAnEmptySegment(): void
    {
        self::assertSame('/x//y', JsonGatewayRequest::path('/x/{query.query_type}/y', ['namespace' => 'n']));
    }

    public function testTheQueryFlattensNestedFieldsWithDotsAndRepeatsLists(): void
    {
        $fields = [
            'namespace' => 'n',
            'execution' => ['workflowId' => 'w', 'runId' => ''],
            'maximumPageSize' => 200,
            'skipArchival' => true,
            'nextPageToken' => 'AQ==',
            'ids' => ['a', 'b'],
        ];

        self::assertSame(
            'namespace=n&execution.workflowId=w&execution.runId=&maximumPageSize=200&skipArchival=true&nextPageToken=AQ%3D%3D&ids=a&ids=b',
            JsonGatewayRequest::query($fields),
        );
    }
}
