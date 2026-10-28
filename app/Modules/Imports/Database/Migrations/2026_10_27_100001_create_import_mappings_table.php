<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mapeo de columnas guardado (ADR-0005): cada layout de exportación del SII se mapea una vez a los
 * campos de una plantilla y se reutiliza. `columns` es columna de origen → campo de la plantilla. El
 * nombre no se repite dentro de la plantilla. Como un catálogo, un mapeo que ya no sirve se desactiva
 * y nunca se borra: los lotes que lo usaron lo siguen citando.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_mappings', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->string('template');
            $table->string('name');
            $table->jsonb('columns')->default('{}');
            $table->foreignUuid('created_by')->constrained('users');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['template', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_mappings');
    }
};
