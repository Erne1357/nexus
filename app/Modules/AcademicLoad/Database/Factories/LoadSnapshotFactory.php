<?php

namespace App\Modules\AcademicLoad\Database\Factories;

use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Models\LoadSnapshot;
use App\Modules\AcademicLoad\Rules\SnapshotPayload;
use App\Modules\Core\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Una versión publicada con un payload v1 válido y realista, para probar lo que lee el snapshot (consulta publicada,
 * exportes) sin armar grupos en Scheduling: tres grupos con un compartido entre dos carreras (una con dos retículas
 * y otra sin la materia en su retícula), un salón prestado por otro departamento, una sesión virtual y un grupo sin
 * docente con su justificación. El encabezado sale de la carga y de quien publica, como en la publicación real.
 *
 * Al crearla, la carga queda con esta versión como la última (`current_version`, `published_by`, `published_at`) si es
 * más nueva que la que tenía; su estado no se toca (la carga nace publicada por omisión).
 *
 * Para armar otros casos: `withGroups([...])` con grupos hechos con `group()`, `placement()` y `session()`, y
 * `publishedAt()` para fechar cada versión.
 *
 * @extends Factory<LoadSnapshot>
 */
#[UseModel(LoadSnapshot::class)]
class LoadSnapshotFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'academic_load_id' => AcademicLoad::factory()->inStatus(LoadStatus::Published),
            'version' => 1,
            'created_by' => User::factory(),
            'created_at' => fn (): CarbonImmutable => CarbonImmutable::now()->startOfSecond(),
            'payload' => fn (array $attributes): array => self::payload(
                AcademicLoad::query()->findOrFail($attributes['academic_load_id']),
                $attributes['version'],
                User::query()->findOrFail($attributes['created_by']),
                null,
                CarbonImmutable::parse($attributes['created_at']),
            ),
        ];
    }

    /**
     * La versión publicada en ese momento: lo dicen igual la fila (`created_at`), el payload (`published_at`) y la
     * carga si es su última versión.
     */
    public function publishedAt(CarbonImmutable $at): static
    {
        return $this->state(['created_at' => $at]);
    }

    public function configure(): static
    {
        return $this->afterCreating(function (LoadSnapshot $snapshot): void {
            $load = $snapshot->academicLoad;

            if ($snapshot->version > $load->current_version) {
                $load->update(['current_version' => $snapshot->version, 'published_by' => $snapshot->created_by, 'published_at' => $snapshot->created_at]);
            }
        });
    }

    /**
     * La versión con estos grupos en lugar de los de ejemplo.
     *
     * @param  list<array<string, mixed>>  $groups
     */
    public function withGroups(array $groups): static
    {
        return $this->state([
            'payload' => fn (array $attributes): array => self::payload(
                AcademicLoad::query()->findOrFail($attributes['academic_load_id']),
                $attributes['version'],
                User::query()->findOrFail($attributes['created_by']),
                $groups,
                CarbonImmutable::parse($attributes['created_at']),
            ),
        ]);
    }

    /**
     * Un payload completo de la carga: el encabezado real y los grupos dados, o los de ejemplo.
     *
     * @param  list<array<string, mixed>>|null  $groups
     * @return array<string, mixed>
     */
    public static function payload(AcademicLoad $load, int $version, User $publisher, ?array $groups = null, ?CarbonImmutable $publishedAt = null): array
    {
        return [
            ...SnapshotPayload::header($load, $version, $publisher, $publishedAt ?? CarbonImmutable::now()),
            'groups' => $groups ?? self::sampleGroups($load->department_id),
        ];
    }

    /**
     * Un grupo con una sesión de teoría y su carrera dueña; `$overrides` reemplaza llaves de primer nivel.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function group(array $overrides = []): array
    {
        return array_replace([
            'id' => (string) Str::uuid7(),
            'code' => 'A',
            'status' => 'confirmed',
            'capacity' => 35,
            'justification' => null,
            'subject' => ['id' => (string) Str::uuid7(), 'code' => 'SCD-1027', 'name' => 'Tópicos Avanzados de Programación', 'credits' => 5, 'theory_hours' => 2, 'practice_hours' => 3, 'department_id' => (string) Str::uuid7()],
            'teacher' => ['id' => (string) Str::uuid7(), 'rfc' => 'OISM850312AB1', 'name' => 'María Fernanda Ortiz Saucedo'],
            'placements' => [self::placement()],
            'sessions' => [self::session()],
        ], $overrides);
    }

    /**
     * El lugar de un grupo en una carrera; por omisión, la dueña en el cuarto semestre de su retícula.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function placement(array $overrides = []): array
    {
        return array_replace([
            'career' => ['id' => (string) Str::uuid7(), 'code' => 'ISC', 'name' => 'Ingeniería en Sistemas Computacionales'],
            'study_plan' => ['id' => (string) Str::uuid7(), 'code' => 'ISIC-2010-224'],
            'semester' => 4,
            'seats' => 35,
            'is_owner' => true,
        ], $overrides);
    }

    /**
     * Una sesión; por omisión, teoría el lunes de 7:00 a 9:00 en un salón del edificio A.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function session(array $overrides = []): array
    {
        return array_replace([
            'day' => 1,
            'starts_at' => '07:00',
            'ends_at' => '09:00',
            'kind' => 'teoria',
            'kind_label' => 'Teoría',
            'room' => [
                'id' => (string) Str::uuid7(),
                'code' => 'A-101',
                'name' => null,
                'building' => ['id' => (string) Str::uuid7(), 'code' => 'A', 'name' => 'Edificio A'],
                'owner_department_id' => null,
            ],
        ], $overrides);
    }

    /**
     * Los grupos de ejemplo de un departamento, con docentes, carreras, retículas y salones compartidos entre ellos.
     *
     * @return list<array<string, mixed>>
     */
    private static function sampleGroups(string $departmentId): array
    {
        $uuid = fn (): string => (string) Str::uuid7();
        $building = ['id' => $uuid(), 'code' => 'A', 'name' => 'Edificio A'];
        $lab = ['id' => $uuid(), 'code' => 'LC', 'name' => 'Laboratorio de Cómputo'];
        $room = ['id' => $uuid(), 'code' => 'A-101', 'name' => null, 'building' => $building, 'owner_department_id' => $departmentId];
        $evening = ['id' => $uuid(), 'code' => 'A-204', 'name' => 'Aula 204', 'building' => $building, 'owner_department_id' => $departmentId];
        $loaned = ['id' => $uuid(), 'code' => 'LC-2', 'name' => 'Laboratorio 2', 'building' => $lab, 'owner_department_id' => $uuid()];

        $isc = ['id' => $uuid(), 'code' => 'ISC', 'name' => 'Ingeniería en Sistemas Computacionales'];
        $ige = ['id' => $uuid(), 'code' => 'IGE', 'name' => 'Ingeniería en Gestión Empresarial'];
        $ind = ['id' => $uuid(), 'code' => 'IND', 'name' => 'Ingeniería Industrial'];
        $iscPlan = ['id' => $uuid(), 'code' => 'ISIC-2010-224'];

        $ortiz = ['id' => $uuid(), 'rfc' => 'OISM850312AB1', 'name' => 'María Fernanda Ortiz Saucedo'];
        $delgado = ['id' => $uuid(), 'rfc' => 'DERJ780921QK4', 'name' => 'Jesús Alberto Delgado Ríos'];

        $subject = fn (string $code, string $name, int $theory, int $practice): array => [
            'id' => $uuid(), 'code' => $code, 'name' => $name, 'credits' => $theory + $practice,
            'theory_hours' => $theory, 'practice_hours' => $practice, 'department_id' => $departmentId,
        ];

        return [
            self::group([
                'code' => 'A',
                'subject' => $subject('SCD-1027', 'Tópicos Avanzados de Programación', 2, 3),
                'teacher' => $ortiz,
                'placements' => [self::placement(['career' => $isc, 'study_plan' => $iscPlan, 'semester' => 4, 'seats' => 35])],
                'sessions' => [
                    self::session(['day' => 1, 'starts_at' => '07:00', 'ends_at' => '09:00', 'room' => $room]),
                    self::session(['day' => 3, 'starts_at' => '07:00', 'ends_at' => '10:00', 'kind' => 'practica', 'kind_label' => 'Práctica', 'room' => $loaned]),
                ],
            ]),
            self::group([
                'code' => 'D',
                'capacity' => 40,
                'subject' => $subject('ACA-0909', 'Taller de Investigación I', 0, 4),
                'teacher' => $delgado,
                'placements' => [
                    self::placement(['career' => $isc, 'study_plan' => $iscPlan, 'semester' => 6, 'seats' => 20]),
                    self::placement(['career' => $ige, 'study_plan' => ['id' => $uuid(), 'code' => 'IGEM-2009-201'], 'semester' => 5, 'seats' => 15, 'is_owner' => false]),
                    self::placement(['career' => $ige, 'study_plan' => ['id' => $uuid(), 'code' => 'IGEM-2016-201'], 'semester' => 6, 'seats' => 15, 'is_owner' => false]),
                    self::placement(['career' => $ind, 'study_plan' => null, 'semester' => null, 'seats' => 5, 'is_owner' => false]),
                ],
                'sessions' => [
                    self::session(['day' => 2, 'starts_at' => '10:00', 'ends_at' => '12:00', 'room' => $room]),
                    self::session(['day' => 4, 'starts_at' => '10:00', 'ends_at' => '12:00', 'room' => $room]),
                ],
            ]),
            self::group([
                'code' => 'M',
                'status' => 'planned',
                'capacity' => 30,
                'justification' => 'Docente en contratación; se asigna antes del inicio de clases.',
                'subject' => $subject('SCC-1010', 'Graficación', 2, 2),
                'teacher' => null,
                'placements' => [self::placement(['career' => $isc, 'study_plan' => $iscPlan, 'semester' => 5, 'seats' => 30])],
                'sessions' => [
                    self::session(['day' => 1, 'starts_at' => '19:00', 'ends_at' => '21:00', 'room' => $evening]),
                    self::session(['day' => 5, 'starts_at' => '19:00', 'ends_at' => '21:00', 'kind' => 'virtual', 'kind_label' => 'Virtual', 'room' => null]),
                ],
            ]),
        ];
    }
}
