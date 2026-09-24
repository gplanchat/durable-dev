<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `waiting_on` for an application that already ran the create migration (#324). On a table created
 * with the column, this migration does nothing.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasColumn('durable_workflow_runs', 'waiting_on')) {
            return;
        }

        Schema::table('durable_workflow_runs', function (Blueprint $table): void {
            $table->text('waiting_on')->nullable();
        });
    }

    /**
     * Unlike `picked_up_at`, dropping loses nothing a later run cannot write again: the next
     * suspension records the wait anew.
     */
    public function down(): void
    {
        if (!Schema::hasColumn('durable_workflow_runs', 'waiting_on')) {
            return;
        }

        Schema::table('durable_workflow_runs', function (Blueprint $table): void {
            $table->dropColumn('waiting_on');
        });
    }
};
