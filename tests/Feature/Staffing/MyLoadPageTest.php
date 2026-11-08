<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Enums\SettingScope;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Setting;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherExternalLoad;
use App\Modules\Faculty\Models\TeacherPosition;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Staffing\Queries\MyLoadQuery;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
| «Mi carga» (tarea 3.10): los grupos del docente por día, con huecos, traslados entre campus y la cuenta de horas.
*/

beforeEach(function () {
    $this->term = Term::factory()->current()->create(['code' => '20271', 'name' => 'Ene-Jun 2027']);
    $this->c2 = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    $this->c1 = Campus::factory()->create(['code' => 'C1', 'name' => 'Campus I']);
    $this->sc = Department::factory()->create(['code' => 'SC']);
    $this->me = Teacher::factory()->adscribedTo($this->sc)->create();
    $this->user = User::factory()->withRole(Role::Docente)->create();
    $this->me->update(['user_id' => $this->user->id]);
});

function loadGroup(Campus $campus, string $code, string $name, array $sessions, array $attrs = []): Group
{
    $subject = Subject::factory()->create(['department_id' => test()->sc->id, 'name' => $name, 'theory_hours' => 4, 'practice_hours' => 0]);
    $group = Group::factory()->create(['term_id' => test()->term->id, 'campus_id' => $campus->id, 'subject_id' => $subject->id, 'code' => $code, 'teacher_id' => test()->me->id, ...$attrs]);

    foreach ($sessions as [$day, $start, $end]) {
        GroupSession::factory()->on($day, $start, $end)->create(['group_id' => $group->id]);
    }

    return $group;
}

it('muestra sus grupos por día, de todos los campus, con salón y campus', function () {
    loadGroup($this->c2, '1A', 'Fundamentos de Programación', [[2, '11:00', '13:00'], [5, '11:00', '13:00']]);
    loadGroup($this->c1, '3B', 'Estructura de Datos', [[1, '07:00', '09:00']]);
    loadGroup($this->c2, 'CAN', 'Materia cancelada', [[3, '07:00', '09:00']], ['status' => 'cancelled']);
    Group::factory()->create(['term_id' => $this->term->id, 'teacher_id' => Teacher::factory()->create()->id]);

    Livewire::actingAs($this->user)->test('staffing::pages.my-load')
        ->assertOk()
        ->assertSeeInOrder(['Lunes', 'Estructura de Datos', '07:00–09:00', '3B', 'C1'])
        ->assertSeeInOrder(['Martes', 'Fundamentos de Programación', '11:00–13:00', '1A', 'C2'])
        ->assertSee('Viernes')
        ->assertDontSee('Materia cancelada')
        ->assertDontSeeHtml('data-testid="day-6"')
        ->assertSeeHtml('data-testid="my-load-requests-slot"');
});

it('el sábado aparece solo si tiene sesiones ese día', function () {
    loadGroup($this->c2, '1A', 'Fundamentos', [[6, '08:00', '10:00']]);

    Livewire::actingAs($this->user)->test('staffing::pages.my-load')->assertSeeHtml('data-testid="day-6"')->assertSee('Sábado');
});

it('marca el hueco entre dos sesiones del mismo día y el traslado entre campus', function () {
    loadGroup($this->c2, '1A', 'Fundamentos', [[1, '07:00', '09:00'], [3, '07:00', '09:00']]);
    loadGroup($this->c2, '1B', 'Cálculo', [[1, '11:00', '13:00']]);
    loadGroup($this->c1, '2A', 'Redes', [[1, '13:00', '15:00'], [3, '09:00', '11:00']]);

    Livewire::actingAs($this->user)->test('staffing::pages.my-load')
        ->assertSeeHtml('data-testid="gap"')
        ->assertSee('Hueco de 2 h')
        ->assertSeeInOrder(['Traslado C2 → C1 ·', '0 min'])
        ->assertSeeHtml('data-testid="transfer"');
});

it('SC-09: el traslado entre campus con menos de staffing.min_campus_travel_minutes libres es un aviso; con más, neutro y con su tiempo', function () {
    loadGroup($this->c2, '1A', 'Fundamentos', [[1, '07:00', '09:00'], [2, '07:00', '09:00']]);
    loadGroup($this->c1, '2A', 'Redes', [[1, '09:30', '10:30'], [2, '12:00', '13:00']]);

    $page = Livewire::actingAs($this->user)->test('staffing::pages.my-load');
    $transfers = domOf($page)->querySelectorAll('[data-testid=transfer]');

    expect($transfers)->toHaveCount(2)
        ->and(trim(preg_replace('/\s+/', ' ', $transfers->item(0)->textContent)))->toBe('Traslado C2 → C1 · 30 min')
        ->and($transfers->item(0)->getAttribute('data-short'))->toBe('true')
        ->and(trim(preg_replace('/\s+/', ' ', $transfers->item(1)->textContent)))->toBe('Traslado C2 → C1 · 3 h')
        ->and($transfers->item(1)->getAttribute('data-short'))->toBe('false');

    Setting::query()->create(['scope' => SettingScope::Global, 'scope_id' => null, 'key' => 'staffing.min_campus_travel_minutes', 'value' => 20]);

    expect(domOf(Livewire::actingAs($this->user)->test('staffing::pages.my-load'))->querySelector('[data-testid=transfer]')->getAttribute('data-short'))->toBe('false');
});

it('sin huecos ni traslados no los marca', function () {
    loadGroup($this->c2, '1A', 'Fundamentos', [[1, '07:00', '09:00']]);
    loadGroup($this->c2, '1B', 'Cálculo', [[1, '09:00', '11:00']]);

    Livewire::actingAs($this->user)->test('staffing::pages.my-load')->assertDontSeeHtml('data-testid="gap"')->assertDontSeeHtml('data-testid="transfer"');
});

it('muestra la ecuación de horas: base, externas con su fuente, asignadas y restantes (HC-08)', function () {
    loadGroup($this->c2, '1A', 'Fundamentos', [[1, '07:00', '09:00']]);
    loadGroup($this->c1, '1B', 'Cálculo', [[2, '07:00', '09:00']]);
    TeacherPosition::factory()->asignatura(20)->create(['teacher_id' => $this->me->id]);
    TeacherExternalLoad::query()->create(['teacher_id' => $this->me->id, 'term_id' => $this->term->id, 'source' => 'Colegio Bachilleres', 'hours' => 3]);

    Livewire::actingAs($this->user)->test('staffing::pages.my-load')
        ->assertSeeHtml('data-testid="hours-base"')
        ->assertSeeInOrder(['Horas base', '20', 'Externas', '3', 'Colegio Bachilleres', 'Asignadas', '8', '2 grupos', 'Restantes', '9']);
});

it('un docente sin grupos igual ve base y externas con asignadas en 0', function () {
    TeacherPosition::factory()->asignatura(12)->create(['teacher_id' => $this->me->id]);
    TeacherExternalLoad::query()->create(['teacher_id' => $this->me->id, 'term_id' => $this->term->id, 'source' => 'UACJ', 'hours' => 2]);

    $data = app(MyLoadQuery::class)->forTeacher($this->me, $this->term);

    expect($data['hours'])->toMatchArray(['base' => 12, 'external' => 2, 'assigned' => 0, 'remaining' => 10]);

    Livewire::actingAs($this->user)->test('staffing::pages.my-load')->assertSee('UACJ')->assertSee('Todavía no tienes grupos asignados');
});

it('mantiene un número constante de consultas sin importar cuántos grupos tiene', function () {
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(MyLoadQuery::class)->forTeacher($this->me, $this->term);
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    loadGroup($this->c2, 'G0', 'Materia 0', [[1, '07:00', '09:00']]);
    $few = $count();

    foreach (range(1, 6) as $n) {
        loadGroup($n % 2 === 0 ? $this->c1 : $this->c2, 'G'.$n, 'Materia '.$n, [[1 + $n % 5, sprintf('%02d:00', 8 + $n), sprintf('%02d:00', 9 + $n)]]);
    }

    expect($count())->toBe($few)->and($few)->toBeLessThanOrEqual(25);
});

describe('alcance', function () {
    it('un usuario sin puesto de docente recibe 403', function () {
        Livewire::actingAs(User::factory()->create())->test('staffing::pages.my-load')->assertForbidden();
    });

    it('un jefe o coordinador sin ficha de docente recibe 403', function () {
        $jefe = User::factory()->withRole(Role::JefeDepartamento, ['campus_id' => $this->c2->id, 'department_id' => $this->sc->id])->create();
        $coordinador = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => Career::factory()->create()->id])->create();
        loadGroup($this->c2, '1A', 'Fundamentos', [[1, '07:00', '09:00']]);

        Livewire::actingAs($jefe)->test('staffing::pages.my-load')->assertForbidden();
        Livewire::actingAs($coordinador)->test('staffing::pages.my-load')->assertForbidden();
    });

    it('un docente sin ficha ligada ve un aviso y ningún grupo', function () {
        $loose = User::factory()->withRole(Role::Docente)->create();
        loadGroup($this->c2, '1A', 'Fundamentos', [[1, '07:00', '09:00']]);

        Livewire::actingAs($loose)->test('staffing::pages.my-load')->assertOk()->assertSee('no está ligado a una ficha de docente')->assertDontSee('Fundamentos');
    });

    it('un docente no ve los grupos de otro', function () {
        $other = Teacher::factory()->create();
        $user = User::factory()->withRole(Role::Docente)->create();
        $other->update(['user_id' => $user->id]);
        loadGroup($this->c2, '1A', 'Fundamentos de mi colega', [[1, '07:00', '09:00']]);

        Livewire::actingAs($user)->test('staffing::pages.my-load')->assertDontSee('Fundamentos de mi colega')->assertSee('Todavía no tienes grupos');
    });

    it('la ruta pide sesión', function () {
        $this->get('/mi-espacio/mi-carga')->assertRedirect('/login');
        $this->actingAs($this->user)->get('/mi-espacio/mi-carga')->assertOk()->assertSee('Mi carga');
    });
});
