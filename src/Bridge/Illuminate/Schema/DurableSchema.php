<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Illuminate\Schema;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;

/**
 * Les quatre tables, créées à la demande — le pendant Illuminate de
 * {@see \Gplanchat\Bridge\Dbal\Schema\DurableSchema}, avec la même forme.
 *
 * « La même forme » n'est pas une intention : les deux ponts rejouent les suites de conformité de
 * DUR041, et un journal dont les colonnes divergeraient casserait le replay en silence plutôt qu'à
 * l'écriture.
 *
 * ponytail: création à la demande plutôt qu'une migration publiée. Une application Laravel voudra
 * `php artisan migrate`, et le paquet devra publier ses migrations ; d'ici là ce garde-fou suffit
 * aux tests et à un worker qui démarre sur une base vide. Le drapeau `$ensured` évite l'aller-retour
 * à chaque écriture.
 *
 * @see DUR030 un seul socle SQL, pas de cluster
 * @see DUR041 les suites de conformité que les deux ponts rejouent
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
                // Auto-increment : `readStream()` promet l'ordre d'insertion, l'id le porte.
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
