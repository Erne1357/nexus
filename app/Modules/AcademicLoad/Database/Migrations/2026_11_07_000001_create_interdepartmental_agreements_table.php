<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Acuerdos entre departamentos (docs/dominio/modelo-de-datos.md, `interdepartmental_agreements`; tareas 3.6 y 3.7):
 * lo que hoy se acuerda de palabra en la junta interdepartamental queda registrado y lo verifica el validador.
 *
 * - **Préstamo de salón** (`room_loan`): un salón del campus, un día y unas horas, para un grupo del departamento que
 *   lo pide (`from`); lo acepta o rechaza el departamento dueño del salón (`to`). HC-10 solo cuenta los aceptados.
 * - **Grupo compartido** (`shared_group`): el cupo de una carrera de otro departamento en un grupo; lo propone el
 *   departamento que imparte la materia (`from`) y lo acepta el jefe del departamento de esa carrera (`to`;
 *   confirmado el 7 de noviembre de 2026). El cupo nace al aceptarse.
 *
 * `group_id` (siempre presente) no lleva llave foránea: los grupos son de Scheduling, a la derecha de AcademicLoad en el mapa. La
 * llave compuesta (`room_id`, `campus_id`) a `rooms` exige un salón del campus del acuerdo. Un CHECK amarra la forma
 * de cada tipo; otros, los departamentos distintos, el día, las horas, los lugares, el motivo al rechazar y que
 * quién decidió y cuándo vayan juntos y solo fuera de `proposed`. Un salón no se presta dos veces a la misma hora del
 * mismo día y periodo, y una carrera tiene a lo más un acuerdo vivo (propuesto o aceptado) por grupo. No se borra:
 * se rechaza o se cancela. Que `to` sea el dueño del salón o el departamento de la carrera lo aseguran las Actions que
 * los crean (RequestRoomLoan, ProposeSharedGroup), porque el dueño de un salón puede cambiar entre periodos.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('create extension if not exists btree_gist');

        Schema::create('interdepartmental_agreements', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('term_id')->constrained();
            $table->foreignUuid('campus_id')->constrained();
            $table->string('kind');
            $table->foreignUuid('from_department_id')->constrained('departments');
            $table->foreignUuid('to_department_id')->constrained('departments');
            $table->uuid('group_id');
            $table->uuid('room_id')->nullable();
            $table->unsignedSmallInteger('day')->nullable();
            $table->time('starts_at')->nullable();
            $table->time('ends_at')->nullable();
            $table->foreignUuid('career_id')->nullable()->constrained();
            $table->unsignedSmallInteger('max_seats')->nullable();
            $table->string('status')->default('proposed');
            $table->text('comment')->nullable();
            $table->foreignUuid('requested_by')->constrained('users');
            $table->foreignUuid('decided_by')->nullable()->constrained('users');
            $table->timestampTz('decided_at')->nullable();
            $table->timestampsTz();

            $table->foreign(['room_id', 'campus_id'], 'interdepartmental_agreements_room_campus_foreign')->references(['id', 'campus_id'])->on('rooms');
            $table->index(['term_id', 'campus_id', 'to_department_id', 'status']);
            $table->index(['term_id', 'campus_id', 'from_department_id', 'status']);
            $table->index('group_id');
        });

        $checks = [
            'kind_check' => "kind in ('room_loan', 'shared_group')",
            'status_check' => "status in ('proposed', 'accepted', 'rejected', 'cancelled')",
            'departments_check' => 'from_department_id <> to_department_id',
            'shape_check' => <<<'SQL'
                (kind = 'room_loan' and room_id is not null and day is not null and starts_at is not null and ends_at is not null
                    and career_id is null and max_seats is null)
                or (kind = 'shared_group' and career_id is not null and max_seats is not null
                    and room_id is null and day is null and starts_at is null and ends_at is null)
                SQL,
            'day_check' => 'day between 1 and 6',
            'time_check' => 'ends_at > starts_at',
            'seats_check' => 'max_seats > 0',
            'rejected_comment_check' => "status <> 'rejected' or btrim(coalesce(comment, '')) <> ''",
            'decision_check' => "(status = 'proposed') = (decided_at is null) and (decided_at is null) = (decided_by is null)",
        ];

        foreach ($checks as $name => $check) {
            DB::statement("alter table interdepartmental_agreements add constraint interdepartmental_agreements_{$name} check ({$check})");
        }

        DB::statement(<<<'SQL'
            alter table interdepartmental_agreements add constraint interdepartmental_agreements_room_no_overlap exclude using gist (
                room_id with =,
                term_id with =,
                day with =,
                tsrange(date '2000-01-01' + starts_at, date '2000-01-01' + ends_at) with &&
            ) where (kind = 'room_loan' and status in ('proposed', 'accepted'))
            SQL);

        DB::statement(<<<'SQL'
            create unique index interdepartmental_agreements_shared_unique on interdepartmental_agreements (group_id, career_id)
            where kind = 'shared_group' and status in ('proposed', 'accepted')
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('interdepartmental_agreements');
    }
};
