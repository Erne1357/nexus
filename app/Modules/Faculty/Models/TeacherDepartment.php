<?php

namespace App\Modules\Faculty\Models;

use App\Modules\Academic\Models\Department;
use App\Modules\Faculty\Database\Factories\TeacherDepartmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Adscripción: un docente en un departamento, sin campus, porque el docente no es de un campus (confirmado el 30
 * de octubre de 2026). Una es la principal (la del SII); para el ranking cuentan todas igual (confirmado el 30 de
 * octubre de 2026).
 */
#[Fillable(['teacher_id', 'department_id', 'is_primary'])]
#[UseFactory(TeacherDepartmentFactory::class)]
class TeacherDepartment extends Model
{
    /** @use HasFactory<TeacherDepartmentFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_primary' => 'boolean'];
    }

    /**
     * @return BelongsTo<Teacher, $this>
     */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }
}
