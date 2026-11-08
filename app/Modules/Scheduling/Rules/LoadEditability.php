<?php

namespace App\Modules\Scheduling\Rules;

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Models\Campus;
use App\Modules\Scheduling\Enums\GroupStatus;
use App\Modules\Scheduling\Models\Group;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

/**
 * La única regla de «grupo editable» del editor de horarios (3.5) y de los cupos (3.6): el grupo no está cancelado y
 * la carga de su departamento en su campus y periodo está en propuesta, asignación o ajustes ("Aprobaciones y
 * permisos"). Un grupo sin fila de carga no se edita (supuesto por confirmar, lo más restrictivo).
 *
 * Orden de candados: las Actions toman la carga con lockLoad() (FOR SHARE) antes de bloquear el grupo (FOR UPDATE),
 * como las transiciones de carga, para no provocar un deadlock; si aun así la base lo detecta, guard() lo traduce.
 */
final class LoadEditability
{
    /** @var list<LoadStatus> */
    public const array EDITABLE = [LoadStatus::Proposed, LoadStatus::Staffing, LoadStatus::Adjusting];

    private const string DEADLOCK = '40P01';

    /**
     * Toma la carga del grupo con FOR SHARE (llámalo antes de bloquear el grupo).
     */
    public function lockLoad(Group $group): ?AcademicLoad
    {
        return AcademicLoad::query()
            ->where('term_id', $group->term_id)
            ->where('campus_id', $group->campus_id)
            ->where('department_id', $group->department_id)
            ->sharedLock()
            ->first(['id', 'status']);
    }

    /**
     * @param  string  $what  lo que no se edita, para el mensaje («horarios», «cupos»)
     * @param  string  $key  la llave del error
     *
     * @throws ValidationException
     */
    public function ensure(Group $group, string $what = 'horarios', string $key = 'session'): void
    {
        if ($group->status === GroupStatus::Cancelled) {
            throw ValidationException::withMessages([$key => "El grupo {$group->code} está cancelado: no se editan {$what}."]);
        }

        $status = $this->lockLoad($group)?->status;
        $reason = self::isEditable($status) ? null : self::reason(
            $status,
            Department::withTrashed()->whereKey($group->department_id)->value('name'),
            Campus::withTrashed()->whereKey($group->campus_id)->value('name'),
            $what,
        );

        if ($reason !== null) {
            throw ValidationException::withMessages([$key => $reason]);
        }
    }

    /**
     * El motivo por el que cada grupo no se edita, o null si se edita. Una consulta de cargas por las tuplas (periodo,
     * campus, departamento) de los grupos y, solo si alguno está bloqueado, una de departamentos y una de campus para los
     * mensajes, sin importar cuántos grupos sean (el tablero del editor).
     *
     * @param  iterable<Group>  $groups
     * @return array<string, ?string>
     */
    public function blockedReasons(iterable $groups, string $what = 'horarios'): array
    {
        $groups = collect($groups)->values();
        $key = fn (Group|AcademicLoad $row): string => $row->term_id.'|'.$row->campus_id.'|'.$row->department_id;
        $tuples = $groups->reject(fn (Group $group): bool => $group->status === GroupStatus::Cancelled)->unique($key)->values();

        $statuses = $tuples->isEmpty() ? collect() : AcademicLoad::query()
            ->where(function (Builder $query) use ($tuples): void {
                foreach ($tuples as $group) {
                    $query->orWhere(fn (Builder $tuple) => $tuple->where('term_id', $group->term_id)->where('campus_id', $group->campus_id)->where('department_id', $group->department_id));
                }
            })
            ->get(['term_id', 'campus_id', 'department_id', 'status'])
            ->mapWithKeys(fn (AcademicLoad $load): array => [$key($load) => $load->status]);

        $departments = null;
        $campuses = null;

        return $groups->mapWithKeys(function (Group $group) use ($statuses, $key, $tuples, $what, &$departments, &$campuses): array {
            if ($group->status === GroupStatus::Cancelled) {
                return [$group->id => "El grupo {$group->code} está cancelado: no se editan {$what}."];
            }

            $status = $statuses[$key($group)] ?? null;

            if (self::isEditable($status)) {
                return [$group->id => null];
            }

            $departments ??= Department::withTrashed()->whereIn('id', $tuples->pluck('department_id')->unique()->all())->pluck('name', 'id');
            $campuses ??= Campus::withTrashed()->whereIn('id', $tuples->pluck('campus_id')->unique()->all())->pluck('name', 'id');

            return [$group->id => self::reason($status, $departments[$group->department_id] ?? null, $campuses[$group->campus_id] ?? null, $what)];
        })->all();
    }

    /**
     * Corre `$work` y, si la base detecta un deadlock, lo traduce a un error de validación en vez de un 500.
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     *
     * @throws ValidationException
     */
    public function guard(Closure $work, string $key = 'session'): mixed
    {
        try {
            return $work();
        } catch (QueryException $exception) {
            if ($this->isDeadlock($exception)) {
                throw ValidationException::withMessages([$key => 'Otra persona está cambiando esta carga; intenta de nuevo.']);
            }

            throw $exception;
        }
    }

    public function isDeadlock(QueryException $exception): bool
    {
        return (string) $exception->getCode() === self::DEADLOCK || ($exception->errorInfo[0] ?? null) === self::DEADLOCK;
    }

    private static function isEditable(?LoadStatus $status): bool
    {
        return $status !== null && in_array($status, self::EDITABLE, true);
    }

    private static function reason(?LoadStatus $status, ?string $department, ?string $campus, string $what): string
    {
        return $status === null
            ? "{$department} no tiene carga en {$campus} en este periodo: no se editan {$what}."
            : "La carga de {$department} en {$campus} está en ".mb_strtolower($status->label()).": no se editan {$what}.";
    }
}
