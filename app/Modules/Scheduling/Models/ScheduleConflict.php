<?php

namespace App\Modules\Scheduling\Models;

use App\Modules\Scheduling\Database\Factories\ScheduleConflictFactory;
use App\Modules\Scheduling\Enums\ConflictSeverity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Choque que encontró el validador en un periodo y campus: la regla (`HC-xx`, `SC-xx`), su gravedad, de qué se trata
 * (`group`, `teacher` o `room` con su id), el grupo, el departamento y la carrera dueña para contarlo en el tablero,
 * el detalle y el mensaje en español. Tabla derivada: la reescribe el recálculo del periodo.
 */
#[Fillable(['term_id', 'campus_id', 'department_id', 'career_id', 'group_id', 'rule', 'severity', 'subject_type', 'subject_id', 'related', 'message', 'resolved_at'])]
#[UseFactory(ScheduleConflictFactory::class)]
class ScheduleConflict extends Model
{
    /** @use HasFactory<ScheduleConflictFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'severity' => ConflictSeverity::class,
            'related' => 'array',
            'resolved_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Group, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /**
     * Los choques vigentes: los que el último recálculo no resolvió.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function open(Builder $query): void
    {
        $query->whereNull('schedule_conflicts.resolved_at');
    }
}
