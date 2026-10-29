<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Carrera, con el departamento que la coordina administrativamente. Catálogo: soft deletes e `is_active`;
 * el código es la clave natural y sigue reservado aunque la carrera se borre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('careers', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->string('code')->unique();
            $table->string('name');
            $table->foreignUuid('department_id')->constrained();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('department_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('careers');
    }
};
