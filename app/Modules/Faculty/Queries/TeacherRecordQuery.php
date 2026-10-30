<?php

namespace App\Modules\Faculty\Queries;

use App\Modules\Core\Models\Term;
use App\Modules\Core\Queries\KeyLookup;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherDepartment;
use App\Modules\Faculty\Models\TeacherPosition;
use App\Modules\Faculty\Rules\BaseHours;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lecturas de la ficha de un docente (tarea 1.13). Quién la ve lo decide TeacherPolicy (`view`) antes de llamarla.
 * Sin N+1: adscripciones con departamento y campus, plazas y usuario, en una consulta cada una; las horas base del
 * periodo salen de las plazas ya cargadas con la misma regla que la lista (BaseHours, S1 y S2, supuestos por
 * confirmar).
 */
final class TeacherRecordQuery
{
    /**
     * La ficha de `$teacher` en `$term` (el periodo activo; null si no hay). Las adscripciones y plazas borradas no
     * entran; el departamento y el campus de una adscripción sí, aunque estén borrados.
     */
    public function for(Teacher $teacher, ?Term $term): TeacherRecord
    {
        $adscriptions = $teacher->adscriptions()
            ->with([
                'department' => fn (BelongsTo $department) => $department->withTrashed()->select(['id', 'code', 'name']),
                'campus' => fn (BelongsTo $campus) => $campus->withTrashed()->select(['id', 'code', 'name']),
            ])
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->get();

        $today = today()->toDateString();
        $termDay = $term?->starts_on->toDateString();
        $order = ['vigente' => 0, 'por_empezar' => 1, 'vencida' => 2];
        $positions = $teacher->positions()->get();
        $status = $positions->mapWithKeys(fn (TeacherPosition $position): array => [$position->id => $this->status($position, $today)])->all();

        $positions = $positions
            ->sortBy([
                fn (TeacherPosition $a, TeacherPosition $b): int => $order[$status[$a->id]] <=> $order[$status[$b->id]],
                fn (TeacherPosition $a, TeacherPosition $b): int => strcmp((string) $b->valid_from?->toDateString(), (string) $a->valid_from?->toDateString()),
                fn (TeacherPosition $a, TeacherPosition $b): int => strcmp($a->id, $b->id),
            ])
            ->values();
        $status = $positions->mapWithKeys(fn (TeacherPosition $position): array => [$position->id => $status[$position->id]])->all();
        $counts = $positions->mapWithKeys(fn (TeacherPosition $position): array => [$position->id => $termDay !== null && $this->covers($position, $termDay)])->all();

        return new TeacherRecord(
            teacher: $teacher,
            adscriptions: $adscriptions,
            positions: $positions,
            positionStatus: $status,
            countsInTerm: $counts,
            baseHours: $term === null ? null : BaseHours::onDate(
                $positions->map(fn (TeacherPosition $position): array => [
                    'base_hours' => $position->base_hours,
                    'valid_from' => $position->valid_from?->toDateString(),
                    'valid_to' => $position->valid_to?->toDateString(),
                ]),
                $term->starts_on,
            ),
            term: $term,
            user: $teacher->user_id === null ? null : $teacher->user()->first(['id', 'username', 'is_active', 'must_change_password']),
        );
    }

    /**
     * Una adscripción sin borrar de ese docente. El id llega del navegador: uno que no es uuid, o de otro docente, no
     * existe (ADR-0014).
     *
     * @throws ModelNotFoundException<TeacherDepartment>
     */
    public function findAdscription(Teacher $teacher, string $id): TeacherDepartment
    {
        return KeyLookup::findOrFail(TeacherDepartment::query()->where('teacher_id', $teacher->getKey()), $id);
    }

    /**
     * Una plaza sin borrar de ese docente; el id llega del navegador.
     *
     * @throws ModelNotFoundException<TeacherPosition>
     */
    public function findPosition(Teacher $teacher, string $id): TeacherPosition
    {
        return KeyLookup::findOrFail(TeacherPosition::query()->where('teacher_id', $teacher->getKey()), $id);
    }

    private function status(TeacherPosition $position, string $today): string
    {
        return match (true) {
            $position->valid_from !== null && $position->valid_from->toDateString() > $today => 'por_empezar',
            $position->valid_to !== null && $position->valid_to->toDateString() < $today => 'vencida',
            default => 'vigente',
        };
    }

    private function covers(TeacherPosition $position, string $day): bool
    {
        return ($position->valid_from === null || $position->valid_from->toDateString() <= $day)
            && ($position->valid_to === null || $position->valid_to->toDateString() >= $day);
    }
}
