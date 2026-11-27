<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las alternativas del motor de una solicitud de cambio (tarea 8.7; CR-02 y CR-03; docs/dominio/modelo-de-datos.md,
 * `change_requests`): jsonb nulo hasta que el jefe las pide. La escribe ProposeChangeAlternatives (los acomodos sin
 * choques, o el mensaje de que no hubo) y ChooseChangeAlternative marca la elegida al aprobar. Va aparte de `impact`,
 * porque el impacto se recalcula cada vez que el jefe abre una pendiente y las alternativas no.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('change_requests', function (Blueprint $table) {
            $table->jsonb('alternatives')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('change_requests', function (Blueprint $table) {
            $table->dropColumn('alternatives');
        });
    }
};
