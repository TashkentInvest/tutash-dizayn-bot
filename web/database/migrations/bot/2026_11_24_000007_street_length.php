<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** length_km: the street's length from the official list (km), shown on the map (tools/import_street_lengths.py). */
return new class extends Migration
{
    protected $connection = 'bot';

    public function up(): void
    {
        if (! Schema::connection($this->connection)->hasColumn('streets', 'length_km')) {
            Schema::connection($this->connection)->table('streets', fn (Blueprint $t) => $t->double('length_km')->nullable());
        }
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('streets', fn (Blueprint $t) => $t->dropColumn('length_km'));
    }
};
