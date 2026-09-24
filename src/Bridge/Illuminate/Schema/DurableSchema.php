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

    /**
     * @var array<string, bool>
     */
    private array $runsTableColumns = [];

    public function __construct(
        private readonly Connection $connection,
        private readonly string $eventsTable = 'durable_events',
        private readonly string $metadataTable = 'durable_workflow_metadata',
        private readonly string $parentLinkTable = 'durable_child_workflow_parent_link',
        private readonly string $runsTable = 'durable_workflow_runs',
    ) {}

    /**
     * The journal's table as configured: whoever reads the journal without being handed its store
     * reads this one, not the default.
     */
    public function eventsTable(): string
    {
        return $this->eventsTable;
    }

    /**
     * Whether the runs table has the `picked_up_at` column. Tables created before #447 do not, and
     * nothing alters an existing table: without the column, the pickup is not recorded and the run
     * list does not tell a run waiting for a worker. Asked once per process.
     */
    public function runsTableTracksPickup(): bool
    {
        return $this->runsTableHas('picked_up_at');
    }

    /**
     * Whether the runs table has the `waiting_on` column (#324), under the same rule as
     * {@see runsTableTracksPickup()}.
     */
    public function runsTableTracksWait(): bool
    {
        return $this->runsTableHas('waiting_on');
    }

    private function runsTableHas(string $column): bool
    {
        if (isset($this->runsTableColumns[$column])) {
            return $this->runsTableColumns[$column];
        }

        $builder = $this->connection->getSchemaBuilder();
        // No table yet is no answer: a worker may boot before the migrations run.
        if (!$builder->hasTable($this->runsTable)) {
            return false;
        }

        return $this->runsTableColumns[$column] = $builder->hasColumn($this->runsTable, $column);
    }

    public function ensure(): void
    {
        if ($this->ensured) {
            return;
        }

        $builder = $this->connection->getSchemaBuilder();
        $tables = [$this->eventsTable, $this->metadataTable, $this->runsTable, $this->parentLinkTable];
        $missing = array_values(array_filter($tables, static fn(string $table): bool => !$builder->hasTable($table)));
        if ([] === $missing) {
            $this->ensured = true;

            return;
        }
        // MySQL commits an open transaction on DDL, and Laravel's own commit then fails. Refused on
        // every platform, as the DBAL bridge and Messenger's Doctrine transport do.
        if ($this->connection->transactionLevel() > 0) {
            throw DurableSchemaMissing::insideTransaction($missing);
        }

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
                // When a worker first picked the run up (#447); null while the run waits for one.
                $table->dateTime('picked_up_at')->nullable();
                // What the run last suspended on (#324); read only while it is running.
                $table->text('waiting_on')->nullable();
                // The run list filters on status and orders by start (#339).
                $table->index(['status', 'started_at']);
            });
        }

        if (!$builder->hasTable($this->parentLinkTable)) {
            $builder->create($this->parentLinkTable, function (Blueprint $table): void {
                $table->string('child_execution_id', 128)->primary();
                $table->string('parent_execution_id', 128)->index();
            });
        }

        $this->ensured = true;
    }
}
