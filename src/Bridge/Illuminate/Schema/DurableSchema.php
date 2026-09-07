<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Illuminate\Schema;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;

/**
 * The four tables, created on demand — the Illuminate counterpart of
 * {@see \Gplanchat\Bridge\Dbal\Schema\DurableSchema}, with the same shape.
 *
 * "The same shape" is not a mere intention: both bridges replay the conformance suites of
 * DUR041, and a journal whose columns diverged would break replay silently rather than at
 * write time.
 *
 * ponytail: creation on demand rather than a published migration. A Laravel application will want
 * `php artisan migrate`, and the package will have to publish its migrations; until then this
 * safeguard is enough for the tests and for a worker starting on an empty database. The `$ensured`
 * flag avoids the round trip on every write.
 *
 * @see DUR030 a single SQL foundation, no cluster
 * @see DUR041 the conformance suites both bridges replay
 */
final class DurableSchema
{
    private bool $ensured = false;

    public function __construct(
        private readonly Connection $connection,
        private readonly string $eventsTable = 'durable_events',
        private readonly string $metadataTable = 'durable_workflow_metadata',
        private readonly string $parentLinkTable = 'durable_child_workflow_parent_link',
        private readonly string $runsTable = 'durable_workflow_runs',
    ) {}

    public function ensure(): void
    {
        if ($this->ensured) {
            return;
        }
        $this->ensured = true;

        $builder = $this->connection->getSchemaBuilder();

        if (!$builder->hasTable($this->eventsTable)) {
            $builder->create($this->eventsTable, function (Blueprint $table): void {
                // Auto-increment: `readStream()` promises insertion order, and the id carries it.
                $table->bigIncrements('id');
                $table->string('execution_id', 128)->index();
                $table->string('event_type', 255);
                $table->text('payload');
                $table->dateTime('recorded_at');
            });
        }

        if (!$builder->hasTable($this->metadataTable)) {
            $builder->create($this->metadataTable, function (Blueprint $table): void {
                $table->string('execution_id', 128)->primary();
                $table->string('workflow_type', 255);
                $table->text('payload');
                $table->boolean('completed')->default(false);
            });
        }

        if (!$builder->hasTable($this->runsTable)) {
            $builder->create($this->runsTable, function (Blueprint $table): void {
                $table->string('execution_id', 128)->primary();
                $table->string('workflow_type', 255);
                $table->string('status', 32);
                $table->dateTime('started_at')->index();
                $table->dateTime('ended_at')->nullable();
            });
        }

        if (!$builder->hasTable($this->parentLinkTable)) {
            $builder->create($this->parentLinkTable, function (Blueprint $table): void {
                $table->string('child_execution_id', 128)->primary();
                $table->string('parent_execution_id', 128)->index();
            });
        }
    }
}
