<?php

namespace App\Modules\AcademicLoad\Queries;

use App\Modules\AcademicLoad\Events\LoadSnapshotRequested;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Models\LoadSnapshot;
use App\Modules\AcademicLoad\Rules\SnapshotDiff;
use App\Modules\AcademicLoad\Rules\SnapshotPayload;
use App\Modules\AcademicLoad\Rules\VersionChanges;

/**
 * Lo que publicaría la carga ahora, para confirmar antes de publicar (tarea 4.3): grupos, sesiones, docentes y salones
 * distintos, grupos sin docente y la diferencia contra la versión vigente. Pide los grupos con el mismo evento que la
 * publicación (LoadSnapshotRequested), así que cuenta exactamente lo que se congelaría; quien contesta solo lee.
 *
 * En una republicación da también qué cambia contra la versión vigente (ensayo 8.11, E-16): el mismo diff que guardará
 * la publicación (SnapshotDiff, con el departamento de cada carrera) en las secciones por tipo del tablero de versiones
 * (VersionChanges::byKind(): nuevos, cancelados, horario, docente, salón, cupo y cupos por carrera).
 */
final class PublicationPreviewQuery
{
    /**
     * `changes` es null en la primera publicación y [] si ningún grupo cambia contra la versión vigente.
     *
     * @return array{groups: int, sessions: int, teachers: int, rooms: int, withoutTeacher: int, nextVersion: int, current: array{groups: int, sessions: int, teachers: int, rooms: int}|null, changes: list<array{kind: string, label: string, icon: string, rows: list<array<string, mixed>>}>|null}
     */
    public function preview(AcademicLoad $load): array
    {
        $request = new LoadSnapshotRequested($load);
        event($request);
        $groups = $request->groups() ?? [];

        $current = $load->current_version > 0
            ? LoadSnapshot::query()->where('academic_load_id', $load->getKey())->where('version', $load->current_version)->value('payload')
            : null;
        $current = $current === null ? null : SnapshotPayload::normalize($current);

        return [
            ...self::count($groups),
            'withoutTeacher' => count(array_filter($groups, fn (array $group): bool => $group['teacher'] === null)),
            'nextVersion' => $load->current_version + 1,
            'current' => $current === null ? null : self::count($current['groups']),
            'changes' => $current === null ? null : self::changes($current, $groups),
        ];
    }

    /**
     * Qué cambia contra la versión vigente, por tipo, como lo dirá el diff de la versión nueva.
     *
     * @param  array<string, mixed>  $current  la versión vigente, normalizada
     * @param  list<array<string, mixed>>  $groups  los grupos de ahora
     * @return list<array{kind: string, label: string, icon: string, rows: list<array<string, mixed>>}>
     */
    private static function changes(array $current, array $groups): array
    {
        $after = ['department' => $current['department'], 'groups' => $groups];

        return VersionChanges::byKind(SnapshotDiff::between($current, $after, ReanalysisChangesQuery::careerDepartments($current, $after)));
    }

    /**
     * @param  list<array<string, mixed>>  $groups
     * @return array{groups: int, sessions: int, teachers: int, rooms: int}
     */
    private static function count(array $groups): array
    {
        $sessions = array_merge(...array_map(fn (array $group): array => $group['sessions'], $groups ?: [['sessions' => []]]));

        return [
            'groups' => count($groups),
            'sessions' => count($sessions),
            'teachers' => count(array_unique(array_filter(array_map(fn (array $group): ?string => $group['teacher']['id'] ?? null, $groups)))),
            'rooms' => count(array_unique(array_filter(array_map(fn (array $session): ?string => $session['room']['id'] ?? null, $sessions)))),
        ];
    }
}
