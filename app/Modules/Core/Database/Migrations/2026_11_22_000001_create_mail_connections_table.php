<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La cuenta con que Nexus manda el correo (tarea 1.29, ADR-0032, CF-S9): la de Microsoft 365 que el admin conecta en
 * «Correos y colas» con permisos delegados. Una sola por proveedor (índice único en `provider`, CHECK con `microsoft`).
 * Los dos tokens se guardan cifrados con APP_KEY (cast `encrypted` del modelo), por eso son `text`. `expired_at` marca
 * la conexión que Microsoft ya no renueva (invalid_grant): hay que reconectar. Desconectar borra la fila.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_connections', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->string('provider', 20)->unique();
            $table->string('account_email');
            $table->text('access_token');
            $table->text('refresh_token');
            $table->timestampTz('access_token_expires_at');
            $table->foreignUuid('connected_by')->constrained('users');
            $table->timestampTz('connected_at');
            $table->timestampTz('last_used_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestampTz('expired_at')->nullable();
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE mail_connections ADD CONSTRAINT mail_connections_provider_check CHECK (provider IN ('microsoft'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_connections');
    }
};
