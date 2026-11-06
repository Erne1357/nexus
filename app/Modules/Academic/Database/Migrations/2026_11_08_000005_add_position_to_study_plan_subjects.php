<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El renglón de una materia dentro de su semestre en la retícula (D21, confirmado el 5 de noviembre de 2026): el
 * `renglon` del SII (1 a 99). Nulo es «sin renglón»: va después de las que lo tienen, por clave de materia. Los
 * pendientes de la migración lo guardan también, para que no se pierda el dato del SII.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('study_plan_subjects', function (Blueprint $table) {
            $table->smallInteger('position')->nullable()->after('is_required');
            $table->index(['study_plan_id', 'semester', 'position']);
        });

        Schema::table('study_plan_subject_pendings', function (Blueprint $table) {
            $table->smallInteger('position')->nullable()->after('sii_grid');
        });
    }

    public function down(): void
    {
        Schema::table('study_plan_subject_pendings', function (Blueprint $table) {
            $table->dropColumn('position');
        });

        Schema::table('study_plan_subjects', function (Blueprint $table) {
            $table->dropIndex(['study_plan_id', 'semester', 'position']);
            $table->dropColumn('position');
        });
    }
};
