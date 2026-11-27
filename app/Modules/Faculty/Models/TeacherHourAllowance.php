<?php

namespace App\Modules\Faculty\Models;

use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Horas autorizadas de un docente en un periodo (tarea 1.33, SEL-12): el límite de HC-08 es el mayor entre su base más
 * la tolerancia y estas horas. Las da GrantHourAllowance.
 */
#[Fillable(['teacher_id', 'term_id', 'hours', 'reason', 'granted_by', 'granted_at'])]
class TeacherHourAllowance extends Model
{
    use HasUuids;

    protected $table = 'teacher_hour_allowances';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'hours' => 'integer',
            'granted_at' => 'immutable_datetime',
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

    /**
     * @return BelongsTo<User, $this>
     */
    public function grantor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }
}
