<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** phone2: a staff member's second number (the bot accepts either when they confirm with the contact button). */
return new class extends Migration
{
    protected $connection = 'bot';

    public function up(): void
    {
        if (! Schema::connection($this->connection)->hasColumn('staff', 'phone2')) {
            Schema::connection($this->connection)->table('staff', fn (Blueprint $t) => $t->text('phone2')->nullable());
        }
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('staff', fn (Blueprint $t) => $t->dropColumn('phone2'));
    }
};
