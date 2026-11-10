<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Database\Factories\LoadSnapshotFactory;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Models\LoadSnapshot;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\CampusProfile;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
| Las páginas públicas de lo publicado (tarea 4.4, DESIGN.md, «Consulta publicada», variante A «Fichas por materia»):
| se ven sin sesión, leen solo de los snapshots y no enseñan cupos, versiones, RFC, justificaciones ni lo que falta por
| publicar; el personal con permiso sí ve la franja «Sin publicar» (P7).
*/

/**
 * @param  list<array<string, mixed>>  $groups
 */
function publishedPagesSnapshot(Term $term, Campus $campus, string $department, array $groups, string $at = '2026-12-12 10:30', LoadStatus $status = LoadStatus::Published): AcademicLoad
{
    $load = AcademicLoad::factory()
        ->for($term)
        ->for($campus)
        ->for(Department::query()->firstWhere('code', $department) ?? Department::factory()->create(['code' => $department, 'name' => 'Departamento '.$department]))
        ->inStatus($status)
        ->create();

    LoadSnapshot::factory()->for($load)->publishedAt(CarbonImmutable::parse($at))->withGroups($groups)->create();

    return $load;
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function publishedPagesGroup(string $subject, string $name, string $code, array $overrides = []): array
{
    return LoadSnapshotFactory::group([
        'code' => $code,
        'subject' => ['id' => (string) Str::uuid7(), 'code' => $subject, 'name' => $name, 'credits' => 5, 'theory_hours' => 3, 'practice_hours' => 2, 'department_id' => (string) Str::uuid7()],
        ...$overrides,
    ]);
}

function publishedPagesIndex(): Testable
{
    return Livewire::withQueryParams(['periodo' => '20271', 'campus' => 'C2'])->test('academic-load::pages.published.index');
}

beforeEach(function () {
    $this->term = Term::factory()->create(['code' => '20271', 'name' => 'Enero–Junio 2027', 'starts_on' => '2027-01-25']);
    $this->c2 = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    $this->c1 = Campus::factory()->create(['code' => 'C1', 'name' => 'Campus I']);

    $this->career = ['id' => (string) Str::uuid7(), 'code' => 'ISC', 'name' => 'Ingeniería en Sistemas Computacionales'];
    $this->plan = ['id' => (string) Str::uuid7(), 'code' => 'ISIC-2010-224'];
    $this->laura = ['id' => (string) Str::uuid7(), 'rfc' => 'RIML800101AB1', 'name' => 'Laura Ríos Medina'];
    $this->room = ['id' => (string) Str::uuid7(), 'code' => 'B-12', 'name' => 'Aula B-12', 'building' => ['id' => (string) Str::uuid7(), 'code' => 'B', 'name' => 'Edificio B'], 'owner_department_id' => null];
    $this->placement = fn (int $semester = 3) => LoadSnapshotFactory::placement(['career' => $this->career, 'study_plan' => $this->plan, 'semester' => $semester, 'seats' => 40]);

    $this->groupB = publishedPagesGroup('ACF-0904', 'Cálculo Vectorial', 'B', [
        'teacher' => $this->laura,
        'capacity' => 37,
        'justification' => 'Sin salón por causa de fuerza mayor',
        'placements' => [($this->placement)()],
        'sessions' => [
            LoadSnapshotFactory::session(['day' => 1, 'starts_at' => '07:00', 'ends_at' => '09:00', 'room' => $this->room]),
            LoadSnapshotFactory::session(['day' => 3, 'starts_at' => '08:00', 'ends_at' => '09:00', 'kind' => 'practica', 'kind_label' => 'Práctica', 'room' => null]),
        ],
    ]);
    $this->groupD = publishedPagesGroup('AED-1026', 'Estructura de Datos', 'D', [
        'teacher' => null,
        'placements' => [($this->placement)()],
        'sessions' => [LoadSnapshotFactory::session(['day' => 2, 'starts_at' => '10:00', 'ends_at' => '12:00', 'kind' => 'virtual', 'kind_label' => 'Virtual', 'room' => null])],
    ]);
    $this->basic = publishedPagesSnapshot($this->term, $this->c2, 'CB', [$this->groupB, $this->groupD]);
    publishedPagesSnapshot($this->term, $this->c1, 'CB', [publishedPagesGroup('ACF-0000', 'Solo del Campus I', 'Z', ['placements' => [LoadSnapshotFactory::placement()]])], '2026-12-09 08:00');

    $this->semesterUrl = fn (array $query = []) => route('published.semester', ['periodo' => '20271', 'campus' => 'C2', 'carrera' => 'ISC', ...$query]);
    $this->indexUrl = route('published.index', ['periodo' => '20271', 'campus' => 'C2']);
});

describe('inicio', function () {
    it('responde sin sesión con las carreras publicadas y sus botones de semestre', function () {
        $this->get($this->indexUrl)
            ->assertOk()
            ->assertSee('Ingeniería en Sistemas Computacionales')
            ->assertSee('Actualizado el 12 de diciembre de 2026')
            ->assertSee('aria-label="ISC, semestre 3"', false)
            ->assertDontSee('Solo del Campus I');
    });

    it('sin parámetros toma el primer campus del primer periodo publicado y con ellos el que se pide', function () {
        $this->get(route('published.index'))->assertOk()->assertSee('Campus I');
        $this->get(route('published.index', ['periodo' => '20271', 'campus' => 'C1']))->assertOk()->assertSee('Campus I');
    });

    it('sin publicaciones en el campus dice que todavía no hay horarios', function () {
        Campus::factory()->create(['code' => 'EAD', 'name' => 'Campus Virtual']);
        $this->get(route('published.index', ['periodo' => '20271', 'campus' => 'EAD']))->assertOk()->assertSee('Todavía no hay horarios publicados para Campus Virtual en Enero–Junio 2027.');
        $this->get(route('published.index', ['periodo' => '29999', 'campus' => 'C2']))->assertNotFound();
    });

    it('lista el último periodo publicado y no mezcla la carga de otro periodo', function () {
        $old = Term::factory()->create(['code' => '20263', 'name' => 'Agosto–Diciembre 2026', 'starts_on' => '2026-08-17']);
        publishedPagesSnapshot($old, $this->c2, 'CB', [publishedPagesGroup('OLD-0001', 'Materia vieja', 'A', ['placements' => [($this->placement)(1)]])], '2026-06-01 08:00');

        $this->get($this->indexUrl)->assertOk()->assertSee('Enero–Junio 2027')->assertDontSee('OLD-0001');
        $this->get(route('published.index', ['periodo' => '20263', 'campus' => 'C2']))->assertOk()->assertSee('Agosto–Diciembre 2026');
    });

    it('busca docentes sin acentos y enlaza a su vista', function () {
        publishedPagesIndex()
            ->set('teacherSearch', 'rios')
            ->assertSee('Laura Ríos Medina')
            ->assertSeeHtml(route('published.teacher', ['periodo' => '20271', 'campus' => 'C2', 'docente' => $this->laura['id']]))
            ->set('teacherSearch', 'zzz')
            ->assertSee('Ningún docente coincide');
    });

    it('busca salones y enlaza a su vista con el edificio', function () {
        publishedPagesIndex()
            ->set('roomSearch', 'b-12')
            ->assertSee('Edificio B')
            ->assertSeeHtml(route('published.room', ['periodo' => '20271', 'campus' => 'C2', 'salon' => $this->room['id']]));
    });

    it('ofrece una select de retícula cuando la carrera tiene varias, la de código mayor primero', function () {
        $newer = ['id' => (string) Str::uuid7(), 'code' => 'ISIC-2024-300'];
        publishedPagesSnapshot($this->term, $this->c2, 'EE', [publishedPagesGroup('EEX-0001', 'Circuitos', 'A', ['placements' => [LoadSnapshotFactory::placement(['career' => $this->career, 'study_plan' => $newer, 'semester' => 1])]])]);

        $test = publishedPagesIndex();
        $test->assertSeeHtml('aria-label="Retícula de ISC"');
        expect(Str::before($test->html(), 'ISIC-2010-224'))->toContain('ISIC-2024-300');
        $test->set('plans.ISC', 'ISIC-2010-224')->assertSeeHtml('reticula=ISIC-2010-224&amp;semestre=3');
    });

    it('sin salones en el perfil no ofrece la búsqueda por salón', function () {
        $this->c2->update(['settings' => [...CampusProfile::DEFAULTS, 'uses_rooms' => false]]);
        publishedPagesSnapshot($this->term, $this->c2, 'EE', [publishedPagesGroup('EEX-0001', 'Circuitos', 'A', ['placements' => [($this->placement)()]])], '2026-12-13 10:00');

        $this->get($this->indexUrl)->assertOk()->assertDontSee('Por salón');
    });
});

describe('semestre', function () {
    it('muestra una ficha por grupo del snapshot, agrupadas por materia, sin sesión', function () {
        $this->get(($this->semesterUrl)(['reticula' => 'ISIC-2010-224', 'semestre' => 3]))
            ->assertOk()
            ->assertSee('Ingeniería en Sistemas Computacionales')
            ->assertSee('Semestre 3')
            ->assertSee('2 materias · 2 grupos')
            ->assertSee('ACF-0904')->assertSee('Cálculo Vectorial')->assertSee('5 créditos')->assertSee('1 grupo')
            ->assertSee('AED-1026')
            ->assertSee('Actualizado el 12 de diciembre de 2026')
            ->assertDontSee('Solo del Campus I');
    });

    it('pone por asignar al grupo sin docente y a la sesión presencial sin salón, y virtual a la virtual', function () {
        $html = $this->get(($this->semesterUrl)(['reticula' => 'ISIC-2010-224', 'semestre' => 3]))->assertOk()->getContent();

        expect($html)->toContain('Por asignar')->toContain('Virtual')->toContain('Laura Ríos Medina')
            ->and(substr_count($html, 'Por asignar'))->toBeGreaterThanOrEqual(2)
            ->and($html)->not->toContain('alert-warning')->not->toContain('exclamation-triangle');
    });

    it('pinta la mini-semana con las horas, el salón y el enlace del docente y del salón', function () {
        $this->get(($this->semesterUrl)(['reticula' => 'ISIC-2010-224', 'semestre' => 3]))
            ->assertSeeInOrder(['7:00', '9:00', 'B-12'])
            ->assertSeeHtml(route('published.teacher', ['periodo' => '20271', 'campus' => 'C2', 'docente' => $this->laura['id']]))
            ->assertSeeHtml(route('published.room', ['periodo' => '20271', 'campus' => 'C2', 'salon' => $this->room['id']]))
            ->assertSee('Sin icono: teoría');
    });

    it('un semestre sin grupos lo dice y una carrera no publicada es 404', function () {
        $this->get(($this->semesterUrl)(['semestre' => 9]))->assertOk()->assertSee('No hay grupos publicados para este semestre.');
        $this->get(route('published.semester', ['periodo' => '20271', 'campus' => 'C2', 'carrera' => 'XXX']))->assertNotFound();
        $this->get(route('published.semester', ['periodo' => '20271', 'campus' => 'C1', 'carrera' => 'ISC']))->assertOk(); // C1 sí la publicó (placement de ejemplo)
    });

    it('cambiar la carrera lleva a su URL y cambiar el semestre actualiza el estado de la URL', function () {
        publishedPagesSnapshot($this->term, $this->c2, 'EE', [publishedPagesGroup('EEX-0001', 'Circuitos', 'A', ['placements' => [LoadSnapshotFactory::placement(['career' => ['id' => (string) Str::uuid7(), 'code' => 'IEL', 'name' => 'Ingeniería Eléctrica'], 'study_plan' => ['id' => (string) Str::uuid7(), 'code' => 'IELE-2010-211'], 'semester' => 2])]])]);

        Livewire::test('academic-load::pages.published.semester', ['periodo' => '20271', 'campus' => 'C2', 'carrera' => 'ISC'])
            ->assertSet('plan', 'ISIC-2010-224')
            ->assertSet('semester', 3)
            ->set('carrera', 'IEL')
            ->assertRedirect(route('published.semester', ['periodo' => '20271', 'campus' => 'C2', 'carrera' => 'IEL', 'reticula' => 'IELE-2010-211', 'semestre' => 2]));
    });

    it('sin uses_time_blocks la mini-semana dice sin horario fijo', function () {
        $this->c2->update(['settings' => [...CampusProfile::DEFAULTS, 'uses_time_blocks' => false]]);
        publishedPagesSnapshot($this->term, $this->c2, 'EE', [publishedPagesGroup('EEX-0001', 'Circuitos', 'A', ['placements' => [($this->placement)()], 'sessions' => []])], '2026-12-13 10:00');

        $this->get(($this->semesterUrl)(['semestre' => 3]))->assertOk()->assertSee('Sin horario fijo');
    });
});

describe('docente y salón', function () {
    it('el docente muestra sus grupos, las horas a la semana y su semana sin sesión', function () {
        $this->get(route('published.teacher', ['periodo' => '20271', 'campus' => 'C2', 'docente' => $this->laura['id']]))
            ->assertOk()
            ->assertSee('Laura Ríos Medina')
            ->assertSee('1 grupo')
            ->assertSeeText('3 horas a la semana')
            ->assertSee('ACF-0904 · B')
            ->assertSee('Cálculo Vectorial')
            ->assertSee('Actualizado el 12 de diciembre de 2026')
            ->assertSee('ISC')
            ->assertDontSee('AED-1026');
    });

    it('el salón muestra sus grupos con el docente y el edificio', function () {
        $this->get(route('published.room', ['periodo' => '20271', 'campus' => 'C2', 'salon' => $this->room['id']]))
            ->assertOk()
            ->assertSee('B-12 · Aula B-12')
            ->assertSee('Edificio B')
            ->assertSeeText('2 horas a la semana')
            ->assertSee('ACF-0904 · B')
            ->assertSee('Laura Ríos Medina');
    });

    it('en celular elige el día con ?dia= y lista sus horas', function () {
        Livewire::withQueryParams(['dia' => 3])->test('academic-load::pages.published.teacher', ['periodo' => '20271', 'campus' => 'C2', 'docente' => $this->laura['id']])
            ->assertSee('8:00')
            ->assertSeeHtml('aria-pressed="true" aria-label="Miércoles"')
            ->call('$set', 'day', 5)
            ->assertSee('Sin clases el viernes.');
    });

    it('lleva las sesiones que se empalman en carriles', function () {
        publishedPagesSnapshot($this->term, $this->c2, 'EE', [publishedPagesGroup('EEX-0001', 'Circuitos', 'A', ['teacher' => $this->laura, 'placements' => [($this->placement)()], 'sessions' => [LoadSnapshotFactory::session(['day' => 1, 'starts_at' => '08:00', 'ends_at' => '10:00', 'room' => null])]])], '2026-12-13 10:00');

        $this->get(route('published.teacher', ['periodo' => '20271', 'campus' => 'C2', 'docente' => $this->laura['id']]))->assertOk()->assertSeeHtml('--lanes: 2');
    });

    it('da 404 con un uuid mal formado, uno que no es de lo publicado o la vista de salón sin salones', function () {
        $this->get('/horarios/20271/C2/docente/no-es-uuid')->assertNotFound();
        $this->get('/horarios/20271/C2/salon/123')->assertNotFound();
        $this->get(route('published.teacher', ['periodo' => '20271', 'campus' => 'C2', 'docente' => (string) Str::uuid7()]))->assertNotFound();
        $this->get(route('published.room', ['periodo' => '20271', 'campus' => 'C2', 'salon' => (string) Str::uuid7()]))->assertNotFound();

        $this->c2->update(['settings' => [...CampusProfile::DEFAULTS, 'uses_rooms' => false]]);
        publishedPagesSnapshot($this->term, $this->c2, 'EE', [publishedPagesGroup('EEX-0001', 'Circuitos', 'A', ['placements' => [($this->placement)()]])], '2026-12-13 10:00');
        $this->get(route('published.room', ['periodo' => '20271', 'campus' => 'C2', 'salon' => $this->room['id']]))->assertNotFound();
    });

    it('da 404 con un periodo o un campus que no existen', function () {
        $this->get('/horarios/29999/C2/docente/'.$this->laura['id'])->assertNotFound();
        $this->get('/horarios/20271/XX/docente/'.$this->laura['id'])->assertNotFound();
        $this->get('/horarios/29999/C2/carrera/ISC')->assertNotFound();
    });
});

describe('lo que el público no ve', function () {
    it('no enseña cupos, versiones, RFC, justificaciones ni estados en ninguna página', function () {
        $urls = [
            $this->indexUrl,
            ($this->semesterUrl)(['reticula' => 'ISIC-2010-224', 'semestre' => 3]),
            route('published.teacher', ['periodo' => '20271', 'campus' => 'C2', 'docente' => $this->laura['id']]),
            route('published.room', ['periodo' => '20271', 'campus' => 'C2', 'salon' => $this->room['id']]),
        ];

        foreach ($urls as $url) {
            $html = $this->get($url)->assertOk()->getContent();

            foreach (['RIML800101AB1', 'fuerza mayor', 'Capacidad', 'capacidad', 'cupo', 'versión', 'Versión', 'Publicada', 'Sin publicar', 'incompleto'] as $secret) {
                expect($html)->not->toContain($secret);
            }
        }
    });

    it('no avisa lo que falta por publicar aunque haya cargas sin publicar', function () {
        AcademicLoad::factory()->for($this->term)->for($this->c2)->for(Department::factory()->create(['code' => 'II', 'name' => 'Industrial']))->inStatus(LoadStatus::Staffing)->create();

        $this->get($this->indexUrl)->assertOk()->assertDontSee('Sin publicar')->assertDontSee('Industrial');
    });

    it('al personal con permiso en el campus le dice qué cargas faltan por publicar y a otro rol no', function () {
        AcademicLoad::factory()->for($this->term)->for($this->c2)->for(Department::factory()->create(['code' => 'II', 'name' => 'Industrial']))->inStatus(LoadStatus::Staffing)->create();

        $head = User::factory()->withRole(Role::JefeDepartamento, ['campus_id' => $this->c2->id, 'department_id' => Department::query()->firstWhere('code', 'II')->id])->create();
        $this->actingAs($head)->get($this->indexUrl)->assertOk()->assertSee('Sin publicar')->assertSee('II · Industrial · Asignación');

        $coordinator = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => Career::factory()->create()->id])->create();
        $this->actingAs($coordinator)->get($this->indexUrl)->assertOk()->assertDontSee('Sin publicar');

        $this->actingAs(User::factory()->withRole(Role::Subdireccion)->create())->get($this->indexUrl)->assertOk()->assertSee('II · Industrial · Asignación');

        $otherCampus = User::factory()->withRole(Role::JefeDepartamento, ['campus_id' => $this->c1->id, 'department_id' => Department::factory()->create()->id])->create();
        $this->actingAs($otherCampus)->get($this->indexUrl)->assertOk()->assertDontSee('Sin publicar');
    });

    it('si no falta ninguna carga no sale la franja ni con permiso', function () {
        $head = User::factory()->admin()->create();

        $this->actingAs($head)->get($this->indexUrl)->assertOk()->assertDontSee('Sin publicar');
    });
});

describe('banda y límite', function () {
    it('el invitado ve «Iniciar sesión» y la banda lleva la liebre al inicio de la consulta', function () {
        $this->get(route('published.index'))
            ->assertOk()
            ->assertSee('Iniciar sesión')
            ->assertSeeHtml('href="'.route('login').'"')
            ->assertSeeHtml('href="'.route('published.index').'"')
            ->assertSee('Horarios oficiales del Instituto Tecnológico de Ciudad Juárez')
            ->assertDontSee('Ir a Nexus');
    });

    it('con sesión muestra el nombre y «Ir a Nexus»', function () {
        $user = User::factory()->create(['name' => 'Ana Pérez']);

        $this->actingAs($user)->get(route('published.index'))->assertOk()->assertSee('Ana Pérez')->assertSee('Ir a Nexus')->assertDontSee('Iniciar sesión');
    });

    it('cambiar el periodo o el campus lleva a /horarios con ese contexto', function () {
        $old = Term::factory()->create(['code' => '20263', 'name' => 'Agosto–Diciembre 2026', 'starts_on' => '2026-08-17']);
        publishedPagesSnapshot($old, $this->c1, 'CB', [publishedPagesGroup('OLD-0001', 'Materia vieja', 'A', ['placements' => [($this->placement)(1)]])], '2026-06-01 08:00');

        Livewire::test('academic-load::components.published-band', ['termCode' => '20271', 'campusCode' => 'C2'])
            ->assertSet('campus', 'C2')
            ->set('campus', 'C1')
            ->assertRedirect(route('published.index', ['periodo' => '20271', 'campus' => 'C1']))
            ->set('periodo', '20263')
            ->assertSet('campus', 'C1')
            ->assertRedirect(route('published.index', ['periodo' => '20263', 'campus' => 'C1']));
    });

    it('la ruta pública lleva el limitador published-schedule de 240 por minuto', function () {
        foreach (['published.index', 'published.semester', 'published.teacher', 'published.room'] as $name) {
            expect(app('router')->getRoutes()->getByName($name)->gatherMiddleware())->toContain('throttle:published-schedule')->not->toContain('auth');
        }

        expect(RateLimiter::limiter('published-schedule')(request())->maxAttempts)->toBe(240);
    });
});
