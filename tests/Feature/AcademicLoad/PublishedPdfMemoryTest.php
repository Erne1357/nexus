<?php

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Database\Factories\LoadSnapshotFactory;
use App\Modules\AcademicLoad\Enums\AcademicLoadPermission;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Exports\DepartmentPdf;
use App\Modules\AcademicLoad\Exports\PublishedExport;
use App\Modules\AcademicLoad\Exports\PublishedExports;
use App\Modules\AcademicLoad\Jobs\GeneratePublishedExport;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Models\LoadSnapshot;
use App\Modules\AcademicLoad\Queries\PublishedScheduleQuery;
use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Models\Term;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
| La memoria de los PDF de lo publicado (incidencia E-05 del ensayo 8.11): el PDF de un departamento con cientos de grupos
| se acababa los 128 MB del contenedor y respondía 500. Un departamento como Sistemas y Computación de la demo masiva
| (237 grupos, 66 materias, 632 sesiones) se arma dentro del presupuesto, y el job sube su propio tope para los más
| grandes (Ciencias Económico-Administrativas, 671 grupos).
*/

/**
 * Los grupos de un departamento grande, con la forma de los de la demo masiva: materias de cuatro grupos, dos o tres
 * sesiones por grupo (una práctica en uno de cada tres, en un salón prestado en uno de cada siete), docentes y salones
 * repetidos y dos campus.
 *
 * @return list<array<string, mixed>>
 */
function departmentPdfVolumeGroups(string $departmentId, int $subjects, int $groupsPerSubject): array
{
    $uuid = fn (): string => (string) Str::uuid7();
    $building = ['id' => $uuid(), 'code' => 'A', 'name' => 'Edificio A'];
    $rooms = array_map(fn (int $index): array => ['id' => $uuid(), 'code' => sprintf('A-%03d', $index), 'name' => null, 'building' => $building, 'owner_department_id' => $departmentId], range(1, 30));
    $loaned = ['id' => $uuid(), 'code' => 'LC-2', 'name' => 'Laboratorio 2', 'building' => $building, 'owner_department_id' => $uuid()];
    $teachers = array_map(fn (int $index): array => ['id' => $uuid(), 'rfc' => sprintf('DOCE%06dAB1', $index), 'name' => "Docente Número {$index} Apellido Paterno Materno"], range(1, 45));
    $campuses = [['id' => $uuid(), 'code' => 'C1', 'name' => 'Campus I'], ['id' => $uuid(), 'code' => 'C2', 'name' => 'Campus II']];
    $groups = [];

    foreach (range(1, $subjects) as $subjectIndex) {
        $subject = ['id' => $uuid(), 'code' => sprintf('SCD-%04d', 1000 + $subjectIndex), 'name' => "Materia de prueba número {$subjectIndex} del departamento", 'credits' => 5, 'theory_hours' => 2, 'practice_hours' => 3, 'department_id' => $departmentId];

        foreach (range(1, $groupsPerSubject) as $groupIndex) {
            $index = count($groups);
            $hour = 7 + ($index % 13);
            $sessions = [
                LoadSnapshotFactory::session(['day' => 1 + ($index % 2), 'starts_at' => sprintf('%02d:00', $hour), 'ends_at' => sprintf('%02d:00', $hour + 2), 'room' => $rooms[$index % 30]]),
                LoadSnapshotFactory::session(['day' => 3 + ($index % 2), 'starts_at' => sprintf('%02d:00', $hour), 'ends_at' => sprintf('%02d:00', $hour + 1), 'room' => $rooms[($index + 7) % 30]]),
            ];

            if ($index % 3 === 0) {
                $sessions[] = LoadSnapshotFactory::session(['day' => 5, 'starts_at' => sprintf('%02d:00', $hour), 'ends_at' => sprintf('%02d:00', $hour + 2), 'kind' => 'practica', 'kind_label' => 'Práctica', 'room' => $index % 7 === 0 ? $loaned : $rooms[($index + 13) % 30]]);
            }

            $groups[] = LoadSnapshotFactory::group([
                'code' => chr(64 + $groupIndex),
                'capacity' => 30 + ($index % 6),
                'campus' => $campuses[$index % 2],
                'subject' => $subject,
                'teacher' => $teachers[$index % 45],
                'sessions' => $sessions,
            ]);
        }
    }

    return $groups;
}

/*
| Lo que el armado suma al proceso: 115 MB con una sola tabla para todo el departamento y 70 MB con una por materia
| (medido el 19 de noviembre de 2026). El tope deja margen sin dejar pasar la tabla única.
*/
it('arma el PDF de un departamento de 252 grupos dentro de su presupuesto de memoria', function () {
    Storage::fake(PublishedExports::DISK);
    $term = Term::factory()->create(['code' => '20271', 'name' => 'Enero–Junio 2027']);
    $department = Department::factory()->create(['code' => 'SC', 'name' => 'Sistemas y Computación']);
    $load = AcademicLoad::factory()->for($term)->state(['modality_id' => modalityOf('PRES')->id])->for($department)->inStatus(LoadStatus::Published)->create();
    LoadSnapshot::factory()->for($load)->withGroups(departmentPdfVolumeGroups($department->id, 63, 4))->create();
    $export = DepartmentPdf::fromRequest(app(PublishedScheduleQuery::class), $term, modalityOf('PRES'), Request::create('/', 'GET', ['departamento' => $department->id]));

    gc_collect_cycles();
    $before = memory_get_usage();
    memory_reset_peak_usage();
    $pdf = $export->build();
    $peak = (memory_get_peak_usage() - $before) / 1048576;

    expect($pdf)->toStartWith('%PDF-')
        ->and($peak)->toBeLessThan(90.0);
});

/**
 * Un exporte que, al armarse, escribe el tope de memoria con que corre.
 */
function publishedPdfMemoryProbe(): PublishedExport
{
    return new class implements PublishedExport
    {
        public function key(): string
        {
            return 'memoria-de-prueba';
        }

        public function filename(): string
        {
            return 'Memoria.txt';
        }

        public function mimeType(): string
        {
            return 'text/plain';
        }

        public function permission(): ?AcademicLoadPermission
        {
            return null;
        }

        public function scope(): Target
        {
            return Target::of();
        }

        public function build(): string
        {
            return (string) ini_get('memory_limit');
        }
    };
}

it('el job sube su tope de memoria mientras arma el exporte, sin bajar uno mayor', function (?string $start, string $during) {
    Storage::fake(PublishedExports::DISK);
    $previous = (string) ini_get('memory_limit');
    // Un tope menor que el del job pero por encima de lo que ya usa la prueba: el contenedor trae 128 MB.
    ini_set('memory_limit', $start ?? (string) (memory_get_usage(true) + 32 * 1048576));

    try {
        (new GeneratePublishedExport(publishedPdfMemoryProbe()))->handle(app(PublishedExports::class));
    } finally {
        ini_set('memory_limit', $previous);
    }

    expect(Storage::disk(PublishedExports::DISK)->get('published/memoria-de-prueba.txt'))->toBe($during);
})->with([
    'menor que el del job' => [null, GeneratePublishedExport::MEMORY_LIMIT],
    'mayor que el del job' => ['3G', '3G'],
    'sin tope' => ['-1', '-1'],
]);
