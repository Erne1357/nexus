<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El usuario del alumno (AC-10): username = número de control, con su NIP del SII como contraseña. Lo crea y lo liga
 * la migración del SII (SyncStudentAccounts); nulo hasta entonces. Un usuario es de un solo alumno.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->foreignUuid('user_id')->nullable()->unique()->constrained();
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
