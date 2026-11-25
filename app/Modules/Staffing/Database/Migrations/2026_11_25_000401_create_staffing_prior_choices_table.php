<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Marca de «Elección previa» (SEL-16, tarea 1.41): el docente ya trae sus grupos escritos en la relación de materias y
 * no recibe turno en la orden de selección de su periodo y modalidad. Una por docente, periodo y modalidad.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staffing_prior_choices', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('term_id')->constrained();
            $table->foreignUuid('modality_id')->constrained();
            $table->foreignUuid('teacher_id')->constrained();
            $table->foreignUuid('recorded_by')->constrained('users');
            $table->timestampTz('recorded_at');
            $table->text('note')->nullable();
            $table->timestampsTz();

            $table->unique(['term_id', 'modality_id', 'teacher_id']);
            $table->index('teacher_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staffing_prior_choices');
    }
};
