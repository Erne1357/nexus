<?php

namespace App\Modules\AcademicLoad\Actions\History;

use App\Modules\AcademicLoad\Enums\HistoricalGroupSource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Lleva a `historical_groups` (`source = sii`) los grupos que el SII abrió antes de Nexus, leyendo el archivo ya
 * migrado (`sii_archivo.grupos` y `sii_archivo.horarios`, solo lectura; no toca el código de la migración 1.16). Solo
 * periodos Ene-Jun (código `AAAA1`) y Ago-Dic (`AAAA3`): el verano (`AAAA2`) no entra al historial de la propuesta.
 * Todas las columnas del SII son texto y se convierten aquí.
 *
 * Decisiones, todas supuestos por confirmar (el usuario solo decidió la fuente por periodo, 16 de noviembre de 2026):
 * - **Materia:** en Nexus `subjects.code` es la clave TecNM cuando el SII la trae, y cada retícula tiene su propia clave del SII
 *   para la misma materia (3FA y 2GA son ambas ACF-2301). Como la migración (SubjectsStep::tecnmKeys, solo leída): clave del SII →
 *   clave TecNM de `sii_archivo.materias_claves_tecnm`, sin contar la que trae su propia clave del SII como TecNM y, si hay
 *   varias, la menor; se busca `subjects.code` con esa clave y, si no hay, con la clave del SII. Una materia que Nexus no tiene se
 *   cuenta y se reporta; no se crea. `sii_subject_code` guarda la clave del SII, que distingue los grupos «A» de claves distintas.
 * - **Campus:** el de las aulas del horario del grupo (`tipo_horario = 'D'`, clases): el aula, sin espacios y en
 *   mayúsculas, es `rooms.code` (así no choca entre campus: RoomRules). Si el grupo usa aulas de varios campus gana el
 *   que más aulas tiene (empate: el de menor clave). Sin horario o con aulas que Nexus no migró (el curado de aulas solo
 *   trae las usadas desde 20251), el campus queda nulo.
 * - **Carrera y modalidad:** `exclusivo_carrera` y `exclusivo_reticula` son la (carrera, retícula) del SII; el catálogo
 *   curado `Imports/Sii/Curated/planes.csv` (solo lectura) las lleva a la clave de carrera de Nexus y a su modalidad
 *   del SII (`presencial`, `mixta`, `distancia`). `distancia` es EAD; todo lo demás, y todo grupo sin carrera
 *   exclusiva, es PRES (el SII no distingue la modalidad de un grupo abierto a todas las carreras).
 * - **Cancelados:** `estatus_grupo` viene vacío en todo el archivo; un valor distinto de vacío se toma como grupo no
 *   abierto: no entra y se cuenta. Un grupo abierto sin inscritos que dice el SII (vacío) queda con `enrolled` nulo.
 * - **Paralelo:** `paralelo_de` se guarda como lo trae el SII, con los espacios colapsados («3KB B»: materia y grupo).
 *
 * Idempotente: en una sola transacción reemplaza las filas `sii` de los periodos importados; con `$dryRun` calcula
 * el mismo reporte sin escribir. Deja una entrada en la bitácora con los conteos.
 */
final class ImportSiiGroupHistory
{
    /** El grupo que el SII tiene en una modalidad a distancia (`modalidad` de planes.csv) es de esta modalidad. */
    private const string DISTANCE_MODALITY = 'EAD';

    private const string DEFAULT_MODALITY = 'PRES';

    private const int CHUNK = 1000;

    public function __construct(private readonly ?string $plansFile = null) {}

    /**
     * @param  list<string>|null  $terms  los periodos a importar; todos los de tipo 1 y 3 del archivo si es nulo
     * @return array{terms: list<string>, ignored_terms: list<string>, groups: int, per_term: array<string, int>, unknown_subjects: array<string, int>, cancelled: int, without_campus: int, without_career: int, invalid_capacity: int, dry_run: bool}
     *
     * @throws RuntimeException si el archivo del SII no existe en esta base
     */
    public function handle(?array $terms = null, bool $dryRun = false): array
    {
        $this->assertArchive();

        $available = DB::table('sii_archivo.grupos')->distinct()->orderBy('periodo')->pluck('periodo')->map(fn ($term): string => trim((string) $term))->all();
        $wanted = $terms === null ? $available : array_map('trim', $terms);
        $importable = array_values(array_filter($wanted, fn (string $term): bool => in_array($term, $available, true) && preg_match('/^\d{4}[13]$/', $term) === 1));
        $ignored = $terms === null ? [] : array_values(array_diff($wanted, $importable));

        $subjects = DB::table('subjects')->pluck('id', 'code')->all();
        $tecnm = $this->tecnmKeys();
        $modalities = DB::table('modalities')->whereIn('code', [self::DEFAULT_MODALITY, self::DISTANCE_MODALITY])->pluck('id', 'code')->all();
        $default = $modalities[self::DEFAULT_MODALITY] ?? throw new RuntimeException('Falta la modalidad '.self::DEFAULT_MODALITY.': corre las migraciones.');
        $careers = DB::table('careers')->pluck('id', 'code')->all();
        $plans = $this->plans();
        $roomCampus = $this->roomCampuses();
        $now = now();

        $report = ['terms' => $importable, 'ignored_terms' => $ignored, 'groups' => 0, 'per_term' => [], 'unknown_subjects' => [], 'cancelled' => 0, 'without_campus' => 0, 'without_career' => 0, 'invalid_capacity' => 0, 'dry_run' => $dryRun];
        $rows = [];

        foreach ($importable as $term) {
            $aulas = $this->roomsByGroup($term);
            $report['per_term'][$term] = 0;

            foreach (DB::table('sii_archivo.grupos')->where('periodo', $term)->orderBy('_linea')->cursor() as $group) {
                if (trim((string) $group->estatus_grupo) !== '') {
                    $report['cancelled']++;

                    continue;
                }

                $code = trim((string) $group->materia);
                $subjectId = (isset($tecnm[$code]) ? ($subjects[$tecnm[$code]] ?? null) : null) ?? $subjects[$code] ?? null;

                if ($subjectId === null) {
                    $report['unknown_subjects'][$code] = ($report['unknown_subjects'][$code] ?? 0) + 1;

                    continue;
                }

                $plan = $plans[trim((string) $group->exclusivo_carrera).'/'.trim((string) $group->exclusivo_reticula)] ?? null;
                $campusId = $this->campusOf($aulas[$code."\0".trim((string) $group->grupo)] ?? [], $roomCampus);
                $careerId = $plan === null ? null : ($careers[$plan['career']] ?? null);
                $capacity = $this->integer($group->capacidad_grupo);

                $report['groups']++;
                $report['per_term'][$term]++;
                $report['without_campus'] += $campusId === null ? 1 : 0;
                $report['without_career'] += trim((string) $group->exclusivo_carrera) !== '' && $careerId === null ? 1 : 0;
                $report['invalid_capacity'] += $capacity === null ? 1 : 0;

                $rows[] = [
                    'id' => (string) Str::uuid7(),
                    'term_code' => $term,
                    'modality_id' => $plan !== null && $plan['modality'] === 'distancia' ? ($modalities[self::DISTANCE_MODALITY] ?? $default) : $default,
                    'campus_id' => $campusId,
                    'subject_id' => $subjectId,
                    'group_code' => trim((string) $group->grupo),
                    'sii_subject_code' => $code,
                    'capacity' => $capacity ?? 0,
                    'enrolled' => $this->integer($group->alumnos_inscritos),
                    'career_id' => $careerId,
                    'parallel_of' => ($parallel = trim(preg_replace('/\s+/', ' ', (string) $group->paralelo_de) ?? '')) === '' ? null : $parallel,
                    'source' => HistoricalGroupSource::Sii->value,
                    'load_snapshot_id' => null,
                    'recorded_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        arsort($report['unknown_subjects']);

        if ($dryRun) {
            return $report;
        }

        DB::transaction(function () use ($importable, $rows, $report): void {
            DB::table('historical_groups')->where('source', HistoricalGroupSource::Sii->value)->whereIn('term_code', $importable)->delete();

            foreach (array_chunk($rows, self::CHUNK) as $chunk) {
                DB::table('historical_groups')->insertOrIgnore($chunk);
            }

            activity()
                ->event('group_history_imported')
                ->withProperties(['terms' => count($importable), 'groups' => $report['groups'], 'unknown_subjects' => count($report['unknown_subjects']), 'cancelled' => $report['cancelled'], 'without_campus' => $report['without_campus']])
                ->log("Importó el historial de grupos del SII: {$report['groups']} grupos de ".count($importable).' periodos');
        });

        return $report;
    }

    /**
     * Clave del SII → clave TecNM, de cualquier retícula: la menor si hay varias; sin las que traen como TecNM su propia
     * clave del SII (como SubjectsStep::tecnmKeys).
     *
     * @return array<string, string>
     */
    private function tecnmKeys(): array
    {
        $keys = [];

        foreach (DB::table('sii_archivo.materias_claves_tecnm')->get(['materia', 'clave_tecnm']) as $row) {
            $materia = trim((string) $row->materia);
            $code = mb_strtoupper(trim((string) $row->clave_tecnm));

            if ($code === '' || $code === mb_strtoupper($materia)) {
                continue;
            }

            if (! isset($keys[$materia]) || strcmp($code, $keys[$materia]) < 0) {
                $keys[$materia] = $code;
            }
        }

        return $keys;
    }

    /**
     * @throws RuntimeException
     */
    private function assertArchive(): void
    {
        $tables = DB::table('information_schema.tables')->where('table_schema', 'sii_archivo')->whereIn('table_name', ['grupos', 'horarios', 'materias_claves_tecnm'])->count();

        if ($tables < 3) {
            throw new RuntimeException('No hay archivo del SII en esta base (sii_archivo.grupos, horarios y materias_claves_tecnm): corre antes imports:migrate-sii.');
        }
    }

    private function integer(mixed $value): ?int
    {
        $text = trim((string) $value);

        return preg_match('/^\d+$/', $text) === 1 ? (int) $text : null;
    }

    /**
     * El campus de cada aula de Nexus por su clave normalizada (sin espacios y en mayúsculas, como RoomRules).
     *
     * @return array<string, string>
     */
    private function roomCampuses(): array
    {
        $campuses = [];

        foreach (DB::table('rooms')->get(['code', 'campus_id']) as $room) {
            $campuses[mb_strtoupper((string) preg_replace('/\s+/', '', $room->code))] = $room->campus_id;
        }

        return $campuses;
    }

    /**
     * Las aulas de clase de cada grupo de un periodo, normalizadas, por «materia NUL grupo».
     *
     * @return array<string, list<string>>
     */
    private function roomsByGroup(string $term): array
    {
        $byGroup = [];

        $rows = DB::table('sii_archivo.horarios')
            ->where('periodo', $term)->where('tipo_horario', 'D')->where('aula', '<>', '')
            ->selectRaw("materia, grupo, upper(regexp_replace(aula, '\s', '', 'g')) as aula")
            ->distinct()
            ->get();

        foreach ($rows as $row) {
            $byGroup[trim($row->materia)."\0".trim($row->grupo)][] = $row->aula;
        }

        return $byGroup;
    }

    /**
     * @param  list<string>  $aulas
     * @param  array<string, string>  $roomCampus
     */
    private function campusOf(array $aulas, array $roomCampus): ?string
    {
        $count = [];

        foreach ($aulas as $aula) {
            if (isset($roomCampus[$aula])) {
                $count[$roomCampus[$aula]] = ($count[$roomCampus[$aula]] ?? 0) + 1;
            }
        }

        if ($count === []) {
            return null;
        }

        ksort($count);
        arsort($count);

        return (string) array_key_first($count);
    }

    /**
     * Del curado de planes: «carrera/retícula» del SII → clave de carrera de Nexus y modalidad del SII.
     *
     * @return array<string, array{career: string, modality: string}>
     */
    private function plans(): array
    {
        $path = $this->plansFile ?? app_path('Modules/Imports/Sii/Curated/planes.csv');
        $stream = is_file($path) ? fopen($path, 'r') : false;

        if ($stream === false) {
            return [];
        }

        $header = fgetcsv($stream, escape: '') ?: [];
        $plans = [];

        while (($values = fgetcsv($stream, escape: '')) !== false) {
            if ($values === [null] || count($values) < count($header)) {
                continue;
            }

            $row = array_combine($header, array_slice($values, 0, count($header)));
            $plans[trim($row['sii_carrera']).'/'.trim($row['sii_reticula'])] = ['career' => trim($row['carrera']), 'modality' => trim($row['modalidad'])];
        }

        fclose($stream);

        return $plans;
    }
}
