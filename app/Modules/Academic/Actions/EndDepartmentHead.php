<?php

namespace App\Modules\Academic\Actions;

use App\Modules\Academic\Events\DepartmentHeadEnded;
use App\Modules\Academic\Models\DepartmentHead;
use App\Modules\Core\Models\RoleScope;
use App\Modules\Core\Queries\ScopedContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Termina una jefatura y su alcance con `$lastDay` como último día, con la regla de CloseRoleScopes (confirmado el
 * 29 de octubre de 2026): si ya empezó, la jefatura y su alcance terminan ese día (`valid_to`); si empieza
 * después, las dos se borran, porque `valid_to` no puede ser anterior a `valid_from` (la jefatura de forma
 * lógica; el alcance, que no tiene borrado lógico, de verdad). Su alcance es el del mismo usuario, rol, campus y
 * departamento cuyas fechas se traslapan con las de la jefatura, como lo escribe AppointDepartmentHead.
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
            $what = "la jefatura de {$locked->user->name} como ".Str::lcfirst($locked->kind->label())
                ." de {$locked->department->name} en {$locked->campus->name}";

            if ($old['valid_from'] > $last) {
                $locked->delete();

                activity()
                    ->performedOn($locked->user)
                    ->event(ActivityEvent::Deleted)
                    ->withChanges(['old' => $old])
                    ->log("Quitó {$what}, que todavía no empezaba");
            } else {
                $locked->forceFill(['valid_to' => $last])->save();

                activity()
                    ->performedOn($locked->user)
                    ->event(ActivityEvent::Updated)
                    ->withChanges(['attributes' => ['valid_to' => $last], 'old' => ['valid_to' => $old['valid_to']]])
                    ->withProperties(['department_head_id' => $locked->getKey()])
                    ->log("Terminó {$what}");
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
     * Los alcances de la jefatura: mismo usuario, rol, campus y departamento, con fechas que se traslapan.
     *
     * @return Collection<int, RoleScope>
     */
    private function scopesOf(DepartmentHead $head): Collection
    {
        return RoleScope::query()
            ->where('user_id', $head->user_id)
            ->where('role', $head->kind->role())
            ->where('campus_id', $head->campus_id)
            ->where('department_id', $head->department_id)
            ->whereNull('career_id')
            ->when($head->valid_to, fn (Builder $query, CarbonImmutable $to) => $query->where('valid_from', '<=', $to->toDateString()))
            ->where(fn (Builder $query) => $query->whereNull('valid_to')->orWhere('valid_to', '>=', $head->valid_from->toDateString()))
            ->lockForUpdate()
            ->get();
    }
}
