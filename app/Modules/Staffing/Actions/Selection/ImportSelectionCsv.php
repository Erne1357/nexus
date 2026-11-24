<?php

namespace App\Modules\Staffing\Actions\Selection;

use App\Modules\Core\Models\User;
use App\Modules\Staffing\Enums\SelectionEntrySource;
use App\Modules\Staffing\Enums\SelectionEntryStatus;
use App\Modules\Staffing\Enums\SelectionStatus;
use App\Modules\Staffing\Models\Selection;
use App\Modules\Staffing\Rules\SelectionCsv;
use App\Modules\Staffing\Rules\SelectionTier;
use App\Modules\Staffing\Rules\TeacherNameMatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * SEL-06, SEL-07 y SEL-10. Reemplaza los renglones del CSV de una selección en borrador: empata cada nombre con un
 * docente activo, calcula su escalón, agrega al final (`appended`) a los docentes activos con adscripción al
 * departamento que el CSV no trae y recalcula el orden y los turnos. Un nombre sin empate, o dos renglones que empatan
 * con el mismo docente, quedan sin docente (`unresolved`) y bloquean publicar hasta resolverlos a mano. Un CSV sin
 * renglones válidos no toca la cola y devuelve sus errores.
 *
 * Toma primero el candado de la selección (`staffing_selection:{id}`) porque recalcula el orden.
 */
final class ImportSelectionCsv
{
    /**
     * @return array{matched: int, unresolved: list<array{entry_id: string, line: int, name: string, candidates: list<string>}>, errors: list<array{line: int, message: string}>}
     *
     * @throws ValidationException si la selección ya no es borrador
     */
    public function handle(Selection $selection, string $contents, User $actor): array
    {
        $parsed = SelectionCsv::parse($contents);

        if ($parsed['rows'] === []) {
            return ['matched' => 0, 'unresolved' => [], 'errors' => $parsed['errors']];
        }

        return DB::transaction(function () use ($selection, $parsed, $actor): array {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ["staffing_selection:{$selection->getKey()}"]);

            $selection->setRawAttributes(Selection::query()->lockForUpdate()->findOrFail($selection->getKey())->getAttributes(), sync: true);

            if ($selection->status !== SelectionStatus::Draft) {
                throw ValidationException::withMessages(['status' => 'Solo se importa el CSV mientras la selección está en borrador.']);
            }

            $selection->entries()->delete();

            $teachers = RecomputeSelectionOrder::pool($selection)->get(['id', 'name', 'hired_on']);
            $matches = array_map(fn (array $row): array => TeacherNameMatcher::match($row['name'], $teachers), $parsed['rows']);

            $counts = array_count_values(array_filter(array_column($matches, 'teacher_id')));

            $matched = 0;
            $unresolved = [];

            foreach ($parsed['rows'] as $i => $row) {
                $teacherId = $matches[$i]['teacher_id'];
                $candidates = $matches[$i]['candidates'];

                if ($teacherId !== null && $counts[$teacherId] > 1) {
                    $teacherId = null;
                    $candidates = [$matches[$i]['teacher_id']];
                }

                $failed = (int) ! $row['auto_eval'] + (int) ! $row['prior_compliance'] + (int) ! $row['partials_1'] + (int) ! $row['partials_2'];
                $plaza = SelectionTier::plazaTier($row['hours']);

                $entry = $selection->entries()->create([
                    'teacher_id' => $teacherId,
                    'source' => SelectionEntrySource::Csv,
                    'csv_name' => $row['name'],
                    'csv_line' => $row['line'],
                    'base_hours' => $row['hours'],
                    'auto_eval' => $row['auto_eval'],
                    'prior_compliance' => $row['prior_compliance'],
                    'partials_1' => $row['partials_1'],
                    'partials_2' => $row['partials_2'],
                    'seniority_years' => $row['seniority_years'],
                    'hired_on' => $teacherId === null ? null : $teachers->firstWhere('id', $teacherId)?->hired_on,
                    'plaza_tier' => $plaza,
                    'failed_criteria' => $failed,
                    'tier' => SelectionTier::tier($row['hours'], $failed),
                    'status' => SelectionEntryStatus::Waiting,
                ]);

                if ($teacherId === null) {
                    $unresolved[] = ['entry_id' => (string) $entry->getKey(), 'line' => $row['line'], 'name' => $row['name'], 'candidates' => $candidates];
                } else {
                    $matched++;
                }
            }

            app(RecomputeSelectionOrder::class)->handle($selection);

            activity()
                ->performedOn($selection)
                ->causedBy($actor)
                ->event('selection_csv_imported')
                ->withProperties(['rows' => count($parsed['rows']), 'matched' => $matched, 'unresolved' => count($unresolved), 'errors' => count($parsed['errors'])])
                ->log('Importó el CSV de criterios de la selección');

            return ['matched' => $matched, 'unresolved' => $unresolved, 'errors' => $parsed['errors']];
        });
    }
}
