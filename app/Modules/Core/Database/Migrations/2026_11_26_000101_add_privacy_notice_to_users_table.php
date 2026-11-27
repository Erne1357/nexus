<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La aceptación del aviso de privacidad (AC-22, tarea 1.64): `users.privacy_notice_accepted_at` (timestamptz, cuándo lo
 * aceptó) y `users.privacy_notice_version` (la versión que aceptó, la fecha de realización del aviso; la vigente vive en
 * Core\Support\PrivacyNotice::VERSION, no aquí), nulas. Solo agrega columnas nulas (expand/contract, ADR-0033): el
 * release anterior las ignora.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestampTz('privacy_notice_accepted_at')->nullable();
            $table->string('privacy_notice_version', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['privacy_notice_accepted_at', 'privacy_notice_version']);
        });
    }
};
