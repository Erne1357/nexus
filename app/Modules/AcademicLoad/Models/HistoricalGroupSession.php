<?php

namespace App\Modules\AcademicLoad\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una sesión semanal de un grupo histórico (tarea 1.24.1): día 1 (lunes) a 6 (sábado), horas, aula y docente como
 * texto (`room_code` normalizado, `teacher_rfc` del SII), sin llaves a Facilities ni Faculty.
 */
#[Fillable(['historical_group_id', 'day', 'starts_at', 'ends_at', 'room_code', 'teacher_rfc'])]
class HistoricalGroupSession extends Model
{
    use HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['day' => 'integer'];
    }

    /**
     * @return BelongsTo<HistoricalGroup, $this>
     */
    public function historicalGroup(): BelongsTo
    {
        return $this->belongsTo(HistoricalGroup::class);
    }
}
