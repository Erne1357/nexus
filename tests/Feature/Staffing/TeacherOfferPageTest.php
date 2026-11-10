<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Staffing\Models\StaffingRanking;
use App\Modules\Staffing\Queries\TeacherOfferQuery;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
| «Oferta del departamento» (HC-17, tarea 3.10): el docente ve todos los grupos de sus departamentos desde que la carga
| entra a staffing, con su propio lugar y desglose, nunca el de los demás.
*/

beforeEach(function () {
    $this->term = Term::factory()->current()->create(['code' => '20271', 'name' => 'Ene-Jun 2027']);
    $this->c2 = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    $this->c1 = Campus::factory()->create(['code' => 'C1', 'name' => 'Campus I']);
    $this->sc = Department::factory()->create(['code' => 'SC', 'name' => 'Sistemas y Computación']);
    $this->cb = Department::factory()->create(['code' => 'CB', 'name' => 'Ciencias Básicas']);
    $this->other = Department::factory()->create(['code' => 'IND', 'name' => 'Industrial']);

    $this->me = Teacher::factory()->adscribedTo($this->sc)->create(['name' => 'Marisol Quiñones']);
    $this->user = User::factory()->withRole(Role::Docente)->create();
    $this->me->update(['user_id' => $this->user->id]);
    $this->colleague = Teacher::factory()->adscribedTo($this->sc)->create(['name' => 'Óscar Nájera']);
});

function offerGroup(Department $department, Campus $campus, array $attrs = [], ?LoadStatus $load = LoadStatus::Staffing): Group
{
    $subject = $attrs['subject'] ?? Subject::factory()->create(['department_id' => $department->id, 'name' => 'Materia '.fake()->unique()->word()]);
    unset($attrs['subject']);

    if ($load !== null) {
        AcademicLoad::query()->firstOrCreate(
            ['term_id' => test()->term->id, 'campus_id' => $campus->id, 'department_id' => $department->id],
            ['status' => $load],
        );
    }

    return Group::factory()->create(['term_id' => test()->term->id, 'campus_id' => $campus->id, 'subject_id' => $subject->id, ...$attrs]);
}

/**
 * El texto de un elemento de la página, con los espacios juntos.
 */
function offerText(Testable $page, string $selector): ?string
{
    $node = domOf($page->html())->querySelector($selector);

    return $node === null ? null : trim(preg_replace('/\s+/u', ' ', $node->textContent));
}

function offerRank(Group $group, Teacher $teacher, int $rank, array $breakdown = []): StaffingRanking
{
    return StaffingRanking::factory()->create([
        'group_id' => $group->id,
        'teacher_id' => $teacher->id,
        'rank' => $rank,
        'score' => 20 - $rank,
        'breakdown' => ['base_hours' => 40, 'history_count' => 5, 'qualification' => 'preferente', 'evaluation' => 4.5, 'remaining_hours' => 8, 'flags' => [], ...$breakdown],
    ]);
}

it('HC-17: muestra todos los grupos del departamento en todos los campus con su docente, horario y salón', function () {
    $mine = offerGroup($this->sc, $this->c2, ['code' => '1A', 'semester' => 1, 'teacher_id' => $this->colleague->id]);
    $free = offerGroup($this->sc, $this->c1, ['code' => '3B', 'semester' => 3]);
    GroupSession::factory()->on(2, '11:00', '13:00')->create(['group_id' => $mine->id]);
    GroupSession::factory()->on(5, '11:00', '13:00')->create(['group_id' => $mine->id]);

    Livewire::actingAs($this->user)->test('staffing::pages.offer')
        ->assertOk()
        ->assertSee('Semestre 1')
        ->assertSee('Semestre 3')
        ->assertSeeInOrder(['1A', 'Ma Vi', '11:00–13:00', 'Óscar Nájera'])
        ->assertSee('Sin docente')
        ->assertSee('C1')
        ->assertSee('C2');
});

it('HC-17: no muestra la oferta mientras la carga está en borrador o propuesta, y sí desde staffing en adelante', function () {
    offerGroup($this->sc, $this->c2, ['code' => 'DR1'], LoadStatus::Draft);
    offerGroup($this->sc, $this->c1, ['code' => 'PR1'], LoadStatus::Proposed);

    Livewire::actingAs($this->user)->test('staffing::pages.offer')->assertDontSee('DR1')->assertDontSee('PR1');

    foreach ([LoadStatus::Staffing, LoadStatus::Adjusting, LoadStatus::Closed, LoadStatus::Approved, LoadStatus::Published, LoadStatus::Reanalysis] as $status) {
        AcademicLoad::query()->where('department_id', $this->sc->id)->update(['status' => $status]);

        Livewire::actingAs($this->user)->test('staffing::pages.offer')->assertSee('DR1')->assertSee('PR1');
    }
});

it('no muestra grupos cancelados ni de otros periodos ni de departamentos donde no está adscrito', function () {
    offerGroup($this->sc, $this->c2, ['code' => 'CAN1', 'status' => 'cancelled']);
    offerGroup($this->other, $this->c2, ['code' => 'OTR1']);
    $later = Term::factory()->create(['code' => '20272']);
    $stray = offerGroup($this->sc, $this->c2, ['code' => 'FUT1']);
    $stray->update(['term_id' => $later->id]);

    Livewire::actingAs($this->user)->test('staffing::pages.offer')
        ->assertDontSee('CAN1')->assertDontSee('OTR1')->assertDontSee('FUT1')->assertDontSee('Industrial');
});

it('con varios departamentos hay una pestaña por cada uno y ?depto= elige', function () {
    $this->me->adscriptions()->create(['department_id' => $this->cb->id, 'is_primary' => false]);
    offerGroup($this->sc, $this->c2, ['code' => 'SCG1']);
    offerGroup($this->cb, $this->c2, ['code' => 'CBG1']);

    Livewire::actingAs($this->user)->test('staffing::pages.offer')
        ->assertSee('Sistemas y Computación')->assertSee('Ciencias Básicas')
        ->assertSee('CBG1')->assertDontSee('SCG1')
        ->set('department', 'SC')
        ->assertSee('SCG1')->assertDontSee('CBG1');

    Livewire::actingAs($this->user)->withQueryParams(['depto' => 'SC'])->test('staffing::pages.offer')->assertSee('SCG1')->assertDontSee('CBG1');
});

it('muestra su propio lugar («3 de 18»), «tu mejor lugar» por materia y «Asignado a ti»', function () {
    $subject = Subject::factory()->create(['department_id' => $this->sc->id, 'name' => 'Fundamentos']);
    $first = offerGroup($this->sc, $this->c2, ['code' => '1A', 'subject' => $subject]);
    $second = offerGroup($this->sc, $this->c2, ['code' => '1B', 'subject' => $subject, 'teacher_id' => $this->me->id]);
    offerRank($first, $this->me, 3);
    offerRank($first, $this->colleague, 1);
    offerRank($second, $this->me, 1);

    $page = Livewire::actingAs($this->user)->test('staffing::pages.offer')
        ->assertSee('Asignado a ti')
        ->assertSee('Tu mejor lugar')
        ->assertSeeHtml('data-testid="offer-best-'.$subject->code.'"');

    expect(offerText($page, '[data-testid="offer-group-'.$first->id.'"] [data-testid="offer-position"]'))->toBe('3 de 2');
});

it('el panel muestra solo SU desglose y nada de los demás docentes', function () {
    $group = offerGroup($this->sc, $this->c2, ['code' => '1A']);
    offerRank($group, $this->me, 2, ['history_count' => 5, 'evaluation' => 4.5, 'remaining_hours' => 8, 'remaining_after_hours' => 4]);
    $rival = offerRank($group, $this->colleague, 1, ['history_count' => 77, 'evaluation' => 3.37, 'base_hours' => 99]);
    $rival->update(['score' => 123.45]);

    $page = Livewire::actingAs($this->user)->test('staffing::pages.offer')
        ->call('open', $group->id)
        ->assertSee('Tu desglose')
        ->assertSeeInOrder(['Criterio', 'Tuyo', 'Puntos'])
        ->assertSee('Preferente')
        ->assertSee('4.5')
        ->assertSeeHtml('data-testid="offer-remaining"');

    expect(offerText($page, '[data-testid="offer-place"] p'))->toBe('2 de 2 docentes');

    $text = strip_tags($page->html());

    expect($text)->not->toContain('123.45')->not->toContain('3.37')->not->toContain('99 h')->not->toContain('Óscar Nájera');
});

it('el panel de un docente sin evaluación dice «sin evaluación» y sin ranking dice que no está', function () {
    $ranked = offerGroup($this->sc, $this->c2, ['code' => '1A']);
    $unranked = offerGroup($this->sc, $this->c2, ['code' => '1B']);
    offerRank($ranked, $this->me, 1, ['evaluation' => null, 'flags' => ['sin_evaluacion', 'choque']]);

    Livewire::actingAs($this->user)->test('staffing::pages.offer')
        ->call('open', $ranked->id)->assertSee('sin evaluación')->assertSee('Choca con tu horario')
        ->call('open', $unranked->id)->assertSee('No estás en el ranking de este grupo.');
});

it('HC-01: el panel dice con cuál de SUS grupos choca el grupo y a qué hora', function () {
    $offered = offerGroup($this->sc, $this->c2, ['code' => '1A']);
    GroupSession::factory()->on(1, '07:00', '09:00')->create(['group_id' => $offered->id]);
    GroupSession::factory()->on(3, '07:00', '09:00')->create(['group_id' => $offered->id]);
    $mine = offerGroup($this->sc, $this->c1, ['code' => 'A4', 'teacher_id' => $this->me->id, 'subject' => Subject::factory()->create(['department_id' => $this->sc->id, 'name' => 'Ingeniería Económica'])]);
    GroupSession::factory()->on(1, '08:00', '10:00')->create(['group_id' => $mine->id]);
    GroupSession::factory()->on(2, '07:00', '09:00')->create(['group_id' => $mine->id]);
    $notMine = offerGroup($this->sc, $this->c2, ['code' => 'Z9', 'teacher_id' => $this->colleague->id]);
    GroupSession::factory()->on(3, '07:00', '09:00')->create(['group_id' => $notMine->id]);
    offerRank($offered, $this->me, 12, ['flags' => ['choque']]);

    $page = Livewire::actingAs($this->user)->test('staffing::pages.offer')->call('open', $offered->id);

    expect(offerText($page, '[data-testid="offer-clashes"]'))->toBe('Choca con tu horario A4 · Ingeniería Económica · C1 Lu 8:00–10:00')
        ->and(offerText($page, '[data-testid="offer-group-'.$offered->id.'"] [data-testid="offer-marks"]'))->toBe('Choque');
});

it('el panel tiene su botón para cerrar', function () {
    $group = offerGroup($this->sc, $this->c2, ['code' => '1A']);

    Livewire::actingAs($this->user)->test('staffing::pages.offer')->call('open', $group->id)->assertSeeHtml('aria-label="Cerrar el panel"');
});

it('abrir el panel con el id de un grupo que no es de su oferta no muestra nada', function () {
    $foreign = offerGroup($this->other, $this->c2, ['code' => 'OTR1']);

    Livewire::actingAs($this->user)->test('staffing::pages.offer')->call('open', $foreign->id)->assertDontSee('OTR1');
});

it('filtra por materia (?q=), semestre, «Sin docente» y «Entre tus 3 primeros»', function () {
    $algebra = offerGroup($this->sc, $this->c2, ['code' => 'AL1', 'semester' => 1, 'teacher_id' => $this->colleague->id, 'subject' => Subject::factory()->create(['department_id' => $this->sc->id, 'name' => 'Álgebra Lineal'])]);
    $redes = offerGroup($this->sc, $this->c2, ['code' => 'RE1', 'semester' => 5, 'subject' => Subject::factory()->create(['department_id' => $this->sc->id, 'name' => 'Redes'])]);
    $bd = offerGroup($this->sc, $this->c2, ['code' => 'BD1', 'semester' => 5, 'subject' => Subject::factory()->create(['department_id' => $this->sc->id, 'name' => 'Bases de Datos'])]);
    offerRank($algebra, $this->me, 9);
    offerRank($redes, $this->me, 2);
    offerRank($bd, $this->me, 7);

    Livewire::actingAs($this->user)->withQueryParams(['q' => 'algebra'])->test('staffing::pages.offer')
        ->assertSee('AL1')->assertDontSee('RE1')->assertDontSee('BD1')
        ->call('clearFilters')->set('semester', '5')->assertDontSee('AL1')->assertSee('RE1')->assertSee('BD1')
        ->call('clearFilters')->set('state', 'sin')->assertDontSee('AL1')->assertSee('RE1')
        ->call('clearFilters')->set('state', 'top')->assertSee('RE1')->assertDontSee('AL1')->assertDontSee('BD1')
        ->assertSee('Quitar filtros');
});

it('«Entre tus 3 primeros» cuenta exactamente lo que muestra: lugar 1 a 3, sin marca que lo baje y sin contar los suyos', function () {
    $top = offerGroup($this->sc, $this->c2, ['code' => 'TOP1']);
    $noHours = offerGroup($this->sc, $this->c2, ['code' => 'SIN1']);
    $clash = offerGroup($this->sc, $this->c2, ['code' => 'CHO1']);
    $unavailable = offerGroup($this->sc, $this->c2, ['code' => 'NOD1']);
    $mine = offerGroup($this->sc, $this->c2, ['code' => 'MIO1', 'teacher_id' => $this->me->id]);
    $far = offerGroup($this->sc, $this->c2, ['code' => 'LEJ1']);
    offerRank($top, $this->me, 3, ['flags' => ['sin_evaluacion']]);
    offerRank($noHours, $this->me, 1, ['flags' => ['sin_horas']]);
    offerRank($clash, $this->me, 2, ['flags' => ['choque']]);
    offerRank($unavailable, $this->me, 1, ['flags' => ['no_disponible']]);
    offerRank($mine, $this->me, 1);
    offerRank($far, $this->me, 4);

    $page = Livewire::actingAs($this->user)->test('staffing::pages.offer');

    expect(offerText($page, '[data-testid="offer-state-top"]'))->toBe('Entre tus 3 primeros 1');

    $page->set('state', 'top')->assertSee('TOP1')
        ->assertDontSee('SIN1')->assertDontSee('CHO1')->assertDontSee('NOD1')->assertDontSee('MIO1')->assertDontSee('LEJ1')
        ->assertSee('Mostrando');

    expect(offerText($page, '[data-testid="offer-state-todos"]'))->toBe('Todos 6')
        ->and(offerText($page, '[data-testid="offer-state-sin"]'))->toBe('Sin docente 5');
});

it('mantiene un número constante de consultas sin importar cuántos grupos hay', function () {
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(TeacherOfferQuery::class)->forTeacher($this->me, $this->term);
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    $one = offerGroup($this->sc, $this->c2, ['teacher_id' => $this->colleague->id]);
    GroupSession::factory()->inRoom(Room::factory()->create(['building_id' => Building::factory()->create(['campus_id' => $this->c2->id])->id]))->create(['group_id' => $one->id]);
    offerRank($one, $this->me, 1);
    $few = $count();

    foreach (range(1, 8) as $hour) {
        $group = offerGroup($this->sc, $this->c1, ['teacher_id' => $this->colleague->id]);
        GroupSession::factory()->on(3, sprintf('%02d:00', 6 + $hour), sprintf('%02d:00', 7 + $hour))->create(['group_id' => $group->id]);
        offerRank($group, $this->me, 2);
        offerRank($group, $this->colleague, 1);
    }

    expect($count())->toBe($few)->and($few)->toBeLessThanOrEqual(14);
});

describe('alcance', function () {
    it('un usuario sin puesto de docente recibe 403', function () {
        Livewire::actingAs(User::factory()->create())->test('staffing::pages.offer')->assertForbidden();
    });

    it('un jefe de departamento sin ficha de docente recibe 403', function () {
        $jefe = User::factory()->withRole(Role::JefeDepartamento, ['campus_id' => $this->c2->id, 'department_id' => $this->sc->id])->create();
        offerGroup($this->sc, $this->c2, ['code' => '1A']);

        Livewire::actingAs($jefe)->test('staffing::pages.offer')->assertForbidden();
    });

    it('un docente sin ficha ligada a su usuario ve un aviso y ningún grupo', function () {
        $loose = User::factory()->withRole(Role::Docente)->create();
        offerGroup($this->sc, $this->c2, ['code' => '1A']);

        Livewire::actingAs($loose)->test('staffing::pages.offer')->assertOk()->assertSee('no está ligado a una ficha de docente')->assertDontSee('1A');
    });

    it('un docente de otro departamento no ve la oferta de este', function () {
        $industrial = Teacher::factory()->adscribedTo($this->other)->create();
        $user = User::factory()->withRole(Role::Docente)->create();
        $industrial->update(['user_id' => $user->id]);
        offerGroup($this->sc, $this->c2, ['code' => 'SCG1']);
        offerGroup($this->other, $this->c2, ['code' => 'INDG1']);

        Livewire::actingAs($user)->test('staffing::pages.offer')->assertDontSee('SCG1')->assertSee('INDG1');
    });

    it('un docente sin adscripciones vigentes ve un aviso', function () {
        $this->me->adscriptions()->delete();
        offerGroup($this->sc, $this->c2, ['code' => '1A']);

        Livewire::actingAs($this->user)->test('staffing::pages.offer')->assertSee('No estás adscrito')->assertDontSee('1A');
    });

    it('la ruta pide sesión y aparece en Mi espacio solo para el docente', function () {
        $this->get('/mi-espacio/oferta')->assertRedirect('/login');
        $this->actingAs($this->user)->get('/mi-espacio/oferta')->assertOk()->assertSee('Mi espacio');
        $this->actingAs(User::factory()->create())->get('/mi-espacio/oferta')->assertForbidden();
    });
});
