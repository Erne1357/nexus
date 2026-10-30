<?php

namespace App\Modules\Faculty\Models;

use App\Modules\Core\Models\User;
use App\Modules\Faculty\Database\Factories\TeacherFactory;
use App\Modules\Faculty\Enums\TeacherDegree;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Persona que imparte grupos. Su departamento y campus los dan sus adscripciones; sus horas base, sus
 * plazas. El usuario es opcional: algunos docentes son solo registro para RH.
 */
#[Fillable(['employee_number', 'user_id', 'name', 'email', 'degree', 'hired_on', 'is_active'])]
#[UseFactory(TeacherFactory::class)]
class Teacher extends Model
{
    /** @use HasFactory<TeacherFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    /**
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'degree' => TeacherDegree::class,
            'hired_on' => 'immutable_date',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<TeacherDepartment, $this>
     */
    public function adscriptions(): HasMany
    {
        return $this->hasMany(TeacherDepartment::class);
    }

    /**
     * La adscripción principal, la del SII.
     *
     * @return HasOne<TeacherDepartment, $this>
     */
    public function primaryAdscription(): HasOne
    {
        return $this->hasOne(TeacherDepartment::class)->where('is_primary', true);
    }

    /**
     * @return HasMany<TeacherPosition, $this>
     */
    public function positions(): HasMany
    {
        return $this->hasMany(TeacherPosition::class);
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
