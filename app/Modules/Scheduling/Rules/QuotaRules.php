<?php

namespace App\Modules\Scheduling\Rules;

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Rules\AgreementRules;
use App\Modules\Core\Models\Campus;
use App\Modules\Scheduling\Enums\GroupStatus;
use App\Modules\Scheduling\Models\Group;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

/**
 * HC-07 (reglas-de-negocio.md, "Cupos por carrera"): la suma de los cupos de un grupo no pasa su capacidad. Los
 * acuerdos propuestos no cuentan hasta que se aceptan (supuesto por confirmar). Los lugares de cada cupo van de 1 a
 * 999, como los de un acuerdo.
 */
final class QuotaRules
{
    public const int MAX_SEATS = AgreementRules::MAX_SEATS;

    /**
     * @throws ValidationException
     */
    public function ensureWithinCapacity(int $total, int $capacity): void
    {
        if ($total > $capacity) {
            throw $this->overCapacity($total, $capacity);
        }
    }

    /**
     * Los cupos se editan solo en un grupo que no está cancelado y mientras la carga del departamento que lo imparte,
     * en su campus y periodo, está propuesta, en asignación docente o en ajuste (supuesto por confirmar; se unirá con
     * la regla del editor). Sin fila de carga no hay nada que lo impida.
     *
     * @throws ValidationException
     */
    public function ensureEditable(Group $group): void
    {
        if ($group->status === GroupStatus::Cancelled) {
            throw ValidationException::withMessages(['quotas' => 'El grupo está cancelado: no se editan cupos.']);
        }

        $status = AcademicLoad::query()
            ->where('term_id', $group->term_id)
            ->where('campus_id', $group->campus_id)
            ->where('department_id', $group->department_id)
            ->value('status');

        if ($status === null) {
            return;
        }

        $status = $status instanceof LoadStatus ? $status : LoadStatus::from($status);

        if (! in_array($status, [LoadStatus::Proposed, LoadStatus::Staffing, LoadStatus::Adjusting], true)) {
            $department = Department::withTrashed()->find($group->department_id)?->name;
            $campus = Campus::query()->find($group->campus_id)?->name;

            throw ValidationException::withMessages(['quotas' => "La carga de {$department} en {$campus} está en {$status->label()}: no se editan cupos."]);
        }
    }

    /**
     * La carrera, para ganar un cupo nuevo, está activa, sin borrar y se ofrece activa en el campus del grupo.
     *
     * @throws ValidationException
     */
    public function ensureOffered(Career $career, Group $group): void
    {
        $offered = ! $career->trashed() && $career->is_active
            && CareerCampus::query()->where('career_id', $career->getKey())->where('campus_id', $group->campus_id)->where('is_active', true)->exists();

        if (! $offered) {
            throw ValidationException::withMessages(["quotas.{$career->getKey()}" => 'La carrera no está activa u ofrecida en el campus del grupo.']);
        }
    }

    /**
     * Al aceptar un acuerdo: lo repartido más los lugares del acuerdo caben en la capacidad.
     *
     * @throws ValidationException
     */
    public function ensureAgreementFits(int $current, int $seats, int $capacity): void
    {
        if ($current + $seats > $capacity) {
            throw ValidationException::withMessages([
                'max_seats' => 'HC-07: el grupo ya tiene '.$current.' de '.$capacity.' lugares repartidos; caben '.max(0, $capacity - $current).'.',
            ]);
        }
    }

    public function overCapacity(int $total, int $capacity, string $key = 'quotas'): ValidationException
    {
        return ValidationException::withMessages([$key => "HC-07: los cupos suman {$total} y el grupo es de {$capacity} lugares."]);
    }

    /**
     * Si la excepción es el rechazo de HC-07 del trigger de la base (otra escritura cambió los cupos entre la
     * lectura y la escritura), la ValidationException con el mismo mensaje que la Action; si no, null.
     */
    public function fromDatabase(QueryException $e, string $key = 'quotas'): ?ValidationException
    {
        if (preg_match('/HC-07: los cupos del grupo suman (\d+) y su capacidad es (\d+)/', $e->getMessage(), $m) !== 1) {
            return null;
        }

        return $this->overCapacity((int) $m[1], (int) $m[2], $key);
    }

    /**
     * La violación del único de `group_quotas` (la carrera ya tiene cupo en el grupo): su ValidationException, o null.
     */
    public function fromUnique(QueryException $e): ?ValidationException
    {
        return str_contains($e->getMessage(), 'group_quotas_group_id_career_id_unique')
            ? ValidationException::withMessages(['career_id' => 'La carrera ya tiene cupo en este grupo.'])
            : null;
    }
}
