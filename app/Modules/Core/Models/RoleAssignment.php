<?php

namespace App\Modules\Core\Models;

use App\Modules\Core\Database\Factories\RoleAssignmentFactory;
use App\Modules\Core\Enums\Role;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Nombramiento (ADR-0018): `user` ocupa el puesto `role` sobre un campus, una unidad del organigrama, una carrera o
 * una modalidad (nulo = sin límite en esa dimensión), del día `valid_from` al `valid_to` (nulo, sin fin). El
 * coordinador y el jefe de proyecto docente llevan modalidad y, opcionalmente, campus (AZ-S17). Es la única fuente de
 * quién tiene qué puesto. Un departamento académico es una unidad con su mismo id, así que `org_unit_id` de un jefe
 * es el id de su departamento. `career_id` tiene FK a `careers` (la agrega Academic), pero sin relación aquí porque
 * Core no depende de Academic. La base garantiza un solo jefe y una sola Subdirección por unidad en cada fecha, y
 * sube `users.authz_version` en cada cambio. Quién cubre qué lo decide Actor, no este modelo: él descarta los de un
 * campus o una modalidad desactivados o borrados en cada petición (MS8), porque el snapshot cacheado no se invalida
 * al desactivarlos.
 */
#[Fillable(['user_id', 'role', 'campus_id', 'org_unit_id', 'career_id', 'modality_id', 'valid_from', 'valid_to'])]
#[UseFactory(RoleAssignmentFactory::class)]
class RoleAssignment extends Model
{
    /** @use HasFactory<RoleAssignmentFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => Role::class,
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
     * @return BelongsTo<Modality, $this>
     */
    public function modality(): BelongsTo
    {
        return $this->belongsTo(Modality::class);
    }

    /**
     * @return BelongsTo<OrgUnit, $this>
     */
    public function orgUnit(): BelongsTo
    {
        return $this->belongsTo(OrgUnit::class);
    }

    /**
     * Vigentes hoy (`today()` va en America/Ciudad_Juarez por config/app.php):
     * `valid_from` ≤ hoy ≤ `valid_to`, con `valid_to` nulo sin fecha de fin.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function current(Builder $query): void
    {
        $day = today()->toDateString();

        $query->where('valid_from', '<=', $day)
            ->where(fn (Builder $query) => $query->whereNull('valid_to')->orWhere('valid_to', '>=', $day));
    }

    /**
     * Vigentes hoy o por empezar, sin terminar: `valid_to` nulo o no anterior a hoy, sin mirar `valid_from`.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function notEnded(Builder $query): void
    {
        $day = today()->toDateString();

        $query->where(fn (Builder $query) => $query->whereNull('valid_to')->orWhere('valid_to', '>=', $day));
    }
}
