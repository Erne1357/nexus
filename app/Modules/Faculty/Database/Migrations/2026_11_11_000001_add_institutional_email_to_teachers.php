<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El correo institucional del docente (AC-06, tarea 1.16): opcional y único sin importar mayúsculas entre todos los
 * docentes, también los borrados, como el RFC. A él va la invitación de acceso. El dominio lo revisa TeacherRules.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teachers', function (Blueprint $table) {
            $table->string('institutional_email')->nullable();
        });

        DB::statement('create unique index teachers_institutional_email_unique on teachers (lower(institutional_email))');
    }

    public function down(): void
    {
        DB::statement('drop index if exists teachers_institutional_email_unique');

        Schema::table('teachers', function (Blueprint $table) {
            $table->dropColumn('institutional_email');
        });
    }
};
