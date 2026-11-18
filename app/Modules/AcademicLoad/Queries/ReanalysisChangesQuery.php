<?php

namespace App\Modules\AcademicLoad\Queries;

use App\Modules\Academic\Models\Career;
use App\Modules\AcademicLoad\Events\LoadSnapshotRequested;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Rules\SnapshotDiff;
use App\Modules\AcademicLoad\Rules\SnapshotPayload;
use LogicException;

/**
 * Lo que cambió una carga en re-análisis desde su última versión publicada (tarea 8.1, seguimiento #600; RA-10): decide si el
 * re-análisis todavía se puede cancelar sin versión nueva. Pide los grupos de la carga como ahora están con el mismo evento
 * que la publicación (LoadSnapshotRequested, que contesta Scheduling con LoadSnapshotQuery; AcademicLoad no ve grupos) y los
 * compara con la última versión guardada, leída normalizada (SnapshotPayload::normalize, MS13). Solo lee.
 *
 * Hay dos comparaciones. `since` es el diff de la publicación (SnapshotDiff): lo que avisaría una versión n + 1, que no
 * cuenta el tipo de sesión, la justificación ni los nombres. Cancelar usa `changedGroups` / `hasChanges`, que es estricta:
 * cualquier diferencia en lo que el editor de la carga puede cambiar (sesiones con día, horas, salón y tipo; docente;
 * cupo total y por carrera; justificación; estado; grupos nuevos y cancelados) cuenta, porque cancelar deja los datos
 * vivos como la foto publicada y no debe esconder una edición. Los nombres del catálogo (materia, carrera, docente, salón)
 * no son una edición de la carga y no cuentan.
 */
final class ReanalysisChangesQuery
{
    /**
     * El diff (SnapshotDiff) de los grupos de ahora contra la versión vigente de la carga.
     *
     * @return array{version: int, groups: array{added: list<array<string, mixed>>, cancelled: list<array<string, mixed>>, changed: list<array{group: array<string, mixed>, changes: array<string, mixed>}>}, summary: array{added: int, cancelled: int, changed: int, teachers_affected: int, careers_affected: int, departments_affected: int}}
     *
     * @throws LogicException si la carga no tiene versión publicada o nadie dio sus grupos
     */
    public function since(AcademicLoad $load): array
    {
        [$before, $after] = $this->payloads($load);

        return SnapshotDiff::between($before, $after, self::careerDepartments($before, $after));
    }

    /**
     * Cuántos grupos nuevos, cancelados o con cualquier diferencia editable hay desde la última versión publicada
     * (comparación estricta, ver la clase).
     *
     * @throws LogicException si la carga no tiene versión publicada o nadie dio sus grupos
     */
    public function changedGroups(AcademicLoad $load): int
    {
        [$before, $after] = $this->payloads($load);
        $old = self::projections($before['groups']);
        $new = self::projections($after['groups']);

        return count(array_filter(
            array_unique([...array_keys($old), ...array_keys($new)]),
            fn (string $id): bool => ($old[$id] ?? null) !== ($new[$id] ?? null),
        ));
    }

    public function hasChanges(AcademicLoad $load): bool
    {
        return $this->changedGroups($load) > 0;
    }

    /**
     * La versión vigente normalizada y los grupos de ahora con su encabezado.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function payloads(AcademicLoad $load): array
    {
        $published = $load->snapshots()->where('version', $load->current_version)->first()?->payload
            ?? throw new LogicException('La carga no tiene una versión publicada con la que comparar.');

        $request = new LoadSnapshotRequested($load);
        event($request);
        $groups = $request->groups() ?? throw new LogicException('Ningún módulo dio los grupos de la carga.');

        $before = SnapshotPayload::normalize($published);

        return [$before, ['department' => $before['department'], 'groups' => $groups]];
    }

    /**
     * Lo que el editor de la carga puede cambiar de cada grupo, por id del grupo, en un orden fijo (sesiones y lugares
     * ordenados) y sin nombres del catálogo.
     *
     * @param  list<array<string, mixed>>  $groups
     * @return array<string, array<string, mixed>>
     */
    private static function projections(array $groups): array
    {
        $projections = [];

        foreach ($groups as $group) {
            $placements = array_map(fn (array $placement): array => [
                'career' => $placement['career']['id'],
                'study_plan' => $placement['study_plan']['id'] ?? null,
                'semester' => $placement['semester'],
                'seats' => $placement['seats'],
                'is_owner' => $placement['is_owner'],
            ], $group['placements']);
            $sessions = array_map(fn (array $session): array => [
                'day' => $session['day'],
                'starts_at' => $session['starts_at'],
                'ends_at' => $session['ends_at'],
                'room' => $session['room']['id'] ?? null,
                'kind' => $session['kind'],
            ], $group['sessions']);

            usort($placements, fn (array $a, array $b): int => json_encode($a) <=> json_encode($b));
            usort($sessions, fn (array $a, array $b): int => json_encode($a) <=> json_encode($b));

            $projections[$group['id']] = [
                'code' => $group['code'],
                'status' => $group['status'],
                'campus' => $group['campus']['id'] ?? null,
                'subject' => $group['subject']['id'],
                'teacher' => $group['teacher']['id'] ?? null,
                'capacity' => $group['capacity'],
                'justification' => $group['justification'],
                'placements' => $placements,
                'sessions' => $sessions,
            ];
        }

        return $projections;
    }

    /**
     * @param  array{summary: array{added: int, cancelled: int, changed: int}}  $diff
     */
    public static function count(array $diff): int
    {
        return $diff['summary']['added'] + $diff['summary']['cancelled'] + $diff['summary']['changed'];
    }

    /**
     * El departamento de cada carrera de las dos fotos (Academic, también de las borradas): el payload no lo trae y el
     * diff lo necesita para saber qué grupos compartidos tocan a otro departamento (RA-05).
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array<string, string|null>
     */
    public static function careerDepartments(array $before, array $after): array
    {
        return Career::withTrashed()->whereKey(SnapshotDiff::careerIds($before, $after))->pluck('department_id', 'id')->all();
    }
}
