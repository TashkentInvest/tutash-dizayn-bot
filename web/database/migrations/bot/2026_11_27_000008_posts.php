<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Post yuborish": a post (picture / video + text) and one delivery row per person. The bot sends them (bot/posts.py)
 * and keeps the message ids, so the post can be taken back from the chats ("Botdan o'chirish", within 48 hours).
 */
return new class extends Migration
{
    protected $connection = 'bot';

    public function up(): void
    {
        $s = Schema::connection($this->connection);
        $pg = DB::connection($this->connection)->getDriverName() === 'pgsql';
        $now = DB::raw($pg ? "(now() AT TIME ZONE 'utc')" : "(datetime('now'))");  // UTC, as the bot writes times

        if (! $s->hasTable('posts')) {
            $s->create('posts', function (Blueprint $t) use ($now) {
                $t->bigIncrements('id');
                $t->text('caption')->nullable();
                $t->text('media_type')->nullable();       // photo | video
                $t->text('media_path')->nullable();       // under the bot's uploads folder
                $t->integer('media_width')->nullable();   // the video upright: Telegram stretches it without these
                $t->integer('media_height')->nullable();
                $t->integer('media_duration')->nullable();
                $t->text('file_id')->nullable();          // the uploaded file, reused for everyone after the first
                $t->text('audience')->nullable();         // JSON: groups and districts chosen
                $t->boolean('ack_button')->default(true);
                $t->text('status')->default('sending');   // sending | sent
                $t->integer('total')->default(0);
                $t->integer('delivered')->default(0);
                $t->integer('failed')->default(0);
                $t->bigInteger('created_by')->nullable(); // the dashboard user (another database: no foreign key)
                $t->timestamp('created_at')->default($now);
                $t->timestamp('finished_at')->nullable();
                $t->timestamp('recalled_at')->nullable();
                $t->integer('recalled')->default(0);
                $t->integer('recall_failed')->default(0);
                $t->timestamp('recall_finished_at')->nullable();
            });
        }
        if (! $s->hasTable('post_deliveries')) {
            $s->create('post_deliveries', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->foreignId('post_id')->constrained('posts')->cascadeOnDelete();
                $t->bigInteger('user_id');                // Telegram id
                $t->text('status')->default('pending');   // pending | sent | failed
                $t->bigInteger('message_id')->nullable();
                $t->timestamp('sent_at')->nullable();
                $t->timestamp('ack_at')->nullable();
                $t->timestamp('recalled_at')->nullable();
                $t->text('error')->nullable();
                $t->text('recall_error')->nullable();
                $t->unique(['post_id', 'user_id']);
            });
        }
        foreach ([
            "idx_post_deliveries_pending ON post_deliveries(post_id, id) WHERE status = 'pending'",
            'idx_post_deliveries_user ON post_deliveries(user_id)',
        ] as $index) {
            DB::connection($this->connection)->statement('CREATE INDEX IF NOT EXISTS '.$index);
        }
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('post_deliveries');
        Schema::connection($this->connection)->dropIfExists('posts');
    }
};
