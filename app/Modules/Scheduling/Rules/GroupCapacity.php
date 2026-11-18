<?php

namespace App\Modules\Scheduling\Rules;

use App\Modules\Academic\Models\Subject;
use App\Modules\Demand\Rules\SuggestedGroups;
use App\Modules\Facilities\Models\SubjectRoomDefault;
use Illuminate\Support\Collection;

/**
 * La capacidad con que nace un grupo nuevo de una materia en un campus (CreateGroupsFromProposal, ArmParallelGroups): la del
 * salón por defecto de la materia en ese campus (el de menor prioridad, sin contar los salones borrados); sin él, el tamaño
 * objetivo de SuggestedGroups (35, o 25 con laboratorio).
 */
final class GroupCapacity
{
    public static function for(Subject $subject, string $campusId, SuggestedGroups $sizes): int
    {
        return self::forMany([[$subject, $campusId]], $sizes)["{$subject->getKey()}|{$campusId}"];
    }

    /**
     * La de varias materias y campus con dos consultas, por `materia|campus`.
     *
     * @param  iterable<array{0: Subject, 1: string}>  $pairs
     * @return array<string, int>
     */
    public static function forMany(iterable $pairs, SuggestedGroups $sizes): array
    {
        $pairs = collect($pairs);

        if ($pairs->isEmpty()) {
            return [];
        }

        $defaults = SubjectRoomDefault::query()
            ->with('room')
            ->whereIn('subject_id', $pairs->map(fn (array $pair): string => $pair[0]->getKey())->unique()->all())
            ->whereIn('campus_id', $pairs->pluck(1)->unique()->all())
            ->orderBy('priority')
            ->get()
            ->groupBy(fn (SubjectRoomDefault $default): string => "{$default->subject_id}|{$default->campus_id}")
            ->map(fn (Collection $rows): SubjectRoomDefault => $rows->first());

        return $pairs->mapWithKeys(fn (array $pair): array => [
            "{$pair[0]->getKey()}|{$pair[1]}" => $defaults->get("{$pair[0]->getKey()}|{$pair[1]}")?->room?->capacity ?? $sizes->sizeFor((bool) $pair[0]->needs_lab),
        ])->all();
    }
}
