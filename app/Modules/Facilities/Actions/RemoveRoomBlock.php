<?php

namespace App\Modules\Facilities\Actions;

use App\Modules\Facilities\Events\RoomBlockRemoved;
use App\Modules\Facilities\Models\RoomBlock;
use App\Modules\Facilities\Rules\RoomRules;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Quita un bloqueo de un salón: borrado lógico, con bitácora y, después del commit, RoomBlockRemoved.
 * Volver a quitar uno ya quitado no hace nada. Quién puede lo decide RoomPolicy::update.
 */
final class RemoveRoomBlock
{
    public function handle(RoomBlock $block): void
    {
        DB::transaction(function () use ($block): void {
            $locked = RoomBlock::query()->lockForUpdate()->find($block->getKey());

            if ($locked === null) {
                return;
            }

            $locked->delete();

            $starts = substr($locked->starts_at, 0, 5);
            $ends = substr($locked->ends_at, 0, 5);

            activity()
                ->performedOn($locked)
                ->event(ActivityEvent::Deleted)
                ->withChanges(['old' => $locked->only(['room_id', 'term_id', 'day', 'starts_at', 'ends_at', 'reason'])])
                ->log("Quitó el bloqueo del salón {$locked->room->code} el ".RoomRules::DAYS[$locked->day]." de {$starts} a {$ends}");

            RoomBlockRemoved::dispatch($locked);
        });
    }
}
