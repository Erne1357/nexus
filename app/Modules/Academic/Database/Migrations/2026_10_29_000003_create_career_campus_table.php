<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * En qué campus se ofrece cada carrera. Una vez por carrera y campus, también contra una fila borrada: se
 * deja de ofrecer con `is_active` y, si se quita, con borrado lógico, como todo en Academic (confirmado el
 * 29 de octubre de 2026). El coordinador se asigna por `role_scopes`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('career_campus', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('career_id')->constrained();
            $table->foreignUuid('campus_id')->constrained();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['career_id', 'campus_id']);
            $table->index('campus_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('career_campus');
    }
};
