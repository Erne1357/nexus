<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Contraer la carga por modalidad (ADR-0025): `interdepartmental_agreements.campus_id` queda solo en el préstamo de
 * salón, donde es el campus del salón (M2: el campus es el lugar; la llave compuesta `(room_id, campus_id)` a `rooms`
 * lo amarra). El acuerdo de un grupo compartido es de la modalidad (`modality_id`) y su lugar es el del grupo, así que
 * la columna queda nula: se vacía en los que ya existen y el CHECK de forma lo exige (y exige el campus en el préstamo:
 * sin él, la llave compuesta no se revisaría). Se quitan los índices de alcance por campus; los de modalidad
 * (2026_11_12_210401) ya los reemplazan.
 *
 * down() devuelve a cada grupo compartido el campus de su grupo y la columna obligatoria, con el CHECK y los índices de
 * antes. Se niega, sin cambiar nada, si un grupo compartido es de un grupo sin campus o que ya no existe, y nombra esos
 * acuerdos.
 */
return new class extends Migration
{
    private const string SHAPE = <<<'SQL'
        (kind = 'room_loan' and room_id is not null and day is not null and starts_at is not null and ends_at is not null
            and career_id is null and max_seats is null)
        or (kind = 'shared_group' and career_id is not null and max_seats is not null
            and room_id is null and day is null and starts_at is null and ends_at is null)
        SQL;

    /**
     * Los índices de alcance por campus de 2026_11_07_000001 (PostgreSQL recorta los nombres a 63 caracteres).
     *
     * @var array<string, string>
     */
    private const array CAMPUS_INDEXES = [
        'interdepartmental_agreements_term_id_campus_id_to_department_id' => 'term_id, campus_id, to_department_id, status',
        'interdepartmental_agreements_term_id_campus_id_from_department_' => 'term_id, campus_id, from_department_id, status',
    ];

    public function up(): void
    {
        DB::statement('alter table interdepartmental_agreements alter column campus_id drop not null');
        DB::table('interdepartmental_agreements')->where('kind', 'shared_group')->update(['campus_id' => null]);
        DB::statement('alter table interdepartmental_agreements drop constraint interdepartmental_agreements_shape_check');
        DB::statement('alter table interdepartmental_agreements add constraint interdepartmental_agreements_shape_check check ((kind = \'room_loan\') = (campus_id is not null) and ('.self::SHAPE.'))');

        foreach (array_keys(self::CAMPUS_INDEXES) as $index) {
            DB::statement("drop index if exists {$index}");
        }
    }

    public function down(): void
    {
        $orphans = DB::table('interdepartmental_agreements')
            ->where('kind', 'shared_group')
            ->whereNull('campus_id')
            ->whereNotExists(fn ($query) => $query->from('groups')->whereColumn('groups.id', 'interdepartmental_agreements.group_id')->whereNotNull('groups.campus_id'))
            ->orderBy('id')
            ->pluck('id');

        if ($orphans->isNotEmpty()) {
            throw new RuntimeException('Estos acuerdos de grupo compartido son de un grupo sin campus o que ya no existe y no se pueden regresar a un acuerdo con campus: '.$orphans->implode(', ').'.');
        }

        DB::statement('alter table interdepartmental_agreements drop constraint interdepartmental_agreements_shape_check');
        DB::statement("update interdepartmental_agreements set campus_id = groups.campus_id from groups where groups.id = interdepartmental_agreements.group_id and interdepartmental_agreements.kind = 'shared_group' and interdepartmental_agreements.campus_id is null");
        DB::statement('alter table interdepartmental_agreements add constraint interdepartmental_agreements_shape_check check ('.self::SHAPE.')');
        DB::statement('alter table interdepartmental_agreements alter column campus_id set not null');

        foreach (self::CAMPUS_INDEXES as $index => $columns) {
            DB::statement("create index if not exists {$index} on interdepartmental_agreements ({$columns})");
        }
    }
};
