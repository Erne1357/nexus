<?php

namespace App\Modules\Facilities\Actions;

use App\Modules\Facilities\Events\RoomBlockAdded;
use App\Modules\Facilities\Models\Room;
use App\Modules\Facilities\Models\RoomBlock;
use App\Modules\Facilities\Rules\RoomRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Bloquea un salón (mantenimiento, reservado) un día y un rango de horas, para un periodo o para todos.
 * Recibe datos ya validados en forma. RoomRules exige que el día sea uno de los del perfil del campus
 * del salón y que las horas caigan en su jornada, la de fin posterior a la de inicio. Deja bitácora y,
 * después del commit, avisa con RoomBlockAdded. Vuelve a leer el salón con bloqueo: uno borrado no se encuentra. Quién puede lo decide RoomPolicy::update.
 */
final class AddRoomBlock
{
    public function __construct(private readonly RoomRules $rules) {}

    /**
     * @param  array{term_id?: ?string, day: int, starts_at: string, ends_at: string, reason: string}  $data
     *
     * @throws ValidationException
     */
    public function handle(Room $room, array $data): RoomBlock
    {
        $startsAt = $this->rules->normalizeTime($data['starts_at'], 'starts_at', 'inicio');
        $endsAt = $this->rules->normalizeTime($data['ends_at'], 'ends_at', 'fin');
        $reason = $this->rules->normalizeReason($data['reason']);
        $termId = ($data['term_id'] ?? '') === '' ? null : $data['term_id'];

        return DB::transaction(function () use ($room, $data, $startsAt, $endsAt, $reason, $termId): RoomBlock {
            $room = Room::query()->lockForUpdate()->findOrFail($room->getKey());
            $this->rules->ensureBlockFitsCampus($room->campus->profile, $data['day'], $startsAt, $endsAt);
            $this->rules->ensureTermExists($termId);

            $block = RoomBlock::create([
                'room_id' => $room->getKey(),
                'term_id' => $termId,
                'day' => $data['day'],
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'reason' => $reason,
            ]);

            activity()
                ->performedOn($block)
                ->event(ActivityEvent::Created)
                ->withChanges(['attributes' => $block->only(['room_id', 'term_id', 'day', 'starts_at', 'ends_at', 'reason'])])
                ->log("Bloqueó el salón {$room->code} el ".RoomRules::DAYS[$block->day]." de {$startsAt} a {$endsAt}");

            RoomBlockAdded::dispatch($block);

            return $block;
        });
    }
}
