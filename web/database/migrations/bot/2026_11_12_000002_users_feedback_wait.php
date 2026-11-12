<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A request being written in the bot: the level the user picked and until when (unix time) the text is awaited.
 * Kept in the database, not in the bot's memory: a restart of the bot no longer forgets it.
 */
return new class extends Migration
{
    protected $connection = 'bot';

    public function up(): void
    {
        $s = Schema::connection($this->connection);
        foreach (['fb_wait' => 'text', 'fb_wait_until' => 'double'] as $column => $type) {
            if (! $s->hasColumn('users', $column)) {
                $s->table('users', fn (Blueprint $t) => $t->{$type}($column)->nullable());
            }
        }
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('users', fn (Blueprint $t) => $t->dropColumn(['fb_wait', 'fb_wait_until']));
    }
};
