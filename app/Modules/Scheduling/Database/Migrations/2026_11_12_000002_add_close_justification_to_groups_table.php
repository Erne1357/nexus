<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La justificación de HC-15 (tarea 4.1; P2, confirmado el 10 de noviembre de 2026): el jefe la escribe para el grupo
 * que se queda sin docente o sin salón al cerrar, y queda con quién y cuándo. La escribe solo `JustifyGroup`; con
 * texto, el grupo deja de bloquear el cierre por eso. La lee Subdirección y va en el snapshot; lo público solo dice
 * «Por asignar».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->text('close_justification')->nullable();
            $table->foreignUuid('close_justified_by')->nullable()->constrained('users');
            $table->timestampTz('close_justified_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->dropConstrainedForeignId('close_justified_by');
            $table->dropColumn(['close_justification', 'close_justified_at']);
        });
    }
};
