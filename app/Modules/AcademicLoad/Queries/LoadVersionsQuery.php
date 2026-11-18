<?php

namespace App\Modules\AcademicLoad\Queries;

use App\Modules\Academic\Models\Career;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Enums\SnapshotReason;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Models\LoadSnapshot;
use App\Modules\AcademicLoad\Rules\SnapshotDiff;
use App\Modules\AcademicLoad\Rules\SnapshotPayload;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Las versiones publicadas de una carga, para la línea de versiones del componente de publicar (tarea 4.3, DESIGN.md
 * «Publicación») y el tablero de versiones (8.10): cuándo, quién, con qué comentario, de dónde salió (`reason`), cuántos
 * grupos y sesiones tenía cada una y el resumen de lo que cambió contra la anterior, y el diff completo de una versión.
 * Lee solo `load_snapshots` y `load_transitions`: los conteos y el resumen salen en SQL, sin traer las fotos. El diff es el
 * guardado al publicar (tarea 8.4); el de una versión publicada antes de esa tarea se calcula al leerlo con las dos fotos.
 */
final class LoadVersionsQuery
{
    /**
     * De la primera a la última. El comentario es el de la transición a publicada de esa versión (la N-ésima). `reason` y
     * `summary` (el resumen del diff guardado, SnapshotDiff) son nulos en las versiones publicadas antes de la tarea 8.4,
     * y `summary` también en la versión 1.
     *
     * @return list<array{version: int, publishedAt: CarbonImmutable, publisher: string, comment: ?string, groups: int, sessions: int, reason: ?SnapshotReason, summary: ?array<string, int>}>
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
                'load_snapshots.reason',
                DB::raw("load_snapshots.diff->'summary' as diff_summary"),
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
                'reason' => $row->reason,
                'summary' => $row->diff_summary === null ? null : json_decode($row->diff_summary, true),
            ])
            ->all();
    }

    /**
     * Lo que cambió la versión contra la anterior, con la forma de SnapshotDiff: el guardado al publicarla o, si es de
     * antes de la tarea 8.4, calculado con las dos fotos y el departamento de cada carrera de hoy. Null en la primera
     * versión, si no existe o si falta la anterior.
     *
     * @return array<string, mixed>|null
     */
    public function diff(AcademicLoad $load, int $version): ?array
    {
        if ($version < 2) {
            return null;
        }

        $stored = LoadSnapshot::query()->where('academic_load_id', $load->getKey())->where('version', $version)->first(['id', 'diff']);

        if ($stored?->diff !== null) {
            return $stored->diff;
        }

        $payloads = $stored === null ? collect() : LoadSnapshot::query()
            ->where('academic_load_id', $load->getKey())
            ->whereIn('version', [$version - 1, $version])
            ->pluck('payload', 'version');

        if ($payloads->count() < 2) {
            return null;
        }

        $before = SnapshotPayload::normalize($payloads[$version - 1]);
        $after = SnapshotPayload::normalize($payloads[$version]);
        $departments = Career::withTrashed()->whereKey(SnapshotDiff::careerIds($before, $after))->pluck('department_id', 'id')->all();

        return SnapshotDiff::between($before, $after, $departments);
    }

    /**
     * Las cifras de la línea de versiones (diff()): grupos que entraron y salieron, grupos con otro docente y grupos con
     * otro salón en alguna sesión. Null en la primera versión o si no existe.
     *
     * @return array{added: int, removed: int, teachers: int, rooms: int}|null
     */
    public function changes(AcademicLoad $load, int $version): ?array
    {
        $diff = $this->diff($load, $version);

        if ($diff === null) {
            return null;
        }

        $changed = collect($diff['groups']['changed']);

        return [
            'added' => count($diff['groups']['added']),
            'removed' => count($diff['groups']['cancelled']),
            'teachers' => $changed->filter(fn (array $entry): bool => array_key_exists('teacher', $entry['changes']))->count(),
            'rooms' => $changed->filter(fn (array $entry): bool => array_key_exists('room', $entry['changes']))->count(),
        ];
    }
}
