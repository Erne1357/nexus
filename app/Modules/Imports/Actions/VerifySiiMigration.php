<?php

namespace App\Modules\Imports\Actions;

use App\Modules\Imports\Enums\VerificationDiagnosis;
use App\Modules\Imports\Sii\KardexCodes;
use App\Modules\Imports\Sii\SiiVerification;
use App\Modules\Students\Actions\RefreshStudentProgress;
use Illuminate\Support\Facades\DB;

/**
 * Compara la exportación del SII guardada en `sii_archivo` con lo que quedó en Nexus y deja el resultado en el esquema
 * `sii_verificacion` (decisión del 10 de noviembre de 2026, confirmada: cada corrida compara el original con Nexus y
 * avisa si algo no cuadra). Como `sii_archivo`, no es parte del modelo de dominio: lo consulta el administrador en la
 * base, no se edita, vive fuera de las migraciones y cada corrida lo reemplaza completo en una sola transacción. Borra
 * sin CASCADE: si alguien creó una vista sobre una de sus tablas, la verificación falla en lugar de borrarla.
 *
 * Antes de comparar recalcula el avance de todos los alumnos aquí mismo (RefreshStudentProgress, lo que hace el job
 * RecomputeStudentProgress), porque el job que encolaron las plantillas puede no haber corrido aún; lo hace directo y no
 * con `dispatchSync()`, que con el candado del job (WithoutOverlapping) se saltaría el recálculo si la cola ya está
 * recalculando. Devuelve lo que tardó.
 *
 * - `materias`: un renglón por renglón de `historia_alumno`, con su línea, el alumno, el periodo, la clave del SII, la
 *   materia y el plan de Nexus a los que llegó y su diagnóstico (VerificationDiagnosis). El renglón se une con el kárdex
 *   de Nexus por alumno, periodo y clave del SII (`upper(sii_subject_code)`); el periodo es el que guarda Nexus: el del
 *   SII si es un periodo (KardexCodes::term), si no, ninguno. Un renglón que no llegó se explica con la materia de Nexus
 *   que le tocaba según los mapas de claves de la corrida (`$subjectForPlan` del plan del alumno y, si no está ahí,
 *   `$subjectFor`, como KardexSubjects).
 * - `alumnos`: un renglón por alumno de `alumnos_todos`. Según el SII (regla confirmada), está completo cuando los créditos
 *   de sus materias aprobadas (`estatus_materia` A) que están en su propia retícula llegan a los `creditos_totales` de
 *   esa retícula; una materia aprobada dos veces cuenta una vez. Según Nexus, cuando `student_progress.credits_approved`
 *   llega a `study_plans.total_credits` (el avance ya cuenta lo aprobado en materias pendientes del plan).
 *
 * Un alumno, una retícula o una materia repetidos en el archivo cuentan una vez: el primero por línea; de una materia
 * repetida en una retícula, sus créditos mayores.
 *
 * Todo se une por hash, sin índices sobre `sii_archivo`; lo que no llegó al kárdex se explica con una búsqueda por
 * alumno y materia (índice único del kárdex) solo en esos renglones. Con datos sintéticos del tamaño de la exportación
 * real (68 000 alumnos, dos millones de renglones de historia y de kárdex), el paso tarda alrededor de un minuto.
 */
final class VerifySiiMigration
{
    public const string SCHEMA = 'sii_verificacion';

    /** Tabla temporal con los mapas de claves de la corrida: plan de Nexus ('' = alias general), clave del SII y materia de Nexus. */
    private const string KEYS = 'sii_verificacion_claves';

    /**
     * Las tablas que lee la comparación. Antes de comparar se les actualizan las estadísticas: el archivo y el kárdex se
     * acaban de cargar y, sin ellas, PostgreSQL puede creerlas vacías y unirlas renglón por renglón en lugar de por hash.
     *
     * @var list<string>
     */
    private const array COMPARED = [
        'sii_archivo.historia_alumno', 'sii_archivo.alumnos_todos', 'sii_archivo.materias_carreras', 'sii_archivo.carreras',
        'kardex_records', 'students', 'student_progress', 'study_plans', 'subjects', 'study_plan_subjects', 'study_plan_subject_pendings',
    ];

    /** Cuántas líneas de la historia se citan por clave del SII en lo que no pasó sin explicación. */
    private const int SHOWN_LINES = 5;

    public function __construct(private readonly RefreshStudentProgress $progress) {}

    /**
     * @param  array<string|int, string>  $subjectFor  clave del SII → materia de Nexus (alias general)
     * @param  array<string, array<string|int, string>>  $subjectForPlan  plan de Nexus → clave del SII → materia de Nexus
     */
    public function handle(array $subjectFor, array $subjectForPlan): SiiVerification
    {
        $started = hrtime(true);
        $this->progress->handle();
        $progressSeconds = (hrtime(true) - $started) / 1e9;

        DB::transaction(function () use ($subjectFor, $subjectForPlan): void {
            $this->reset();
            $this->loadKeys($subjectFor, $subjectForPlan);
            DB::statement('analyze '.implode(', ', self::COMPARED));
            DB::statement($this->subjectsSql());
            DB::statement($this->studentsSql());
            DB::statement('alter table '.self::SCHEMA.'.materias add primary key (_linea)');
            DB::statement('create index on '.self::SCHEMA.'.materias (no_de_control)');
            DB::statement('create index on '.self::SCHEMA.'.materias (diagnostico, clave_sii)');
            DB::statement('alter table '.self::SCHEMA.'.alumnos add primary key (no_de_control)');
        });

        return $this->result($progressSeconds);
    }

    /**
     * Crea el esquema si no existe, borra sus tablas y vuelve a crear las dos vacías.
     */
    private function reset(): void
    {
        $schema = self::SCHEMA;

        DB::statement("create schema if not exists {$schema}");
        DB::statement("comment on schema {$schema} is 'Verificación de la última corrida de imports:migrate-sii: el SII original (sii_archivo) contra Nexus. Se reemplaza en cada corrida. No se edita.'");

        foreach (DB::table('pg_tables')->where('schemaname', $schema)->pluck('tablename') as $table) {
            DB::statement("drop table {$schema}.\"".str_replace('"', '""', $table).'"');
        }

        DB::statement("create table {$schema}.materias (
            _linea integer not null,
            no_de_control text not null,
            periodo text not null,
            periodo_nexus text,
            clave_sii text not null,
            estatus_sii text not null,
            reticula_sii text,
            creditos_sii numeric,
            plan_nexus text,
            clave_nexus text,
            creditos_nexus integer,
            resultado_nexus text,
            clave_nexus_esperada text,
            diagnostico text not null
        )");
        DB::statement("comment on table {$schema}.materias is 'Un renglón por renglón de sii_archivo.historia_alumno (_linea): la materia y el plan de Nexus a los que llegó y su diagnóstico.'");

        DB::statement("create table {$schema}.alumnos (
            _linea integer not null,
            no_de_control text not null,
            reticula_sii text not null,
            estatus_sii text not null,
            creditos_requeridos_sii numeric,
            creditos_campo_sii numeric,
            creditos_sii numeric not null,
            materias_sii integer not null,
            completo_sii boolean not null,
            en_nexus boolean not null,
            plan_nexus text,
            estatus_nexus text,
            creditos_requeridos_nexus integer,
            creditos_nexus integer,
            materias_nexus integer,
            completo_nexus boolean,
            creditos_perdidos numeric not null
        )");
        DB::statement("comment on table {$schema}.alumnos is 'Un renglón por alumno de sii_archivo.alumnos_todos (_linea): créditos y si está completo según el SII y según Nexus.'");
    }

    /**
     * @param  array<string|int, string>  $subjectFor
     * @param  array<string, array<string|int, string>>  $subjectForPlan
     */
    private function loadKeys(array $subjectFor, array $subjectForPlan): void
    {
        DB::statement('drop table if exists pg_temp.'.self::KEYS);
        DB::statement('create temporary table '.self::KEYS.' (plan_nexus text not null, clave_sii text not null, clave_nexus text not null) on commit drop');

        $rows = [];

        foreach (['' => $subjectFor, ...$subjectForPlan] as $plan => $map) {
            foreach ($map as $materia => $subject) {
                $rows[] = ['plan_nexus' => (string) $plan, 'clave_sii' => (string) $materia, 'clave_nexus' => $subject];
            }
        }

        foreach (array_chunk($rows, 1000) as $chunk) {
            DB::table(self::KEYS)->insert($chunk);
        }

        DB::statement('analyze '.self::KEYS);
    }

    private function subjectsSql(): string
    {
        $schema = self::SCHEMA;
        $keys = self::KEYS;
        $credits = $this->numeric('creditos_materia');
        $first = KardexCodes::FIRST_YEAR;
        $last = (int) date('Y') + 1;
        $diagnosis = fn (VerificationDiagnosis $case): string => "'".str_replace("'", "''", $case->value)."'";

        return <<<SQL
            insert into {$schema}.materias (_linea, no_de_control, periodo, periodo_nexus, clave_sii, estatus_sii, reticula_sii, creditos_sii,
                plan_nexus, clave_nexus, creditos_nexus, resultado_nexus, clave_nexus_esperada, diagnostico)
            with historia as (
                select h._linea, h.no_de_control, h.periodo, h.materia, h.estatus_materia,
                    case when h.periodo ~ '^[0-9]{4}[123]$' then case when left(h.periodo, 4)::int between {$first} and {$last} then h.periodo end end as periodo_nexus
                from sii_archivo.historia_alumno h
            ), alumno as (
                select distinct on (no_de_control) no_de_control, carrera, reticula
                from sii_archivo.alumnos_todos
                order by no_de_control, _linea
            ), lugar as (
                select carrera, reticula, materia, max({$credits}) as creditos
                from sii_archivo.materias_carreras
                group by carrera, reticula, materia
            ), en_alguna_reticula as (
                select distinct materia from sii_archivo.materias_carreras
            ), kardex as (
                select distinct on (student_id, coalesce(term_code, ''), upper(sii_subject_code))
                    id, student_id, subject_id, coalesce(term_code, '') as periodo, upper(sii_subject_code) as clave, result
                from kardex_records
                where deleted_at is null and sii_subject_code is not null
                order by student_id, coalesce(term_code, ''), upper(sii_subject_code), updated_at desc, id
            ), dos_claves as (
                select student_id, subject_id
                from kardex_records
                where deleted_at is null and result = 'aprobada' and sii_subject_code is not null
                group by student_id, subject_id
                having count(distinct upper(sii_subject_code)) > 1
            )
            select h._linea, h.no_de_control, h.periodo, h.periodo_nexus, h.materia, h.estatus_materia,
                a.carrera || '/' || a.reticula, l.creditos,
                sp.code, s.code, s.credits, k.result, esperada.code,
                case
                    when st.id is null then {$diagnosis(VerificationDiagnosis::StudentMissing)}
                    when k.id is null then case
                        when exists (
                            select 1 from kardex_records otra
                            where otra.student_id = st.id and otra.subject_id = esperada.id
                                and coalesce(otra.term_code, '') = coalesce(h.periodo_nexus, '') and otra.deleted_at is null
                        ) then {$diagnosis(VerificationDiagnosis::KeptOther)}
                        when r.materia is null then {$diagnosis(VerificationDiagnosis::UnknownKey)}
                        else {$diagnosis(VerificationDiagnosis::Unexplained)}
                    end
                    when sps.id is null and pd.id is not null then {$diagnosis(VerificationDiagnosis::PlanPending)}
                    when sps.id is null then {$diagnosis(VerificationDiagnosis::OutsidePlan)}
                    when d.subject_id is not null then {$diagnosis(VerificationDiagnosis::SameSubject)}
                    when l.materia is null then {$diagnosis(VerificationDiagnosis::OutsideSiiGrid)}
                    when l.creditos is distinct from s.credits then {$diagnosis(VerificationDiagnosis::CreditsDiffer)}
                    else {$diagnosis(VerificationDiagnosis::Same)}
                end
            from historia h
            left join alumno a on a.no_de_control = h.no_de_control
            left join lugar l on l.carrera = a.carrera and l.reticula = a.reticula and l.materia = h.materia
            left join en_alguna_reticula r on r.materia = h.materia
            left join students st on st.control_number = h.no_de_control and st.deleted_at is null
            left join study_plans sp on sp.id = st.study_plan_id
            left join kardex k on k.student_id = st.id and k.periodo = coalesce(h.periodo_nexus, '') and k.clave = upper(h.materia)
            left join subjects s on s.id = k.subject_id
            left join study_plan_subjects sps on sps.study_plan_id = st.study_plan_id and sps.subject_id = k.subject_id and sps.deleted_at is null
            left join study_plan_subject_pendings pd on pd.study_plan_id = st.study_plan_id and pd.subject_id = k.subject_id and pd.resolved_at is null
            left join dos_claves d on d.student_id = st.id and d.subject_id = k.subject_id
            left join {$keys} por_plan on por_plan.plan_nexus = sp.code and por_plan.clave_sii = h.materia
            left join {$keys} general on general.plan_nexus = '' and general.clave_sii = h.materia
            left join subjects esperada on esperada.code = coalesce(por_plan.clave_nexus, general.clave_nexus)
            SQL;
    }

    private function studentsSql(): string
    {
        $schema = self::SCHEMA;
        $credits = $this->numeric('creditos_materia');
        $total = $this->numeric('creditos_totales');
        $field = $this->numeric('a.creditos_aprobados');

        return <<<SQL
            insert into {$schema}.alumnos (_linea, no_de_control, reticula_sii, estatus_sii, creditos_requeridos_sii, creditos_campo_sii,
                creditos_sii, materias_sii, completo_sii, en_nexus, plan_nexus, estatus_nexus, creditos_requeridos_nexus, creditos_nexus,
                materias_nexus, completo_nexus, creditos_perdidos)
            with alumno as (
                select distinct on (no_de_control) _linea, no_de_control, carrera, reticula, estatus_alumno, creditos_aprobados
                from sii_archivo.alumnos_todos
                order by no_de_control, _linea
            ), lugar as (
                select carrera, reticula, materia, max({$credits}) as creditos
                from sii_archivo.materias_carreras
                group by carrera, reticula, materia
            ), reticula as (
                select distinct on (carrera, reticula) carrera, reticula, {$total} as total
                from sii_archivo.carreras
                order by carrera, reticula, _linea
            ), aprobadas as (
                select h.no_de_control, h.materia, max(l.creditos) as creditos, bool_or(l.materia is not null) as en_reticula
                from sii_archivo.historia_alumno h
                join alumno a on a.no_de_control = h.no_de_control
                left join lugar l on l.carrera = a.carrera and l.reticula = a.reticula and l.materia = h.materia
                where h.estatus_materia in ('A', 'a')
                group by h.no_de_control, h.materia
            ), sii as (
                select no_de_control, coalesce(sum(creditos) filter (where en_reticula), 0) as creditos, count(*) filter (where en_reticula) as materias
                from aprobadas
                group by no_de_control
            )
            select a._linea, a.no_de_control, a.carrera || '/' || a.reticula, a.estatus_alumno, c.total, {$field},
                coalesce(x.creditos, 0), coalesce(x.materias, 0), coalesce(coalesce(x.creditos, 0) >= c.total, false),
                st.id is not null, sp.code, st.status, sp.total_credits, p.credits_approved, p.subjects_approved,
                case when st.id is not null then coalesce(p.credits_approved >= sp.total_credits, false) end,
                coalesce(x.creditos, 0) - coalesce(p.credits_approved, 0)
            from alumno a
            left join reticula c on c.carrera = a.carrera and c.reticula = a.reticula
            left join sii x on x.no_de_control = a.no_de_control
            left join students st on st.control_number = a.no_de_control and st.deleted_at is null
            left join study_plans sp on sp.id = st.study_plan_id
            left join student_progress p on p.student_id = st.id
            SQL;
    }

    private function result(float $progressSeconds): SiiVerification
    {
        $schema = self::SCHEMA;
        $counts = DB::table("{$schema}.materias")->groupBy('diagnostico')->selectRaw('diagnostico, count(*) as total')->pluck('total', 'diagnostico');
        $diagnoses = [];

        foreach (VerificationDiagnosis::cases() as $case) {
            if (isset($counts[$case->value])) {
                $diagnoses[$case->value] = (int) $counts[$case->value];
            }
        }

        $statuses = DB::table("{$schema}.alumnos")
            ->selectRaw('estatus_sii, count(*) as alumnos, count(*) filter (where completo_sii) as completos_sii, count(*) filter (where completo_nexus) as completos_nexus, count(*) filter (where not en_nexus) as fuera')
            ->groupBy('estatus_sii')->orderBy('estatus_sii')->get()
            ->map(fn (object $row): array => [
                'status' => (string) $row->estatus_sii,
                'students' => (int) $row->alumnos,
                'completeSii' => (int) $row->completos_sii,
                'completeNexus' => (int) $row->completos_nexus,
                'missing' => (int) $row->fuera,
            ])->all();

        $incomplete = DB::table("{$schema}.alumnos")
            ->where('completo_sii', true)->where('en_nexus', true)->where('completo_nexus', false)
            ->orderBy('no_de_control')->get()
            ->map(fn (object $row): array => [
                'control' => (string) $row->no_de_control,
                'grid' => (string) $row->reticula_sii,
                'required' => self::number($row->creditos_requeridos_sii),
                'sii' => self::number($row->creditos_sii),
                'plan' => (string) $row->plan_nexus,
                'planTotal' => $row->creditos_requeridos_nexus === null ? null : (int) $row->creditos_requeridos_nexus,
                'nexus' => $row->creditos_nexus === null ? null : (int) $row->creditos_nexus,
                'lost' => self::number($row->creditos_perdidos),
            ])->all();

        $unexplained = DB::table("{$schema}.materias")
            ->where('diagnostico', VerificationDiagnosis::Unexplained->value)
            ->selectRaw('clave_sii, count(*) as renglones, array_to_string((array_agg(_linea order by _linea))[1:'.self::SHOWN_LINES."], ', ') as lineas")
            ->groupBy('clave_sii')->orderBy('clave_sii')->get()
            ->map(fn (object $row): array => ['materia' => (string) $row->clave_sii, 'rows' => (int) $row->renglones, 'lines' => (string) $row->lineas])
            ->all();

        $outsideSiiGrid = DB::table("{$schema}.materias")
            ->where('diagnostico', VerificationDiagnosis::OutsideSiiGrid->value)
            ->selectRaw("clave_sii, string_agg(distinct clave_nexus, ', ' order by clave_nexus) as claves_nexus, count(*) as renglones")
            ->groupBy('clave_sii')->orderBy('clave_sii')->get()
            ->map(fn (object $row): array => ['materia' => (string) $row->clave_sii, 'subjects' => (string) $row->claves_nexus, 'rows' => (int) $row->renglones])
            ->all();

        $creditsDiffer = DB::table("{$schema}.materias")
            ->where('diagnostico', VerificationDiagnosis::CreditsDiffer->value)
            ->selectRaw('clave_sii, clave_nexus, creditos_sii, creditos_nexus, count(*) as renglones')
            ->groupBy('clave_sii', 'clave_nexus', 'creditos_sii', 'creditos_nexus')
            ->orderBy('clave_sii')->orderBy('clave_nexus')->orderBy('creditos_sii')->get()
            ->map(fn (object $row): array => [
                'materia' => (string) $row->clave_sii,
                'subject' => (string) $row->clave_nexus,
                'sii' => $row->creditos_sii === null ? null : self::number($row->creditos_sii),
                'nexus' => (int) $row->creditos_nexus,
                'rows' => (int) $row->renglones,
            ])->all();

        return new SiiVerification(
            array_sum($diagnoses),
            (int) DB::table("{$schema}.alumnos")->count(),
            $diagnoses,
            $statuses,
            $incomplete,
            $unexplained,
            $outsideSiiGrid,
            $creditsDiffer,
            $progressSeconds,
        );
    }

    /**
     * Un `numeric` de PostgreSQL sin ceros de más (`5.0` → `5`); vacío si no hay.
     */
    private static function number(mixed $value): string
    {
        $text = (string) $value;

        return str_contains($text, '.') ? rtrim(rtrim($text, '0'), '.') : $text;
    }

    /**
     * Un texto del archivo del SII como número, o null si no lo es.
     */
    private function numeric(string $column): string
    {
        return "case when {$column} ~ '^-?[0-9]+(\\.[0-9]+)?$' then {$column}::numeric end";
    }
}
