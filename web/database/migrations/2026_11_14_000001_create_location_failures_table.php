<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Location checks a staff member failed in the Mini App (too far from the street or the object, a weak GPS fix, a point
 * picked far away): where they stood and the place it was compared with. The dashboard's own data; the bot never reads it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('location_failures', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('staff_id')->index();
            $t->unsignedBigInteger('street_id')->nullable();
            $t->unsignedBigInteger('object_id')->nullable();
            $t->unsignedBigInteger('inspection_id')->nullable();
            $t->string('kind', 20);    // add_object | evidence | point
            $t->string('reason', 20);  // off_street | too_far | point_far | accuracy
            $t->double('latitude');
            $t->double('longitude');
            $t->double('accuracy')->nullable();
            $t->double('target_lat')->nullable();  // the object's place (or where the staff member stood, for a picked point)
            $t->double('target_lon')->nullable();
            $t->double('distance_m')->nullable();
            $t->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('location_failures');
    }
};
