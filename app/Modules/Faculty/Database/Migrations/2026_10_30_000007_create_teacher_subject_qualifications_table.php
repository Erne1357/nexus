<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Perfil: el jefe declara qué materias puede dar un docente y con qué nivel. Único por docente y materia,
 * también contra una borrada; quien lo declaró queda en `qualified_by`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teacher_subject_qualifications', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('teacher_id')->constrained();
            $table->foreignUuid('subject_id')->constrained();
            $table->string('level');
            $table->foreignUuid('qualified_by')->constrained('users');
            $table->text('notes')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['teacher_id', 'subject_id']);
            $table->index('subject_id');
            $table->index('qualified_by');
        });

        DB::statement("alter table teacher_subject_qualifications add constraint teacher_subject_qualifications_level_check check (level in ('apto', 'preferente'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_subject_qualifications');
    }
};
