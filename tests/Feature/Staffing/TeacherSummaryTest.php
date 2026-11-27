<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Actions\UpdateSetting;
use App\Modules\Core\Authorization\AcademicLoadVisibility;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Core\Settings\SettingRegistry;
use App\Modules\Faculty\Enums\AvailabilityKind;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherAvailability;
use App\Modules\Faculty\Models\TeacherPosition;
use App\Modules\Faculty\Models\TeacherSubjectQualification;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Staffing\Enums\SelectionStatus;
use App\Modules\Staffing\Models\Selection;
use App\Modules\Staffing\Models\SelectionEntry;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
| El tablero «Resumen» del docente (tarea 1.85, IN-01 e IN-03): Core lo pinta en `/resumen` con HomePanels para el perfil
| Docente; lo que dice sale de TeacherSummaryQuery (probada aparte) y aquí se revisa lo que ve el docente, el alcance
| (nunca lo de otro docente) y cuándo aparece cada caja.
*/

const TSUM_TZ = 'America/Ciudad_Juarez';

beforeEach(function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-11-26 12:10', TSUM_TZ));
    $this->planning = Term::factory()->current()->create(['code' => '20271', 'name' => 'Ene-Jun 2027', 'starts_on' => '2027-01-18', 'ends_on' => '2027-06-11']);
    $this->running = Term::factory()->create(['code' => '20263', 'name' => 'Ago-Dic 2026', 'starts_on' => '2026-08-17', 'ends_on' => '2026-12-11']);
    $this->campus = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    $this->dept = Department::factory()->create(['code' => 'SC', 'name' => 'Sistemas y Computación']);
    $this->teacher = Teacher::factory()->adscribedTo($this->dept)->create(['name' => 'Laura Pérez']);
    TeacherPosition::factory()->tiempoCompleto()->create(['teacher_id' => $this->teacher->id]);
    $this->user = User::factory()->withRole(Role::Docente)->create(['username' => 'PEAL800101XX1']);
    $this->teacher->update(['user_id' => $this->user->id]);
});

afterEach(fn () => Carbon::setTestNow());

function tsumGroup(Term $term, string $code, array $sessions, ?Teacher $teacher = null, array $subject = []): Group
{
    $group = Group::factory()->create([
        'term_id' => $term->id,
        'campus_id' => test()->campus->id,
        'subject_id' => Subject::factory()->create(['department_id' => test()->dept->id, 'theory_hours' => 4, 'practice_hours' => 0, ...$subject])->id,
        'code' => $code,
        'teacher_id' => ($teacher ?? test()->teacher)->id,
    ]);

    foreach ($sessions as [$day, $from, $to]) {
        GroupSession::factory()->on($day, $from, $to)->create(['group_id' => $group->id]);
    }

    return $group;
}

function tsumPanel(User $user): Testable
{
    actingAsRole($user, Role::Docente);

    return Livewire::test('staffing::components.teacher-summary');
}

it('IN-03: el docente ve su ficha, las cifras, Hoy, Atención, su semana, el periodo en planeación y su acceso', function () {
    tsumGroup($this->running, 'A', [[4, '07:00', '09:00']], null, ['name' => 'Programación Estructurada']);
    tsumGroup($this->running, 'C', [[4, '16:00', '18:00']], null, ['name' => 'Taller de Ética']);
    tsumGroup($this->planning, 'D', [[1, '10:00', '12:00']], null, ['name' => 'Estructura de Datos']);

    tsumPanel($this->user)
        ->assertSeeHtml('data-testid="teacher-summary"')
        ->assertSee('Laura Pérez')
        ->assertSee('Sistemas y Computación')
        ->assertSeeHtml('data-testid="summary-tiles"')
        ->assertSeeHtml('data-testid="tile-today"')
        ->assertSee('la siguiente a las 16:00')
        ->assertSee('Programación Estructurada')
        ->assertSee('Siguiente · en 3 h 50 min')
        ->assertSeeHtml('data-testid="summary-attention"')
        ->assertSeeHtml('data-testid="summary-week"')
        ->assertSee('Tu semana')
        ->assertSeeHtml('data-testid="summary-planning"')
        ->assertSee('En preparación')
        ->assertSee('Estructura de Datos')
        ->assertSee('Tu experiencia académica')
        ->assertSee('Disponible en todo el horario.')
        ->assertSee('Si algo cambió, avísale a tu jefe de departamento.')
        ->assertSee('Tu acceso')
        ->assertSee('PEAL800101XX1')
        ->assertSee('Cambiar contraseña');
});

it('IN-03: en /resumen el encabezado lleva el subtítulo del docente y no el bloque de siempre', function () {
    actingAsRole($this->user, Role::Docente);

    $this->get('/resumen')
        ->assertOk()
        ->assertSee('Tus clases de hoy, tu carga y lo que necesita tu atención.')
        ->assertSee('data-testid="teacher-summary"', false)
        ->assertDontSee('role-block', false);
});

it('sin ficha de docente: el aviso y «Tu acceso»', function () {
    $this->teacher->update(['user_id' => null]);

    tsumPanel($this->user)
        ->assertSee('Todavía no encontramos tu ficha de docente.')
        ->assertSee('Pídele a tu jefe de departamento que la revise.')
        ->assertSee('Tu acceso')
        ->assertSee('PEAL800101XX1')
        ->assertDontSeeHtml('data-testid="summary-tiles"');
});

it('Review Focus 3: sin periodos no se rompe y dice que hoy no hay clases', function () {
    Term::query()->delete();

    tsumPanel($this->user)
        ->assertSee('Laura Pérez')
        ->assertSee('Nada que atender')
        ->assertDontSeeHtml('data-testid="summary-planning"')
        ->assertDontSeeHtml('data-testid="summary-week"');
});

it('Review Focus 4: nunca aparece nada de otro docente (grupos, materias, rangos ni turno)', function () {
    $other = Teacher::factory()->adscribedTo($this->dept)->create(['name' => 'Óscar Nájera']);
    tsumGroup($this->planning, 'OTRO', [[1, '07:00', '09:00']], $other, ['name' => 'Materia Ajena Uno']);
    tsumGroup($this->running, 'OTRO2', [[4, '12:00', '13:00']], $other, ['name' => 'Materia Ajena Dos']);
    TeacherSubjectQualification::factory()->for($other)->for(Subject::factory()->create(['name' => 'Experiencia Ajena']))->create();
    TeacherAvailability::factory()->create(['teacher_id' => $other->id, 'term_id' => $this->planning->id, 'day' => 2, 'starts_at' => '07:00', 'ends_at' => '09:00', 'kind' => AvailabilityKind::Unavailable]);
    $selection = Selection::factory()->create(['term_id' => $this->planning->id, 'department_id' => $this->dept->id, 'status' => SelectionStatus::Published]);
    SelectionEntry::factory()->create(['selection_id' => $selection->id, 'teacher_id' => $other->id, 'position' => 3, 'slot_starts_at' => '2026-12-08 10:30', 'slot_ends_at' => '2026-12-08 11:00']);

    tsumPanel($this->user)
        ->assertDontSee('Materia Ajena Uno')
        ->assertDontSee('Materia Ajena Dos')
        ->assertDontSee('Experiencia Ajena')
        ->assertDontSee('Óscar Nájera')
        ->assertDontSee('Tu turno')
        ->assertSee('Disponible en todo el horario.')
        ->assertSee('Sin experiencia académica registrada.');
});

it('la disponibilidad guardada son excepciones y se listan por día', function () {
    TeacherAvailability::factory()->create(['teacher_id' => $this->teacher->id, 'term_id' => $this->planning->id, 'day' => 1, 'starts_at' => '07:00', 'ends_at' => '09:00', 'kind' => AvailabilityKind::Unavailable]);

    tsumPanel($this->user)
        ->assertSee('7:00–9:00')
        ->assertSee('No disponible')
        ->assertDontSee('Disponible en todo el horario.');
});

it('la experiencia muestra 6 y «y N más» despliega el resto en el lugar', function () {
    TeacherSubjectQualification::factory()->count(9)->for($this->teacher)->create();

    tsumPanel($this->user)
        ->assertSeeHtml('<span class="font-mono">9</span> materias')
        ->assertSee('y 3 más')
        ->assertSeeHtml('data-testid="experience-more"');
});

it('con selección viva sale el turno en Atención; sin ella, no', function () {
    tsumGroup($this->planning, 'A', [[1, '07:00', '09:00']]);

    tsumPanel($this->user)->assertDontSee('Tu turno');

    $selection = Selection::factory()->create(['term_id' => $this->planning->id, 'department_id' => $this->dept->id, 'status' => SelectionStatus::Published]);
    SelectionEntry::factory()->create(['selection_id' => $selection->id, 'teacher_id' => $this->teacher->id, 'position' => 14, 'slot_starts_at' => '2026-12-08 10:30', 'slot_ends_at' => '2026-12-08 11:00']);

    tsumPanel($this->user)
        ->assertSee('Tu turno: martes 8 de diciembre, 10:30–11:00')
        ->assertSee('Lugar 14 de 1')
        ->assertSee('Ir a la oferta')
        ->assertSee('Selección en curso');
});

it('MN-05: con la carga oculta no sale el periodo en planeación', function () {
    tsumGroup($this->planning, 'A', [[1, '07:00', '09:00']], null, ['name' => 'Estructura de Datos']);
    app(UpdateSetting::class)->handle(app(SettingRegistry::class)->get(AcademicLoadVisibility::SETTING), false);

    tsumPanel($this->user)
        ->assertDontSeeHtml('data-testid="summary-planning"')
        ->assertDontSee('Estructura de Datos')
        ->assertDontSeeHtml('data-testid="tile-remaining"');
});

it('un jefe que también es docente ve el tablero con el perfil de docente y lo de siempre con el de jefe', function () {
    $boss = User::factory()
        ->withRole(Role::JefeDepartamento, ['campus_id' => $this->campus->id, 'department_id' => $this->dept->id])
        ->withRole(Role::Docente)
        ->create(['name' => 'Rosa Jefa', 'username' => 'JEFR800101XX1']);
    $this->teacher->update(['user_id' => $boss->id]);

    actingAsRole($boss, Role::JefeDepartamento);
    Livewire::test('core::pages.home')
        ->assertDontSeeHtml('data-testid="teacher-summary"')
        ->assertSee('Validas los catálogos');

    actingAsRole($boss, Role::Docente);
    Livewire::test('core::pages.home')
        ->assertSeeHtml('data-testid="teacher-summary"')
        ->assertSee('Laura Pérez')
        ->assertDontSee('Validas los catálogos');
});

it('el alumno sigue viendo su tablero y no el del docente', function () {
    $this->actingAs(User::factory()->withRole(Role::Alumno)->create());

    $this->get('/resumen')
        ->assertOk()
        ->assertSee('data-testid="student-summary"', false)
        ->assertDontSee('data-testid="teacher-summary"', false);
});
