<?php

namespace App\Modules\Faculty\Models;

use App\Modules\Faculty\Database\Factories\TeacherPositionFactory;
use App\Modules\Faculty\Enums\PositionKind;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Plaza de un docente: tipo, horas base y vigencia. Un límite nulo es sin límite. Un docente puede tener
 * varias vigentes a la vez (confirmado el 30 de octubre de 2026).
 */
#[Fillable(['teacher_id', 'kind', 'base_hours', 'valid_from', 'valid_to'])]
#[UseFactory(TeacherPositionFactory::class)]
class TeacherPosition extends Model
{
    /** @use HasFactory<TeacherPositionFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    /**
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'kind' => PositionKind::class,
            'base_hours' => 'integer',
            'valid_from' => 'immutable_date',
            'valid_to' => 'immutable_date',
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
     * Vigentes en una fecha: `valid_from` ≤ fecha ≤ `valid_to`, con límite nulo sin límite. El día se cuenta
     * en Ciudad Juárez (config/app.php) aunque la fecha llegue en otra zona, como DepartmentHead::currentOn().
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function currentOn(Builder $query, CarbonInterface $date): void
    {
        $day = $date->toImmutable()->setTimezone(config('app.timezone'))->toDateString();

        $query->where(fn (Builder $query) => $query->whereNull('valid_from')->orWhere('valid_from', '<=', $day))
            ->where(fn (Builder $query) => $query->whereNull('valid_to')->orWhere('valid_to', '>=', $day));
    }
}
