<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Foreign keys between the bot's tables (PostgreSQL; SQLite is only for local work and the tests).
 *
 * What a delete does:
 *   a bot user        -> their downloads, photos, requests and report subscription go with them (a "delete my data" request);
 *                        a staff member linked to them is only unlinked
 *   a staff member    -> their attendance days go; the businesses they added keep existing (created_by = NULL);
 *                        refused while they have evidence (the dashboard hides the button then)
 *   a district/street -> refused while streets / files / staff / businesses still point at it: the dashboard deletes
 *                        the children first (files go to data/_trash) and hides the button when businesses exist
 *   a business        -> refused while evidence exists (businesses are switched off, never deleted)
 * The outbox has none: a message may go to any Telegram id.
 *
 * Added NOT VALID, then validated: the check of the existing rows does not lock the tables for writes.
 */
return new class extends Migration
{
    protected $connection = 'bot';

    private const KEYS = [
        // table, column, parent table, parent column, on delete
        ['downloads', 'user_id', 'users', 'user_id', 'CASCADE'],
        ['user_photos', 'user_id', 'users', 'user_id', 'CASCADE'],
        ['feedback', 'user_id', 'users', 'user_id', 'CASCADE'],
        ['report_recipients', 'user_id', 'users', 'user_id', 'CASCADE'],
        ['staff', 'user_id', 'users', 'user_id', 'SET NULL'],
        ['streets', 'district_id', 'districts', 'id', 'RESTRICT'],
        ['staff', 'district_id', 'districts', 'id', 'RESTRICT'],
        ['street_files', 'street_id', 'streets', 'id', 'RESTRICT'],
        ['objects', 'street_id', 'streets', 'id', 'RESTRICT'],
        ['objects', 'created_by', 'staff', 'id', 'SET NULL'],
        ['inspections', 'object_id', 'objects', 'id', 'RESTRICT'],
        ['inspections', 'staff_id', 'staff', 'id', 'RESTRICT'],
        ['staff_days', 'staff_id', 'staff', 'id', 'CASCADE'],
    ];

    public function up(): void
    {
        $db = DB::connection($this->connection);
        if ($db->getDriverName() !== 'pgsql') {
            return;
        }
        foreach (self::KEYS as [$table, $column, $parent, $key, $onDelete]) {
            $name = "fk_{$table}_{$column}";
            if ($db->selectOne('SELECT 1 FROM pg_constraint WHERE conname = ?', [$name])) {
                continue;
            }
            $db->statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} FOREIGN KEY ({$column}) REFERENCES {$parent}({$key}) ON DELETE {$onDelete} NOT VALID");
            $db->statement("ALTER TABLE {$table} VALIDATE CONSTRAINT {$name}");
        }
    }

    public function down(): void
    {
        $db = DB::connection($this->connection);
        if ($db->getDriverName() !== 'pgsql') {
            return;
        }
        foreach (self::KEYS as [$table, $column]) {
            $db->statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS fk_{$table}_{$column}");
        }
    }
};
