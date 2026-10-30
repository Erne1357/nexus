<?php

namespace App\Modules\Facilities\Database\Seeders;

use App\Modules\Core\Database\Seeders\Concerns\RefusesProduction;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;

/**
 * Edificios, salones, bloqueos y salones por defecto demo para probar las pantallas con datos verosímiles. Los
 * edificios y los grupos de salones viven en `data/buildings.php` (nombres inventados al estilo del ITCJ); las
 * capacidades, las características, los bloqueos y los salones por defecto salen de una semilla, así que la misma
 * semilla da lo mismo. Cada campus tiene su propio generador (semilla + clave del campus): sembrar solo Campus II da
 * lo mismo que sembrarlo junto con Campus I.
 *
 * Campus II da unos 7 edificios y 90 salones; Campus I, unos 12 y 180. Hay los cinco tipos de salón, con y sin
 * departamento dueño (los departamentos del catálogo de AcademicDemoSeeder), inactivos, un edificio inactivo, un
 * salón borrado por campus, capacidades de 12 a 300 y nombres largos. Los bloqueos son de Ene-Jun 2027 o de todos
 * los periodos. Las materias con laboratorio de las carreras que se ofrecen en el campus reciben 1 o 2 salones por
 * defecto (prioridad 1 y 2, origen manual o historial), de preferencia del departamento de la materia.
 *
 * Escribe por clave natural con inserts en bloques (`DB::table()`: el campus va explícito y el id lo pone el default
 * `uuidv7()`): volver a sembrar deja edificios y salones como en los archivos, sin restaurar lo borrado, y no repite
 * bloqueos ni salones por defecto. Requiere los campus y el catálogo (AcademicDemoSeeder) y el periodo `20271`
 * (DemoTermSeeder). No corre en producción.
 */
class FacilitiesDemoSeeder extends Seeder
{
    use RefusesProduction;

    /** Periodo al que pertenecen los bloqueos que no son de todos. */
    public const string BLOCK_TERM = '20271';

    private const int CHUNK = 500;

    private const array BLOCK_REASONS = ['Mantenimiento', 'Evento institucional', 'Reservado por la Subdirección', 'Remodelación', 'Aplicación de examen'];

    private const array BLOCK_HOURS = [[7, 9], [9, 11], [11, 13], [13, 15], [15, 18], [17, 20]];

    /**
     * Los datos de `data/buildings.php`.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public static function data(): array
    {
        return require __DIR__.'/data/buildings.php';
    }

    /**
     * @param  list<string>  $campuses  claves de los campus cuyas instalaciones se siembran
     * @param  int  $seed  semilla: la misma da las mismas capacidades, bloqueos y salones por defecto
     *
     * @throws RuntimeException en producción o si falta algún campus, algún departamento dueño o el periodo
     * @throws InvalidArgumentException si un campus no tiene datos
     */
    public function run(array $campuses = ['C2'], int $seed = 2027): void
    {
        $this->refuseProduction();

        $campusIds = DB::table('campuses')->whereIn('code', $campuses)->pluck('id', 'code')->all();

        if (count($campusIds) !== count(array_unique($campuses))) {
            throw new RuntimeException('Faltan campus de '.implode(', ', $campuses).': corre antes CampusSeeder.');
        }

        $termId = DB::table('terms')->where('code', self::BLOCK_TERM)->value('id')
            ?? throw new RuntimeException('Falta el periodo '.self::BLOCK_TERM.': corre antes DemoTermSeeder.');
        $departmentIds = DB::table('departments')->pluck('id', 'code')->all();
        $data = self::data();
        $now = CarbonImmutable::now();

        DB::transaction(function () use ($campuses, $campusIds, $termId, $departmentIds, $data, $seed, $now): void {
            foreach ($campuses as $code) {
                $buildings = $data[$code] ?? throw new InvalidArgumentException("No hay edificios demo para el campus {$code}.");
                $campusId = $campusIds[$code];
                $random = new Randomizer(new Mt19937(($seed + crc32($code)) & 0x7FFFFFFF));

                $this->seedBuildings($campusId, $buildings, $now);
                $this->seedRooms($campusId, $buildings, $departmentIds, $random, $now);
                $this->seedBlocks($campusId, $termId, $random, $now);
                $this->seedDefaults($campusId, $random, $now);
            }
        });
    }

    /**
     * @param  list<array<string, mixed>>  $buildings
     */
    private function seedBuildings(string $campusId, array $buildings, CarbonImmutable $now): void
    {
        $rows = array_map(fn (array $building): array => [
            'campus_id' => $campusId,
            'code' => $building['code'],
            'name' => $building['name'],
            'is_active' => $building['active'] ?? true,
            'created_at' => $now,
            'updated_at' => $now,
            'deleted_at' => ($building['deleted'] ?? false) ? $now : null,
        ], $buildings);

        DB::table('buildings')->upsert($rows, ['campus_id', 'code'], ['name', 'is_active', 'updated_at']);
    }

    /**
     * @param  list<array<string, mixed>>  $buildings
     * @param  array<string, string>  $departmentIds
     */
    private function seedRooms(string $campusId, array $buildings, array $departmentIds, Randomizer $random, CarbonImmutable $now): void
    {
        $buildingIds = DB::table('buildings')->where('campus_id', $campusId)->pluck('id', 'code')->all();
        $rows = [];

        foreach ($buildings as $building) {
            foreach ($building['rooms'] as $group) {
                $owner = $group['owner'] ?? null;

                if ($owner !== null && ! isset($departmentIds[$owner])) {
                    throw new RuntimeException("Falta el departamento {$owner}: corre antes AcademicDemoSeeder.");
                }

                for ($i = 1; $i <= $group['count']; $i++) {
                    $rows[] = $this->roomRow($campusId, $buildingIds[$building['code']], $group, $i, $owner === null ? null : $departmentIds[$owner], $random, $now);
                }
            }
        }

        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            DB::table('rooms')->upsert($chunk, ['campus_id', 'code'], ['building_id', 'name', 'type', 'capacity', 'department_id', 'features', 'is_active', 'updated_at']);
        }
    }

    /**
     * @param  array<string, mixed>  $group
     * @return array<string, mixed>
     */
    private function roomRow(string $campusId, string $buildingId, array $group, int $index, ?string $departmentId, Randomizer $random, CarbonImmutable $now): array
    {
        $code = $group['prefix'].sprintf('%02d', $index);
        $fromEnd = $group['count'] - $index;
        $capacity = $group['pin'][$index - 1] ?? $random->getInt(...$group['capacity']);
        $features = $random->shuffleArray($group['features'] ?? []);
        $name = match (true) {
            $group['unnamed'] ?? false => null,
            ($group['long'] ?? false) && $index === 1 => $group['label'].$code.' de docencia, investigación aplicada y desarrollo de prototipos con equipo especializado',
            default => $group['label'].$code,
        };

        return [
            'campus_id' => $campusId,
            'building_id' => $buildingId,
            'code' => $code,
            'name' => $name,
            'type' => $group['type'],
            'capacity' => $capacity,
            'department_id' => $departmentId,
            'features' => json_encode(array_slice($features, 0, $random->getInt(0, count($features)))),
            'is_active' => $fromEnd >= ($group['inactive'] ?? 0),
            'created_at' => $now,
            'updated_at' => $now,
            'deleted_at' => $fromEnd < ($group['deleted'] ?? 0) ? $now : null,
        ];
    }

    /**
     * Uno o dos bloqueos para cada séptimo salón activo; no repite en los salones que ya tienen bloqueos.
     */
    private function seedBlocks(string $campusId, string $termId, Randomizer $random, CarbonImmutable $now): void
    {
        $rooms = DB::table('rooms')->where('campus_id', $campusId)->whereNull('deleted_at')->where('is_active', true)->orderBy('code')->pluck('id')->all();
        $blocked = DB::table('room_blocks')->whereIn('room_id', $rooms)->distinct()->pluck('room_id')->flip()->all();
        $rows = [];

        foreach ($rooms as $index => $roomId) {
            if ($index % 7 !== 3) {
                continue;
            }

            for ($n = $random->getInt(1, 2); $n > 0; $n--) {
                [$start, $end] = self::BLOCK_HOURS[$random->getInt(0, count(self::BLOCK_HOURS) - 1)];
                $row = [
                    'room_id' => $roomId,
                    'term_id' => $random->getInt(0, 2) === 0 ? null : $termId,
                    'day' => $random->getInt(1, 6),
                    'starts_at' => sprintf('%02d:00', $start),
                    'ends_at' => sprintf('%02d:00', $end),
                    'reason' => self::BLOCK_REASONS[$random->getInt(0, count(self::BLOCK_REASONS) - 1)],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                if (! isset($blocked[$roomId])) {
                    $rows[] = $row;
                }
            }
        }

        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            DB::table('room_blocks')->insert($chunk);
        }
    }

    /**
     * Salones por defecto de las materias con laboratorio de las carreras del campus: de uno a dos salones activos
     * de laboratorio, taller o sala de cómputo, de preferencia del departamento de la materia.
     */
    private function seedDefaults(string $campusId, Randomizer $random, CarbonImmutable $now): void
    {
        $labRooms = DB::table('rooms')->where('campus_id', $campusId)->whereNull('deleted_at')->where('is_active', true)
            ->whereIn('type', ['laboratorio', 'taller', 'sala_computo'])->orderBy('code')->get(['id', 'department_id']);

        $subjects = DB::table('subjects')->where('needs_lab', true)->whereNull('deleted_at')->where('is_active', true)
            ->whereIn('id', function ($query) use ($campusId): void {
                $query->select('study_plan_subjects.subject_id')->from('study_plan_subjects')
                    ->join('study_plans', 'study_plans.id', '=', 'study_plan_subjects.study_plan_id')
                    ->join('career_campus', 'career_campus.career_id', '=', 'study_plans.career_id')
                    ->where('career_campus.campus_id', $campusId);
            })->orderBy('code')->get(['id', 'department_id']);

        $rows = [];

        foreach ($subjects as $subject) {
            $own = $labRooms->where('department_id', $subject->department_id)->values();
            $pool = $own->isNotEmpty() ? $own : $labRooms;

            if ($pool->isEmpty()) {
                continue;
            }

            $picked = $random->pickArrayKeys($pool->all(), min($random->getInt(1, 2), $pool->count()));

            foreach ($picked as $position => $key) {
                $rows[] = [
                    'subject_id' => $subject->id,
                    'campus_id' => $campusId,
                    'room_id' => $pool[$key]->id,
                    'priority' => $position + 1,
                    'source' => $random->getInt(0, 1) === 0 ? 'manual' : 'historial',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            DB::table('subject_room_defaults')->insertOrIgnore($chunk);
        }
    }
}
