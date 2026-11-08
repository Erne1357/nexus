<?php

namespace App\Modules\Scheduling\Rules;

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Models\Campus;
use App\Modules\Scheduling\Enums\GroupStatus;
use App\Modules\Scheduling\Models\Group;
use Closure;
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

        $reason = $this->reason($group, $this->lockLoad($group)?->status, $what);

        if ($reason !== null) {
            throw ValidationException::withMessages([$key => $reason]);
        }
    }

    /**
     * El motivo por el que cada grupo no se edita, o null si se edita; una sola consulta de cargas.
     *
     * @param  iterable<Group>  $groups
     * @return array<string, ?string>
     */
    public function blockedReasons(iterable $groups, string $what = 'horarios'): array
    {
        $groups = collect($groups)->values();

        if ($groups->isEmpty()) {
            return [];
        }

        $statuses = AcademicLoad::query()
            ->whereIn('term_id', $groups->pluck('term_id')->unique())
            ->whereIn('campus_id', $groups->pluck('campus_id')->unique())
            ->whereIn('department_id', $groups->pluck('department_id')->unique())
            ->get(['term_id', 'campus_id', 'department_id', 'status'])
            ->mapWithKeys(fn (AcademicLoad $load): array => [$load->term_id.'|'.$load->campus_id.'|'.$load->department_id => $load->status]);

        return $groups->mapWithKeys(fn (Group $group): array => [
            $group->id => $group->status === GroupStatus::Cancelled
                ? "El grupo {$group->code} está cancelado: no se editan {$what}."
                : $this->reason($group, $statuses[$group->term_id.'|'.$group->campus_id.'|'.$group->department_id] ?? null, $what),
        ])->all();
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

    private function reason(Group $group, ?LoadStatus $status, string $what): ?string
    {
        if ($status !== null && in_array($status, self::EDITABLE, true)) {
            return null;
        }

        $department = Department::withTrashed()->whereKey($group->department_id)->value('name');
        $campus = Campus::withTrashed()->whereKey($group->campus_id)->value('name');

        return $status === null
            ? "{$department} no tiene carga en {$campus} en este periodo: no se editan {$what}."
            : "La carga de {$department} en {$campus} está en ".mb_strtolower($status->label()).": no se editan {$what}.";
    }
}
