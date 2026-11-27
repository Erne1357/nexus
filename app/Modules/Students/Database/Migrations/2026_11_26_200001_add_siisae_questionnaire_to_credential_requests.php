<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CO-12: motivo de la reposición y las dos respuestas del cuestionario del SIISAE. Todo nullable: las solicitudes que ya
 * existen, las de solo foto y las anteriores a esta tarea no lo llevan. La CHECK solo acota el motivo a sus tres valores.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credential_requests', function (Blueprint $table) {
            $table->string('reposition_reason')->nullable();
            $table->boolean('siisae_photo_visible')->nullable();
            $table->boolean('siisae_data_correct')->nullable();
        });

        DB::statement("alter table credential_requests add constraint credential_requests_reposition_reason_check check (reposition_reason is null or reposition_reason in ('first_time', 'lost_not_santander', 'lost_was_santander'))");
    }

    public function down(): void
    {
        DB::statement('alter table credential_requests drop constraint if exists credential_requests_reposition_reason_check');

        Schema::table('credential_requests', function (Blueprint $table) {
            $table->dropColumn(['reposition_reason', 'siisae_photo_visible', 'siisae_data_correct']);
        });
    }
};
