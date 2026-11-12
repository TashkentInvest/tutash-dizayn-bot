<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A staff member may be in no district: taken off a district's list (Xodimlar page) they stay in the system, with their
 * bot link and evidence, but see no streets until a district takes them again. (SQLite: the bot's schema has it already.)
 */
return new class extends Migration
{
    protected $connection = 'bot';

    public function up(): void
    {
        if (DB::connection($this->connection)->getDriverName() === 'pgsql') {
            DB::connection($this->connection)->statement('ALTER TABLE staff ALTER COLUMN district_id DROP NOT NULL');
        }
    }

    public function down(): void
    {
        // not reversible while someone is in no district
    }
};
