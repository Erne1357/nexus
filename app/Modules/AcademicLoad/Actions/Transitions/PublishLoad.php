<?php

namespace App\Modules\AcademicLoad\Actions\Transitions;

use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Enums\SnapshotReason;
use App\Modules\AcademicLoad\Events\LoadSnapshotRequested;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Models\LoadSnapshot;
use App\Modules\AcademicLoad\Queries\ReanalysisChangesQuery;
use App\Modules\AcademicLoad\Queries\ReanalysisCycleQuery;
use App\Modules\AcademicLoad\Rules\SnapshotDiff;
use App\Modules\AcademicLoad\Rules\SnapshotPayload;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Actions\SyncPublishedTeaching;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;

/**
 * Publica la carga de un departamento en una modalidad (tarea 4.3, docs/proceso/proceso-propuesto.md, etapa 8; M1): de
 * `closed` (modalidad sin visto bueno) o `approved` a `published`. Es también la republicación (tarea 8.4): una carga
 * re-analizada o reabierta vuelve por el cierre (y el visto bueno, si la modalidad lo exige, RA-03) y se publica aquí como
 * la versión n + 1; no hay otra Action. Todo en una transacción, para que no haya carga publicada sin su foto ni foto
 * sin carga publicada:
 *
 * 1. `TransitionLoad` bloquea la carga, revisa el diagrama y junta los vetos de LoadTransitioning (Scheduling vuelve a
 *    revisar HC-15 al publicar, confirmado el 10 de noviembre de 2026).
 * 2. Pide los grupos a Scheduling con LoadSnapshotRequested, arma el payload con su encabezado y lo revisa contra
 *    `SnapshotPayload` (una foto que no cumple no se guarda).
 * 3. Guarda la versión siguiente (`current_version + 1`; la anterior se conserva, HC-18) con lo que cambió contra la
 *    anterior (`diff`, SnapshotDiff, con el departamento de cada carrera leído de Academic; nulo en la versión 1) y su
 *    motivo (`reason`: `published` la 1; `reanalysis` si el último cierre salió de re-análisis; `correction` si no), y
 *    anota en la carga quién y cuándo publicó. La bitácora «published» lleva el motivo y el resumen del diff.
 * 4. Lleva el historial docente a lo que dice esta versión (`SyncPublishedTeaching`, Faculty, `source = nexus`): una
 *    fila por grupo con docente, en el campus de su grupo; las de la versión anterior que ya no están se borran
 *    lógicamente. La versión anterior se lee normalizada (SnapshotPayload::normalize, MS13).
 *
 * Los avisos de la republicación (RA-05, RA-06) salen después del commit, del listener NotifyAffectedOnRepublication,
 * que lee el diff guardado. Quién puede lo decide `AcademicLoadPolicy::publish` antes de llamarla.
 */
final class PublishLoad
{
    public function __construct(
        private readonly TransitionLoad $transition,
        private readonly SyncPublishedTeaching $teaching,
        private readonly ReanalysisCycleQuery $cycle,
    ) {}

    /**
     * @throws ValidationException si el diagrama no lo permite o un módulo lo veta
     * @throws InvalidArgumentException si la foto no cumple su forma
     */
    public function handle(AcademicLoad $load, User $actor, ?string $comment = null): LoadSnapshot
    {
        return DB::transaction(function () use ($load, $actor, $comment): LoadSnapshot {
            $load = $this->transition->handle($load, LoadStatus::Published, $actor, $comment, self::publishable(...));

            $request = new LoadSnapshotRequested($load);
            event($request);
            $groups = $request->groups() ?? throw new LogicException('Ningún módulo dio los grupos de la foto de la carga.');

            $version = $load->current_version + 1;
            $publishedAt = CarbonImmutable::now()->startOfSecond();
            $payload = [...SnapshotPayload::header($load, $version, $actor, $publishedAt), 'groups' => $groups];
            SnapshotPayload::assertValid($payload);

            $previous = $load->snapshots()->where('version', $load->current_version)->first()?->payload;
            $previous = $previous === null ? null : SnapshotPayload::normalize($previous);
            $diff = $previous === null ? null : SnapshotDiff::between($previous, $payload, self::careerDepartments($previous, $payload));
            $reason = $this->reason($load, $version);

            $snapshot = $load->snapshots()->make([
                'version' => $version,
                'payload' => $payload,
                'diff' => $diff,
                'reason' => $reason,
                'created_by' => $actor->getKey(),
            ]);
            // `created_at` no es asignable en masa: se pone aparte para que la versión y la publicación lleven el mismo instante.
            $snapshot->created_at = $publishedAt;
            $snapshot->save();

            $load->update(['current_version' => $version, 'published_by' => $actor->getKey(), 'published_at' => $publishedAt]);

            $taught = self::taughtByCampus($groups);
            $before = self::taughtByCampus($previous['groups'] ?? []);

            foreach (array_unique([...array_keys($taught), ...array_keys($before)]) as $campus) {
                $this->teaching->handle($load->term->code, $campus === '' ? null : (string) $campus, $taught[$campus] ?? [], $before[$campus] ?? []);
            }

            $sessions = array_sum(array_map(fn (array $group): int => count($group['sessions']), $groups));

            activity()
                ->performedOn($load)
                ->causedBy($actor)
                ->event('published')
                ->withProperties(['version' => $version, 'groups' => count($groups), 'sessions' => $sessions, 'reason' => $reason->value, ...($diff === null ? [] : ['changes' => $diff['summary']])])
                ->log("Publicó la versión {$version} de la carga de {$load->department->code} en {$load->modality->name}: ".count($groups).' grupos'.self::changesLabel($diff));

            return $snapshot;
        });
    }

    /**
     * Publicar exige una carga cerrada o aprobada. El diagrama también deja pasar de re-análisis a publicada, pero esa flecha es
     * cancelar el re-análisis sin cambios (CancelReanalysis, RA-10): publicar desde ahí se saltaría el cierre y el visto bueno.
     * Recibe la carga releída con bloqueo por TransitionLoad.
     *
     * @return list<string>
     */
    private static function publishable(AcademicLoad $load): array
    {
        return $load->status === LoadStatus::Reanalysis
            ? ["La carga está en {$load->status->label()}: no puede pasar a Publicada; ciérrala primero, o cancela el re-análisis si no cambió nada."]
            : [];
    }

    /**
     * La versión 1 es la publicación; una posterior, re-análisis si el último cierre de la carga salió de `reanalysis`
     * (RA-01 a RA-08) y corrección si salió de asignación (se reabrió a ajustes, P3).
     */
    private function reason(AcademicLoad $load, int $version): SnapshotReason
    {
        return match (true) {
            $version === 1 => SnapshotReason::Published,
            $this->cycle->closedFromReanalysis($load) => SnapshotReason::Reanalysis,
            default => SnapshotReason::Correction,
        };
    }

    /**
     * El departamento de cada carrera de las dos fotos (Academic, también de las borradas): el payload no lo trae y el
     * diff lo necesita para saber qué grupos compartidos tocan a otro departamento (RA-05).
     *
     * @param  array<string, mixed>  $previous
     * @param  array<string, mixed>  $payload
     * @return array<string, string|null>
     */
    private static function careerDepartments(array $previous, array $payload): array
    {
        return ReanalysisChangesQuery::careerDepartments($previous, $payload);
    }

    /**
     * «; contra la versión anterior: nuevos 2, cancelados 1, con cambios 3» para la bitácora, o nada en la versión 1.
     *
     * @param  array<string, mixed>|null  $diff
     */
    private static function changesLabel(?array $diff): string
    {
        if ($diff === null) {
            return '';
        }

        ['added' => $added, 'cancelled' => $cancelled, 'changed' => $changed] = $diff['summary'];

        return "; contra la versión anterior: nuevos {$added}, cancelados {$cancelled}, con cambios {$changed}";
    }

    /**
     * Las materias impartidas que dice una foto, por campus del grupo ('' sin campus): una por grupo con docente.
     *
     * @param  list<array<string, mixed>>  $groups
     * @return array<string, list<array{teacher_id: string, subject_id: string, group_code: string}>>
     */
    private static function taughtByCampus(array $groups): array
    {
        $taught = [];

        foreach ($groups as $group) {
            if ($group['teacher'] !== null) {
                $taught[$group['campus']['id'] ?? ''][] = ['teacher_id' => $group['teacher']['id'], 'subject_id' => $group['subject']['id'], 'group_code' => $group['code']];
            }
        }

        return $taught;
    }
}
