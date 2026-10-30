<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Core\Queries\ScopedContext;
use App\Modules\Faculty\Enums\AvailabilityKind;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherAvailability;
use App\Modules\Faculty\Models\TeacherPosition;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/*
| Editor de disponibilidad (`/faculty/teachers/{teacher}/availability`, faculty::pages.teacher-availability; DESIGN.md,
| "Cuadrícula día x hora"): página hija de la ficha con la cuadrícula del periodo activo. La cuadrícula usa el perfil
| del campus activo o, sin él, el presencial por omisión (S12, supuesto por confirmar). Sin periodo activo pide
| elegirlo. Lo abren y guardan quienes administran al docente (TeacherPolicy::manageAvailability); cada método vuelve
| a autorizar. «Copiar del periodo anterior» trae la del semestre anterior, sin verano, sin guardarla.
*/

beforeEach(function () {
    $this->sc = Department::factory()->create(['code' => 'SC', 'name' => 'Sistemas y Computación']);
    $this->ii = Department::factory()->create(['code' => 'II', 'name' => 'Ingeniería Industrial']);
    $this->term = Term::factory()->current()->create(['code' => '20271', 'name' => 'Ene-Jun 2027', 'starts_on' => '2027-01-25', 'ends_on' => '2027-06-11']);
    $this->previous = Term::factory()->create(['code' => '20263', 'name' => 'Ago-Dic 2026', 'starts_on' => '2026-08-24', 'ends_on' => '2026-12-11']);
    Term::factory()->create(['code' => '20262', 'name' => 'Verano 2026', 'starts_on' => '2026-06-22', 'ends_on' => '2026-08-07']);

    $this->ana = Teacher::factory()->adscribedTo($this->sc)->create(['employee_number' => '1000001', 'name' => 'Ana Pérez']);
    TeacherPosition::factory()->for($this->ana)->tiempoCompleto()->create();
    $this->beto = Teacher::factory()->adscribedTo($this->ii)->create(['employee_number' => '1000002', 'name' => 'Beto Ruiz']);

    $this->admin = User::factory()->admin()->create();
    $this->jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->sc->id])->create();
    $this->jefeIi = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->ii->id])->create();
    $this->activityBase = (int) Activity::max('id');
});

function availabilityEditor(User $user, Teacher $teacher): Testable
{
    return Livewire::actingAs($user)->test('faculty::pages.teacher-availability', ['teacher' => $teacher->id]);
}

/**
 * @return list<string>
 */
function savedAvailability(Teacher $teacher, Term $term): array
{
    return TeacherAvailability::query()->where('teacher_id', $teacher->id)->where('term_id', $term->id)
        ->orderBy('day')->orderBy('starts_at')->get()
        ->map(fn (TeacherAvailability $row): string => $row->day.' '.substr($row->starts_at, 0, 5).'-'.substr($row->ends_at, 0, 5).' '.$row->kind->value)
        ->all();
}

it('muestra el docente, el periodo activo, el horario y la cuadrícula con su resumen', function () {
    TeacherAvailability::factory()->for($this->ana)->for($this->term)->create(['day' => 1, 'starts_at' => '07:00', 'ends_at' => '09:00']);

    $this->actingAs($this->jefe)->get(route('faculty.teachers.availability', $this->ana->id))
        ->assertOk()
        ->assertSeeInOrder(['Ana Pérez', 'Disponibilidad', 'Ene-Jun 2027', '1000001', 'lunes a viernes, 7:00 a 21:00', 'una hora'])
        ->assertSeeInOrder(['Disponible', '68', 'h de 70', 'Preferido', 'No disponible', '2', 'Horas base', '40'])
        ->assertSeeInOrder(['No disponible', 'Preferido', 'Borrar'])
        ->assertSeeInOrder(['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes'])
        ->assertSee('Copiar de Ago-Dic 2026')
        ->assertSee('Guardar disponibilidad');
});

it('el enlace de regreso lleva a la pestaña Disponibilidad de la ficha', function () {
    availabilityEditor($this->jefe, $this->ana)
        ->assertSeeHtml('href="'.route('faculty.teachers.show', ['teacher' => $this->ana->id, 'tab' => 'disponibilidad']).'"');
});

describe('perfil (S12)', function () {
    it('usa el del campus activo', function () {
        $ead = Campus::factory()->create(['code' => 'EAD', 'name' => 'Educación a Distancia', 'settings' => ['days' => [1, 2, 3, 4, 5, 6], 'day_start' => '08:00', 'day_end' => '14:00']]);
        Campus::factory()->create(['code' => 'C2']);
        session([ScopedContext::ACTIVE_CAMPUS => $ead->id]);

        availabilityEditor($this->admin, $this->ana)
            ->assertSee('Horario de Educación a Distancia')
            ->assertSee('lunes a sábado, 8:00 a 14:00')
            ->assertSee('Sábado')
            ->assertDontSee('20:00');
    });

    it('sin campus activo usa el presencial por omisión', function () {
        Campus::factory()->count(2)->create();

        availabilityEditor($this->admin, $this->ana)
            ->assertSee('Horario presencial por omisión')
            ->assertSee('lunes a viernes, 7:00 a 21:00')
            ->assertDontSee('Sábado');
    });
});

describe('guardar', function () {
    it('guarda lo marcado como rangos unidos del periodo activo, avisa y da true', function () {
        $page = availabilityEditor($this->jefe, $this->ana)->call('save', [
            ['day' => 1, 'starts_at' => '07:00', 'kind' => 'unavailable'],
            ['day' => 1, 'starts_at' => '08:00', 'kind' => 'unavailable'],
            ['day' => 2, 'starts_at' => '09:00', 'kind' => 'preferred'],
        ]);

        expect($page->effects['returns'][0] ?? null)->toBeTrue()
            ->and(savedAvailability($this->ana, $this->term))->toBe(['1 07:00-09:00 unavailable', '2 09:00-10:00 preferred'])
            ->and(lastToastOf($page))->toMatchArray(['type' => 'success', 'title' => 'Disponibilidad guardada', 'description' => 'Ene-Jun 2027: 2 h no disponibles y 1 h preferidas.']);
    });

    it('guarda con el perfil del campus activo', function () {
        $ead = Campus::factory()->create(['settings' => ['days' => [1, 2, 3, 4, 5, 6]]]);
        Campus::factory()->create();
        session([ScopedContext::ACTIVE_CAMPUS => $ead->id]);

        availabilityEditor($this->admin, $this->ana)->call('save', [['day' => 6, 'starts_at' => '08:00', 'kind' => 'unavailable']]);

        expect(savedAvailability($this->ana, $this->term))->toBe(['6 08:00-09:00 unavailable']);
    });

    it('sin campus activo rechaza un sábado, que no está en el perfil por omisión', function () {
        Campus::factory()->count(2)->create(['settings' => ['days' => [1, 2, 3, 4, 5, 6]]]);

        $page = availabilityEditor($this->admin, $this->ana)->call('save', [['day' => 6, 'starts_at' => '09:00', 'kind' => 'unavailable']]);

        expect(savedAvailability($this->ana, $this->term))->toBe([])
            ->and($page->effects['returns'][0] ?? null)->toBeFalse()
            ->and(lastToastOf($page))->toMatchArray(['type' => 'error', 'title' => 'No se guardó la disponibilidad'])
            ->and(lastToastOf($page)['description'])->toContain('sábado');
    });

    it('una celda con forma inválida no escribe y lo dice en un toast', function (array $cells) {
        $page = availabilityEditor($this->jefe, $this->ana)->call('save', $cells);

        expect(savedAvailability($this->ana, $this->term))->toBe([])
            ->and(lastToastOf($page)['type'])->toBe('error')
            ->and(activitySince()->count())->toBe(0);
    })->with([
        'hora fuera del horario' => [[['day' => 1, 'starts_at' => '06:00', 'kind' => 'unavailable']]],
        'tipo que no existe' => [[['day' => 1, 'starts_at' => '07:00', 'kind' => 'available']]],
        'celda que no es arreglo' => [['lunes']],
    ]);

    it('el periodo es el que se abrió, aunque después cambie el activo', function () {
        $page = availabilityEditor($this->jefe, $this->ana);
        session([ScopedContext::ACTIVE_TERM => $this->previous->id]);

        $page->call('save', [['day' => 1, 'starts_at' => '07:00', 'kind' => 'unavailable']]);

        expect(savedAvailability($this->ana, $this->term))->toBe(['1 07:00-08:00 unavailable'])
            ->and(savedAvailability($this->ana, $this->previous))->toBe([]);
    });
});

describe('copiar del periodo anterior', function () {
    it('da la del semestre anterior, sin verano, como tipos por día y bloque, sin guardarla', function () {
        TeacherAvailability::factory()->for($this->ana)->for($this->previous)->create(['day' => 1, 'starts_at' => '07:00', 'ends_at' => '09:00']);
        TeacherAvailability::factory()->for($this->ana)->for($this->previous)->preferred()->create(['day' => 5, 'starts_at' => '20:00', 'ends_at' => '21:00']);

        $page = availabilityEditor($this->jefe, $this->ana)->call('copyPrevious');
        $kinds = $page->effects['returns'][0];

        expect($kinds)->toHaveCount(5)
            ->and($kinds[0][0])->toBe(AvailabilityKind::Unavailable->value)
            ->and($kinds[0][1])->toBe(AvailabilityKind::Unavailable->value)
            ->and($kinds[0][2])->toBeNull()
            ->and($kinds[4][13])->toBe(AvailabilityKind::Preferred->value)
            ->and(savedAvailability($this->ana, $this->term))->toBe([])
            ->and(lastToastOf($page))->toMatchArray(['title' => 'Se copió la de Ago-Dic 2026', 'description' => 'Falta guardarla.']);
    });

    it('queda deshabilitado con su motivo si el semestre anterior no tiene disponibilidad', function () {
        availabilityEditor($this->jefe, $this->ana)
            ->assertSee('Ago-Dic 2026 no tiene disponibilidad capturada.')
            ->call('copyPrevious')
            ->assertReturned(fn (mixed $returned): bool => $returned === null);
    });
});

it('sin periodo activo pide elegirlo y no guarda', function () {
    $this->term->update(['is_current' => false]);

    $page = availabilityEditor($this->jefe, $this->ana)
        ->assertSee('Elige un periodo en tu credencial')
        ->assertDontSee('Guardar disponibilidad')
        ->call('save', [['day' => 1, 'starts_at' => '07:00', 'kind' => 'unavailable']]);

    expect(TeacherAvailability::query()->count())->toBe(0)
        ->and(lastToastOf($page)['type'])->toBe('error');
});

describe('alcance', function () {
    it('el jefe de otro departamento recibe 403', function () {
        $this->actingAs($this->jefeIi)->get(route('faculty.teachers.availability', $this->ana->id))->assertForbidden();
        $this->actingAs($this->jefe)->get(route('faculty.teachers.availability', $this->beto->id))->assertForbidden();
    });

    it('el docente y el invitado no entran; un id que no existe da 404', function () {
        $docente = User::factory()->withRole(Role::Docente)->create();

        $this->actingAs($docente)->get(route('faculty.teachers.availability', $this->ana->id))->assertForbidden();
        $this->actingAs($this->admin)->get('/faculty/teachers/no-es-uuid/availability')->assertNotFound();
        auth()->logout();
        $this->get(route('faculty.teachers.availability', $this->ana->id))->assertRedirect(route('login'));
    });

    it('cada método da 403 si lo llama alguien que no cubre al docente, también sobre una página que abrió el admin', function (string $method, array $arguments) {
        $page = availabilityEditor($this->admin, $this->ana);
        $this->actingAs($this->jefeIi);

        $page->call($method, ...$arguments)->assertForbidden();

        expect(TeacherAvailability::query()->count())->toBe(0)
            ->and(activitySince()->count())->toBe(0);
    })->with([
        'guardar' => ['save', [[['day' => 1, 'starts_at' => '07:00', 'kind' => 'unavailable']]]],
        'copiar del periodo anterior' => ['copyPrevious', []],
    ]);
});
