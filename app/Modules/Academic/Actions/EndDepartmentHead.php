<?php

namespace App\Modules\Academic\Actions;

use App\Modules\Academic\Events\DepartmentHeadEnded;
use App\Modules\Academic\Models\DepartmentHead;
use App\Modules\Core\Models\RoleScope;
use App\Modules\Core\Queries\ScopedContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Termina una jefatura y su alcance con `$lastDay` como último día, con la regla de CloseRoleScopes (confirmado el
 * 29 de octubre de 2026): si ya empezó, la jefatura y su alcance terminan ese día (`valid_to`); si empieza
 * después, las dos se borran, porque `valid_to` no puede ser anterior a `valid_from` (la jefatura de forma
 * lógica; el alcance, que no tiene borrado lógico, de verdad). Su alcance es el que escribió AppointDepartmentHead:
 * mismo usuario, rol, campus (ninguno para el jefe, que es de todo el Tec) y departamento, y el mismo `valid_from`;
 * otro alcance del mismo cargo que empezó otro día o que tiene otro campus (uno capturado a mano, uno de antes de que
 * el jefe fuera de todo el Tec o el de otra jefatura) no se toca. Si no encuentra ninguno, termina solo la jefatura
 * y la bitácora lo dice.
 *
 * Nunca alarga: una jefatura borrada o que ya termina para `$lastDay` se queda como está, sin bitácora ni aviso.
 * El rol de spatie se queda, porque sin alcance vigente no cuenta en ScopedContext. Deja la bitácora sobre el
 * usuario, recarga ScopedContext y avisa con DepartmentHeadEnded después del commit.
 */
final class EndDepartmentHead
{
    public function handle(DepartmentHead $head, CarbonImmutable $lastDay): void
    {
        $last = $lastDay->toDateString();

        DB::transaction(function () use ($head, $last): void {
            $locked = DepartmentHead::query()->lockForUpdate()->find($head->getKey());

            if ($locked === null || ($locked->valid_to !== null && $locked->valid_to->toDateString() <= $last)) {
                return;
            }

            $locked->load([
                'user',
                'department' => fn ($query) => $query->withTrashed(),
                'campus' => fn ($query) => $query->withTrashed(),
            ]);

            $scopes = $this->scopesOf($locked);
            $old = [
                'department_head_id' => $locked->getKey(),
                'kind' => $locked->kind->value,
                'department_id' => $locked->department_id,
                'campus_id' => $locked->campus_id,
                'valid_from' => $locked->valid_from->toDateString(),
                'valid_to' => $locked->valid_to?->toDateString(),
            ];
            $what = "la jefatura de {$locked->user->name} como ".$locked->kind->position($locked->department->name, $locked->campus?->name);
            $missing = $scopes->isEmpty() ? '; no encontró su alcance' : '';
            $properties = ['department_head_id' => $locked->getKey(), 'role_scope_ids' => $scopes->modelKeys()];

            if ($old['valid_from'] > $last) {
                $locked->delete();

                activity()
                    ->performedOn($locked->user)
                    ->event(ActivityEvent::Deleted)
                    ->withChanges(['old' => $old])
                    ->withProperties($properties)
                    ->log("Quitó {$what}, que todavía no empezaba{$missing}");
            } else {
                $locked->forceFill(['valid_to' => $last])->save();

                activity()
                    ->performedOn($locked->user)
                    ->event(ActivityEvent::Updated)
                    ->withChanges(['attributes' => ['valid_to' => $last], 'old' => ['valid_to' => $old['valid_to']]])
                    ->withProperties($properties)
                    ->log("Terminó {$what}{$missing}");
            }

            foreach ($scopes as $scope) {
                if ($scope->valid_to !== null && $scope->valid_to->toDateString() <= $last) {
                    continue;
                }

                if ($scope->valid_from->toDateString() > $last) {
                    $scope->delete();
                } else {
                    $scope->forceFill(['valid_to' => $last])->save();
                }
            }

            $head->setRawAttributes($locked->getAttributes(), sync: true);

            app(ScopedContext::class)->refresh();

            DepartmentHeadEnded::dispatch($locked);
        });
    }

    /**
     * Los alcances de la jefatura: mismo usuario, rol, campus, departamento y `valid_from`, como los escribe
     * AppointDepartmentHead. El del jefe no tiene campus (`campus_id` nulo); uno con campus del mismo día no es suyo.
     *
     * @return Collection<int, RoleScope>
     */
    private function scopesOf(DepartmentHead $head): Collection
    {
        return RoleScope::query()
            ->where('user_id', $head->user_id)
            ->where('role', $head->kind->role())
            ->when(
                $head->campus_id === null,
                fn ($query) => $query->whereNull('campus_id'),
                fn ($query) => $query->where('campus_id', $head->campus_id),
            )
            ->where('department_id', $head->department_id)
            ->whereNull('career_id')
            ->where('valid_from', $head->valid_from->toDateString())
            ->lockForUpdate()
            ->get();
    }

}
