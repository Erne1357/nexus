<?php

namespace App\Modules\Facilities\Actions;

use App\Modules\Academic\Actions\UpdateDepartmentContact;
use App\Modules\Academic\Models\Department;
use App\Modules\Core\Models\Campus;
use App\Modules\Facilities\Enums\RoomType;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Normaliza edificios, salones y departamentos con el mapa del Campus I (tarea 1.24.2). Idempotente: corrida otra
 * vez no cambia nada. Todo lo escribe con las Actions de siempre (bitácora y eventos incluidos), en una transacción.
 *
 * Campus I (`C1`), con el dataset `Database/Seeders/data/campus-map-c1.php`:
 *  a. Cada edificio del mapa se busca por `map_key` y, si no, por clave; se crea o se renombra (clave y nombre del
 *     mapa) y se reactiva.
 *  b. Cada salón del mapa se busca por `map_key`, luego por los alias del SII (clave del SII => `map_key`, solo con
 *     evidencia) y luego por la clave (los números como 105 son la clave del salón en el SII). Si existe se mueve al
 *     edificio del mapa y recibe `map_key` y nombre; se queda con su clave, tipo, capacidad y dueño.
 *  c. Los espacios del mapa sin salón se crean: aula 30 lugares, laboratorio 20, sin dueño (supuesto por confirmar).
 *  d. Un salón del SII sin lugar en el mapa se deja como está, en su edificio y con su dueño (decisión del usuario,
 *     20 de noviembre de 2026).
 *  e. Un edificio de C1 que no es del mapa y se quedó sin salones activos se desactiva (nunca se borra).
 *
 * Campus II (`C2`): los salones del edificio CAMPUSII cuya clave es una letra y números (A2, B9, D3) pasan al edificio
 * de esa letra («Edificio A»), que se crea si falta; los demás se quedan (supuesto por confirmar, decisión D1).
 *
 * Departamentos: la ubicación y el contacto del mapa, con UpdateDepartmentContact; la clave y el nombre del SII no
 * cambian. Los dueños de los salones no se tocan.
 *
 * Con `$dryRun` hace todo dentro de una transacción que revierte y devuelve el mismo reporte.
 */
final class SyncCampusMap
{
    public const string MAP_PATH = __DIR__.'/../Database/Seeders/data/campus-map-c1.php';

    private const array ZERO = [
        'buildings_created' => 0, 'buildings_updated' => 0, 'buildings_deactivated' => 0,
        'rooms_moved' => 0, 'rooms_linked' => 0, 'rooms_created' => 0,
        'c2_buildings_created' => 0, 'c2_rooms_moved' => 0, 'departments_updated' => 0,
    ];

    public function __construct(
        private readonly CreateBuilding $createBuilding,
        private readonly UpdateBuilding $updateBuilding,
        private readonly CreateRoom $createRoom,
        private readonly UpdateRoom $updateRoom,
        private readonly UpdateDepartmentContact $updateContact,
    ) {}

    /**
     * @param  array<string, mixed>|null  $map  el dataset; por omisión el versionado
     * @return array{buildings_created: int, buildings_updated: int, buildings_deactivated: int, rooms_moved: int, rooms_linked: int, rooms_created: int, rooms_unmatched: list<string>, c2_buildings_created: int, c2_rooms_moved: int, departments_updated: int}
     */
    public function handle(bool $dryRun = false, ?array $map = null): array
    {
        $map ??= require self::MAP_PATH;
        $report = ['rooms_unmatched' => [], ...self::ZERO];

        try {
            DB::transaction(function () use ($map, &$report, $dryRun): void {
                $this->syncC1($map, $report);
                $this->syncC2($report);
                $this->syncDepartments($map, $report);

                if ($dryRun) {
                    throw new RuntimeException('dry-run');
                }
            });
        } catch (RuntimeException $exception) {
            if (! $dryRun || $exception->getMessage() !== 'dry-run') {
                throw $exception;
            }
        }

        return $report;
    }

    /**
     * @param  array<string, mixed>  $map
     * @param  array<string, mixed>  $report
     */
    private function syncC1(array $map, array &$report): void
    {
        $campus = Campus::query()->where('code', 'C1')->first();

        if ($campus === null) {
            return;
        }

        $buildings = [];

        foreach ($map['buildings'] as $item) {
            $buildings[$item['code']] = $this->upsertBuilding($campus, $item, $report);
        }

        $aliases = [];
        foreach ($map['aliases'] as $siiCode => $key) {
            $aliases[$key][] = $siiCode;
        }

        $byCode = Room::query()->where('campus_id', $campus->getKey())->get()->keyBy('code');
        $byKey = $byCode->whereNotNull('map_key')->keyBy('map_key');

        foreach ($map['rooms'] as $item) {
            $building = $buildings[$item['building']];
            $room = $byKey->get($item['map_key']);

            foreach ($aliases[$item['map_key']] ?? [] as $siiCode) {
                $room ??= $this->free($byCode->get($siiCode));
            }

            $room ??= $this->free($byCode->get($item['code']));

            if ($room === null) {
                $this->createRoom->handle($campus, [
                    'building_id' => $building->getKey(), 'code' => $item['code'], 'name' => $item['name'], 'map_key' => $item['map_key'],
                    'type' => $item['type'], 'capacity' => RoomType::from($item['type']) === RoomType::Laboratorio ? 20 : 30,
                ]);
                $report['rooms_created']++;

                continue;
            }

            $data = ['building_id' => $building->getKey(), 'map_key' => $item['map_key']];

            if ($item['name'] !== null) {
                $data['name'] = $item['name'];
            }

            $moved = $room->building_id !== $building->getKey();
            $this->updateRoom->handle($room, $data);

            if ($room->wasChanged()) {
                $report[$moved ? 'rooms_moved' : 'rooms_linked']++;
            }
        }

        $mapCodes = array_keys($buildings);

        Building::query()->where('campus_id', $campus->getKey())->where('is_active', true)
            ->whereNotIn('code', $mapCodes)
            ->whereDoesntHave('rooms', fn ($rooms) => $rooms->where('is_active', true))
            ->get()
            ->each(function (Building $building) use (&$report): void {
                $this->updateBuilding->handle($building, ['is_active' => false]);
                $report['buildings_deactivated']++;
            });

        $report['rooms_unmatched'] = Room::query()->where('campus_id', $campus->getKey())->where('is_active', true)
            ->whereNull('map_key')->orderBy('code')->pluck('code')->all();
    }

    /**
     * Un salón del SII solo cruza con un espacio si aún no está ligado a otro.
     */
    private function free(?Room $room): ?Room
    {
        return $room !== null && $room->map_key === null ? $room : null;
    }

    /**
     * @param  array{map_key: string, code: string, name: string}  $item
     * @param  array<string, mixed>  $report
     */
    private function upsertBuilding(Campus $campus, array $item, array &$report): Building
    {
        $scope = fn () => Building::withTrashed()->where('campus_id', $campus->getKey());
        $building = $scope()->where('map_key', $item['map_key'])->first() ?? $scope()->where('code', $item['code'])->first();

        if ($building === null) {
            $report['buildings_created']++;

            return $this->createBuilding->handle($campus, ['code' => $item['code'], 'name' => $item['name'], 'map_key' => $item['map_key']]);
        }

        $restored = $building->trashed();

        if ($restored) {
            $building->restore();
        }

        $this->updateBuilding->handle($building, ['code' => $item['code'], 'name' => $item['name'], 'map_key' => $item['map_key'], 'is_active' => true]);

        if ($restored || $building->wasChanged()) {
            $report['buildings_updated']++;
        }

        return $building;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function syncC2(array &$report): void
    {
        $campus = Campus::query()->where('code', 'C2')->first();
        $source = $campus === null ? null : Building::query()->where('campus_id', $campus->getKey())->where('code', 'CAMPUSII')->first();

        if ($source === null) {
            return;
        }

        $rooms = Room::query()->where('building_id', $source->getKey())->orderBy('code')->get()
            ->filter(fn (Room $room): bool => preg_match('/^[A-Z]\d+$/', $room->code) === 1);

        foreach ($rooms as $room) {
            $letter = $room->code[0];
            $target = Building::withTrashed()->where('campus_id', $campus->getKey())->where('code', $letter)->first();

            if ($target === null) {
                $target = $this->createBuilding->handle($campus, ['code' => $letter, 'name' => "Edificio {$letter}"]);
                $report['c2_buildings_created']++;
            }

            $this->updateRoom->handle($room, ['building_id' => $target->getKey()]);
            $report['c2_rooms_moved']++;
        }

        if ($source->is_active && ! $source->rooms()->where('is_active', true)->exists()) {
            $this->updateBuilding->handle($source, ['is_active' => false]);
            $report['buildings_deactivated']++;
        }
    }

    /**
     * @param  array<string, mixed>  $map
     * @param  array<string, mixed>  $report
     */
    private function syncDepartments(array $map, array &$report): void
    {
        foreach ($map['departments'] as $item) {
            $department = Department::query()->where('code', $item['code'])->first();

            if ($department === null) {
                continue;
            }

            $this->updateContact->handle($department, array_diff_key($item, ['code' => 1]));

            if ($department->wasChanged()) {
                $report['departments_updated']++;
            }
        }
    }
}
