<?php

namespace App\Modules\Scheduling\Rules;

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Models\Modality;
use App\Modules\Scheduling\Enums\GroupStatus;
use App\Modules\Scheduling\Models\Group;
use Closure;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use PDOException;

/**
 * La única regla de «grupo editable» del editor de horarios (3.5) y de los cupos (3.6): el grupo no está cancelado y
 * la carga de su departamento en su modalidad y periodo (M1; AcademicLoad::ofDepartment) está en propuesta, asignación o
 * ajustes ("Aprobaciones y permisos"). Un grupo sin fila de carga no se edita (supuesto por confirmar, lo más
 * restrictivo).
 *
 * Orden de candados: las Actions toman la carga con lockLoad() (FOR SHARE) antes de bloquear el grupo (FOR UPDATE),
 * como las transiciones de carga, para no provocar un deadlock; si aun así la base lo detecta, guard() lo traduce, también
 * cuando Laravel lo envuelve en un DeadlockException al salir de una transacción anidada.
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
            ->ofDepartment($group->term_id, $group->modality_id, $group->department_id)
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
            Modality::withTrashed()->whereKey($group->modality_id)->value('name'),
            $what,
        );

        if ($reason !== null) {
            throw ValidationException::withMessages([$key => $reason]);
        }
    }

    /**
     * El motivo por el que cada grupo no se edita, o null si se edita. Una consulta de cargas por las tuplas (periodo,
     * modalidad, departamento) de los grupos y, solo si alguno está bloqueado, una de departamentos y una de modalidades
     * para los mensajes, sin importar cuántos grupos sean (el tablero del editor).
     *
     * @param  iterable<Group>  $groups
     * @return array<string, ?string>
     */
    public function blockedReasons(iterable $groups, string $what = 'horarios'): array
    {
        $groups = collect($groups)->values();
        $key = fn (Group|AcademicLoad $row): string => $row->term_id.'|'.$row->modality_id.'|'.$row->department_id;
        $tuples = $groups->reject(fn (Group $group): bool => $group->status === GroupStatus::Cancelled)->unique($key)->values();

        $statuses = $tuples->isEmpty() ? collect() : AcademicLoad::query()
            ->where(function (Builder $query) use ($tuples): void {
                foreach ($tuples as $group) {
                    $query->orWhere(fn (Builder $tuple) => $tuple->ofDepartment($group->term_id, $group->modality_id, $group->department_id));
                }
            })
            ->get(['term_id', 'modality_id', 'department_id', 'status'])
            ->mapWithKeys(fn (AcademicLoad $load): array => [$key($load) => $load->status]);

        $departments = null;
        $modalities = null;

        return $groups->mapWithKeys(function (Group $group) use ($statuses, $key, $tuples, $what, &$departments, &$modalities): array {
            if ($group->status === GroupStatus::Cancelled) {
                return [$group->id => "El grupo {$group->code} está cancelado: no se editan {$what}."];
            }

            $status = $statuses[$key($group)] ?? null;

            if (self::isEditable($status)) {
                return [$group->id => null];
            }

            $departments ??= Department::withTrashed()->whereIn('id', $tuples->pluck('department_id')->unique()->all())->pluck('name', 'id');
            $modalities ??= Modality::withTrashed()->whereIn('id', $tuples->pluck('modality_id')->unique()->all())->pluck('name', 'id');

            return [$group->id => self::reason($status, $departments[$group->department_id] ?? null, $modalities[$group->modality_id] ?? null, $what)];
        })->all();
    }

    /**
     * Corre `$work` y, si la base detecta un deadlock, lo traduce a un error de validación en vez de un 500 (con
     * `$message`, si se da). Va por fuera de la transacción: dentro de ella, PostgreSQL ya la abortó y no se puede seguir.
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     *
     * @throws ValidationException
     */
    public function guard(Closure $work, string $key = 'session', ?string $message = null): mixed
    {
        try {
            return $work();
        } catch (QueryException|DeadlockException $exception) {
            if ($this->isDeadlock($exception)) {
                throw ValidationException::withMessages([$key => $message ?? 'Otra persona está cambiando esta carga; intenta de nuevo.']);
            }

            throw $exception;
        }
    }

    /**
     * Si el error es un deadlock (40P01), aunque Laravel lo haya envuelto en un DeadlockException al salir de una
     * transacción anidada.
     */
    public function isDeadlock(PDOException $exception): bool
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if ((string) $current->getCode() === self::DEADLOCK || ($current instanceof PDOException && ($current->errorInfo[0] ?? null) === self::DEADLOCK)) {
                return true;
            }
        }

        return false;
    }

    private static function isEditable(?LoadStatus $status): bool
    {
        return $status !== null && in_array($status, self::EDITABLE, true);
    }

    private static function reason(?LoadStatus $status, ?string $department, ?string $modality, string $what): string
    {
        return $status === null
            ? "{$department} no tiene carga en {$modality} en este periodo: no se editan {$what}."
            : "La carga de {$department} en {$modality} está en ".mb_strtolower($status->label()).": no se editan {$what}.";
    }
}
