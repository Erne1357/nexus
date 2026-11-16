<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Historial de grupos por materia (tarea 5.6, docs/dominio/modelo-de-datos.md, `historical_groups`): los grupos que se
 * abrieron en periodos pasados, con su cupo y sus inscritos, para que la propuesta de carrera compare contra los
 * últimos periodos del mismo tipo. Antes de Nexus sale de lo migrado del SII (`source = sii`); desde 20271, de lo que
 * Nexus publica (`source = nexus`, con la versión del snapshot de la que sale).
 *
 * `term_code` es texto y no llave: el historial del SII trae periodos que Nexus no tiene en `terms`. `enrolled` es
 * nulo en la fuente `nexus` (en R1 la inscripción sigue en el SII) y cuando el SII no lo trae. Registro histórico:
 * sin borrado lógico ni `is_active`; se reemplaza al reimportar o al publicar otra versión (supuesto por confirmar).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('historical_groups', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->string('term_code', 5)->index();
            $table->foreignUuid('modality_id')->constrained();
            $table->foreignUuid('campus_id')->nullable()->constrained();
            $table->foreignUuid('subject_id')->constrained();
            $table->string('group_code');
            $table->integer('capacity');
            $table->integer('enrolled')->nullable();
            $table->foreignUuid('career_id')->nullable()->constrained();
            $table->string('parallel_of')->nullable();
            $table->string('source');
            $table->foreignUuid('load_snapshot_id')->nullable()->constrained('load_snapshots');
            $table->timestampTz('recorded_at');
            $table->timestamps();

            $table->unique(['term_code', 'subject_id', 'group_code', 'source']);
            $table->index(['subject_id', 'term_code']);
        });

        DB::statement('alter table historical_groups add constraint historical_groups_capacity_check check (capacity >= 0)');
        DB::statement('alter table historical_groups add constraint historical_groups_enrolled_check check (enrolled is null or enrolled >= 0)');
        DB::statement("alter table historical_groups add constraint historical_groups_source_check check (source in ('sii', 'nexus'))");
        DB::statement("alter table historical_groups add constraint historical_groups_term_code_check check (term_code ~ '^[0-9]{4}[123]$')");
        DB::statement("alter table historical_groups add constraint historical_groups_snapshot_check check ((source = 'nexus') = (load_snapshot_id is not null))");
    }

    public function down(): void
    {
        Schema::dropIfExists('historical_groups');
    }
};
