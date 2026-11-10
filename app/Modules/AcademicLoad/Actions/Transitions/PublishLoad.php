<?php

namespace App\Modules\AcademicLoad\Actions\Transitions;

use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Events\LoadSnapshotRequested;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Models\LoadSnapshot;
use App\Modules\AcademicLoad\Rules\SnapshotPayload;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Actions\SyncPublishedTeaching;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;

/**
 * Publica la carga de un departamento en un campus (tarea 4.3, docs/proceso/proceso-propuesto.md, etapa 8): de
 * `closed` (campus sin visto bueno) o `approved` a `published`, y en R2 también desde `reanalysis`. Todo en una
 * transacción, para que no haya carga publicada sin su foto ni foto sin carga publicada:
 *
 * 1. `TransitionLoad` bloquea la carga, revisa el diagrama y junta los vetos de LoadTransitioning (Scheduling vuelve a
 *    revisar HC-15 al publicar, confirmado el 10 de noviembre de 2026).
 * 2. Pide los grupos a Scheduling con LoadSnapshotRequested, arma el payload con su encabezado y lo revisa contra
 *    `SnapshotPayload` (una foto que no cumple no se guarda).
 * 3. Guarda la versión siguiente (`current_version + 1`; la anterior se conserva, HC-18) y anota en la carga quién y
 *    cuándo publicó.
 * 4. Lleva el historial docente a lo que dice esta versión (`SyncPublishedTeaching`, Faculty, `source = nexus`): una
 *    fila por grupo con docente; las de la versión anterior que ya no están se borran lógicamente.
 *
 * Quién puede lo decide `AcademicLoadPolicy::publish` antes de llamarla.
 */
final class PublishLoad
{
    public function __construct(
        private readonly TransitionLoad $transition,
        private readonly SyncPublishedTeaching $teaching,
    ) {}

    /**
     * @throws ValidationException si el diagrama no lo permite o un módulo lo veta
     * @throws InvalidArgumentException si la foto no cumple su forma
     */
    public function handle(AcademicLoad $load, User $actor, ?string $comment = null): LoadSnapshot
    {
        return DB::transaction(function () use ($load, $actor, $comment): LoadSnapshot {
            $load = $this->transition->handle($load, LoadStatus::Published, $actor, $comment, fn (AcademicLoad $load): array => []);

            $request = new LoadSnapshotRequested($load);
            event($request);
            $groups = $request->groups() ?? throw new LogicException('Ningún módulo dio los grupos de la foto de la carga.');

            $version = $load->current_version + 1;
            $publishedAt = CarbonImmutable::now()->startOfSecond();
            $payload = [...SnapshotPayload::header($load, $version, $actor, $publishedAt), 'groups' => $groups];
            SnapshotPayload::assertValid($payload);

            $previous = $load->snapshots()->where('version', $load->current_version)->first()?->payload;

            $snapshot = $load->snapshots()->create([
                'version' => $version,
                'payload' => $payload,
                'created_by' => $actor->getKey(),
                'created_at' => $publishedAt,
            ]);

            $load->update(['current_version' => $version, 'published_by' => $actor->getKey(), 'published_at' => $publishedAt]);

            $this->teaching->handle(
                $load->term->code,
                $load->campus_id,
                self::taught($groups),
                $previous === null ? [] : self::taught($previous['groups']),
            );

            $sessions = array_sum(array_map(fn (array $group): int => count($group['sessions']), $groups));

            activity()
                ->performedOn($load)
                ->causedBy($actor)
                ->event('published')
                ->withProperties(['version' => $version, 'groups' => count($groups), 'sessions' => $sessions])
                ->log("Publicó la versión {$version} de la carga de {$load->department->code} en {$load->campus->code}: ".count($groups).' grupos');

            return $snapshot;
        });
    }

    /**
     * Las materias impartidas que dice una foto: una por grupo con docente.
     *
     * @param  list<array<string, mixed>>  $groups
     * @return list<array{teacher_id: string, subject_id: string, group_code: string}>
     */
    private static function taught(array $groups): array
    {
        return array_values(array_map(
            fn (array $group): array => ['teacher_id' => $group['teacher']['id'], 'subject_id' => $group['subject']['id'], 'group_code' => $group['code']],
            array_filter($groups, fn (array $group): bool => $group['teacher'] !== null),
        ));
    }
}
