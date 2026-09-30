<?php

declare(strict_types=1);

use Gplanchat\Bridge\Illuminate\Schema\DurableMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The pass epochs (DUR053, #505) for an application that already ran the create migration. On a
 * database created with the table, this migration does nothing.
 */
return new class extends DurableMigration {
    public function up(): void
    {
        if (Schema::hasTable('durable_execution_heads')) {
            return;
        }

        Schema::create('durable_execution_heads', function (Blueprint $table): void {
            $table->string('execution_id', 128)->primary();
            $table->unsignedBigInteger('epoch');
        });
    }

    /**
     * Nothing: on a fresh install the create migration made the table, and this one cannot tell
     * whether it added it.
     */
    public function down(): void {}
};
