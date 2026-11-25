<?php

namespace App\Modules\Staffing\Models;

use App\Modules\Faculty\Models\Teacher;
use App\Modules\Staffing\Database\Factories\SelectionEntryFactory;
use App\Modules\Staffing\Enums\SelectionEntrySource;
use App\Modules\Staffing\Enums\SelectionEntryStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Renglón de un docente en la cola de una selección: sus datos del CSV, su lugar y turno, y cómo cerró (tarea 1.33).
 */
#[Fillable(['selection_id', 'teacher_id', 'source', 'csv_name', 'csv_line', 'base_hours', 'auto_eval', 'prior_compliance', 'partials_1', 'partials_2', 'seniority_years', 'hired_on', 'plaza_tier', 'failed_criteria', 'tier', 'position', 'slot_index', 'slot_starts_at', 'slot_ends_at', 'extra_slot_starts_at', 'extra_slot_ends_at', 'status', 'closed_at', 'closed_hours', 'out_of_band_acknowledged_at', 'notified_soon_at', 'notified_start_at', 'notified_extra_at', 'prior_choice_at'])]
#[UseFactory(SelectionEntryFactory::class)]
class SelectionEntry extends Model
{
    /** @use HasFactory<SelectionEntryFactory> */
    use HasFactory, HasUuids;

    protected $table = 'staffing_selection_entries';

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'source' => SelectionEntrySource::class,
            'status' => SelectionEntryStatus::class,
            'csv_line' => 'integer',
            'base_hours' => 'integer',
            'auto_eval' => 'boolean',
            'prior_compliance' => 'boolean',
            'partials_1' => 'boolean',
            'partials_2' => 'boolean',
            'seniority_years' => 'decimal:2',
            'hired_on' => 'immutable_date',
            'plaza_tier' => 'integer',
            'failed_criteria' => 'integer',
            'tier' => 'integer',
            'position' => 'integer',
            'slot_index' => 'integer',
            'slot_starts_at' => 'immutable_datetime',
            'slot_ends_at' => 'immutable_datetime',
            'extra_slot_starts_at' => 'immutable_datetime',
            'extra_slot_ends_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'closed_hours' => 'integer',
            'out_of_band_acknowledged_at' => 'immutable_datetime',
            'notified_soon_at' => 'immutable_datetime',
            'notified_start_at' => 'immutable_datetime',
            'notified_extra_at' => 'immutable_datetime',
            'prior_choice_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * SEL-16: el docente ya trae sus grupos de la relación de materias (`prior_choice_at`): su renglón está `done`, sin
     * lugar ni turno.
     */
    public function isPriorChoice(): bool
    {
        return $this->prior_choice_at !== null;
    }

    /**
     * @return BelongsTo<Selection, $this>
     */
    public function selection(): BelongsTo
    {
        return $this->belongsTo(Selection::class);
    }

    /**
     * @return BelongsTo<Teacher, $this>
     */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }
}
