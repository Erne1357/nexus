<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Alias de una materia: la clave con que el SII la conoce (en un plan, si se sabe cuál). Una materia puede tener
 * varias claves del SII y una clave apunta a una sola materia, así que `code` es único en toda la tabla. Es un
 * mapeo técnico que escribe solo la importación: sin borrado lógico ni `is_active` (supuesto por confirmar).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subject_aliases', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('subject_id')->constrained();
            $table->foreignUuid('study_plan_id')->nullable()->constrained();
            $table->string('code')->unique();
            $table->string('source')->default('sii');
            $table->timestampsTz();

            $table->index('subject_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subject_aliases');
    }
};
