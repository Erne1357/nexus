<?php

namespace App\Modules\Core\Models;

use App\Modules\Core\Database\Factories\RoleScopeFactory;
use App\Modules\Core\Enums\Role;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Alcance de un rol (ADR-0004): sobre qué campus, departamento o carrera ejerce `user` el rol
 * `role`, y desde cuándo. `department_id` y `career_id` son enteros sin relación porque Core no
 * depende de Academic. Quién cubre qué lo decide ScopedContext, no este modelo.
 */
#[Fillable(['user_id', 'role', 'campus_id', 'department_id', 'career_id', 'valid_from', 'valid_to'])]
#[UseFactory(RoleScopeFactory::class)]
class RoleScope extends Model
{
    /** @use HasFactory<RoleScopeFactory> */
    use HasFactory;

    /**
     * Zona de las fechas de vigencia: un alcance vale por días de Ciudad Juárez.
     */
    public const string TIMEZONE = 'America/Ciudad_Juarez';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => Role::class,
            'campus_id' => 'integer',
            'department_id' => 'integer',
            'career_id' => 'integer',
            'valid_from' => 'immutable_date',
            'valid_to' => 'immutable_date',
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
     * @return BelongsTo<Campus, $this>
     */
    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }

    /**
     * Vigentes en `$on` (hoy en Ciudad Juárez si no se da): `valid_from` ≤ día ≤ `valid_to`,
     * con `valid_to` nulo sin fecha de fin.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function current(Builder $query, ?CarbonInterface $on = null): void
    {
        $day = CarbonImmutable::instance($on ?? now())->setTimezone(self::TIMEZONE)->toDateString();

        $query->where('valid_from', '<=', $day)
            ->where(fn (Builder $query) => $query->whereNull('valid_to')->orWhere('valid_to', '>=', $day));
    }
}
