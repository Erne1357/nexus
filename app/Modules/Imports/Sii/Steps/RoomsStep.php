<?php

namespace App\Modules\Imports\Sii\Steps;

use App\Modules\Facilities\Enums\RoomType;
use App\Modules\Facilities\Rules\RoomRules;
use App\Modules\Imports\Sii\SiiBatch;
use App\Modules\Imports\Sii\SiiCuratedMismatch;
use App\Modules\Imports\Sii\SiiFile;
use App\Modules\Imports\Sii\SiiMigration;
use App\Modules\Imports\Sii\SiiStep;
use Illuminate\Validation\ValidationException;

/**
 * Aulas (reglas-de-negocio.md "Reglas de datos"). `Curated/aulas.csv` lista solo las aulas que tuvieron sesiones
 * en horarios desde 20251 y decide cuáles se migran (`incluir`), a qué campus, edificio, tipo y capacidad. Las
 * ficticias, virtuales, duplicadas `EAD<aula>`, cubículos, residencias y servicio social van con `incluir=no`
 * (supuesto por confirmar). `sesiones_desde_20251` y `nota` son para la revisión humana y el código los ignora.
 *
 * Las claves del aula se comparan normalizadas como las guarda Nexus (RoomRules: sin espacios, mayúsculas); dos filas
 * curadas incluidas del mismo campus que chocan así son error: el curado debe dejar una en `incluir=no`. Contrato, distinto al de otros pasos: un aula
 * del SII que el curado no trae no es error (no tuvo uso): se omite con un solo aviso agregado y, si estaba activa
 * en el SII, con un aviso por aula. Una fila curada cuya aula no existe en el SII, o con valores inválidos
 * (`incluir`, `campus`, `tipo`, `capacidad`), detiene el paso antes de escribir (SiiCuratedMismatch).
 *
 * Entrega un lote `rooms` por campus (`aulas-C1.csv`, `aulas-C2.csv`). Avisos (supuestos por confirmar): el SII
 * permite cruces en un aula incluida (Nexus no), o la tiene inactiva (la plantilla no trae estado: queda activa).
 */
final class RoomsStep implements SiiStep
{
    private const array CAMPUSES = ['C1', 'C2'];

    private const int LIST_CAP = 20;

    public function name(): string
    {
        return 'aulas';
    }

    public function batches(SiiMigration $migration): iterable
    {
        $siiRooms = $this->siiRooms($migration);
        $curated = $this->curated($migration);
        $this->checkContract($siiRooms, $curated);

        $byCampus = array_fill_keys(self::CAMPUSES, ['rows' => [], 'excluded' => [], 'incidents' => []]);

        foreach ($curated as $row) {
            $campus = $row['campus'];
            $code = $row['code'];

            if ($row['incluir'] !== 'si') {
                $byCampus[$campus]['excluded'][] = $code;

                continue;
            }

            $byCampus[$campus]['rows'][] = [
                'building' => $row['edificio'],
                'code' => $code,
                'name' => $row['nombre'],
                'type' => $row['tipo'],
                'capacity' => $row['capacidad'],
                'department' => $row['departamento'],
            ];

            if ($siiRooms[$code]['crossing'] === 'S') {
                $byCampus[$campus]['incidents'][] = $this->warning($code, "El aula {$code}: el SII permite cruces en esta aula; Nexus no: revisar (supuesto por confirmar).");
            }

            if ($siiRooms[$code]['status'] === 'I') {
                $byCampus[$campus]['incidents'][] = $this->warning($code, "El aula {$code} está inactiva en el SII: se migra activa (la plantilla no trae estado; supuesto por confirmar).");
            }
        }

        $global = $this->uncuratedIncidents($siiRooms, array_column($curated, 'code'));
        $batches = [];

        foreach ($byCampus as $campus => $batch) {
            $incidents = $batch['incidents'];

            if ($batch['excluded'] !== []) {
                $incidents[] = $this->warning($campus, $this->excludedMessage($campus, $batch['excluded']));
            }

            if ($batch['rows'] !== []) {
                $batches[] = [$campus, $batch['rows'], $incidents];
            } elseif ($incidents !== []) {
                $global = [...$global, ...$incidents];
            }
        }

        if ($batches === []) {
            // Las incidencias solo viajan en los lotes: sin aulas incluidas, un lote vacío de C1 las lleva.
            $batches[] = [self::CAMPUSES[0], [], []];
        }

        $batches[0][2] = [...$global, ...$batches[0][2]];

        foreach ($batches as [$campus, $rows, $incidents]) {
            yield new SiiBatch('rooms', "aulas-{$campus}.csv", $rows, campus: $campus, incidents: $incidents);
        }
    }

    /**
     * La clave del aula como la guarda Nexus (RoomRules): sin espacios y en mayúsculas.
     */
    private function normalize(string $code): string
    {
        try {
            return app(RoomRules::class)->normalizeCode($code);
        } catch (ValidationException) {
            return trim($code);
        }
    }

    /**
     * Las aulas del SII por clave normalizada. Dos claves que chocan al normalizar (`' 910'` y `'910'`) son una sola.
     *
     * @return array<string, array{crossing: string, status: string, duplicates: int}>
     */
    private function siiRooms(SiiMigration $migration): array
    {
        $rooms = [];

        foreach ($migration->export()->reader(SiiFile::Aulas)->rows() as $row) {
            $key = $this->normalize($row['aula']);
            $rooms[$key] ??= [
                'crossing' => strtoupper(trim($row['permite_cruce'])),
                'status' => strtoupper(trim($row['estatus'])),
                'duplicates' => 0,
            ];
            $rooms[$key]['duplicates']++;
        }

        return $rooms;
    }

    /**
     * Las filas curadas, ya validadas y con su clave normalizada en `code`.
     *
     * @return list<array<string, string>>
     *
     * @throws SiiCuratedMismatch si alguna fila trae un valor inválido, o si dos incluidas del mismo campus chocan
     *                            al normalizar la clave (el curado debe decir cuál se queda)
     */
    private function curated(SiiMigration $migration): array
    {
        $curated = [];
        $invalid = [];
        $included = [];
        $collisions = [];
        $types = array_column(RoomType::cases(), 'value');

        foreach ($migration->curated('aulas') as $row) {
            $original = trim($row['aula']);
            $row = array_map(trim(...), $row);
            $row['code'] = $this->normalize($original);
            $row['incluir'] = strtolower($row['incluir']);
            $problems = [];

            if (! in_array($row['incluir'], ['si', 'no'], true)) {
                $problems[] = 'incluir';
            }

            if (! in_array($row['campus'], self::CAMPUSES, true)) {
                $problems[] = 'campus';
            }

            if ($row['incluir'] === 'si' && ! in_array($row['tipo'], $types, true)) {
                $problems[] = 'tipo';
            }

            if ($row['incluir'] === 'si' && ! (ctype_digit($row['capacidad']) && (int) $row['capacidad'] >= 1)) {
                $problems[] = 'capacidad';
            }

            if ($problems !== []) {
                $invalid[] = "{$original} (".implode(', ', $problems).' inválido)';

                continue;
            }

            if ($row['incluir'] === 'si') {
                $key = $row['campus'].'/'.$row['code'];

                if (isset($included[$key])) {
                    $collisions[] = "{$included[$key]} y {$original} (campus {$row['campus']}, ambas quedan como {$row['code']})";
                } else {
                    $included[$key] = $original;
                }
            }

            $curated[] = $row;
        }

        if ($invalid !== []) {
            throw new SiiCuratedMismatch('aulas', $this->capped($invalid), []);
        }

        if ($collisions !== []) {
            throw new SiiCuratedMismatch('aulas (claves que chocan al normalizar: deja una en incluir=no)', [], $this->capped($collisions));
        }

        return $curated;
    }

    /**
     * @param  array<string, array{crossing: string, status: string, duplicates: int}>  $siiRooms
     * @param  list<array<string, string>>  $curated
     *
     * @throws SiiCuratedMismatch
     */
    private function checkContract(array $siiRooms, array $curated): void
    {
        $extra = array_values(array_unique(array_diff(array_column($curated, 'code'), array_map('strval', array_keys($siiRooms)))));

        if ($extra !== []) {
            throw new SiiCuratedMismatch('aulas', [], $this->capped($extra));
        }
    }

    /**
     * El aviso agregado de las aulas del SII sin curado, uno por aula activa en el SII, y uno por clave del SII
     * repetida al recortar.
     *
     * @param  array<string, array{crossing: string, status: string, duplicates: int}>  $siiRooms
     * @param  list<string>  $curated  claves normalizadas del curado
     * @return list<array{level: 'error'|'warning', ref: string, message: string}>
     */
    private function uncuratedIncidents(array $siiRooms, array $curated): array
    {
        $curated = array_flip($curated);
        $incidents = [];
        $skipped = 0;

        foreach ($siiRooms as $code => $room) {
            $code = (string) $code;

            if ($room['duplicates'] > 1) {
                $incidents[] = $this->warning($code, "El SII trae el aula {$code} más de una vez al recortar espacios: se toma como una sola.");
            }

            if (isset($curated[$code])) {
                continue;
            }

            $skipped++;

            if ($room['status'] === 'A') {
                $incidents[] = $this->warning($code, "El aula {$code} está activa en el SII pero no está en el curado: no se migra.");
            }
        }

        if ($skipped > 0) {
            array_unshift($incidents, $this->warning('aulas', ($skipped === 1 ? '1 aula del SII no está' : "{$skipped} aulas del SII no están").' en el curado (sin uso desde 20251): no se migran.'));
        }

        return $incidents;
    }

    /**
     * @param  list<string>  $excluded
     */
    private function excludedMessage(string $campus, array $excluded): string
    {
        $count = count($excluded);
        $listed = implode(', ', array_slice($excluded, 0, self::LIST_CAP)).($count > self::LIST_CAP ? ', …' : '');

        return "{$campus}: ".($count === 1 ? '1 aula excluida' : "{$count} aulas excluidas")." del curado (incluir=no): no se migran ({$listed}).";
    }

    /**
     * @param  list<string>  $keys
     * @return list<string>
     */
    private function capped(array $keys): array
    {
        return count($keys) > self::LIST_CAP ? [...array_slice($keys, 0, self::LIST_CAP), '… ('.count($keys).' en total)'] : $keys;
    }

    /**
     * @return array{level: 'warning', ref: string, message: string}
     */
    private function warning(string $ref, string $message): array
    {
        return ['level' => 'warning', 'ref' => $ref, 'message' => $message];
    }
}
