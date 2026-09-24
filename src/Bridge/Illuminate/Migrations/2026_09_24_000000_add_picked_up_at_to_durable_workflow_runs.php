<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `picked_up_at` for an application that already ran the create migration (#447).
 *
 * The rows already there count as picked up: a run that predates the column cannot say whether a
 * worker took it, and a false "waiting for a worker" on each of them would be worse than no signal.
 * On a table created with the column, this migration does nothing.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasColumn('durable_workflow_runs', 'picked_up_at')) {
            return;
        }

        Schema::table('durable_workflow_runs', function (Blueprint $table): void {
            $table->dateTime('picked_up_at')->nullable();
        });
        Schema::getConnection()->table('durable_workflow_runs')->update(['picked_up_at' => Schema::getConnection()->raw('started_at')]);
    }

    /**
     * Nothing: on a fresh install the create migration made the column, and this one cannot tell
     * whether it added it. Dropping it here, then migrating again, would mark queued runs as picked up.
     */
    public function down(): void {}
};
