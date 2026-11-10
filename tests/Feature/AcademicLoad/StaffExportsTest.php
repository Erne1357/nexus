<?php

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Database\Factories\LoadSnapshotFactory;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Exports\CampusWorkbook;
use App\Modules\AcademicLoad\Exports\PublishedExports;
use App\Modules\AcademicLoad\Exports\SemesterPdf;
use App\Modules\AcademicLoad\Jobs\GeneratePublishedExport;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Models\LoadSnapshot;
use App\Modules\AcademicLoad\Queries\PublishedScheduleQuery;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use OpenSpout\Reader\XLSX\Reader;

/*
| Los exportes del personal de lo publicado (tarea 4.5): el PDF por departamento y el Excel del campus, solo con sesión
| y `academic_load.load.export` (decisión del usuario del 10 de noviembre de 2026; Subdirección, jefes de departamento,
| jefes de proyecto docente y Servicios Escolares, supuesto por confirmar). Sin el permiso no se encola nada.
*/

beforeEach(function () {
    Storage::fake(PublishedExports::DISK);
    $this->term = Term::factory()->create(['code' => '20271', 'name' => 'Enero–Junio 2027']);
    $this->campus = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    $this->department = Department::factory()->create(['code' => 'SC', 'name' => 'Sistemas y Computación']);
    $this->load = AcademicLoad::factory()->for($this->term)->for($this->campus)->for($this->department)->inStatus(LoadStatus::Published)->create();
    $this->lent = ['id' => (string) Str::uuid7(), 'code' => 'LC-2', 'name' => 'Laboratorio 2', 'building' => ['id' => (string) Str::uuid7(), 'code' => 'L', 'name' => 'Edificio de Laboratorios'], 'owner_department_id' => (string) Str::uuid7()];

    LoadSnapshot::factory()->for($this->load)->publishedAt(CarbonImmutable::parse('2026-12-12 10:30'))->withGroups([
        LoadSnapshotFactory::group([
            'code' => 'C', 'capacity' => 33,
            'subject' => ['id' => (string) Str::uuid7(), 'code' => 'AED-1026', 'name' => 'Estructura de Datos', 'credits' => 5, 'theory_hours' => 2, 'practice_hours' => 3, 'department_id' => $this->department->id],
            'teacher' => ['id' => (string) Str::uuid7(), 'rfc' => 'CAOI800101AB1', 'name' => 'Iván Castañeda Ortiz'],
            'sessions' => [
                LoadSnapshotFactory::session(['day' => 1, 'starts_at' => '09:00', 'ends_at' => '10:00']),
                LoadSnapshotFactory::session(['day' => 2, 'starts_at' => '09:00', 'ends_at' => '11:00', 'kind' => 'practica', 'kind_label' => 'Práctica', 'room' => $this->lent]),
            ],
        ]),
    ])->create();

    $this->excelUrl = route('published.export', ['periodo' => '20271', 'campus' => 'C2', 'tipo' => 'excel']);
    $this->pdfUrl = route('published.export', ['periodo' => '20271', 'campus' => 'C2', 'tipo' => 'departamento', 'departamento' => $this->department->id]);
});

function staffExportsHead(Department $department, Campus $campus): User
{
    return User::factory()->withRole(Role::JefeDepartamento, ['campus_id' => $campus->id, 'department_id' => $department->id])->create();
}

it('el jefe de departamento descarga el Excel del campus con un renglón por sesión', function () {
    $response = $this->actingAs(staffExportsHead($this->department, $this->campus))->get($this->excelUrl);

    $response->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    expect($response->headers->get('Content-Disposition'))->toContain('Carga publicada 20271 C2.xlsx');

    $path = tempnam(sys_get_temp_dir(), 'xlsx-test-');
    file_put_contents($path, $response->streamedContent());
    $reader = new Reader;
    $reader->open($path);
    $rows = [];

    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $rows[] = $row->toArray();
        }
    }

    $reader->close();
    unlink($path);

    expect($rows[0])->toBe(CampusWorkbook::HEADER)
        ->and($rows)->toHaveCount(3)
        ->and(array_slice($rows[1], 0, 7))->toBe(['SC', 'AED-1026', 'Estructura de Datos', 'C', 5, 33, 'Iván Castañeda Ortiz'])
        ->and(array_slice($rows[2], 10))->toBe(['Martes', '9:00', '11:00', 'Práctica', 'LC-2', 'Edificio de Laboratorios', 'Sí'])
        ->and(json_encode($rows, JSON_UNESCAPED_UNICODE))->not->toContain('CAOI800101AB1');
});

it('Subdirección y el jefe de proyecto docente también descargan el PDF del departamento', function (Role $role) {
    $user = User::factory()->withRole($role, ['campus_id' => $this->campus->id, 'department_id' => $role === Role::Subdireccion ? null : $this->department->id])->create();

    $response = $this->actingAs($user)->get($this->pdfUrl);

    $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($response->headers->get('Content-Disposition'))->toContain('Carga SC - 20271 C2.pdf');
})->with([Role::Subdireccion, Role::JefeProyectoDocente]);

it('sin sesión manda a iniciar sesión y no encola nada', function () {
    Queue::fake();

    $this->get($this->excelUrl)->assertRedirect(route('login'));

    Queue::assertNothingPushed();
});

it('a quien no tiene el permiso le responde 403 sin encolar nada', function (Role $role) {
    Queue::fake();
    $scope = match ($role) {
        Role::Coordinador => ['campus_id' => $this->campus->id, 'career_id' => careerKey(20)],
        Role::JefeDepartamento => ['campus_id' => $this->campus->id, 'department_id' => Department::factory()->create()->id],
        default => ['campus_id' => $this->campus->id],
    };
    $this->actingAs(User::factory()->withRole($role, $scope)->create());

    $this->get($this->excelUrl)->assertForbidden();
    $this->get($this->pdfUrl)->assertForbidden();

    Queue::assertNotPushed(GeneratePublishedExport::class);
})->with(['docente' => Role::Docente, 'coordinador' => Role::Coordinador, 'jefe de otro departamento' => Role::JefeDepartamento]);

it('responde 404 a un tipo o un departamento que no están publicados', function () {
    $this->actingAs(staffExportsHead($this->department, $this->campus));

    $this->get(route('published.export', ['periodo' => '20271', 'campus' => 'C2', 'tipo' => 'otro']))->assertNotFound();
    $this->get(route('published.export', ['periodo' => '20271', 'campus' => 'C2', 'tipo' => 'departamento', 'departamento' => (string) Str::uuid7()]))->assertNotFound();
    $this->get(route('published.export', ['periodo' => '20271', 'campus' => 'C2', 'tipo' => 'departamento', 'departamento' => ['x']]))->assertNotFound();
});

it('el PDF del departamento lleva la capacidad y la marca de préstamo, que el público no ve', function () {
    $schedule = app(PublishedScheduleQuery::class)->schedule($this->term, $this->campus);
    $data = [
        'title' => 'Sistemas y Computación', 'subtitle' => '1 grupo', 'updated' => '12 de diciembre de 2026',
        'days' => [['day' => 1, 'name' => 'Lunes'], ['day' => 2, 'name' => 'Martes']],
        'subjects' => SemesterPdf::subjects($schedule->department($this->department->id), $schedule),
        'term' => $this->term, 'campus' => $this->campus,
    ];

    $staff = view('academic-load::pdf.semester', [...$data, 'staff' => true])->render();
    $public = view('academic-load::pdf.semester', $data)->render();

    expect($staff)->toContain('Cap.')->toContain('>33<')->toContain('Préstamo')
        ->and($public)->not->toContain('Cap.')->not->toContain('Préstamo');
});

it('la consulta ofrece los exportes del personal solo a quien tiene el permiso', function () {
    $index = route('published.index', ['periodo' => '20271', 'campus' => 'C2']);

    $this->get($index)->assertOk()->assertDontSee('Exportes del personal');
    $this->actingAs(User::factory()->withRole(Role::Docente)->create())->get($index)->assertOk()->assertDontSee('Exportes del personal');
    $this->actingAs(staffExportsHead($this->department, $this->campus))->get($index)
        ->assertOk()->assertSee('Exportes del personal')->assertSee('Excel del campus')->assertSee('SC · Sistemas y Computación');
});
