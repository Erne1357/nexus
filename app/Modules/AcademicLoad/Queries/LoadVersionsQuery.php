<?php

namespace App\Modules\AcademicLoad\Queries;

use App\Modules\Academic\Models\Career;
use App\Modules\AcademicLoad\Enums\AcademicLoadPermission;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Enums\SnapshotReason;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Models\LoadSnapshot;
use App\Modules\AcademicLoad\Notifications\LoadRepublished;
use App\Modules\AcademicLoad\Rules\SnapshotDiff;
use App\Modules\AcademicLoad\Rules\SnapshotPayload;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Enums\MailStatus;
use App\Modules\Core\Models\MailLog;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Las versiones publicadas de una carga, para la línea de versiones del componente de publicar (tarea 4.3, DESIGN.md
 * «Publicación») y el tablero de versiones (8.10): cuándo, quién, con qué comentario, de dónde salió (`reason`), cuántos
 * grupos y sesiones tenía cada una y el resumen de lo que cambió contra la anterior, y el diff completo de una versión.
 * Lee solo `load_snapshots` y `load_transitions`: los conteos y el resumen salen en SQL, sin traer las fotos. El diff es el
 * guardado al publicar (tarea 8.4); el de una versión publicada antes de esa tarea se calcula al leerlo con las dos fotos.
 * Para el tablero da también las cargas con versiones que el usuario ve (loadsWithVersions()) y el nombre de las carreras
 * del diff.
 */
final class LoadVersionsQuery
{
    /**
     * De la primera a la última. El comentario es el de la transición a publicada de esa versión (la N-ésima; la de cancelar
     * un re-análisis, `reanalysis` → `published`, no crea versión y no cuenta, RA-10); `reopened`,
     * desde la 2, la transición con que la carga salió de publicada después de la versión anterior (la reapertura a ajustes
     * con su motivo, o el inicio del re-análisis), con quién y cuándo. `teachers` cuenta los docentes distintos de la foto.
     * `reason` y `summary` (el resumen del diff guardado, SnapshotDiff) son nulos en las versiones publicadas antes de la
     * tarea 8.4, y `summary` también en la versión 1.
     *
     * @return list<array{version: int, publishedAt: CarbonImmutable, publisher: string, comment: ?string, reopened: ?array{to: LoadStatus, by: string, at: CarbonImmutable, comment: ?string}, groups: int, sessions: int, teachers: int, reason: ?SnapshotReason, summary: ?array<string, int>}>
     */
    public function versions(AcademicLoad $load): array
    {
        $comments = [];
        $reopened = [];

        foreach ($load->transitions()
            ->join('users', 'users.id', '=', 'load_transitions.user_id')
            ->orderBy('load_transitions.created_at')->orderBy('load_transitions.id')
            ->get(['load_transitions.from_status', 'load_transitions.to_status', 'load_transitions.comment', 'load_transitions.created_at', 'users.name as user_name']) as $transition) {
            if ($transition->to_status === LoadStatus::Published && $transition->from_status === LoadStatus::Reanalysis) {
                unset($reopened[count($comments) + 1]);
            } elseif ($transition->to_status === LoadStatus::Published) {
                $comments[] = $transition->comment;
            } elseif ($transition->from_status === LoadStatus::Published && $comments !== []) {
                $reopened[count($comments) + 1] ??= [
                    'to' => $transition->to_status,
                    'by' => (string) $transition->getAttribute('user_name'),
                    'at' => $transition->created_at->toImmutable(),
                    'comment' => $transition->comment,
                ];
            }
        }

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
                DB::raw("(select count(distinct g->'teacher'->>'id') from jsonb_array_elements(load_snapshots.payload->'groups') g) as teachers_count"),
            ])
            ->map(fn (LoadSnapshot $row): array => [
                'version' => $row->version,
                'publishedAt' => $row->created_at,
                'publisher' => $row->publisher,
                'comment' => $comments[$row->version - 1] ?? null,
                'reopened' => $reopened[$row->version] ?? null,
                'groups' => (int) $row->groups_count,
                'sessions' => (int) $row->sessions_count,
                'teachers' => (int) $row->teachers_count,
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

        if ($stored === null) {
            return null;
        }

        // Las fotos no cambian (trigger de la base): el diff calculado de una versión de antes de la tarea 8.4 se guarda
        // por el id de su foto, para no sacar las dos cargas útiles en cada render.
        return Cache::rememberForever("load_snapshot_diff:{$stored->getKey()}", function () use ($load, $version): ?array {
            $payloads = LoadSnapshot::query()
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
        });
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

    /**
     * Si ya salió el aviso de la republicación de una versión y por dónde les llegó a los docentes (RA-05, RA-06; ensayo
     * 8.11, E-04 y F-04). Lo leen el resumen de la versión y el drawer «Ver por docente o carrera», con una sola lectura:
     *
     * - `bell` y `mail`: el aviso al docente, reconocido por su título (LoadRepublished::teacherTitle(), que también es el
     *   asunto del correo): `bell` si se guardó alguna campana y `mail` si salió algún correo (el registro de correos,
     *   CF-S8, con estado «enviado»). Es el canal que dice el drawer;
     * - `sent`: si ya salió algún aviso, al docente o a la coordinación de una carrera o a los jefes de otro departamento
     *   (los títulos que empiezan con LoadRepublished::othersTitlePrefix()). El resumen dice «Se avisó a» o, si no,
     *   «Por avisar a». Los avisos salen de la cola (NotifyAffectedOnRepublication): recién publicada, o con la cola
     *   detenida, no ha salido ninguno.
     *
     * Cuentan los de entre la publicación de la versión y la de la siguiente (`$until`); la campana, además, por el
     * periodo y la modalidad de la carga. El correo no guarda la modalidad: dos cargas del mismo departamento republicadas
     * a la vez se confunden (supuesto por confirmar).
     *
     * @return array{bell: bool, mail: bool, sent: bool}
     */
    public function noticeChannels(AcademicLoad $load, CarbonImmutable $from, ?CarbonImmutable $until): array
    {
        $load->loadMissing(['department' => fn ($query) => $query->withTrashed()]);
        $department = (string) $load->department?->name;
        $teacher = $this->channels($load, $from, $until, fn (Builder $query, string $column): Builder => $query->where($column, LoadRepublished::teacherTitle($department)));

        if ($teacher['bell'] || $teacher['mail']) {
            return [...$teacher, 'sent' => true];
        }

        $prefix = addcslashes(LoadRepublished::othersTitlePrefix($department), '\\%_').'%';
        $others = $this->channels($load, $from, $until, fn (Builder $query, string $column): Builder => $query->whereLike($column, $prefix, caseSensitive: true));

        return [...$teacher, 'sent' => $others['bell'] || $others['mail']];
    }

    /**
     * Si hay campana y correo enviado del aviso de la republicación de la carga entre `$from` y `$until` con el título que
     * pide `$title` (que recibe la consulta y la columna del título: `data->title` en la campana y `subject` en el correo).
     *
     * @param  Closure(Builder, string): Builder  $title
     * @return array{bell: bool, mail: bool}
     */
    private function channels(AcademicLoad $load, CarbonImmutable $from, ?CarbonImmutable $until, Closure $title): array
    {
        return [
            'bell' => $title(DatabaseNotification::query(), 'data->title')
                ->where('type', LoadRepublished::class)
                ->where('data->term_id', $load->term_id)
                ->where('data->modality_id', $load->modality_id)
                ->where('created_at', '>=', $from)
                ->when($until !== null, fn ($query) => $query->where('created_at', '<', $until))
                ->exists(),
            'mail' => $title(MailLog::query(), 'subject')
                ->where('kind', class_basename(LoadRepublished::class))
                ->where('status', MailStatus::Sent)
                ->where('sent_at', '>=', $from)
                ->when($until !== null, fn ($query) => $query->where('sent_at', '<', $until))
                ->exists(),
        ];
    }

    /**
     * Las versiones para el tablero de versiones (tarea 8.10): las de versions(), de la primera a la última, con el motivo
     * siempre dicho y el resumen de lo que cambió también en las publicadas antes de la tarea 8.4 (`legacy`): su motivo se
     * deduce (SnapshotReason::inferredFor()) y su resumen se calcula con diff(). Esas no avisaron a nadie: los avisos de
     * la republicación son de la misma tarea.
     *
     * @return list<array{version: int, publishedAt: CarbonImmutable, publisher: string, comment: ?string, reopened: ?array{to: LoadStatus, by: string, at: CarbonImmutable, comment: ?string}, groups: int, sessions: int, teachers: int, reason: SnapshotReason, legacy: bool, summary: ?array<string, int>}>
     */
    public function board(AcademicLoad $load): array
    {
        return array_map(function (array $version) use ($load): array {
            $legacy = $version['reason'] === null;

            return [
                ...$version,
                'reason' => $version['reason'] ?? SnapshotReason::inferredFor($version['version'], $version['reopened']['to'] ?? null),
                'legacy' => $legacy,
                'summary' => $version['summary'] ?? ($legacy && $version['version'] > 1 ? ($this->diff($load, $version['version'])['summary'] ?? null) : null),
            ];
        }, $this->versions($load));
    }

    /**
     * Las cargas del periodo en la modalidad que ya tienen alguna versión publicada y que el usuario ve
     * (`academic_load.load.view` sobre su modalidad y su departamento), por clave del departamento, con el departamento
     * (aunque esté borrado), la modalidad y el periodo: las que ofrece el tablero de versiones.
     *
     * @return Collection<int, AcademicLoad>
     */
    public function loadsWithVersions(Actor $actor, Term $term, Modality $modality): Collection
    {
        return $actor->constrain(AcademicLoad::query()
            ->where('academic_loads.term_id', $term->getKey())
            ->where('academic_loads.modality_id', $modality->getKey())
            ->where('academic_loads.current_version', '>', 0), AcademicLoadPermission::LoadView, [
                'modality' => 'academic_loads.modality_id',
                'unit' => 'academic_loads.department_id',
            ])
            ->join('departments', 'departments.id', '=', 'academic_loads.department_id')
            ->orderBy('departments.code')
            ->select('academic_loads.*')
            ->with(['department' => fn ($query) => $query->withTrashed(), 'modality', 'term'])
            ->get();
    }

    /**
     * El nombre de cada carrera, por id (también las borradas): las del diff solo traen su clave.
     *
     * @param  list<string>  $ids
     * @return array<string, string>
     */
    public function careerNames(array $ids): array
    {
        return $ids === [] ? [] : Career::withTrashed()->whereKey($ids)->pluck('name', 'id')->all();
    }
}
