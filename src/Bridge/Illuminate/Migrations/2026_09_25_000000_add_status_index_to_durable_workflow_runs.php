<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The `(status, started_at)` index for an application that already ran the create migration
 * (#339): the run list filters on `status` and orders by `started_at`. On a table created with
 * the index, this migration does nothing.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasIndex('durable_workflow_runs', ['status', 'started_at'])) {
            return;
        }

        Schema::table('durable_workflow_runs', function (Blueprint $table): void {
            $table->index(['status', 'started_at']);
        });
    }

    /**
     * Nothing: on a fresh install the create migration made the index, and this one cannot tell
     * whether it added it.
     */
    public function down(): void {}
};
