<?php

namespace App\Modules\Academic\Models;

use App\Modules\Academic\Database\Factories\DepartmentHeadFactory;
use App\Modules\Academic\Enums\DepartmentHeadKind;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
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
 * Quién dirige un departamento en un campus y desde cuándo (`kind`: jefe o jefe de proyecto docente).
 * `valid_to` nulo es vigente sin fecha de fin. Un solo jefe vigente por departamento y campus; de jefe de
 * proyecto docente puede haber varios (la base lo garantiza).
 */
#[Fillable(['department_id', 'campus_id', 'user_id', 'kind', 'valid_from', 'valid_to'])]
#[UseFactory(DepartmentHeadFactory::class)]
class DepartmentHead extends Model
{
    /** @use HasFactory<DepartmentHeadFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => DepartmentHeadKind::class,
            'valid_from' => 'immutable_date',
            'valid_to' => 'immutable_date',
        ];
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * @return BelongsTo<Campus, $this>
     */
    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Vigentes en una fecha: `valid_from` ≤ fecha ≤ `valid_to`, con `valid_to` nulo sin fecha de fin.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function currentOn(Builder $query, CarbonInterface $date): void
    {
        $day = $date->toDateString();

        $query->where('valid_from', '<=', $day)
            ->where(fn (Builder $query) => $query->whereNull('valid_to')->orWhere('valid_to', '>=', $day));
    }
}
