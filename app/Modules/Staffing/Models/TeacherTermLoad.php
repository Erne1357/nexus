<?php

namespace App\Modules\Staffing\Models;

use App\Modules\Core\Models\Term;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Staffing\Database\Factories\TeacherTermLoadFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Horas de un docente en un periodo: base, frente a grupo (PL-01), el tope de su banda y el de honorarios si es el que
 * aplica (HC-08, tarea 1.50; nulas en una fila calculada antes), externas, asignadas, límite (el tope de la banda o lo que autorizó su casilla, SEL-12) y
 * restantes (negativas si rebasa el límite), y con plaza administrativa (PL-03 y HC-22, tarea 1.80; nulas si no la tiene) sus
 * horas administrativas y el tope de 42 h vigente; con plaza docente (PL-04, tarea 1.81) sus horas extra y el tope con
 * honorarios vigente (44 h). Tabla derivada para tableros y HC-08.
 */
#[Fillable(['teacher_id', 'term_id', 'base_hours', 'teaching_hours', 'band_limit_hours', 'honorarios_cap_hours', 'administrative_hours', 'administrative_cap_hours', 'extra_hours', 'with_honorarios_max_hours', 'external_hours', 'assigned_hours', 'limit_hours', 'remaining_hours', 'groups_count', 'computed_at'])]
#[UseFactory(TeacherTermLoadFactory::class)]
class TeacherTermLoad extends Model
{
    /** @use HasFactory<TeacherTermLoadFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'base_hours' => 'integer',
            'teaching_hours' => 'integer',
            'band_limit_hours' => 'integer',
            'honorarios_cap_hours' => 'integer',
            'administrative_hours' => 'integer',
            'administrative_cap_hours' => 'integer',
            'extra_hours' => 'integer',
            'with_honorarios_max_hours' => 'integer',
            'external_hours' => 'integer',
            'assigned_hours' => 'integer',
            'limit_hours' => 'integer',
            'remaining_hours' => 'integer',
            'groups_count' => 'integer',
            'computed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Teacher, $this>
     */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    /**
     * @return BelongsTo<Term, $this>
     */
    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }
}
