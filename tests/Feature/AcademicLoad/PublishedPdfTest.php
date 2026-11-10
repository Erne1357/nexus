<?php

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Database\Factories\LoadSnapshotFactory;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Exports\PublishedExports;
use App\Modules\AcademicLoad\Exports\SemesterPdf;
use App\Modules\AcademicLoad\Exports\WeekPdf;
use App\Modules\AcademicLoad\Jobs\GeneratePublishedExport;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Models\LoadSnapshot;
use App\Modules\AcademicLoad\Queries\PublishedGroup;
use App\Modules\AcademicLoad\Queries\PublishedScheduleQuery;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
| Los PDF públicos de lo publicado (tarea 4.5): por carrera, retícula y semestre (tabla tipo SII), por docente y por
| salón (semana), sin sesión (decisión del usuario del 10 de noviembre de 2026). Solo de lo publicado: lo demás es 404
| y no encola nada. El público ve «Por asignar», nunca el RFC ni la justificación.
*/

beforeEach(function () {
    Storage::fake(PublishedExports::DISK);
    $this->term = Term::factory()->create(['code' => '20271', 'name' => 'Enero–Junio 2027']);
    $this->campus = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    $this->teacher = ['id' => (string) Str::uuid7(), 'rfc' => 'RIML800101AB1', 'name' => 'Laura Ríos Medina'];
    $this->career = ['id' => (string) Str::uuid7(), 'code' => 'ISC', 'name' => 'Ingeniería en Sistemas Computacionales'];
    $this->plan = ['id' => (string) Str::uuid7(), 'code' => 'ISIC-2010-224'];
    $this->room = ['id' => (string) Str::uuid7(), 'code' => 'B-12', 'name' => 'Aula B-12', 'building' => ['id' => (string) Str::uuid7(), 'code' => 'B', 'name' => 'Edificio B'], 'owner_department_id' => null];

    $placement = LoadSnapshotFactory::placement(['career' => $this->career, 'study_plan' => $this->plan, 'semester' => 3]);
    $subject = fn (string $code, string $name): array => ['id' => (string) Str::uuid7(), 'code' => $code, 'name' => $name, 'credits' => 5, 'theory_hours' => 3, 'practice_hours' => 2, 'department_id' => (string) Str::uuid7()];
    $load = AcademicLoad::factory()->for($this->term)->for($this->campus)->for(Department::factory())->inStatus(LoadStatus::Published)->create();

    LoadSnapshot::factory()->for($load)->publishedAt(CarbonImmutable::parse('2026-12-12 10:30'))->withGroups([
        LoadSnapshotFactory::group([
            'code' => 'B', 'subject' => $subject('ACF-0904', 'Cálculo Vectorial'), 'teacher' => $this->teacher, 'placements' => [$placement],
            'sessions' => [
                LoadSnapshotFactory::session(['day' => 1, 'starts_at' => '08:00', 'ends_at' => '09:00', 'room' => $this->room]),
                LoadSnapshotFactory::session(['day' => 3, 'starts_at' => '08:00', 'ends_at' => '10:00', 'kind' => 'practica', 'kind_label' => 'Práctica', 'room' => $this->room]),
            ],
        ]),
        LoadSnapshotFactory::group([
            'code' => 'K', 'subject' => $subject('AED-1026', 'Estructura de Datos'), 'teacher' => null, 'justification' => 'Contratación en trámite',
            'placements' => [$placement],
            'sessions' => [LoadSnapshotFactory::session(['day' => 5, 'starts_at' => '17:00', 'ends_at' => '18:00', 'kind' => 'virtual', 'kind_label' => 'Virtual', 'room' => null])],
        ]),
    ])->create();
});

function publishedPdfUrl(string $name, array $parameters): string
{
    return route($name, ['periodo' => '20271', 'campus' => 'C2', ...$parameters]);
}

it('descarga sin sesión el PDF de un semestre publicado, con su nombre', function () {
    $response = $this->get(publishedPdfUrl('published.semester.pdf', ['carrera' => 'ISC', 'reticula' => 'ISIC-2010-224', 'semestre' => 3]));

    $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($response->streamedContent())->toStartWith('%PDF')
        ->and($response->headers->get('Content-Disposition'))->toContain('Horario ISC semestre 3 - 20271 C2.pdf');
});

it('descarga sin sesión el PDF de un docente y el de un salón', function () {
    $this->get(publishedPdfUrl('published.teacher.pdf', ['docente' => $this->teacher['id']]))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $this->get(publishedPdfUrl('published.room.pdf', ['salon' => $this->room['id']]))->assertOk()->assertHeader('Content-Type', 'application/pdf');
});

it('mientras la cola lo arma responde una página que se recarga sola', function () {
    Queue::fake();

    $this->get(publishedPdfUrl('published.teacher.pdf', ['docente' => $this->teacher['id']]))
        ->assertStatus(202)
        ->assertSee('Preparando el PDF')
        ->assertSee('<meta http-equiv="refresh" content="3">', false);

    Queue::assertPushed(GeneratePublishedExport::class, 1);
});

it('responde 404 sin encolar nada a lo que no está publicado', function (string $name, array $parameters) {
    Queue::fake();

    $this->get(publishedPdfUrl($name, $parameters))->assertNotFound();

    Queue::assertNothingPushed();
})->with([
    'carrera no publicada' => ['published.semester.pdf', ['carrera' => 'IGE', 'reticula' => 'ISIC-2010-224', 'semestre' => 3]],
    'semestre sin grupos' => ['published.semester.pdf', ['carrera' => 'ISC', 'reticula' => 'ISIC-2010-224', 'semestre' => 5]],
    'semestre que no es número' => ['published.semester.pdf', ['carrera' => 'ISC', 'reticula' => 'ISIC-2010-224', 'semestre' => 'tres']],
    'docente sin grupos' => ['published.teacher.pdf', ['docente' => '01a1c605-d429-720c-97d4-b68d05f13b24']],
    'id mal formado' => ['published.teacher.pdf', ['docente' => 'no-es-uuid']],
    'salón sin sesiones' => ['published.room.pdf', ['salon' => '01a1c605-d429-720c-97d4-b68d05f13b24']],
]);

it('responde 404 a otro periodo o campus', function () {
    $this->get(route('published.teacher.pdf', ['periodo' => '20263', 'campus' => 'C2', 'docente' => $this->teacher['id']]))->assertNotFound();
    $this->get(route('published.teacher.pdf', ['periodo' => '20271', 'campus' => 'C1', 'docente' => $this->teacher['id']]))->assertNotFound();
});

it('las descargas públicas llevan su limitador', function () {
    foreach (['published.semester.pdf', 'published.teacher.pdf', 'published.room.pdf'] as $name) {
        expect(Route::getRoutes()->getByName($name)->gatherMiddleware())->toContain('throttle:published-exports');
    }
});

it('el PDF del semestre lista materias y grupos por día, con «Por asignar» y sin el RFC ni la justificación', function () {
    $schedule = app(PublishedScheduleQuery::class)->schedule($this->term, $this->campus);
    $groups = $schedule->semester($this->career['id'], $this->plan['id'], 3);

    $html = view('academic-load::pdf.semester', [
        'title' => 'Ingeniería en Sistemas Computacionales', 'subtitle' => 'Semestre 3', 'updated' => '12 de diciembre de 2026',
        'days' => [['day' => 1, 'name' => 'Lunes'], ['day' => 3, 'name' => 'Miércoles'], ['day' => 5, 'name' => 'Viernes']],
        'subjects' => SemesterPdf::subjects($groups, $schedule), 'term' => $this->term, 'campus' => $this->campus,
    ])->render();

    expect($html)->toContain('ACF-0904 · B')
        ->toContain('Laura Ríos Medina')
        ->toContain('8:00–9:00')
        ->toContain('8:00–10:00')
        ->toContain('Práctica')
        ->toContain('Por asignar')
        ->toContain('Virtual')
        ->toContain('Actualizado el 12 de diciembre de 2026')
        ->not->toContain('RIML800101AB1')
        ->not->toContain('Contratación en trámite');
});

it('la semana de un docente acomoda cada sesión en sus bloques y suma sus horas', function () {
    $schedule = app(PublishedScheduleQuery::class)->schedule($this->term, $this->campus);
    $week = WeekPdf::week($schedule->teacher($this->teacher['id']), $schedule, fn (PublishedGroup $group, array $session): array => $session);

    expect($week['hours'])->toBe(3)
        ->and($week['cells'][1][1]['span'])->toBe(1)
        ->and($week['cells'][1][1]['items'][0]['place'])->toBe('B-12')
        ->and($week['cells'][3][1]['span'])->toBe(2)
        ->and($week['cells'][3][2])->toBe('covered')
        ->and($week['blocks'][0]['label'])->toBe('7:00');
});
