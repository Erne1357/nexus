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
 * compara con la última versión guardada, leída normalizada (SnapshotPayload::normalize, MS13), con SnapshotDiff: es el
 * mismo diff que guardaría PublishLoad si se publicara en ese momento, así que «no cambió nada» quiere decir exactamente que
 * la versión n + 1 saldría igual a la n. Solo lee.
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
        $published = $load->snapshots()->where('version', $load->current_version)->first()?->payload
            ?? throw new LogicException('La carga no tiene una versión publicada con la que comparar.');

        $request = new LoadSnapshotRequested($load);
        event($request);
        $groups = $request->groups() ?? throw new LogicException('Ningún módulo dio los grupos de la carga.');

        $before = SnapshotPayload::normalize($published);
        $after = ['department' => $before['department'], 'groups' => $groups];

        return SnapshotDiff::between($before, $after, self::careerDepartments($before, $after));
    }

    /**
     * Cuántos grupos nuevos, cancelados o con cambios hay desde la última versión publicada.
     */
    public function changedGroups(AcademicLoad $load): int
    {
        return self::count($this->since($load));
    }

    public function hasChanges(AcademicLoad $load): bool
    {
        return $this->changedGroups($load) > 0;
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
