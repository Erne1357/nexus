<?php

namespace App\Modules\Academic\Actions;

use App\Modules\Academic\Events\CareerOfferingChanged;
use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Core\Models\Campus;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use LogicException;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Ofrece una carrera en un campus o deja de ofrecerla (`career_campus`, una fila por carrera y
 * campus). Ofrecer crea la fila, o restaura la borrada, y la deja activa; dejar de ofrecer la
 * desactiva. La oferta de una carrera inactiva nace inactiva (la misma regla de la importación,
 * reglas-de-negocio.md, "Reglas de datos"): se registra, pero no cuenta hasta que la carrera se
 * active y se vuelva a ofrecer. Quién puede lo decide CareerPolicy antes de llamarla.
 *
 * En una transacción lee la fila con bloqueo, también la borrada. Si nada cambia, no escribe,
 * no registra ni avisa; si algo cambia, deja la bitácora sobre la oferta con el valor anterior y,
 * después del commit, avisa con CareerOfferingChanged. Dejar de ofrecer lo que nunca se ofreció
 * (sin fila, o con la única fila borrada) no hace nada y devuelve null. Ofrecer nunca desactiva: una oferta activa
 * de una carrera inactiva se queda activa. Si otra sesión crea la primera fila al mismo tiempo, la lee
 * y sigue con ella.
 */
final class SetCareerOffering
{
    public function handle(Career $career, Campus $campus, bool $offered): ?CareerCampus
    {
        return DB::transaction(function () use ($career, $campus, $offered): ?CareerCampus {
            $find = fn (): ?CareerCampus => CareerCampus::withTrashed()
                ->where('career_id', $career->id)
                ->where('campus_id', $campus->id)
                ->lockForUpdate()
                ->first();

            $offering = $find();

            if ($offering === null && ! $offered) {
                return null;
            }

            if ($offering === null) {
                $isActive = $offered && $career->is_active;

                try {
                    $offering = DB::transaction(fn (): CareerCampus => CareerCampus::create(['career_id' => $career->id, 'campus_id' => $campus->id, 'is_active' => $isActive]));
                } catch (UniqueConstraintViolationException) {
                    $offering = $find() ?? throw new LogicException('La oferta debería existir tras el choque de unicidad.');
                }

                if ($offering->wasRecentlyCreated) {
                    return $this->record($offering, ActivityEvent::Created, "Ofreció la carrera {$career->code} en {$campus->code}", ['attributes' => $offering->only(['is_active'])]);
                }
            }

            $wasTrashed = $offering->trashed();
            $wasActive = $offering->is_active;

            // Ofrecer nunca desactiva: una oferta activa de una carrera inactiva se queda como está.
            $isActive = $offered && ($career->is_active || ($wasActive && ! $wasTrashed));

            if (! $wasTrashed && $wasActive === $isActive) {
                return $offering;
            }

            if ($wasTrashed && ! $offered) {
                return null;
            }

            if ($wasTrashed) {
                $offering->restore();
            }

            $offering->is_active = $isActive;
            $offering->save();

            $description = $isActive ? "Ofreció la carrera {$career->code} en {$campus->code}" : "Dejó de ofrecer la carrera {$career->code} en {$campus->code}";

            return $this->record($offering, ActivityEvent::Updated, $description, [
                'attributes' => ['is_active' => $isActive],
                'old' => ['is_active' => $wasActive, 'deleted' => $wasTrashed],
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function record(CareerCampus $offering, ActivityEvent $event, string $description, array $changes): CareerCampus
    {
        activity()
            ->performedOn($offering)
            ->event($event)
            ->withChanges($changes)
            ->log($description);

        CareerOfferingChanged::dispatch($offering);

        return $offering;
    }
}
