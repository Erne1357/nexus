<?php

namespace App\Modules\Faculty\Database\Seeders;

use App\Modules\Core\Database\Seeders\Concerns\RefusesProduction;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Variedad en la captura de los docentes activos del último periodo abierto, para la pantalla de validación: de los
 * que FacultyDemoSeeder sembró, unos quedan sin disponibilidad, unos sin perfil (sin materias calificadas) y unos con
 * horas fuera del ITCJ (el resto, sin ellas). Quién cae en cada grupo y cuántas horas externas lleva salen de un hash
 * del RFC, no del UUID (que trae bits al azar), así que es lo mismo en cada corrida y no cambia al sembrar más docentes.
 * Lo que quita lo borra de forma lógica (`deleted_at`). Idempotente. Sin periodos abiertos, no hace nada. No corre
 * en producción.
 *
 * Porcentajes: `demo` 20 % sin disponibilidad, 10 % sin perfil y 25 % con horas externas; `massive` 25, 15 y 30.
 * A quien debe tener disponibilidad y no la tiene se le pone un bloque preferente, para que el porcentaje sea el pedido.
 *
 * Con `$complete` (demo por puntos, lleva al punto 3, docs/arquitectura/testing.md) hace lo contrario, como el jefe que
 * termina la captura antes de validar: a cada docente activo sin disponibilidad en el periodo o sin perfil le devuelve
 * lo último que se le quitó, y a quien aun así queda sin disponibilidad le pone un bloque preferente. Quien nunca tuvo
 * perfil (un alta sin materias calificadas) se queda sin él: no hace falta para ser candidato, solo suma al ranking. Las
 * horas externas no se tocan: no tenerlas también es una captura completa.
 */
class TeacherCaptureDemoSeeder extends Seeder
{
    use RefusesProduction;

    /** @var array<string, array{availability: int, profile: int, external: int}> */
    private const array PROFILES = [
        'demo' => ['availability' => 20, 'profile' => 10, 'external' => 25],
        'massive' => ['availability' => 25, 'profile' => 15, 'external' => 30],
    ];

    /**
     * @param  bool  $complete  completa la captura en vez de dejarla con variedad (ver la docblock de la clase)
     *
     * @throws RuntimeException en producción o con un perfil que no existe
     */
    public function run(string $profile = 'demo', bool $complete = false): void
    {
        $this->refuseProduction();

        $percent = self::PROFILES[$profile] ?? throw new RuntimeException("Perfil desconocido: {$profile}.");
        $term = DB::table('terms')->whereIn('id', DB::table('term_openings')->select('term_id'))->orderByDesc('starts_on')->orderByDesc('code')->first(['id']);

        if ($term === null) {
            return;
        }

        if ($complete) {
            $this->complete($term->id);

            return;
        }

        $started = hrtime(true);
        $now = now();
        $stamps = ['created_at' => $now, 'updated_at' => $now, 'deleted_at' => null];
        $withoutAvailability = [];
        $withAvailability = [];
        $withoutProfile = [];
        $withExternal = [];
        $withoutExternal = [];

        $teachers = DB::table('teachers')->where('is_active', true)->whereNull('deleted_at')->orderBy('rfc')->pluck('id', 'rfc');

        foreach ($teachers as $number => $id) {
            $this->in($number, 'availability', $percent['availability']) ? $withoutAvailability[] = $id : $withAvailability[] = $id;

            if ($this->in($number, 'profile', $percent['profile'])) {
                $withoutProfile[] = $id;
            }

            $this->in($number, 'external', $percent['external']) ? $withExternal[] = $id : $withoutExternal[] = $id;
        }

        $rfcs = $teachers->flip();

        DB::transaction(function () use ($term, $now, $stamps, $withoutAvailability, $withAvailability, $withoutProfile, $withExternal, $withoutExternal, $rfcs): void {
            foreach (array_chunk($withoutAvailability, 1000) as $chunk) {
                DB::table('teacher_availability')->where('term_id', $term->id)->whereNull('deleted_at')->whereIn('teacher_id', $chunk)->update(['deleted_at' => $now]);
            }

            foreach (array_chunk($withoutProfile, 1000) as $chunk) {
                DB::table('teacher_subject_qualifications')->whereNull('deleted_at')->whereIn('teacher_id', $chunk)->update(['deleted_at' => $now]);
            }

            foreach (array_chunk($withoutExternal, 1000) as $chunk) {
                DB::table('teacher_external_loads')->where('term_id', $term->id)->whereNull('deleted_at')->whereIn('teacher_id', $chunk)->update(['deleted_at' => $now]);
            }

            $this->insertMissing('teacher_availability', $term->id, $withAvailability, fn (string $id): array => [
                'teacher_id' => $id, 'term_id' => $term->id, 'day' => 1, 'starts_at' => '07:00', 'ends_at' => '09:00', 'kind' => 'preferred', ...$stamps,
            ]);

            $this->insertMissing('teacher_external_loads', $term->id, $withExternal, fn (string $id): array => [
                'teacher_id' => $id, 'term_id' => $term->id, 'source' => 'Universidad Autónoma de Ciudad Juárez', 'hours' => 4 + crc32("hours/{$rfcs[$id]}") % 9, 'notes' => null, ...$stamps,
            ]);
        });

        $this->command?->info(sprintf('Captura docente (%s): %d sin disponibilidad, %d sin perfil, %d con horas externas, %.1f s.', $profile, count($withoutAvailability), count($withoutProfile), count($withExternal), (hrtime(true) - $started) / 1e9));
    }

    /**
     * Devuelve a cada docente activo sin disponibilidad en el periodo o sin perfil las filas que se le borraron al último
     * (las de su `deleted_at` más reciente), y a quien sigue sin disponibilidad le pone un bloque preferente.
     */
    private function complete(string $termId): void
    {
        $started = hrtime(true);
        $now = now();
        $teachers = DB::table('teachers')->where('is_active', true)->whereNull('deleted_at')->orderBy('rfc')->pluck('id')->all();
        $restored = ['teacher_availability' => 0, 'teacher_subject_qualifications' => 0];

        DB::transaction(function () use ($termId, $now, $teachers, &$restored): void {
            foreach (array_chunk($teachers, 1000) as $chunk) {
                $restored['teacher_availability'] += $this->restoreLast('teacher_availability', $chunk, $now, $termId);
                $restored['teacher_subject_qualifications'] += $this->restoreLast('teacher_subject_qualifications', $chunk, $now);
            }

            $this->insertMissing('teacher_availability', $termId, $teachers, fn (string $id): array => [
                'teacher_id' => $id, 'term_id' => $termId, 'day' => 1, 'starts_at' => '07:00', 'ends_at' => '09:00', 'kind' => 'preferred',
                'created_at' => $now, 'updated_at' => $now, 'deleted_at' => null,
            ]);
        });

        $this->command?->info(sprintf('Captura docente completa: %d bloques de disponibilidad y %d materias del perfil devueltos, %.1f s.',
            $restored['teacher_availability'], $restored['teacher_subject_qualifications'], (hrtime(true) - $started) / 1e9));
    }

    /**
     * Quita el borrado lógico de las filas de `$table` borradas al último de los docentes de `$teacherIds` que no tienen
     * ninguna viva (en el periodo, si la tabla es por periodo). Da cuántas filas devolvió.
     *
     * @param  list<string>  $teacherIds
     */
    private function restoreLast(string $table, array $teacherIds, Carbon $now, ?string $termId = null): int
    {
        $scoped = fn ($query) => $termId === null ? $query : $query->where('term_id', $termId);
        $alive = $scoped(DB::table($table))->whereNull('deleted_at')->whereIn('teacher_id', $teacherIds)->distinct()->pluck('teacher_id')->all();
        $missing = array_values(array_diff($teacherIds, $alive));

        if ($missing === []) {
            return 0;
        }

        $last = $scoped(DB::table($table))->whereNotNull('deleted_at')->whereIn('teacher_id', $missing)
            ->groupBy('teacher_id')->selectRaw('teacher_id, max(deleted_at) as deleted_at')->pluck('deleted_at', 'teacher_id');
        $count = 0;

        foreach ($last as $teacherId => $deletedAt) {
            $count += $scoped(DB::table($table))->where('teacher_id', $teacherId)->where('deleted_at', $deletedAt)->update(['deleted_at' => null, 'updated_at' => $now]);
        }

        return $count;
    }

    private function in(string $number, string $group, int $percent): bool
    {
        return crc32("{$group}/{$number}") % 100 < $percent;
    }

    /**
     * Inserta una fila a los docentes que no tienen ninguna de la tabla en el periodo.
     *
     * @param  list<string>  $ids
     * @param  callable(string): array<string, mixed>  $row
     */
    private function insertMissing(string $table, string $termId, array $ids, callable $row): void
    {
        foreach (array_chunk($ids, 1000) as $chunk) {
            $has = DB::table($table)->where('term_id', $termId)->whereNull('deleted_at')->whereIn('teacher_id', $chunk)->distinct()->pluck('teacher_id')->flip();
            $rows = [];

            foreach ($chunk as $id) {
                if (! isset($has[$id])) {
                    $rows[] = $row($id);
                }
            }

            if ($rows !== []) {
                DB::table($table)->insert($rows);
            }
        }
    }
}
