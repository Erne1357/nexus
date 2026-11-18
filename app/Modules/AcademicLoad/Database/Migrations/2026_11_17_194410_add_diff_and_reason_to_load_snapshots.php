<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El diff y el motivo de cada versión publicada (tarea 8.4; docs/dominio/modelo-de-datos.md, `load_snapshots`): `diff`
 * es lo que cambió contra la versión anterior (forma en `Rules\SnapshotDiff`, nulo en la versión 1) y `reason` de dónde
 * salió la versión (`SnapshotReason`: `published`, `reanalysis` o `correction`). Los dos se escriben al crear la fila, en
 * la misma transacción que la publicación: la tabla sigue siendo inmutable (HC-18, su trigger no cambia). Las versiones
 * anteriores a esta migración quedan con los dos nulos; no se rellenan, porque el trigger no deja reescribirlas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('load_snapshots', function (Blueprint $table) {
            $table->jsonb('diff')->nullable();
            $table->string('reason')->nullable();
        });

        DB::statement("alter table load_snapshots add constraint load_snapshots_reason_check check (reason in ('published', 'reanalysis', 'correction'))");
        DB::statement('alter table load_snapshots add constraint load_snapshots_diff_check check (version > 1 or diff is null)');
    }

    public function down(): void
    {
        Schema::table('load_snapshots', function (Blueprint $table) {
            $table->dropColumn(['diff', 'reason']);
        });
    }
};
