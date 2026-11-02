<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Notificaciones en base de datos de Laravel (tarea 2.9), con las llaves de Nexus: UUIDv7 en `id` y en el
 * destinatario polimórfico (ADR-0014), `data` en jsonb y fechas con zona. Cada fila guarda el mensaje de una
 * NexusNotification (título, detalle, icono, destino y el periodo y campus del aviso). No es catálogo ni
 * bitácora: leerla solo llena `read_at`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->string('type');
            $table->uuidMorphs('notifiable');
            $table->jsonb('data');
            $table->timestampTz('read_at')->nullable();
            $table->timestampsTz();

            $table->index(['notifiable_type', 'notifiable_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
