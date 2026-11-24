<?php

namespace App\Modules\Facilities\Console;

use App\Modules\Core\Models\Campus;
use App\Modules\Facilities\Actions\UpdateRoom;
use App\Modules\Facilities\Enums\RoomType;
use App\Modules\Facilities\Models\Room;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Cambia el tipo de varios salones de un campus (1.33). Todo o nada: valida cada código y cada tipo antes de escribir;
 * usa UpdateRoom, así que deja bitácora.
 *
 *     docker compose exec app php artisan facilities:set-room-types C1 701=sala_computo 702=laboratorio --no-interaction
 */
final class SetRoomTypesCommand extends Command
{
    protected $signature = 'facilities:set-room-types {campus : Código del campus} {assignments* : Pares codigo=tipo}';

    protected $description = 'Cambia el tipo de los salones indicados de un campus (todo o nada)';

    public function handle(UpdateRoom $update): int
    {
        $campus = Campus::query()->where('code', $this->argument('campus'))->first();

        if ($campus === null) {
            $this->error("No existe el campus {$this->argument('campus')}.");

            return self::FAILURE;
        }

        $plan = [];
        $errors = [];

        foreach ($this->argument('assignments') as $assignment) {
            [$code, $type] = array_pad(explode('=', (string) $assignment, 2), 2, '');
            $roomType = RoomType::tryFrom($type);

            if ($roomType === null) {
                $errors[] = "Tipo inválido en «{$assignment}».";

                continue;
            }

            $room = Room::query()->where('campus_id', $campus->id)->where('code', $code)->first();

            if ($room === null) {
                $errors[] = "No existe el salón {$code} en {$campus->code}.";

                continue;
            }

            $plan[] = [$room, $roomType];
        }

        if ($errors !== []) {
            foreach ($errors as $error) {
                $this->error($error);
            }

            $this->error('No se cambió ningún salón.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($plan, $update): void {
            foreach ($plan as [$room, $type]) {
                $update->handle($room, ['type' => $type->value]);
            }
        });

        $this->info(count($plan).' salones actualizados.');

        return self::SUCCESS;
    }
}
