<?php

namespace App\Modules\Scheduling\Actions;

use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Scheduling\Enums\GroupStatus;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Rules\GroupCode;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Recalcula la clave de un grupo con la nomenclatura del SII (GroupCode; reglas-de-negocio.md, "Grupos y horarios"):
 * la letra de la hora de inicio más temprana de sus sesiones, con número si otro grupo no cancelado de la misma
 * materia y periodo ya la tiene, o una clave provisional si se quedó sin sesiones. La llama el listener de
 * GroupScheduleChanged cada vez que cambian sus sesiones.
 *
 * En una transacción relee el grupo con bloqueo. No toca un grupo cancelado ni el de un departamento cuya carga del
 * periodo y campus está publicada (supuesto por confirmar). Solo escribe si la clave cambia, y entonces deja la
 * bitácora con la anterior y la nueva. Si el índice único `groups_code_unique` rechaza la clave porque otra sesión se
 * adelantó, repite el cálculo una vez con lo que ya se guardó.
 */
final class AssignGroupCode
{
    public function handle(Group $group): Group
    {
        try {
            return $this->assign($group);
        } catch (UniqueConstraintViolationException) {
            return $this->assign($group);
        }
    }

    private function assign(Group $group): Group
    {
        return DB::transaction(function () use ($group): Group {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ["groups:{$group->term_id}:{$group->subject_id}"]);

            $group->setRawAttributes($group->newQuery()->lockForUpdate()->findOrFail($group->getKey())->getAttributes(), sync: true);

            if ($group->status === GroupStatus::Cancelled || $this->loadIsPublished($group)) {
                return $group;
            }

            $taken = Group::query()->active()
                ->where('term_id', $group->term_id)
                ->where('subject_id', $group->subject_id)
                ->whereKeyNot($group->getKey())
                ->pluck('code')->all();

            $code = GroupCode::forStart($this->earliestStartMinutes($group), $taken);

            if ($code === $group->code) {
                return $group;
            }

            $old = $group->code;
            $group->update(['code' => $code]);

            activity()
                ->performedOn($group)
                ->event(ActivityEvent::Updated)
                ->withChanges(['attributes' => ['code' => $code], 'old' => ['code' => $old]])
                ->log("Cambió la clave del grupo de {$old} a {$code}");

            return $group;
        });
    }

    private function loadIsPublished(Group $group): bool
    {
        return AcademicLoad::query()
            ->where('term_id', $group->term_id)
            ->where('campus_id', $group->campus_id)
            ->where('department_id', $group->department_id)
            ->where('status', LoadStatus::Published)
            ->exists();
    }

    private function earliestStartMinutes(Group $group): ?int
    {
        $start = $group->sessions()->min('starts_at');

        if ($start === null) {
            return null;
        }

        [$hours, $minutes] = array_map('intval', explode(':', (string) $start));

        return $hours * 60 + $minutes;
    }
}
