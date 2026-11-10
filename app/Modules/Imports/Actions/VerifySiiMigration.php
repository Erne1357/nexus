<?php

namespace App\Modules\Imports\Actions;

use App\Modules\Imports\Enums\VerificationDiagnosis;
use App\Modules\Imports\Enums\VerificationState;
use App\Modules\Imports\Sii\GridTotalCredits;
use App\Modules\Imports\Sii\KardexCodes;
use App\Modules\Imports\Sii\SiiVerification;
use App\Modules\Students\Actions\RefreshStudentProgress;
use Illuminate\Support\Facades\DB;

/**
 * Compara la exportación del SII guardada en `sii_archivo` con lo que quedó en Nexus y deja el resultado en el esquema
 * `sii_verificacion` (decisión del 10 de noviembre de 2026, confirmada: cada corrida compara el original con Nexus y
 * avisa si algo no cuadra). Como `sii_archivo`, no es parte del modelo de dominio: lo consulta el administrador en la
 * base, no se edita, vive fuera de las migraciones (ADR-0022) y cada corrida real lo reemplaza completo en una sola
 * transacción, también cuando no se puede verificar (`skip()`: tablas vacías y la razón en `_corrida`), para que nunca
 * se lea el resultado de otra exportación como de esta. Borra sin CASCADE: si alguien creó una vista sobre una de sus
 * tablas, la verificación falla en lugar de borrarla.
 *
 * Antes de comparar recalcula el avance de todos los alumnos aquí mismo (RefreshStudentProgress, lo que hace el job
 * RecomputeStudentProgress), porque el job que encolaron las plantillas puede no haber corrido aún; lo hace directo y no
 * con `dispatchSync()`, que con el candado del job (WithoutOverlapping) se saltaría el recálculo si la cola ya está
 * recalculando. Devuelve lo que tardó.
 *
 * - `materias`: un renglón por renglón de `historia_alumno`, con su línea, el alumno, el periodo, la clave del SII, la
 *   materia y el plan de Nexus a los que llegó y su diagnóstico (VerificationDiagnosis). El renglón se une con el kárdex
 *   de Nexus por alumno, periodo y clave del SII (`upper(sii_subject_code)`); el periodo es el que guarda Nexus: el del
 *   SII si es un periodo (KardexCodes::term), si no, ninguno. La materia de Nexus que le toca a cada clave sale de los
 *   mapas de claves de la corrida (`$subjectForPlan` del plan del alumno y, si no está ahí, `$subjectFor`, como
 *   KardexSubjects). Los renglones de un alumno que caen en la misma fila del kárdex (alumno, materia de Nexus y periodo)
 *   se ordenan como en KardexStep (la aprobada, la de mayor calificación y la última línea): los que pierden «se quedan
 *   uno». Un estatus distinto de A, R y P no pasa al kárdex y es «sin explicar» antes que cualquier otro diagnóstico.
 * - `alumnos`: un renglón por alumno de `alumnos_todos`. Según el SII (regla confirmada), está completo cuando los
 *   créditos de sus materias aprobadas (`estatus_materia` A) que están en su propia retícula llegan a los créditos
 *   totales de esa retícula (GridTotalCredits, la regla del paso `carreras`: solo un entero mayor que cero; sin él no
 *   está completo); una materia aprobada dos veces cuenta una vez. Según Nexus, cuando `student_progress.credits_approved`
 *   llega a `study_plans.total_credits` (el avance ya cuenta lo aprobado en materias pendientes del plan).
 *   `reticula_migrada` dice si su retícula está en `$planFor` (la migración la pasa a Nexus).
 * - `_corrida`: un renglón con la carpeta de la exportación, cuándo se verificó, el estado (VerificationState) y la
 *   razón.
 *
 * Un alumno, una retícula o una materia repetidos en el archivo cuentan una vez: el primero por línea; de una materia
 * repetida en una retícula, sus créditos mayores; de una retícula repetida en `carreras`, como dice GridTotalCredits.
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

    /** Tabla temporal con cada retícula del SII: sus créditos totales (GridTotalCredits) y si se migra. */
    private const string GRIDS = 'sii_verificacion_reticulas';

    /**
     * Las tablas que lee la comparación. Antes de comparar se les actualizan las estadísticas: el archivo y el kárdex se
     * acaban de cargar y, sin ellas, PostgreSQL puede creerlas vacías y unirlas renglón por renglón en lugar de por hash.
     *
     * @var list<string>
     */
    private const array COMPARED = [
        'sii_archivo.historia_alumno', 'sii_archivo.alumnos_todos', 'sii_archivo.materias_carreras', 'sii_archivo.carreras',
        'kardex_records', 'students', 'student_progress', 'study_plans', 'subjects', 'study_plan_subjects', 'study_plan_subject_pendings',
        self::KEYS, self::GRIDS,
    ];

    /** Cuántas líneas de la historia se citan por clave del SII en lo que no pasó sin explicación. */
    private const int SHOWN_LINES = 5;

    public function __construct(private readonly RefreshStudentProgress $progress) {}

    /**
     * @param  string  $dir  carpeta de la exportación, para `_corrida`
     * @param  array<string|int, string>  $subjectFor  clave del SII → materia de Nexus (alias general)
     * @param  array<string, array<string|int, string>>  $subjectForPlan  plan de Nexus → clave del SII → materia de Nexus
     * @param  array<string, mixed>  $planFor  las retículas del SII que se migran (`carrera/reticula` → plan)
     */
    public function handle(string $dir, array $subjectFor, array $subjectForPlan, array $planFor): SiiVerification
    {
        $started = hrtime(true);
        $this->progress->handle();
        $progressSeconds = (hrtime(true) - $started) / 1e9;

        return DB::transaction(function () use ($dir, $subjectFor, $subjectForPlan, $planFor, $progressSeconds): SiiVerification {
            $this->reset();
            $this->loadKeys($subjectFor, $subjectForPlan);
            $this->loadGrids($planFor);
            DB::statement('analyze '.implode(', ', self::COMPARED));
            DB::statement($this->subjectsSql());
            DB::statement($this->studentsSql());
            DB::statement('alter table '.self::SCHEMA.'.materias add primary key (_linea)');
            DB::statement('create index on '.self::SCHEMA.'.materias (no_de_control)');
            DB::statement('create index on '.self::SCHEMA.'.materias (diagnostico, clave_sii)');
            DB::statement('alter table '.self::SCHEMA.'.alumnos add primary key (no_de_control)');

            $result = $this->result($progressSeconds);
            $differences = $result->differences();

            $differences > 0
                ? $this->record($dir, VerificationState::WithDifferences, "{$differences} renglones o alumnos no cuadran: errores del paso verificacion en _incidencias.csv.")
                : $this->record($dir, VerificationState::Verified, null);

            return $result;
        });
    }

    /**
     * Una corrida real que no se pudo verificar: deja `materias` y `alumnos` vacías y la razón en `_corrida`.
     */
    public function skip(string $dir, string $reason): void
    {
        DB::transaction(function () use ($dir, $reason): void {
            $this->reset();
            $this->record($dir, VerificationState::NotVerified, $reason);
        });
    }

    private function record(string $dir, VerificationState $state, ?string $reason): void
    {
        DB::table(self::SCHEMA.'._corrida')->insert(['carpeta' => $dir, 'estado' => $state->value, 'razon' => $reason]);
    }

    /**
     * Crea el esquema si no existe, borra sus tablas y vuelve a crearlas vacías.
     */
    private function reset(): void
    {
        $schema = self::SCHEMA;

        DB::statement("create schema if not exists {$schema}");
        DB::statement("comment on schema {$schema} is 'Verificación de la última corrida de imports:migrate-sii: el SII original (sii_archivo) contra Nexus. Se reemplaza en cada corrida. No se edita.'");

        foreach (DB::table('pg_tables')->where('schemaname', $schema)->pluck('tablename') as $table) {
            DB::statement("drop table {$schema}.\"".str_replace('"', '""', $table).'"');
        }

        DB::statement("create table {$schema}._corrida (
            carpeta text not null,
            verificado_en timestamptz not null default now(),
            estado text not null,
            razon text
        )");
        DB::statement("comment on table {$schema}._corrida is 'La última corrida real: de qué carpeta, cuándo, si quedó verificada, con diferencias o no verificada, y por qué.'");

        DB::statement("create table {$schema}.materias (
            _linea integer not null,
            no_de_control text not null,
            periodo text not null,
            periodo_nexus text,
            clave_sii text not null,
            estatus_sii text not null,
            calificacion_sii text not null,
            reticula_sii text,
            en_reticula_sii boolean not null,
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
            creditos_requeridos_sii integer,
            creditos_campo_sii numeric,
            creditos_sii numeric not null,
            materias_sii integer not null,
            completo_sii boolean not null,
            reticula_migrada boolean not null,
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
        $rows = [];

        foreach (['' => $subjectFor, ...$subjectForPlan] as $plan => $map) {
            foreach ($map as $materia => $subject) {
                $rows[] = ['plan_nexus' => (string) $plan, 'clave_sii' => (string) $materia, 'clave_nexus' => $subject];
            }
        }

        $this->temporary(self::KEYS, 'plan_nexus text not null, clave_sii text not null, clave_nexus text not null', $rows);
    }

    /**
     * Cada retícula de `sii_archivo.carreras`, con sus créditos totales según GridTotalCredits y si se migra.
     *
     * @param  array<string, mixed>  $planFor
     */
    private function loadGrids(array $planFor): void
    {
        $carreras = DB::table('sii_archivo.carreras')->orderBy('_linea')->get(['carrera', 'reticula', 'creditos_totales'])
            ->map(fn (object $row): array => (array) $row)->all();
        $totals = GridTotalCredits::byGrid($carreras);
        $rows = [];

        foreach ($carreras as $row) {
            $key = trim($row['carrera']).'/'.trim($row['reticula']);
            $rows[$key] ??= [
                'carrera' => $row['carrera'],
                'reticula' => $row['reticula'],
                'total' => isset($totals[$key]) ? GridTotalCredits::kept($totals[$key]) : null,
                'migrada' => isset($planFor[$key]),
            ];
        }

        $this->temporary(self::GRIDS, 'carrera text not null, reticula text not null, total integer, migrada boolean not null', array_values($rows));
    }

    /**
     * Una tabla temporal que se borra al terminar la transacción, con sus renglones.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function temporary(string $table, string $columns, array $rows): void
    {
        DB::statement("drop table if exists pg_temp.{$table}");
        DB::statement("create temporary table {$table} ({$columns}) on commit drop");

        foreach (array_chunk($rows, 1000) as $chunk) {
            DB::table($table)->insert($chunk);
        }
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
            insert into {$schema}.materias (_linea, no_de_control, periodo, periodo_nexus, clave_sii, estatus_sii, calificacion_sii,
                reticula_sii, en_reticula_sii, creditos_sii, plan_nexus, clave_nexus, creditos_nexus, resultado_nexus, clave_nexus_esperada,
                diagnostico)
            with historia as (
                select h._linea, h.no_de_control, h.periodo, h.materia, h.estatus_materia, h.calificacion,
                    case when h.periodo ~ '^[0-9]{4}[123]$' then case when left(h.periodo, 4)::int between {$first} and {$last} then h.periodo end end as periodo_nexus,
                    upper(h.estatus_materia) in ('A', 'R', 'P') as estatus_valido,
                    upper(h.estatus_materia) = 'A' as aprobada,
                    case when h.calificacion ~ '^[0-9]{1,3}$' then case when h.calificacion::int <= 100 then h.calificacion::int else 0 end else 0 end as calificacion_valida
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
            ), renglon as (
                select h.*, a.carrera || '/' || a.reticula as reticula_sii, l.materia is not null as en_reticula, l.creditos,
                    r.materia is not null as en_alguna, st.id as student_id, st.study_plan_id, sp.code as plan_nexus,
                    esperada.id as esperada_id, esperada.code as esperada_code,
                    row_number() over (
                        partition by st.id, esperada.id, coalesce(h.periodo_nexus, ''), h.estatus_valido
                        order by h.aprobada desc, h.calificacion_valida desc, h._linea desc
                    ) as lugar_en_kardex
                from historia h
                left join alumno a on a.no_de_control = h.no_de_control
                left join lugar l on l.carrera = a.carrera and l.reticula = a.reticula and l.materia = h.materia
                left join en_alguna_reticula r on r.materia = h.materia
                left join students st on st.control_number = h.no_de_control and st.deleted_at is null
                left join study_plans sp on sp.id = st.study_plan_id
                left join {$keys} por_plan on por_plan.plan_nexus = sp.code and por_plan.clave_sii = h.materia
                left join {$keys} general on general.plan_nexus = '' and general.clave_sii = h.materia
                left join subjects esperada on esperada.code = coalesce(por_plan.clave_nexus, general.clave_nexus)
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
            select x._linea, x.no_de_control, x.periodo, x.periodo_nexus, x.materia, x.estatus_materia, x.calificacion,
                x.reticula_sii, x.en_reticula, x.creditos, x.plan_nexus, s.code, s.credits, k.result, x.esperada_code,
                case
                    when x.student_id is null then {$diagnosis(VerificationDiagnosis::StudentMissing)}
                    when not x.estatus_valido then {$diagnosis(VerificationDiagnosis::Unexplained)}
                    when x.esperada_id is not null and x.lugar_en_kardex > 1 then {$diagnosis(VerificationDiagnosis::KeptOther)}
                    when k.id is null then case
                        when exists (
                            select 1 from kardex_records otra
                            where otra.student_id = x.student_id and otra.subject_id = x.esperada_id
                                and coalesce(otra.term_code, '') = coalesce(x.periodo_nexus, '') and otra.deleted_at is null
                        ) then {$diagnosis(VerificationDiagnosis::KeptOther)}
                        when not x.en_alguna then {$diagnosis(VerificationDiagnosis::UnknownKey)}
                        else {$diagnosis(VerificationDiagnosis::Unexplained)}
                    end
                    when sps.id is null and pd.id is not null then {$diagnosis(VerificationDiagnosis::PlanPending)}
                    when sps.id is null then {$diagnosis(VerificationDiagnosis::OutsidePlan)}
                    when d.subject_id is not null then {$diagnosis(VerificationDiagnosis::SameSubject)}
                    when not x.en_reticula then {$diagnosis(VerificationDiagnosis::OutsideSiiGrid)}
                    when x.creditos is distinct from s.credits then {$diagnosis(VerificationDiagnosis::CreditsDiffer)}
                    else {$diagnosis(VerificationDiagnosis::Same)}
                end
            from renglon x
            left join kardex k on k.student_id = x.student_id and k.periodo = coalesce(x.periodo_nexus, '') and k.clave = upper(x.materia)
            left join subjects s on s.id = k.subject_id
            left join study_plan_subjects sps on sps.study_plan_id = x.study_plan_id and sps.subject_id = k.subject_id and sps.deleted_at is null
            left join study_plan_subject_pendings pd on pd.study_plan_id = x.study_plan_id and pd.subject_id = k.subject_id and pd.resolved_at is null
            left join dos_claves d on d.student_id = x.student_id and d.subject_id = k.subject_id
            SQL;
    }

    private function studentsSql(): string
    {
        $schema = self::SCHEMA;
        $grids = self::GRIDS;
        $credits = $this->numeric('creditos_materia');
        $field = $this->numeric('a.creditos_aprobados');

        return <<<SQL
            insert into {$schema}.alumnos (_linea, no_de_control, reticula_sii, estatus_sii, creditos_requeridos_sii, creditos_campo_sii,
                creditos_sii, materias_sii, completo_sii, reticula_migrada, en_nexus, plan_nexus, estatus_nexus, creditos_requeridos_nexus,
                creditos_nexus, materias_nexus, completo_nexus, creditos_perdidos)
            with alumno as (
                select distinct on (no_de_control) _linea, no_de_control, carrera, reticula, estatus_alumno, creditos_aprobados
                from sii_archivo.alumnos_todos
                order by no_de_control, _linea
            ), lugar as (
                select carrera, reticula, materia, max({$credits}) as creditos
                from sii_archivo.materias_carreras
                group by carrera, reticula, materia
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
                coalesce(x.creditos, 0), coalesce(x.materias, 0), coalesce(coalesce(x.creditos, 0) >= c.total, false), coalesce(c.migrada, false),
                st.id is not null, sp.code, st.status, sp.total_credits, p.credits_approved, p.subjects_approved,
                case when st.id is not null then coalesce(p.credits_approved >= sp.total_credits, false) end,
                coalesce(x.creditos, 0) - coalesce(p.credits_approved, 0)
            from alumno a
            left join {$grids} c on c.carrera = a.carrera and c.reticula = a.reticula
            left join sii x on x.no_de_control = a.no_de_control
            left join students st on st.control_number = a.no_de_control and st.deleted_at is null
            left join study_plans sp on sp.id = st.study_plan_id
            left join student_progress p on p.student_id = st.id
            SQL;
    }

    private function result(float $progressSeconds): SiiVerification
    {
        $subjects = fn () => DB::table(self::SCHEMA.'.materias');
        $students = fn () => DB::table(self::SCHEMA.'.alumnos');
        $counts = $subjects()->groupBy('diagnostico')->selectRaw('diagnostico, count(*) as total')->pluck('total', 'diagnostico');
        $diagnoses = [];

        foreach (VerificationDiagnosis::cases() as $case) {
            if (isset($counts[$case->value])) {
                $diagnoses[$case->value] = (int) $counts[$case->value];
            }
        }

        $statuses = $students()
            ->selectRaw('estatus_sii, count(*) as alumnos, count(*) filter (where completo_sii) as completos_sii, count(*) filter (where completo_nexus) as completos_nexus')
            ->selectRaw('count(*) filter (where not en_nexus) as fuera, count(*) filter (where not en_nexus and not reticula_migrada) as excluidos')
            ->groupBy('estatus_sii')->orderBy('estatus_sii')->get()
            ->map(fn (object $row): array => [
                'status' => (string) $row->estatus_sii,
                'students' => (int) $row->alumnos,
                'completeSii' => (int) $row->completos_sii,
                'completeNexus' => (int) $row->completos_nexus,
                'missing' => (int) $row->fuera,
                'excluded' => (int) $row->excluidos,
            ])->all();

        $incomplete = $students()
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

        $missing = $students()
            ->where('completo_sii', true)->where('en_nexus', false)->where('reticula_migrada', true)
            ->orderBy('no_de_control')->get()
            ->map(fn (object $row): array => [
                'control' => (string) $row->no_de_control,
                'grid' => (string) $row->reticula_sii,
                'required' => self::number($row->creditos_requeridos_sii),
                'sii' => self::number($row->creditos_sii),
            ])->all();

        $unexplained = $subjects()
            ->where('diagnostico', VerificationDiagnosis::Unexplained->value)
            ->selectRaw('clave_sii, count(*) as renglones, array_to_string((array_agg(_linea order by _linea))[1:'.self::SHOWN_LINES."], ', ') as lineas")
            ->groupBy('clave_sii')->orderBy('clave_sii')->get()
            ->map(fn (object $row): array => ['materia' => (string) $row->clave_sii, 'rows' => (int) $row->renglones, 'lines' => (string) $row->lineas])
            ->all();

        $byKey = fn (VerificationDiagnosis $diagnosis, bool $approvedInSiiGrid = false): array => $subjects()
            ->where('diagnostico', $diagnosis->value)
            ->when($approvedInSiiGrid, fn ($query) => $query->where('en_reticula_sii', true)->whereRaw("upper(estatus_sii) = 'A'"))
            ->selectRaw("clave_sii, string_agg(distinct clave_nexus, ', ' order by clave_nexus) as claves_nexus, count(*) as renglones")
            ->groupBy('clave_sii')->orderBy('clave_sii')->get()
            ->map(fn (object $row): array => ['materia' => (string) $row->clave_sii, 'subjects' => (string) $row->claves_nexus, 'rows' => (int) $row->renglones])
            ->all();

        $creditsDiffer = $subjects()
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
            (int) $students()->count(),
            $diagnoses,
            $statuses,
            $incomplete,
            $missing,
            (int) $students()->where('en_nexus', true)->where('completo_nexus', false)->where('creditos_perdidos', '>', 0)->count(),
            $unexplained,
            $byKey(VerificationDiagnosis::OutsidePlan, approvedInSiiGrid: true),
            $byKey(VerificationDiagnosis::OutsideSiiGrid),
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
