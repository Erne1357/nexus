<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Los correos de puesto en espera (CE-02, tarea 1.63; ADR-0039): el aviso de un puesto que todavía no tiene buzón va a
 * la campana y su correo queda aquí (decisión del usuario del 26 de noviembre de 2026) hasta que el admin capture el
 * buzón. Guarda la notificación serializada y cifrada con APP_KEY, el id de esa notificación (único: si la cola vuelve a
 * entregar el mismo correo no se guarda dos veces), el usuario a quien iba, el puesto (rol y alcance, sin FK: es una
 * cola, no debe trabar nada), si es un aviso de la carga académica (MN-05: no se libera mientras está oculta), el tipo y
 * el asunto para la pantalla, cuántas veces se intentó mandar, y cuándo se retuvo, se tomó para mandarse (`released_at`),
 * falló o el admin lo descartó. No es catálogo ni bitácora: solo se agrega y se marca. Índice parcial sobre lo que sigue
 * en espera.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('held_position_mails', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('notification_id')->unique();
            $table->foreignUuid('user_id')->constrained('users');
            $table->string('role', 40);
            $table->uuid('org_unit_id')->nullable();
            $table->uuid('career_id')->nullable();
            $table->uuid('campus_id')->nullable();
            $table->uuid('modality_id')->nullable();
            $table->boolean('academic_load')->default(false);
            $table->string('kind');
            $table->string('subject')->nullable();
            $table->text('notification');
            $table->timestampTz('held_at')->useCurrent();
            $table->timestampTz('released_at')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('failed_at')->nullable();
            $table->timestampTz('discarded_at')->nullable();
        });

        DB::statement('CREATE INDEX held_position_mails_waiting ON held_position_mails (role, held_at) WHERE released_at IS NULL AND failed_at IS NULL AND discarded_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('held_position_mails');
    }
};
