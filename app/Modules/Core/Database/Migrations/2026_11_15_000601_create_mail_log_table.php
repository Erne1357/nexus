<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Registro de los correos que salen de Nexus (CF-S8): destinatario, asunto, tipo (la clase corta de la notificación
 * o del mailable), el usuario si se reconoce, el estado y la hora. Nunca guarda el cuerpo, porque lleva enlaces con
 * token. No es catálogo ni bitácora: solo se agrega.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_log', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->text('to');
            $table->string('subject');
            $table->string('kind');
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status');
            $table->text('error')->nullable();
            $table->timestampTz('sent_at')->useCurrent();

            $table->index('sent_at');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_log');
    }
};
