<?php

namespace App\Modules\AcademicLoad\Models;

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Database\Factories\HistoricalGroupFactory;
use App\Modules\AcademicLoad\Enums\HistoricalGroupSource;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un grupo que se abrió en un periodo pasado, de lo migrado del SII o de lo que Nexus publicó (tarea 5.6). Registro
 * histórico: sin borrado lógico; se reemplaza al reimportar o al publicar otra versión.
 */
#[Fillable(['term_code', 'modality_id', 'campus_id', 'subject_id', 'group_code', 'sii_subject_code', 'capacity', 'enrolled', 'career_id', 'parallel_of', 'source', 'load_snapshot_id', 'recorded_at'])]
#[UseFactory(HistoricalGroupFactory::class)]
class HistoricalGroup extends Model
{
    /** @use HasFactory<HistoricalGroupFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'enrolled' => 'integer',
            'source' => HistoricalGroupSource::class,
            'recorded_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Subject, $this>
     */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    /**
     * @return BelongsTo<Modality, $this>
     */
    public function modality(): BelongsTo
    {
        return $this->belongsTo(Modality::class);
    }

    /**
     * @return BelongsTo<Campus, $this>
     */
    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }

    /**
     * @return BelongsTo<Career, $this>
     */
    public function career(): BelongsTo
    {
        return $this->belongsTo(Career::class);
    }

    /**
     * @return BelongsTo<LoadSnapshot, $this>
     */
    public function loadSnapshot(): BelongsTo
    {
        return $this->belongsTo(LoadSnapshot::class);
    }
}
