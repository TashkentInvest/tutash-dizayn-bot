<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * chat_id: the private chat the bot writes to (saved on every visit; in a private chat it is the Telegram id).
 * blocked_at: when Telegram said the user can no longer be reached (blocked the bot, account deleted); cleared when
 * they come back. Lets the dashboard and a mass message skip the unreachable.
 */
return new class extends Migration
{
    protected $connection = 'bot';

    public function up(): void
    {
        $s = Schema::connection($this->connection);
        foreach (['chat_id' => 'bigInteger', 'blocked_at' => 'timestamp'] as $column => $type) {
            if (! $s->hasColumn('users', $column)) {
                $s->table('users', fn (Blueprint $t) => $t->{$type}($column)->nullable());
            }
        }
        // the ones already known: a private chat's id is the user's id
        \Illuminate\Support\Facades\DB::connection($this->connection)->table('users')->whereNull('chat_id')
            ->update(['chat_id' => \Illuminate\Support\Facades\DB::raw('user_id')]);
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('users', fn (Blueprint $t) => $t->dropColumn(['chat_id', 'blocked_at']));
    }
};
