<?php

namespace App\Modules\AcademicLoad\Database\Seeders;

use App\Modules\AcademicLoad\Enums\HistoricalGroupSource;
use App\Modules\Core\Database\Seeders\Concerns\RefusesProduction;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;

/**
 * Historial de grupos demo (tarea 5.6), coherente con el kárdex: por cada periodo Ene-Jun o Ago-Dic que tiene kárdex, y
 * por materia y campus del alumno, cuenta los alumnos distintos con resultado aprobada, reprobada o cursando ese
 * periodo y arma `ceil(alumnos / tamaño)` grupos, con 35 de tamaño (25 si la materia lleva laboratorio). Reparte los
 * inscritos parejo entre los grupos, así que la suma de `enrolled` de una materia, periodo y campus es esa cuenta. La
 * capacidad es el tamaño más 0 o 5 lugares (la semilla decide), siempre al menos los inscritos del grupo. Fuente `sii`,
 * modalidad PRES (el kárdex demo es presencial). Los códigos de grupo siguen A, B, … a lo largo de los campus de la
 * misma materia y periodo, porque son únicos por periodo y materia. Con la misma semilla salen las mismas filas.
 *
 * Inserta en bloques con `DB::table()` e ignora las filas que ya existen (no duplica al volver a sembrar).
 */
final class HistoricalGroupsDemoSeeder extends Seeder
{
    use RefusesProduction;

    private const int SIZE = 35;

    private const int LAB_SIZE = 25;

    private const int CHUNK = 2000;

    public function run(int $seed = 2027): void
    {
        $this->refuseProduction();

        $started = hrtime(true);
        $modality = DB::table('modalities')->where('code', 'PRES')->value('id') ?? throw new RuntimeException('Falta la modalidad PRES: corre las migraciones.');
        $random = new Randomizer(new Mt19937($seed));
        $now = now();
        $rows = [];
        $total = 0;

        $counts = DB::table('kardex_records')
            ->join('students', 'students.id', '=', 'kardex_records.student_id')
            ->join('subjects', 'subjects.id', '=', 'kardex_records.subject_id')
            ->join('campuses', 'campuses.id', '=', 'students.campus_id')
            ->whereIn('kardex_records.result', ['aprobada', 'reprobada', 'cursando'])
            ->whereRaw("kardex_records.term_code ~ '^[0-9]{4}[13]$'")
            ->whereNull('kardex_records.deleted_at')
            ->groupBy('kardex_records.term_code', 'subjects.id', 'subjects.needs_lab', 'campuses.code', 'campuses.id')
            ->orderBy('kardex_records.term_code')->orderBy('subjects.id')->orderBy('campuses.code')
            ->selectRaw('kardex_records.term_code, subjects.id as subject_id, subjects.needs_lab, campuses.id as campus_id, count(distinct kardex_records.student_id) as students')
            ->cursor();

        $letters = 0;
        $previous = null;

        foreach ($counts as $entry) {
            $key = $entry->term_code.'|'.$entry->subject_id;
            $letters = $key === $previous ? $letters : 0;
            $previous = $key;

            $size = $entry->needs_lab ? self::LAB_SIZE : self::SIZE;
            $students = (int) $entry->students;
            $groups = intdiv($students + $size - 1, $size);

            for ($i = 0; $i < $groups; $i++) {
                $enrolled = intdiv($students, $groups) + ($i < $students % $groups ? 1 : 0);

                $rows[] = [
                    'id' => (string) Str::uuid7(),
                    'term_code' => $entry->term_code,
                    'modality_id' => $modality,
                    'campus_id' => $entry->campus_id,
                    'subject_id' => $entry->subject_id,
                    'group_code' => $this->code($letters++),
                    'capacity' => max($enrolled, $size + [0, 5][$random->getInt(0, 1)]),
                    'enrolled' => $enrolled,
                    'career_id' => null,
                    'parallel_of' => null,
                    'source' => HistoricalGroupSource::Sii->value,
                    'load_snapshot_id' => null,
                    'recorded_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if (count($rows) >= self::CHUNK) {
                $total += $this->flush($rows);
                $rows = [];
            }
        }

        $total += $this->flush($rows);

        $this->command?->info(sprintf('Historial de grupos del SII: %d grupos en %.1f s.', $total, (hrtime(true) - $started) / 1e9));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function flush(array $rows): int
    {
        return $rows === [] ? 0 : DB::table('historical_groups')->insertOrIgnore($rows);
    }

    /**
     * El código del grupo número `$index`: A a Z y luego A1 a Z1, A2…
     */
    private function code(int $index): string
    {
        $suffix = intdiv($index, 26);

        return chr(65 + $index % 26).($suffix === 0 ? '' : (string) $suffix);
    }
}
