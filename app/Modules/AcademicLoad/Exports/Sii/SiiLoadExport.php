<?php

namespace App\Modules\AcademicLoad\Exports\Sii;

use App\Modules\Academic\Models\SubjectAlias;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Models\LoadSnapshot;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Imports\Sii\SiiFile;
use Illuminate\Database\Eloquent\Collection;

/**
 * Genera los dos archivos que el SII espera (`grupos.csv` y `horarios.csv`) con la forma exacta de sus tablas
 * (`SiiFile::Grupos` y `SiiFile::Horarios`), a partir de las fotos publicadas. Solo lee payloads y `subject_aliases`;
 * no escribe ni guarda estado.
 *
 * Formato (perfil de la exportación real): Latin-1 (Windows-1252), fin de línea LF, encabezado sin comillas, texto entre
 * comillas simples con relleno de espacios al ancho del CHAR (`'3KB       '`), números y fecha-hora sin comillas, nulo
 * como campo vacío.
 *
 * Qué se exporta: la última versión publicada de cada carga del periodo y el campus que tenga alguna, aunque después se
 * haya reabierto, igual que la consulta pública (supuesto por confirmar). Sin versiones publicadas salen los dos archivos
 * solo con el encabezado.
 *
 * Mapeo (cada elección propia es un supuesto por confirmar):
 * - Clave SII de la materia (`materia`, ancho 10) para (materia, plan): el alias con esa materia y ese plan; si no, el
 *   alias de esa materia sin plan; si no, el `code` de la materia en Nexus.
 * - `grupos`: una fila por grupo del payload, la del PADRE: materia con la clave del plan de la carrera dueña
 *   (`placements[0]`), `grupo` = código del grupo (ancho 3), `capacidad_grupo` = lugares de la dueña, o la capacidad
 *   del grupo si solo tiene la dueña; `rfc` = el del docente (ancho 13) o vacío; `alumnos_inscritos` = 0. Vacíos:
 *   `estatus_grupo`, `folio_acta`, `paralelo_de`, `seleccionado_en_bloque`, `tipo_personal`, `alumnos_preinscritos` y
 *   también `exclusivo_carrera`/`exclusivo_reticula` (supuesto: necesitan la clave de carrera del SII, que Nexus no guarda).
 * - Paralelos: por cada lugar no dueño con plan cuya clave SII sea distinta de la del padre y de las ya emitidas del
 *   grupo, otra fila con esa materia, el mismo grupo y docente, `capacidad_grupo` = sus lugares y `paralelo_de` =
 *   materia del padre sin relleno + 4 espacios + grupo del padre, a 17 caracteres. Un lugar sin plan no genera paralelo
 *   (supuesto).
 * - `horarios`: para el padre y cada paralelo, una fila por sesión del payload: periodo = código del periodo,
 *   `tipo_horario` = `D`, `dia_semana` = día + 1 (lunes 2 … sábado 7), horas como `1900-01-01 HH:MM:00.0`, `aula` =
 *   código del salón a 6 (o `EAD` si la sesión no tiene salón), y vacíos `actividad`, `consecutivo`, `vigencia_inicio`,
 *   `vigencia_fin` y `consecutivo_admvo`. Nunca se emiten los códigos de día 1 ni 8 a 14.
 * - Orden: clave del departamento, materia, grupo; cada paralelo justo después de su padre.
 */
final class SiiLoadExport
{
    private const string NO_ROOM = 'EAD';

    /**
     * @return array{'grupos.csv': string, 'horarios.csv': string} bytes en Latin-1
     */
    public function files(Term $term, Campus $campus): array
    {
        return $this->filesOf($term->code, $this->latestSnapshots($term, $campus));
    }

    /**
     * La última versión publicada de cada carga del periodo y el campus que tenga alguna.
     *
     * @return Collection<int, LoadSnapshot>
     */
    public function latestSnapshots(Term $term, Campus $campus): Collection
    {
        return LoadSnapshot::query()->latestVersion()
            ->whereIn('academic_load_id', AcademicLoad::query()
                ->where('term_id', $term->getKey())
                ->where('campus_id', $campus->getKey())
                ->where('current_version', '>', 0)
                ->select('id'))
            ->orderBy('id')
            ->get();
    }

    /**
     * Los dos archivos de estas versiones: las que leyó `SiiExport` al pedirse, para que el archivo diga exactamente
     * lo que nombra su llave aunque se publique otra versión mientras espera en la cola.
     *
     * @param  iterable<LoadSnapshot>  $snapshots
     * @return array{'grupos.csv': string, 'horarios.csv': string}
     */
    public function filesOf(string $termCode, iterable $snapshots): array
    {
        $aliases = $this->aliases($entries = $this->entries($snapshots));

        $blocks = [];
        foreach ($entries as $entry) {
            $rows = $this->rows($entry['group'], $termCode, $aliases);
            $blocks[] = ['sort' => [$entry['department'], trim($rows[0]['key']), (string) $entry['group']['code']], 'rows' => $rows];
        }
        usort($blocks, fn (array $a, array $b): int => $a['sort'] <=> $b['sort']);

        $groups = [];
        $schedules = [];
        foreach ($blocks as $block) {
            foreach ($block['rows'] as $row) {
                $groups[] = $row['group'];
                array_push($schedules, ...$row['schedules']);
            }
        }

        return [
            'grupos.csv' => $this->encode(SiiFile::Grupos->headers(), $groups),
            'horarios.csv' => $this->encode(SiiFile::Horarios->headers(), $schedules),
        ];
    }

    /**
     * Los grupos de esas versiones, con el departamento de su carga.
     *
     * @param  iterable<LoadSnapshot>  $snapshots
     * @return list<array{department: string, group: array<string, mixed>}>
     */
    private function entries(iterable $snapshots): array
    {
        $entries = [];
        foreach ($snapshots as $snapshot) {
            foreach ($snapshot->payload['groups'] as $group) {
                $entries[] = ['department' => (string) $snapshot->payload['department']['code'], 'group' => $group];
            }
        }

        return $entries;
    }

    /**
     * Alias de las materias que se exportan: materia → plan (o '' sin plan) → clave.
     *
     * @param  list<array{department: string, group: array<string, mixed>}>  $entries
     * @return array<string, array<string, string>>
     */
    private function aliases(array $entries): array
    {
        $subjectIds = array_values(array_unique(array_map(fn (array $entry): string => $entry['group']['subject']['id'], $entries)));
        $map = [];
        foreach (SubjectAlias::query()->whereIn('subject_id', $subjectIds)->orderBy('code')->get() as $alias) {
            $map[$alias->subject_id][$alias->study_plan_id ?? ''] ??= $alias->code;
        }

        return $map;
    }

    /**
     * El padre y sus paralelos, con sus horarios.
     *
     * @param  array<string, mixed>  $group
     * @param  array<string, array<string, string>>  $aliases
     * @return list<array{key: string, group: list<string|int|null>, schedules: list<list<string|int|null>>}>
     */
    private function rows(array $group, string $termCode, array $aliases): array
    {
        $placements = $group['placements'];
        $owner = $placements[array_key_first(array_filter($placements, fn (array $p): bool => $p['is_owner'])) ?? 0];
        $key = fn (array $placement): string => $this->subjectKey($group['subject'], $placement['study_plan']['id'] ?? null, $aliases);
        $rfc = $group['teacher']['rfc'] ?? null;
        $code = (string) $group['code'];

        $parentKey = $key($owner);
        $variants = [['key' => $parentKey, 'capacity' => count($placements) === 1 ? $group['capacity'] : $owner['seats'], 'parent' => null]];
        $emitted = [$parentKey];
        foreach ($placements as $placement) {
            if ($placement === $owner || $placement['study_plan'] === null) {
                continue;
            }
            $parallel = $key($placement);
            if (in_array($parallel, $emitted, true)) {
                continue;
            }
            $emitted[] = $parallel;
            $variants[] = ['key' => $parallel, 'capacity' => $placement['seats'], 'parent' => rtrim($parentKey).'    '.$code];
        }

        $sessions = $group['sessions'];
        usort($sessions, fn (array $a, array $b): int => [$a['day'], $a['starts_at']] <=> [$b['day'], $b['starts_at']]);

        return array_map(fn (array $variant): array => [
            'key' => $variant['key'],
            'group' => [
                $this->text($termCode, 5), $this->text($variant['key'], 10), $this->text($code, 3), null,
                $variant['capacity'], 0, null, $variant['parent'] === null ? null : $this->text($variant['parent'], 17),
                null, null, $rfc === null ? null : $this->text($rfc, 13), null, null, null,
            ],
            'schedules' => array_map(fn (array $session): array => [
                $this->text($termCode, 5), $rfc === null ? null : $this->text($rfc, 13), $this->text('D', 1),
                $session['day'] + 1, '1900-01-01 '.$session['starts_at'].':00.0', '1900-01-01 '.$session['ends_at'].':00.0',
                $this->text($variant['key'], 10), $this->text($code, 3),
                $this->text($session['room']['code'] ?? self::NO_ROOM, 6), null, null, null, null, null,
            ], $sessions),
        ], $variants);
    }

    /**
     * @param  array<string, mixed>  $subject
     * @param  array<string, array<string, string>>  $aliases
     */
    private function subjectKey(array $subject, ?string $planId, array $aliases): string
    {
        $byPlan = $aliases[$subject['id']] ?? [];

        return ($planId !== null ? $byPlan[$planId] ?? null : null) ?? $byPlan[''] ?? (string) $subject['code'];
    }

    /**
     * Texto entre comillas simples (con `''` para escapar), recortado y rellenado al ancho del CHAR.
     */
    private function text(string $value, int $width): string
    {
        $value = mb_str_pad(mb_substr($value, 0, $width), $width);

        return "'".str_replace("'", "''", $value)."'";
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<string|int|null>>  $rows
     */
    private function encode(array $headers, array $rows): string
    {
        $lines = [implode(',', $headers)];
        foreach ($rows as $row) {
            $lines[] = implode(',', array_map(fn (string|int|null $cell): string => (string) $cell, $row));
        }

        return mb_convert_encoding(implode("\n", $lines)."\n", 'Windows-1252', 'UTF-8');
    }
}
