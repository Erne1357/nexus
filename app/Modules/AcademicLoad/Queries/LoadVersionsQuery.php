<?php

namespace App\Modules\AcademicLoad\Queries;

use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Models\LoadSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Las versiones publicadas de una carga, para la línea de versiones del componente de publicar (tarea 4.3, DESIGN.md
 * «Publicación»): cuándo, quién, con qué comentario y cuántos grupos y sesiones tenía cada una, y lo que cambió una
 * versión contra la anterior. Lee solo `load_snapshots` y `load_transitions`: los conteos salen del payload en SQL,
 * sin traer las fotos; las dos fotos se leen solo para comparar una versión.
 */
final class LoadVersionsQuery
{
    /**
     * De la primera a la última. El comentario es el de la transición a publicada de esa versión (la N-ésima).
     *
     * @return list<array{version: int, publishedAt: CarbonImmutable, publisher: string, comment: ?string, groups: int, sessions: int}>
     */
    public function versions(AcademicLoad $load): array
    {
        $comments = $load->transitions()
            ->where('to_status', LoadStatus::Published->value)
            ->orderBy('created_at')->orderBy('id')
            ->pluck('comment')
            ->all();

        return LoadSnapshot::query()
            ->where('academic_load_id', $load->getKey())
            ->join('users', 'users.id', '=', 'load_snapshots.created_by')
            ->orderBy('version')
            ->get([
                'load_snapshots.version',
                'load_snapshots.created_at',
                'users.name as publisher',
                DB::raw("jsonb_array_length(load_snapshots.payload->'groups') as groups_count"),
                DB::raw("(select coalesce(sum(jsonb_array_length(g->'sessions')), 0) from jsonb_array_elements(load_snapshots.payload->'groups') g) as sessions_count"),
            ])
            ->map(fn (LoadSnapshot $row): array => [
                'version' => $row->version,
                'publishedAt' => $row->created_at,
                'publisher' => $row->publisher,
                'comment' => $comments[$row->version - 1] ?? null,
                'groups' => (int) $row->groups_count,
                'sessions' => (int) $row->sessions_count,
            ])
            ->all();
    }

    /**
     * Lo que cambió la versión contra la anterior, por grupo (`groups.id`): grupos que entraron y salieron, grupos con
     * otro docente y grupos con otro salón en alguna sesión. Null en la primera versión o si no existe.
     *
     * @return array{added: int, removed: int, teachers: int, rooms: int}|null
     */
    public function changes(AcademicLoad $load, int $version): ?array
    {
        if ($version < 2) {
            return null;
        }

        $payloads = LoadSnapshot::query()
            ->where('academic_load_id', $load->getKey())
            ->whereIn('version', [$version - 1, $version])
            ->pluck('payload', 'version');

        if ($payloads->count() < 2) {
            return null;
        }

        $before = collect($payloads[$version - 1]['groups'])->keyBy('id');
        $after = collect($payloads[$version]['groups'])->keyBy('id');
        $kept = $after->keys()->intersect($before->keys());
        $rooms = fn (array $group): array => array_map(fn (array $session): ?string => $session['room']['id'] ?? null, $group['sessions']);

        return [
            'added' => $after->keys()->diff($before->keys())->count(),
            'removed' => $before->keys()->diff($after->keys())->count(),
            'teachers' => $kept->filter(fn (string $id): bool => ($before[$id]['teacher']['id'] ?? null) !== ($after[$id]['teacher']['id'] ?? null))->count(),
            'rooms' => $kept->filter(fn (string $id): bool => $rooms($before[$id]) !== $rooms($after[$id]))->count(),
        ];
    }
}
