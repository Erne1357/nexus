<?php

namespace App\Modules\Solver\Models;

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Solver\Database\Factories\SolverRunFactory;
use App\Modules\Solver\Enums\RunScope;
use App\Modules\Solver\Enums\SolverRunStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una corrida del motor de horarios (tarea 6.2): qué programó (la carga de un departamento en una modalidad, o los grupos
 * de una carrera), con qué motor, en qué estado está, su entrada y su salida del contrato (en la fila o en un archivo,
 * `*_path`; RunPayloads), sus métricas y quién la lanzó y quién la aceptó. Mientras corre lleva su avance y su mejor
 * puntaje, y si se pidió cancelarla, cuándo (tarea 6.8); `input_hash` es la huella de su entrada (SV-02).
 */
#[Fillable([
    'term_id', 'modality_id', 'scope_kind', 'department_id', 'career_id', 'engine', 'status',
    'input', 'input_path', 'output', 'output_path', 'metrics', 'error',
    'created_by', 'started_at', 'finished_at', 'applied_by', 'applied_at',
    'progress', 'best_score', 'input_hash', 'cancel_requested_at', 'apply_token',
])]
#[UseFactory(SolverRunFactory::class)]
class SolverRun extends Model
{
    /** @use HasFactory<SolverRunFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scope_kind' => RunScope::class,
            'status' => SolverRunStatus::class,
            'input' => 'array',
            'output' => 'array',
            'metrics' => 'array',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'applied_at' => 'immutable_datetime',
            'progress' => 'integer',
            'best_score' => 'float',
            'cancel_requested_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Term, $this>
     */
    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    /**
     * @return BelongsTo<Modality, $this>
     */
    public function modality(): BelongsTo
    {
        return $this->belongsTo(Modality::class);
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * @return BelongsTo<Career, $this>
     */
    public function career(): BelongsTo
    {
        return $this->belongsTo(Career::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function applier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by');
    }
}
