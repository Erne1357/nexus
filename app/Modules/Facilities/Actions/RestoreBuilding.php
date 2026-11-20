<?php

namespace App\Modules\Facilities\Actions;

use App\Modules\Facilities\Events\BuildingUpdated;
use App\Modules\Facilities\Models\Building;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Restaura un edificio borrado (soft delete), con su entrada en la bitácora. No cambia su estado `is_active`: eso lo
 * hace UpdateBuilding. Si no estaba borrado, no hace nada. Después del commit avisa con BuildingUpdated (`deleted_at`).
 * Quién puede lo decide quien la llama (SyncCampusMap, 1.24.2).
 */
final class RestoreBuilding
{
    public function handle(Building $building): Building
    {
        return DB::transaction(function () use ($building): Building {
            $building = Building::withTrashed()->lockForUpdate()->findOrFail($building->getKey());

            if (! $building->trashed()) {
                return $building;
            }

            $building->restore();

            activity()
                ->performedOn($building)
                ->event(ActivityEvent::Restored)
                ->withChanges(['attributes' => ['deleted_at' => null]])
                ->log("Restauró el edificio {$building->code}");

            BuildingUpdated::dispatch($building, ['deleted_at']);

            return $building;
        });
    }
}
