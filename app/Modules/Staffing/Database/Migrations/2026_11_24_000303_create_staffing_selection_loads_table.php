<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las cargas incluidas en una selección (tarea 1.33, diseño 6.12, decisión 22): publicar mueve cada una a `staffing`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staffing_selection_loads', function (Blueprint $table) {
            $table->foreignUuid('selection_id')->constrained('staffing_selections')->cascadeOnDelete();
            $table->foreignUuid('academic_load_id')->constrained('academic_loads');

            $table->primary(['selection_id', 'academic_load_id']);
            $table->index('academic_load_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staffing_selection_loads');
    }
};
