<?php

namespace App\Modules\Students\Models;

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Students\Database\Factories\StudentFactory;
use App\Modules\Students\Enums\StudentStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Alumno, como lo trae el SII. `control_number` es la clave natural. `status` queda vacío si el SII trae un
 * estatus que Nexus no conoce. `status_reason` guarda el
 * motivo del SII (BD1, BT1, TIT…) y `modality_id`, la modalidad del catálogo en que estudia (MS7). `user_id` es su usuario
 * (AC-10), que crea la migración del SII.
 */
#[Fillable(['control_number', 'name', 'career_id', 'study_plan_id', 'campus_id', 'current_semester', 'status', 'status_reason', 'modality_id', 'admitted_term_id', 'is_active'])]
#[UseFactory(StudentFactory::class)]
class Student extends Model
{
    /** @use HasFactory<StudentFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'current_semester' => 'integer',
            'status' => StudentStatus::class,
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Career, $this>
     */
    public function career(): BelongsTo
    {
        return $this->belongsTo(Career::class);
    }

    /**
     * @return BelongsTo<StudyPlan, $this>
     */
    public function studyPlan(): BelongsTo
    {
        return $this->belongsTo(StudyPlan::class);
    }

    /**
     * @return BelongsTo<Campus, $this>
     */
    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }

    /**
     * @return BelongsTo<Modality, $this>
     */
    public function modality(): BelongsTo
    {
        return $this->belongsTo(Modality::class);
    }

    /**
     * @return BelongsTo<Term, $this>
     */
    public function admittedTerm(): BelongsTo
    {
        return $this->belongsTo(Term::class, 'admitted_term_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<KardexRecord, $this>
     */
    public function kardexRecords(): HasMany
    {
        return $this->hasMany(KardexRecord::class);
    }

    /**
     * @return HasOne<StudentProgress, $this>
     */
    public function progress(): HasOne
    {
        return $this->hasOne(StudentProgress::class);
    }
}
