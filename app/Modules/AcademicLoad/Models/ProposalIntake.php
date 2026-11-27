<?php

namespace App\Modules\AcademicLoad\Models;

use App\Modules\AcademicLoad\Database\Factories\ProposalIntakeFactory;
use App\Modules\Core\Models\Campus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * El nuevo ingreso esperado de una columna de la propuesta (tarea 5.6b; DM-09): `suggested_new_students` es el de la
 * proyección de Demand (filas de primer semestre del campus) y `new_students` lo que vale, que el coordinador puede
 * corregir. `captured_at` nulo es «sin tocar»: sigue solo a la proyección. `campus_id` es nulo solo en una modalidad sin
 * salones (MS5).
 */
#[Fillable(['career_proposal_id', 'campus_id', 'suggested_new_students', 'new_students', 'captured_at', 'captured_by'])]
#[UseFactory(ProposalIntakeFactory::class)]
class ProposalIntake extends Model
{
    /** @use HasFactory<ProposalIntakeFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'suggested_new_students' => 'integer',
            'new_students' => 'integer',
            'captured_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<CareerProposal, $this>
     */
    public function proposal(): BelongsTo
    {
        return $this->belongsTo(CareerProposal::class, 'career_proposal_id');
    }

    /**
     * @return BelongsTo<Campus, $this>
     */
    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }
}
