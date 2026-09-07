<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Durable's four tables, through `php artisan migrate`.
 *
 * They have exactly the shape that {@see \Gplanchat\Bridge\Illuminate\Schema\DurableSchema} creates
 * on demand, and that is not a mere intention: `MigrationMatchesSchemaTest` builds both on two
 * connections and compares them column by column. Two ways of creating the same tables are two
 * chances to diverge, and a divergence between the migrated application and the test bench would
 * only show up in production.
 *
 * @see DUR030
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('durable_events', function (Blueprint $table): void {
            // Auto-increment: `readStream()` promises insertion order, and the id carries it.
            $table->bigIncrements('id');
            $table->string('execution_id', 128)->index();
            $table->string('event_type', 255);
            $table->text('payload');
            $table->dateTime('recorded_at');
        });

        Schema::create('durable_workflow_metadata', function (Blueprint $table): void {
            $table->string('execution_id', 128)->primary();
            $table->string('workflow_type', 255);
            $table->text('payload');
            $table->boolean('completed')->default(false);
        });

        // Read projection: the journal is written at every step and read per execution, while a
        // dashboard reads across executions and orders by date. Two access patterns, two tables.
        Schema::create('durable_workflow_runs', function (Blueprint $table): void {
            $table->string('execution_id', 128)->primary();
            $table->string('workflow_type', 255);
            $table->string('status', 32);
            $table->dateTime('started_at')->index();
            $table->dateTime('ended_at')->nullable();
        });

        Schema::create('durable_child_workflow_parent_link', function (Blueprint $table): void {
            $table->string('child_execution_id', 128)->primary();
            $table->string('parent_execution_id', 128)->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('durable_child_workflow_parent_link');
        Schema::dropIfExists('durable_workflow_runs');
        Schema::dropIfExists('durable_workflow_metadata');
        Schema::dropIfExists('durable_events');
    }
};
