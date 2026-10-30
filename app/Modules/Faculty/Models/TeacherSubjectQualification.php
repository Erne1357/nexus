<?php

namespace App\Modules\Faculty\Models;

use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Database\Factories\TeacherSubjectQualificationFactory;
use App\Modules\Faculty\Enums\QualificationLevel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Perfil: el nivel con que el jefe declara que el docente puede dar una materia.
 */
#[Fillable(['teacher_id', 'subject_id', 'level', 'qualified_by', 'notes'])]
#[UseFactory(TeacherSubjectQualificationFactory::class)]
class TeacherSubjectQualification extends Model
{
    /** @use HasFactory<TeacherSubjectQualificationFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    protected $table = 'teacher_subject_qualifications';

    /**
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return ['level' => QualificationLevel::class];
    }

    /**
     * @return BelongsTo<Teacher, $this>
     */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    /**
     * @return BelongsTo<Subject, $this>
     */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function qualifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'qualified_by');
    }
}
