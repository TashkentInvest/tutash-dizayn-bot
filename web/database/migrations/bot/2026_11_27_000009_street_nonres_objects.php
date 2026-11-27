<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * nonres_objects: how many non-residential objects (businesses) the street has, from the official 1st-stage list
 * (tools/import_nonres_objects.py). A fixed figure: once written it never changes — the database refuses it.
 */
return new class extends Migration
{
    protected $connection = 'bot';

    public function up(): void
    {
        $s = Schema::connection($this->connection);
        if (! $s->hasColumn('streets', 'nonres_objects')) {
            $s->table('streets', fn (Blueprint $t) => $t->integer('nonres_objects')->nullable());
        }
        $db = DB::connection($this->connection);
        if ($db->getDriverName() === 'pgsql') {
            $db->unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION streets_nonres_objects_fixed() RETURNS trigger AS $$
                BEGIN
                    IF OLD.nonres_objects IS NOT NULL AND NEW.nonres_objects IS DISTINCT FROM OLD.nonres_objects THEN
                        RAISE EXCEPTION 'streets.nonres_objects is fixed once set (street %)', OLD.id;
                    END IF;
                    RETURN NEW;
                END $$ LANGUAGE plpgsql;
                DROP TRIGGER IF EXISTS trg_streets_nonres_objects_fixed ON streets;
                CREATE TRIGGER trg_streets_nonres_objects_fixed BEFORE UPDATE OF nonres_objects ON streets
                    FOR EACH ROW EXECUTE FUNCTION streets_nonres_objects_fixed();
                SQL);
        } else {
            $db->unprepared(<<<'SQL'
                CREATE TRIGGER IF NOT EXISTS trg_streets_nonres_objects_fixed BEFORE UPDATE OF nonres_objects ON streets
                WHEN OLD.nonres_objects IS NOT NULL AND NEW.nonres_objects IS NOT OLD.nonres_objects
                BEGIN SELECT RAISE(ABORT, 'streets.nonres_objects is fixed once set'); END;
                SQL);
        }
    }

    public function down(): void
    {
        $db = DB::connection($this->connection);
        if ($db->getDriverName() === 'pgsql') {
            $db->unprepared('DROP TRIGGER IF EXISTS trg_streets_nonres_objects_fixed ON streets; DROP FUNCTION IF EXISTS streets_nonres_objects_fixed();');
        } else {
            $db->unprepared('DROP TRIGGER IF EXISTS trg_streets_nonres_objects_fixed');
        }
        Schema::connection($this->connection)->table('streets', fn (Blueprint $t) => $t->dropColumn('nonres_objects'));
    }
};
