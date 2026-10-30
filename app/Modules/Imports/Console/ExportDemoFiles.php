<?php

namespace App\Modules\Imports\Console;

use App\Modules\Imports\Templates\ImportTemplate;
use App\Modules\Imports\Templates\TemplateRegistry;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\LazyCollection;

/**
 * Escribe lo sembrado (DemoSeeder o MassiveSeeder) como un CSV por plantilla de importación, para probar el
 * asistente de punta a punta con archivos grandes sin exportaciones reales del SII. Los encabezados son las
 * etiquetas de cada plantilla, que ColumnMatcher reconoce sin mapeo guardado. Los archivos caen en
 * `storage/app/imports/<dir>/` (disco `imports`, fuera de git) y se suben en el orden de
 * reglas-de-negocio.md, "Reglas de datos".
 *
 * No exporta lo borrado; lo inactivo sí, con su «vigente» en los planes. Los renglones salen por bloques
 * (`lazyById`) a un flujo, sin cargar el kárdex en memoria. No corre en producción: son datos de prueba.
 */
final class ExportDemoFiles extends Command
{
    private const int CHUNK = 5000;

    protected $signature = 'imports:export-demo {--dir=demo : Carpeta dentro del disco imports}';

    protected $description = 'Escribe los datos sembrados como CSV con el formato de cada plantilla de importación';

    public function handle(TemplateRegistry $templates): int
    {
        if ($this->laravel->isProduction()) {
            $this->components->error('No se exportan datos de prueba en producción.');

            return self::FAILURE;
        }

        $dir = trim((string) $this->option('dir'), '/');

        if ($dir === '' || str_contains($dir, '..')) {
            $this->components->error('La carpeta debe ser un nombre dentro del disco imports.');

            return self::FAILURE;
        }

        $disk = Storage::disk('imports');
        $disk->makeDirectory($dir);

        foreach ($this->sources() as $template => $rows) {
            $path = $disk->path("{$dir}/{$template}.csv");
            $count = $this->write($path, $templates->get($template), $rows());

            if ($count === null) {
                $this->components->error("No se pudo escribir {$dir}/{$template}.csv.");

                return self::FAILURE;
            }

            $this->components->twoColumnDetail("{$dir}/{$template}.csv", "{$count} filas");
        }

        return self::SUCCESS;
    }

    /**
     * Una función por plantilla que da los renglones como lista de valores, en el orden de `fields()`.
     *
     * @return array<string, Closure(): iterable<int, list<scalar|null>>>
     */
    private function sources(): array
    {
        return [
            'departments' => fn (): iterable => DB::table('departments')->whereNull('deleted_at')->orderBy('code')
                ->get(['code', 'name'])->map(fn (object $r): array => [$r->code, $r->name]),

            // Un renglón por carrera y campus (reglas de datos): la oferta es lo que lleva el campus.
            'careers' => fn (): iterable => DB::table('career_campus as cc')
                ->join('careers as c', 'c.id', '=', 'cc.career_id')
                ->join('departments as d', 'd.id', '=', 'c.department_id')
                ->join('campuses as k', 'k.id', '=', 'cc.campus_id')
                ->whereNull('cc.deleted_at')->whereNull('c.deleted_at')->whereNull('d.deleted_at')
                ->orderBy('c.code')->orderBy('k.code')
                ->get(['c.code', 'c.name', 'd.code as department', 'k.code as campus'])
                ->map(fn (object $r): array => [$r->code, $r->name, $r->department, $r->campus]),

            'study_plans' => fn (): iterable => DB::table('study_plans as p')
                ->join('careers as c', 'c.id', '=', 'p.career_id')
                ->whereNull('p.deleted_at')->whereNull('c.deleted_at')
                ->orderBy('p.code')
                ->get(['p.code', 'c.code as career', 'p.name', 'p.version', 'p.is_active'])
                ->map(fn (object $r): array => [$r->code, $r->career, $r->name, $r->version, $r->is_active ? 'Sí' : 'No']),

            // Una fila por lugar en la retícula; la especialidad solo si no está borrada.
            'subjects' => fn (): iterable => DB::table('study_plan_subjects as sp')
                ->join('study_plans as p', 'p.id', '=', 'sp.study_plan_id')
                ->join('subjects as s', 's.id', '=', 'sp.subject_id')
                ->join('departments as d', 'd.id', '=', 's.department_id')
                ->leftJoin('specialties as e', fn ($join) => $join->on('e.id', '=', 'sp.specialty_id')->whereNull('e.deleted_at'))
                ->whereNull('sp.deleted_at')->whereNull('p.deleted_at')->whereNull('s.deleted_at')->whereNull('d.deleted_at')
                ->orderBy('p.code')->orderBy('sp.semester')->orderBy('s.code')
                ->get(['p.code as plan', 's.code', 's.name', 'sp.semester', 's.theory_hours', 's.practice_hours', 's.credits', 'd.code as department', 'e.code as specialty', 'e.name as specialty_name'])
                ->map(fn (object $r): array => [$r->plan, $r->code, $r->name, $r->semester, $r->theory_hours, $r->practice_hours, $r->credits, $r->department, $r->specialty, $r->specialty_name]),

            // Solo seriaciones entre materias que siguen en la retícula del plan.
            'prerequisites' => fn (): iterable => DB::table('subject_prerequisites as r')
                ->join('study_plans as p', 'p.id', '=', 'r.study_plan_id')
                ->join('subjects as s', 's.id', '=', 'r.subject_id')
                ->join('subjects as q', 'q.id', '=', 'r.prerequisite_subject_id')
                ->whereNull('r.deleted_at')->whereNull('p.deleted_at')->whereNull('s.deleted_at')->whereNull('q.deleted_at')
                ->whereExists(fn ($grid) => $grid->from('study_plan_subjects as g')->whereColumn('g.study_plan_id', 'r.study_plan_id')->whereColumn('g.subject_id', 'r.subject_id')->whereNull('g.deleted_at'))
                ->whereExists(fn ($grid) => $grid->from('study_plan_subjects as g')->whereColumn('g.study_plan_id', 'r.study_plan_id')->whereColumn('g.subject_id', 'r.prerequisite_subject_id')->whereNull('g.deleted_at'))
                ->orderBy('p.code')->orderBy('s.code')->orderBy('q.code')
                ->get(['p.code as plan', 's.code as subject', 'q.code as prerequisite', 'r.kind'])
                ->map(fn (object $r): array => [$r->plan, $r->subject, $r->prerequisite, $r->kind]),

            'students' => fn (): iterable => $this->chunked(
                DB::table('students as st')
                    ->join('careers as c', 'c.id', '=', 'st.career_id')
                    ->join('study_plans as p', 'p.id', '=', 'st.study_plan_id')
                    ->join('campuses as k', 'k.id', '=', 'st.campus_id')
                    ->whereNull('st.deleted_at')->whereNull('c.deleted_at')->whereNull('p.deleted_at')
                    ->select(['st.id', 'st.control_number', 'st.name', 'c.code as career', 'p.code as plan', 'k.code as campus', 'st.current_semester', 'st.status']),
                'st.id',
                fn (object $r): array => [$r->control_number, $r->name, $r->career, $r->plan, $r->campus, $r->current_semester, $r->status],
            ),

            'kardex' => fn (): iterable => $this->chunked(
                DB::table('kardex_records as kr')
                    ->join('students as st', 'st.id', '=', 'kr.student_id')
                    ->join('subjects as s', 's.id', '=', 'kr.subject_id')
                    ->whereNull('kr.deleted_at')->whereNull('st.deleted_at')->whereNull('s.deleted_at')
                    ->select(['kr.id', 'st.control_number', 's.code', 'kr.term_code', 'kr.grade', 'kr.result', 'kr.attempt']),
                'kr.id',
                fn (object $r): array => [$r->control_number, $r->code, $r->term_code, $r->grade === null ? null : (string) (float) $r->grade, $r->result, $r->attempt],
            ),

            // Un renglón por grupo impartido; el campus puede ir vacío.
            'teacher_history' => fn (): iterable => $this->chunked(
                DB::table('teacher_subject_history as h')
                    ->join('teachers as t', 't.id', '=', 'h.teacher_id')
                    ->join('subjects as s', 's.id', '=', 'h.subject_id')
                    ->leftJoin('campuses as k', 'k.id', '=', 'h.campus_id')
                    ->whereNull('h.deleted_at')->whereNull('t.deleted_at')->whereNull('s.deleted_at')
                    ->select(['h.id', 't.employee_number', 's.code', 'h.term_code', 'k.code as campus', 'h.group_code']),
                'h.id',
                fn (object $r): array => [$r->employee_number, $r->code, $r->term_code, $r->campus, $r->group_code],
            ),

            'evaluations' => fn (): iterable => DB::table('teacher_evaluations as e')
                ->join('teachers as t', 't.id', '=', 'e.teacher_id')
                ->whereNull('e.deleted_at')->whereNull('t.deleted_at')
                ->orderBy('t.employee_number')->orderBy('e.term_code')->orderBy('e.kind')
                ->get(['t.employee_number', 'e.term_code', 'e.kind', 'e.score'])
                ->map(fn (object $r): array => [$r->employee_number, $r->term_code, $r->kind, (string) (float) $r->score]),
        ];
    }

    /**
     * Los renglones de una consulta grande por bloques ordenados por llave, uno a la vez en memoria.
     *
     * @param  Closure(object): list<scalar|null>  $row
     * @return LazyCollection<int, list<scalar|null>>
     */
    private function chunked(Builder $query, string $key, Closure $row): LazyCollection
    {
        return $query->lazyById(self::CHUNK, $key, 'id')->map($row);
    }

    /**
     * Escribe el encabezado con las etiquetas de la plantilla y los renglones; devuelve cuántos renglones, o null
     * si no pudo abrir el archivo.
     *
     * @param  iterable<int, list<scalar|null>>  $rows
     */
    private function write(string $path, ImportTemplate $template, iterable $rows): ?int
    {
        $stream = @fopen($path, 'w');

        if ($stream === false) {
            return null;
        }

        fputcsv($stream, array_values($template->fields()), ',', '"', '');
        $count = 0;

        foreach ($rows as $row) {
            fputcsv($stream, $row, ',', '"', '');
            $count++;
        }

        fclose($stream);

        return $count;
    }
}
