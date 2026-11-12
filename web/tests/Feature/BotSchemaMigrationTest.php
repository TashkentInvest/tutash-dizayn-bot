<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The bot database's migrations build exactly the schema the bot uses. The fixture (tests/fixtures/bot.sqlite) is built
 * by the bot's own schema (tools/make_test_db.py), so a column added on one side only fails here.
 */
class BotSchemaMigrationTest extends TestCase
{
    private function columns(string $connection): array
    {
        $out = [];
        foreach (DB::connection($connection)->select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' AND name <> 'migrations'") as $t) {
            $out[$t->name] = collect(DB::connection($connection)->select("PRAGMA table_info({$t->name})"))->pluck('name')->sort()->values()->all();
        }
        ksort($out);

        return $out;
    }

    public function test_the_migrations_build_the_schema_the_bot_uses(): void
    {
        $fresh = tempnam(sys_get_temp_dir(), 'botschema');
        config(['database.connections.fixture' => ['driver' => 'sqlite', 'database' => base_path('../tests/fixtures/bot.sqlite'), 'prefix' => ''],
            'database.connections.bot.driver' => 'sqlite', 'database.connections.bot.database' => $fresh]);
        DB::purge('bot');

        Artisan::call('migrate', ['--path' => 'database/migrations/bot', '--force' => true]);
        Artisan::call('migrate', ['--path' => 'database/migrations/bot', '--force' => true]);  // twice: nothing to do, no error

        $this->assertSame($this->columns('fixture'), $this->columns('bot'));
        @unlink($fresh);
    }
}
