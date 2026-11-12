<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The bot's database (connection "bot"): every table the bot and the dashboard share. From now on the schema is changed
 * here, by migrations, and the bot only checks at start that what it needs is in place (bot/db.py).
 *
 * On the running server the tables already exist (the bot used to create them): then this adds only what is missing.
 * Run with:  php artisan migrate --path=database/migrations/bot --force   (deploy/update.sh does it)
 * They live in their own folder so the tests' RefreshDatabase never runs them against a developer's bot.db.
 */
return new class extends Migration
{
    protected $connection = 'bot';

    public function up(): void
    {
        $s = Schema::connection($this->connection);
        $pg = DB::connection($this->connection)->getDriverName() === 'pgsql';
        // times are stored in UTC, as the bot writes them
        $now = DB::raw($pg ? "(now() AT TIME ZONE 'utc')" : "(datetime('now'))");
        $make = function (string $table, Closure $columns) use ($s) {
            if (! $s->hasTable($table)) {
                $s->create($table, $columns);
            }
        };

        $make('users', function (Blueprint $t) use ($now) {
            $t->bigInteger('user_id')->primary();  // the Telegram id
            $t->text('username')->nullable();
            $t->text('first_name')->nullable();
            $t->text('last_name')->nullable();
            $t->text('language_code')->nullable();
            $t->timestamp('first_seen')->default($now);
            $t->timestamp('last_seen')->default($now);
            $t->bigInteger('start_count')->default(0);
            $t->text('full_name')->nullable();
            $t->text('phone')->nullable();
            $t->double('latitude')->nullable();
            $t->double('longitude')->nullable();
            $t->timestamp('registered_at')->nullable();
            $t->timestamp('consent_at')->nullable();
            $t->text('lang')->nullable();
            $t->timestamp('concept_at')->nullable();
            $t->timestamp('approved_at')->nullable();
        });
        $make('districts', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->text('name');
            $t->bigInteger('sort_order')->default(0);
            $t->boolean('is_active')->default(true);
            $t->text('phone')->nullable();
        });
        $make('streets', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->bigInteger('district_id')->index('idx_streets_district');
            $t->text('name');
            $t->bigInteger('sort_order')->default(0);
            $t->boolean('is_active')->default(true);
            $t->bigInteger('planned_objects')->default(0);
            $t->double('start_lat')->nullable();
            $t->double('start_lon')->nullable();
            $t->double('end_lat')->nullable();
            $t->double('end_lon')->nullable();
            $t->text('path')->nullable();  // the real shape, [[lat, lon], ...] from a KMZ
        });
        $make('street_files', function (Blueprint $t) use ($now) {
            $t->bigIncrements('id');
            $t->bigInteger('street_id')->index('idx_files_street');
            $t->text('file_name');
            $t->text('path');  // relative to DATA_DIR
            $t->bigInteger('size')->default(0);
            $t->bigInteger('sort_order')->default(0);
            $t->timestamp('created_at')->default($now);
        });
        $make('downloads', function (Blueprint $t) use ($now) {
            $t->bigIncrements('id');
            $t->bigInteger('user_id');
            $t->text('district');
            $t->text('street');
            $t->text('file_name');
            $t->timestamp('sent_at')->default($now);
        });
        $make('file_cache', function (Blueprint $t) {
            $t->text('path');
            $t->bigInteger('size');
            $t->bigInteger('mtime');
            $t->text('file_id');
            $t->primary(['path', 'size', 'mtime']);
        });
        $make('user_photos', function (Blueprint $t) use ($now) {
            $t->bigIncrements('id');
            $t->bigInteger('user_id');
            $t->text('file_id');
            $t->text('file_unique_id');
            $t->text('path');
            $t->timestamp('created_at')->default($now);
        });
        $make('kv', function (Blueprint $t) {
            $t->text('key')->primary();
            $t->double('value');
        });
        $make('feedback', function (Blueprint $t) use ($now) {
            $t->bigIncrements('id');
            $t->bigInteger('user_id')->index('idx_feedback_user');
            $t->text('level');
            $t->text('text');
            $t->timestamp('created_at')->default($now)->index('idx_feedback_created');
            $t->text('answer')->nullable();
            $t->timestamp('answered_at')->nullable();
            $t->text('answered_by')->nullable();
            $t->timestamp('answer_sent_at')->nullable();
            $t->text('answer_error')->nullable();
        });
        $make('staff', function (Blueprint $t) use ($now) {
            $t->bigIncrements('id');
            $t->text('full_name');
            $t->text('phone')->unique();
            $t->bigInteger('district_id');
            $t->text('streets_note')->nullable();
            $t->text('priority_streets')->nullable();
            $t->bigInteger('user_id')->nullable()->unique();
            $t->boolean('is_active')->default(true);
            $t->timestamp('created_at')->default($now);
            $t->text('invite_token')->nullable();
            $t->text('invite_expires')->nullable();
        });
        $make('objects', function (Blueprint $t) use ($now) {
            $t->bigIncrements('id');
            $t->bigInteger('street_id')->index('idx_objects_street');
            $t->text('shop_name');
            $t->text('owner_name')->nullable();
            $t->text('owner_phone')->nullable();
            $t->text('stir')->nullable();
            $t->text('status')->default('pending')->index('idx_objects_status');
            $t->double('latitude')->nullable();
            $t->double('longitude')->nullable();
            $t->boolean('is_active')->default(true);
            $t->bigInteger('created_by')->nullable();
            $t->timestamp('created_at')->default($now);
            $t->timestamp('updated_at')->default($now);
            $t->text('address')->nullable();
            $t->double('found_lat')->nullable();
            $t->double('found_lon')->nullable();
        });
        $make('inspections', function (Blueprint $t) use ($now) {
            $t->bigIncrements('id');
            $t->bigInteger('object_id')->index('idx_inspections_object');
            $t->bigInteger('staff_id')->index('idx_inspections_staff');
            $t->text('kind');
            $t->text('step')->default('photo');
            $t->double('latitude')->nullable();
            $t->double('longitude')->nullable();
            $t->double('accuracy')->nullable();
            $t->double('obj_latitude')->nullable();
            $t->double('obj_longitude')->nullable();
            $t->double('distance_m')->nullable();
            $t->text('photo')->nullable();
            $t->text('photo_hash')->nullable();
            $t->text('video')->nullable();
            $t->text('video_hash')->nullable();
            $t->text('comment')->nullable();
            $t->text('review')->nullable()->index('idx_inspections_review');
            $t->text('review_note')->nullable();
            $t->text('reviewed_by')->nullable();
            $t->timestamp('reviewed_at')->nullable();
            $t->timestamp('created_at')->default($now);
            $t->timestamp('submitted_at')->nullable();
        });
        $make('staff_days', function (Blueprint $t) {
            $t->bigInteger('staff_id');
            $t->text('day');
            $t->text('first_at');
            $t->text('last_at');
            $t->bigInteger('hits')->default(1);
            $t->primary(['staff_id', 'day']);
        });
        $make('report_recipients', function (Blueprint $t) use ($now) {
            $t->bigInteger('user_id')->primary();
            $t->text('label')->nullable();
            $t->timestamp('created_at')->default($now);
            $t->boolean('daily_report')->default(true);
            $t->boolean('attendance_report')->default(true);
            $t->boolean('hourly_report')->default(false);
        });
        $make('outbox', function (Blueprint $t) use ($now) {
            $t->bigIncrements('id');
            $t->bigInteger('user_id');
            $t->text('text');
            $t->timestamp('created_at')->default($now);
            $t->timestamp('sent_at')->nullable();
            $t->text('error')->nullable();
            $t->text('photo')->nullable();
        });

        // the indexes the dashboard's counts, filters and sorts rely on (same statement on PostgreSQL and SQLite)
        foreach ([
            'idx_users_registered ON users(registered_at)', 'idx_users_first_seen ON users(first_seen)',
            'idx_users_last_seen ON users(last_seen)', 'idx_downloads_user ON downloads(user_id)',
            'idx_downloads_sent ON downloads(sent_at)', 'idx_downloads_street ON downloads(district, street)',
            'idx_photos_user ON user_photos(user_id)', 'idx_files_size ON street_files(size)',
            'idx_files_name ON street_files(file_name)', 'idx_inspections_photo_hash ON inspections(photo_hash)',
            'idx_inspections_video_hash ON inspections(video_hash)', 'idx_outbox_unsent ON outbox(id) WHERE sent_at IS NULL',
            'idx_feedback_unanswered ON feedback(id) WHERE answer IS NULL',
            'idx_feedback_undelivered ON feedback(answered_at) WHERE answer IS NOT NULL AND answer_sent_at IS NULL',
        ] as $index) {
            DB::connection($this->connection)->statement('CREATE INDEX IF NOT EXISTS '.$index);
        }
        DB::connection($this->connection)->statement('CREATE UNIQUE INDEX IF NOT EXISTS uq_files_path ON street_files(path)');
    }

    public function down(): void
    {
        // never: these tables hold the users, the catalog and the staff's evidence
    }
};
