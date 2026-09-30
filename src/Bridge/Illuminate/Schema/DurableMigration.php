<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Illuminate\Schema;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Config;

/**
 * A migration of Durable's tables, run on the connection the stores write to (DUR054).
 *
 * The Migrator makes `getConnection()` the default connection for the duration of `up()`, so the
 * `Schema` calls inside land there. Without it, `php artisan migrate` builds the tables on the
 * application's connection while a journal on a connection of its own gets them from the stores'
 * `ensure()` — and every later schema migration keeps missing the journal's database.
 *
 * The key is `durable.connection`, the one `gplanchat/durable-laravel` reads to build the stores.
 * Unset or null, it is the default connection, as before.
 *
 * Not in `Migrations/`: `loadMigrationsFrom()` would take this file for a migration.
 */
abstract class DurableMigration extends Migration
{
    public function getConnection(): ?string
    {
        $connection = Config::get('durable.connection');

        return \is_string($connection) && '' !== $connection ? $connection : null;
    }
}
