<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Horas que un docente tiene autorizadas en un periodo por encima de su base más la tolerancia (tarea 1.33, SEL-12):
 * el límite de HC-08 es el mayor de los dos. Una fila por docente y periodo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teacher_hour_allowances', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('teacher_id')->constrained();
            $table->foreignUuid('term_id')->constrained();
            $table->unsignedSmallInteger('hours');
            $table->text('reason');
            $table->foreignUuid('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('granted_at');
            $table->timestampsTz();

            $table->unique(['teacher_id', 'term_id']);
            $table->index('term_id');
        });

        DB::statement('alter table teacher_hour_allowances add constraint teacher_hour_allowances_hours_check check (hours > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_hour_allowances');
    }
};
