<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Solicitudes de credencial (CO-07, CO-08): el alumno pide la reposición de la credencial física, la foto o las dos, y
 * Comunicación y Difusión las atiende. Dos índices únicos parciales impiden dos abiertas del mismo tipo, también con
 * dos peticiones a la vez; una CHECK exige al menos un tipo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credential_requests', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('student_id')->constrained();
            $table->boolean('wants_reposition')->default(false);
            $table->boolean('wants_photo')->default(false);
            $table->string('status');
            $table->timestampTz('requested_at');
            $table->timestampTz('scheduled_at')->nullable();
            $table->text('place')->nullable();
            $table->timestampTz('ready_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampTz('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->foreignUuid('handled_by')->nullable()->constrained('users');
            $table->timestampTz('photo_updated_at')->nullable();
            $table->timestampsTz();

            $table->index(['student_id', 'status']);
            $table->index('status');
        });

        DB::statement('alter table credential_requests add constraint credential_requests_type_check check (wants_reposition or wants_photo)');
        DB::statement("alter table credential_requests add constraint credential_requests_status_check check (status in ('solicitada', 'citada', 'lista', 'entregada', 'rechazada', 'cancelada'))");
        DB::statement("create unique index credential_requests_one_open_reposition on credential_requests (student_id) where wants_reposition and status in ('solicitada', 'citada', 'lista')");
        DB::statement("create unique index credential_requests_one_open_photo on credential_requests (student_id) where wants_photo and status in ('solicitada', 'citada', 'lista')");
    }

    public function down(): void
    {
        Schema::dropIfExists('credential_requests');
    }
};
