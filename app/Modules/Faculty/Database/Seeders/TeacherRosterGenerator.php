<?php

namespace App\Modules\Faculty\Database\Seeders;

use Random\Randomizer;

/**
 * Plantillas de docentes ficticios para los seeders demo y masivo (FacultyDemoSeeder). Clase pura, sin base: con
 * el mismo Randomizer y los mismos datos da los mismos docentes, así que el seeder los puede insertar por bloques.
 *
 * Cada plantilla trae nombre (de listas, nunca reales), número de empleado (`D` + dígito del campus + consecutivo),
 * adscripciones (`dept` es la posición del departamento en la lista del seeder, `campus` es `own` u `other`),
 * plazas, disponibilidad del periodo actual, horas fuera del ITCJ, perfil, historial y evaluaciones. Las materias
 * van por su posición en la lista de materias del departamento (`$subjectCounts`); un departamento sin materias no
 * da perfil ni historial.
 *
 * Los casos raros no dependen del azar sino del consecutivo, para que aparezcan aun con pocos docentes: cada 20
 * docentes se repiten los 20 escenarios de plazas (los cinco tipos, dos o tres plazas completas, vencidas, futuras
 * y sin plaza), y de los demás, los inactivos, borrados, sin correo, sin grado, de nombre muy largo, con una
 * segunda adscripción y con una en el otro campus.
 */
final class TeacherRosterGenerator
{
    /** Periodos del SII del historial: los 8 más recientes sin verano, hasta el que cursan los alumnos demo. */
    public const array HISTORY_TERMS = ['20231', '20233', '20241', '20243', '20251', '20253', '20261', '20263'];

    /** Periodo en curso de Nexus, para el que se siembra la disponibilidad. */
    public const string CURRENT_TERM = '20271';

    /**
     * Escenarios de plazas: tipo y vigencia (`current`, `expired` o `future`).
     *
     * @var list<list<array{0: string, 1: string}>>
     */
    private const array POSITION_SCENARIOS = [
        [['tiempo_completo', 'current']],
        [['asignatura', 'current']],
        [['medio_tiempo', 'current']],
        [['asignatura', 'current']],
        [['tiempo_completo', 'current'], ['tiempo_completo', 'current']],
        [['honorarios', 'current']],
        [['tres_cuartos', 'current']],
        [['asignatura', 'current']],
        [['tiempo_completo', 'expired'], ['tiempo_completo', 'current']],
        [],
        [['asignatura', 'current'], ['honorarios', 'current']],
        [['asignatura', 'current'], ['medio_tiempo', 'future']],
        [['tiempo_completo', 'current']],
        [['asignatura', 'current']],
        [['tiempo_completo', 'current'], ['tiempo_completo', 'current'], ['tiempo_completo', 'current']],
        [['tres_cuartos', 'current'], ['asignatura', 'current']],
        [['honorarios', 'current']],
        [['asignatura', 'current']],
        [['medio_tiempo', 'current']],
        [['asignatura', 'current']],
    ];

    private const array POSITION_HOURS = ['tiempo_completo' => 40, 'tres_cuartos' => 30, 'medio_tiempo' => 20, 'honorarios' => 0];

    private const array DEGREES = ['licenciatura', 'especialidad', 'maestria', 'maestria', 'doctorado'];

    private const array EXTERNAL_SOURCES = ['TecNM Virtual', 'Universidad Autónoma de Ciudad Juárez', 'Preparatoria particular', 'Capacitación industrial', 'Maquiladora (docencia interna)'];

    private const array MALE_NAMES = ['José', 'Luis', 'Juan', 'Carlos', 'Miguel', 'Jorge', 'Francisco', 'Alejandro', 'Daniel', 'Ricardo', 'Fernando', 'Eduardo', 'Roberto', 'Javier', 'Sergio', 'Héctor', 'Óscar', 'Iván', 'Raúl', 'Julio', 'Armando', 'Rafael', 'Gustavo', 'Arturo', 'Ramón', 'Enrique', 'Manuel', 'Pedro', 'Alfredo', 'Salvador'];

    private const array FEMALE_NAMES = ['María', 'Guadalupe', 'Ana', 'Fernanda', 'Daniela', 'Alejandra', 'Karla', 'Andrea', 'Mariana', 'Gabriela', 'Brenda', 'Rosa', 'Elena', 'Patricia', 'Claudia', 'Verónica', 'Dulce', 'Laura', 'Leticia', 'Silvia', 'Martha', 'Norma', 'Lucía', 'Teresa', 'Beatriz', 'Adriana', 'Rocío', 'Irma', 'Sandra', 'Liliana'];

    private const array SURNAMES = ['Hernández', 'García', 'Martínez', 'López', 'González', 'Pérez', 'Rodríguez', 'Sánchez', 'Ramírez', 'Cruz', 'Flores', 'Gómez', 'Morales', 'Vázquez', 'Jiménez', 'Reyes', 'Díaz', 'Torres', 'Gutiérrez', 'Ruiz', 'Mendoza', 'Aguilar', 'Ortiz', 'Moreno', 'Castillo', 'Romero', 'Álvarez', 'Chávez', 'Rivera', 'Juárez', 'Domínguez', 'Guerrero', 'Estrada', 'Ortega', 'Contreras', 'Soto', 'Delgado', 'Navarro', 'Rangel', 'Villa', 'Carrasco', 'Holguín', 'Terrazas', 'Márquez', 'Lozano', 'Salas', 'Meza', 'Barraza', 'Ponce', 'Sandoval'];

    public function __construct(private readonly Randomizer $random) {}

    /**
     * @param  string  $campusCode  clave del campus (da el prefijo del número de empleado)
     * @param  list<int>  $subjectCounts  materias de cada departamento, en el orden de la lista del seeder
     * @return list<array<string, mixed>>
     */
    public function generate(string $campusCode, int $count, array $subjectCounts): array
    {
        $teachers = [];

        for ($i = 0; $i < $count; $i++) {
            $teachers[] = $this->teacher($campusCode, $i, $subjectCounts);
        }

        return $teachers;
    }

    /**
     * @param  list<int>  $subjectCounts
     * @return array<string, mixed>
     */
    private function teacher(string $campusCode, int $i, array $subjectCounts): array
    {
        $employeeNumber = sprintf('D%s%04d', $this->campusDigit($campusCode), $i + 1);
        $isActive = $i % 17 !== 5;
        $deleted = $i % 23 === 7;
        $adscriptions = $this->adscriptions($i, $subjectCounts);
        $pool = $subjectCounts[$adscriptions[0]['dept']];
        $name = $this->name($i);
        $profile = $this->profile($pool);
        [$history, $evaluations] = $this->historyAndEvaluations($profile);

        return [
            'employee_number' => $employeeNumber,
            'name' => $name,
            'email' => $i % 13 === 3 ? null : strtolower($employeeNumber).'@example.test',
            'degree' => $i % 11 === 4 ? null : self::DEGREES[$this->random->getInt(0, count(self::DEGREES) - 1)],
            'hired_on' => $i % 20 === 6 ? null : sprintf('%d-%02d-%02d', $this->random->getInt(1995, 2025), $this->random->getInt(1, 12), $this->random->getInt(1, 28)),
            'is_active' => $isActive,
            'deleted' => $deleted,
            'adscriptions' => $adscriptions,
            'positions' => $this->positions($i),
            'availability' => $isActive && ! $deleted && $i % 10 !== 9 ? $this->availability() : [],
            'external' => $this->external($i),
            'profile' => $profile,
            'history' => $history,
            'evaluations' => $evaluations,
        ];
    }

    private function campusDigit(string $campusCode): string
    {
        return match ($campusCode) {
            'C1' => '1',
            'C2' => '2',
            default => (string) (3 + crc32($campusCode) % 7),
        };
    }

    /**
     * @param  list<int>  $subjectCounts
     * @return list<array{dept: int, campus: string, primary: bool}>
     */
    private function adscriptions(int $i, array $subjectCounts): array
    {
        $departments = count($subjectCounts);
        $primary = $i < $departments ? $i : $this->weightedDepartment($subjectCounts);
        $adscriptions = [['dept' => $primary, 'campus' => 'own', 'primary' => true]];

        if ($departments > 1 && $i % 5 === 1) {
            $adscriptions[] = ['dept' => ($primary + 1 + $this->random->getInt(0, $departments - 2)) % $departments, 'campus' => 'own', 'primary' => false];
        }

        if ($i % 9 === 2) {
            $adscriptions[] = ['dept' => $this->random->getInt(0, 1) === 0 ? $primary : $this->random->getInt(0, $departments - 1), 'campus' => 'other', 'primary' => false];
        }

        return $adscriptions;
    }

    /**
     * @param  list<int>  $subjectCounts
     */
    private function weightedDepartment(array $subjectCounts): int
    {
        $weights = array_map(fn (int $subjects): int => $subjects + 2, $subjectCounts);
        $pick = $this->random->getInt(1, array_sum($weights));

        foreach ($weights as $dept => $weight) {
            $pick -= $weight;

            if ($pick <= 0) {
                return $dept;
            }
        }

        return count($weights) - 1;
    }

    private function name(int $i): string
    {
        $given = $this->random->getInt(0, 1) === 0 ? self::MALE_NAMES : self::FEMALE_NAMES;
        $pick = fn (array $list): string => $list[$this->random->getInt(0, count($list) - 1)];

        if ($i % 29 === 10) {
            return implode(' ', [$pick($given), $pick($given), $pick($given), $pick($given), $pick(self::SURNAMES), 'de la', $pick(self::SURNAMES), 'y', $pick(self::SURNAMES), 'de los', $pick(self::SURNAMES), 'del Castillo y Ortega']);
        }

        return implode(' ', [$pick($given), $pick(self::SURNAMES), $pick(self::SURNAMES)]);
    }

    /**
     * @return list<array{kind: string, hours: int, from: ?string, to: ?string}>
     */
    private function positions(int $i): array
    {
        $positions = [];

        foreach (self::POSITION_SCENARIOS[$i % count(self::POSITION_SCENARIOS)] as [$kind, $validity]) {
            $positions[] = [
                'kind' => $kind,
                'hours' => self::POSITION_HOURS[$kind] ?? $this->random->getInt(1, 40),
                'from' => match ($validity) {
                    'expired' => '2018-08-20',
                    'future' => '2027-08-16',
                    default => sprintf('%d-%02d-01', $this->random->getInt(2008, 2025), $this->random->getInt(1, 12)),
                },
                'to' => $validity === 'expired' ? '2025-12-31' : null,
            ];
        }

        return $positions;
    }

    /**
     * Bloques de lunes a viernes entre las 7:00 y las 21:00, sin traslapes: las horas contiguas del mismo tipo se
     * unen en un rango y las de tipo distinto quedan en rangos separados.
     *
     * @return list<array{day: int, starts_at: string, ends_at: string, kind: string}>
     */
    private function availability(): array
    {
        $blocks = [];

        for ($day = 1; $day <= 5; $day++) {
            $hour = 7;

            while ($hour < 21) {
                if ($this->random->getInt(1, 100) > 35) {
                    $hour++;

                    continue;
                }

                $length = min($this->random->getInt(1, 3), 21 - $hour);
                $kind = $this->random->getInt(1, 100) <= 65 ? 'unavailable' : 'preferred';
                $last = array_key_last($blocks);

                if ($last !== null && $blocks[$last]['day'] === $day && $blocks[$last]['kind'] === $kind && $blocks[$last]['ends_at'] === sprintf('%02d:00', $hour)) {
                    $blocks[$last]['ends_at'] = sprintf('%02d:00', $hour + $length);
                } else {
                    $blocks[] = ['day' => $day, 'starts_at' => sprintf('%02d:00', $hour), 'ends_at' => sprintf('%02d:00', $hour + $length), 'kind' => $kind];
                }

                $hour += $length;
            }
        }

        return $blocks;
    }

    /**
     * @return list<array{term: string, source: string, hours: int, notes: ?string}>
     */
    private function external(int $i): array
    {
        $loads = [];

        if ($i % 7 === 3) {
            $loads[] = ['term' => self::CURRENT_TERM, 'source' => self::EXTERNAL_SOURCES[$i % count(self::EXTERNAL_SOURCES)], 'hours' => $this->random->getInt(2, 20), 'notes' => $i % 14 === 3 ? 'Horario en las tardes.' : null];
        }

        if ($i % 19 === 4) {
            $loads[] = ['term' => '20263', 'source' => self::EXTERNAL_SOURCES[0], 'hours' => $this->random->getInt(2, 12), 'notes' => null];
        }

        return $loads;
    }

    /**
     * Las materias que el jefe declaró que el docente puede dar: de 3 a 8 de las de su departamento.
     *
     * @return list<array{subject: int, level: string}>
     */
    private function profile(int $pool): array
    {
        if ($pool === 0) {
            return [];
        }

        $subjects = $this->random->pickArrayKeys(range(0, $pool - 1), min($pool, $this->random->getInt(3, 8)));

        return array_map(fn (int $subject): array => ['subject' => $subject, 'level' => $this->random->getInt(1, 100) <= 30 ? 'preferente' : 'apto'], $subjects);
    }

    /**
     * Una fila de historial por grupo impartido en cada uno de los periodos en que dio clase, y sus evaluaciones.
     *
     * @param  list<array{subject: int, level: string}>  $profile
     * @return array{0: list<array{term: string, subject: int, group: string, source: string}>, 1: list<array{term: string, kind: string, score: float}>}
     */
    private function historyAndEvaluations(array $profile): array
    {
        $history = [];
        $evaluations = [];

        if ($profile === []) {
            return [$history, $evaluations];
        }

        $subjects = array_column($profile, 'subject');

        foreach (self::HISTORY_TERMS as $term) {
            if ($this->random->getInt(1, 100) > 85) {
                continue;
            }

            $taught = $this->random->pickArrayKeys($subjects, min(count($subjects), $this->random->getInt(1, 3)));
            $semester = $this->random->getInt(1, 9);

            foreach ($taught as $key) {
                $subject = $subjects[$key];

                foreach (array_slice(['A', 'B'], 0, $this->random->getInt(1, 100) <= 25 ? 2 : 1) as $letter) {
                    $history[] = ['term' => $term, 'subject' => $subject, 'group' => $semester.$letter, 'source' => 'import'];
                }
            }

            foreach (['estudiantil' => 90, 'departamental' => 70] as $kind => $chance) {
                if ($this->random->getInt(1, 100) <= $chance) {
                    $evaluations[] = ['term' => $term, 'kind' => $kind, 'score' => $this->random->getInt(100, 500) / 100];
                }
            }
        }

        return [$history, $evaluations];
    }
}
