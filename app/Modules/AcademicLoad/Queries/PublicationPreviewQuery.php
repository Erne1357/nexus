<?php

namespace App\Modules\AcademicLoad\Queries;

use App\Modules\AcademicLoad\Events\LoadSnapshotRequested;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Models\LoadSnapshot;

/**
 * Lo que publicaría la carga ahora, para confirmar antes de publicar (tarea 4.3): grupos, sesiones, docentes y salones
 * distintos, grupos sin docente y la diferencia contra la versión vigente. Pide los grupos con el mismo evento que la
 * publicación (LoadSnapshotRequested), así que cuenta exactamente lo que se congelaría; quien contesta solo lee.
 */
final class PublicationPreviewQuery
{
    /**
     * @return array{groups: int, sessions: int, teachers: int, rooms: int, withoutTeacher: int, nextVersion: int, current: array{groups: int, sessions: int, teachers: int, rooms: int}|null}
     */
    public function preview(AcademicLoad $load): array
    {
        $request = new LoadSnapshotRequested($load);
        event($request);

        $current = $load->current_version > 0
            ? LoadSnapshot::query()->where('academic_load_id', $load->getKey())->where('version', $load->current_version)->value('payload')
            : null;

        return [
            ...self::count($request->groups() ?? []),
            'withoutTeacher' => count(array_filter($request->groups() ?? [], fn (array $group): bool => $group['teacher'] === null)),
            'nextVersion' => $load->current_version + 1,
            'current' => $current === null ? null : self::count($current['groups']),
        ];
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
