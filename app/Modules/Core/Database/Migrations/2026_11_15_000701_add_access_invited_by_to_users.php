<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Quién mandó la invitación de acceso vigente desde el panel (CF-07, confirmado el 14 de noviembre de 2026):
 * `access_invited_by` es el admin que la mandó con InviteUser; nula en las automáticas (la validación del catálogo de
 * docentes, AC-09, y la captura del correo institucional, AC-07) y si no hay invitación. Solo la del admin abre aunque el
 * usuario tenga un puesto distinto de docente (AccessInvitationLink::opens()): así nadie lleva a otro buzón la
 * invitación automática de un docente que después se vuelve jefe. Cada invitación la vuelve a escribir, y quien deja
 * `access_invited_at` nulo también la deja nula: lo garantiza el CHECK `users_access_invited_by_requires_at`. down()
 * quita el CHECK y la columna.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignUuid('access_invited_by')->nullable()->constrained('users');
        });

        DB::statement('alter table users add constraint users_access_invited_by_requires_at check (access_invited_by is null or access_invited_at is not null)');
    }

    public function down(): void
    {
        DB::statement('alter table users drop constraint if exists users_access_invited_by_requires_at');

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('access_invited_by');
        });
    }
};
