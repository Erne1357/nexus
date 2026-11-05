<?php

namespace App\Modules\Faculty\Database\Seeders;

use App\Modules\Core\Database\Seeders\Concerns\RefusesProduction;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Variedad en la captura de los docentes activos del último periodo abierto, para la pantalla de validación: de los
 * que FacultyDemoSeeder sembró, unos quedan sin disponibilidad, unos sin perfil (sin materias calificadas) y unos con
 * horas fuera del ITCJ (el resto, sin ellas). Quién cae en cada grupo sale de un hash del RFC, así que
 * es el mismo siempre y no cambia al sembrar más docentes. Lo que quita lo borra de forma lógica (`deleted_at`). Idempotente. Sin periodos abiertos, no hace nada. No corre
 * en producción.
 *
 * Porcentajes: `demo` 20 % sin disponibilidad, 10 % sin perfil y 25 % con horas externas; `massive` 25, 15 y 30.
 * A quien debe tener disponibilidad y no la tiene se le pone un bloque preferente, para que el porcentaje sea el pedido.
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
     * @throws RuntimeException en producción o con un perfil que no existe
     */
    public function run(string $profile = 'demo'): void
    {
        $this->refuseProduction();

        $percent = self::PROFILES[$profile] ?? throw new RuntimeException("Perfil desconocido: {$profile}.");
        $term = DB::table('terms')->whereIn('id', DB::table('term_openings')->select('term_id'))->orderByDesc('starts_on')->orderByDesc('code')->first(['id']);

        if ($term === null) {
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

        DB::transaction(function () use ($term, $now, $stamps, $withoutAvailability, $withAvailability, $withoutProfile, $withExternal, $withoutExternal): void {
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
                'teacher_id' => $id, 'term_id' => $term->id, 'source' => 'Universidad Autónoma de Ciudad Juárez', 'hours' => 4 + crc32($id) % 9, 'notes' => null, ...$stamps,
            ]);
        });

        $this->command?->info(sprintf('Captura docente (%s): %d sin disponibilidad, %d sin perfil, %d con horas externas, %.1f s.', $profile, count($withoutAvailability), count($withoutProfile), count($withExternal), (hrtime(true) - $started) / 1e9));
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
