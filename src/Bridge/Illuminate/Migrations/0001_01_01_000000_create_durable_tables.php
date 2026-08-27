<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les quatre tables de Durable, par `php artisan migrate`.
 *
 * Elles ont exactement la forme que {@see \Gplanchat\Bridge\Illuminate\Schema\DurableSchema} crée à
 * la demande, et ce n'est pas une intention : `MigrationMatchesSchemaTest` monte les deux sur deux
 * connexions et compare colonne par colonne. Deux façons de créer les mêmes tables sont deux
 * occasions de diverger, et une divergence entre l'application migrée et le banc de test ne se
 * verrait qu'en production.
 *
 * @see DUR030
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('durable_events', function (Blueprint $table): void {
            // Auto-increment : `readStream()` promet l'ordre d'insertion, l'id le porte.
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

        // Projection de lecture : le journal s'écrit à chaque pas et se lit par exécution, un
        // tableau de bord lit en travers et ordonne par date. Deux motifs d'accès, deux tables.
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
