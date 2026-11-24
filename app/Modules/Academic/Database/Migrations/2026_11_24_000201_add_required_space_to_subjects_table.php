<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tipo de espacio que pide la materia (1.33): `aula`, `sala_computo` o `laboratorio`. Nulo = sin revisar; las reglas de
 * salón lo tratan como aula, pero publicar una selección exige que esté marcado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subjects', fn (Blueprint $table) => $table->string('required_space')->nullable());

        DB::statement("ALTER TABLE subjects ADD CONSTRAINT subjects_required_space_check CHECK (required_space IS NULL OR required_space IN ('aula', 'sala_computo', 'laboratorio'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE subjects DROP CONSTRAINT subjects_required_space_check');
        Schema::table('subjects', fn (Blueprint $table) => $table->dropColumn('required_space'));
    }
};
