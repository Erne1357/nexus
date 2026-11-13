<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Database\Factories\LoadSnapshotFactory;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Models\LoadSnapshot;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Scheduling\Enums\GroupStatus;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Staffing\Models\StaffingRanking;
use Carbon\CarbonImmutable;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
| Lo que ve el docente con la carga publicada (P8, confirmado el 10 de noviembre de 2026): con la carga de un
| departamento publicada, «Mi carga» y la «Oferta del departamento» muestran su última versión publicada, también si se
| reabrió; antes de publicar, lo vivo. Se decide por carga (periodo, modalidad y departamento, M1), así que un docente
| puede ver una carga publicada y otra viva (aquí, Campus I es de otra modalidad para tener dos cargas de Sistemas). El ranking de la oferta sigue vivo, unido por el id del grupo.
*/

beforeEach(function () {
    $this->term = Term::factory()->current()->create(['code' => '20271', 'name' => 'Ene-Jun 2027']);
    $this->c2 = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    modalityOf('EAD')->update(['is_active' => true]);
    // Los grupos de Campus I son de EAD en estas pruebas (publishedViewGroup): el campus es solo el lugar (MS3).
    $this->c1 = Campus::factory()->create(['code' => 'C1', 'name' => 'Campus I']);
    $this->sc = Department::factory()->create(['code' => 'SC', 'name' => 'Sistemas y Computación']);
    $this->me = Teacher::factory()->adscribedTo($this->sc)->create(['name' => 'Marisol Quiñones']);
    $this->user = User::factory()->withRole(Role::Docente)->create();
    $this->me->update(['user_id' => $this->user->id]);
    $this->colleague = Teacher::factory()->adscribedTo($this->sc)->create(['name' => 'Óscar Nájera']);

    $this->scC2 = AcademicLoad::factory()->create(['term_id' => $this->term->id, 'modality_id' => modalityOf('PRES')->id, 'department_id' => $this->sc->id, 'status' => LoadStatus::Staffing]);
    $this->scC1 = AcademicLoad::factory()->create(['term_id' => $this->term->id, 'modality_id' => modalityOf('EAD')->id, 'department_id' => $this->sc->id, 'status' => LoadStatus::Staffing]);

    $buildingC2 = Building::factory()->create(['campus_id' => $this->c2->id]);
    $buildingC1 = Building::factory()->create(['campus_id' => $this->c1->id]);
    $this->a101 = Room::factory()->create(['building_id' => $buildingC2->id, 'code' => 'A-101']);
    $this->b202 = Room::factory()->create(['building_id' => $buildingC2->id, 'code' => 'B-202']);
    $this->c303 = Room::factory()->create(['building_id' => $buildingC2->id, 'code' => 'C-303']);
    $this->l1 = Room::factory()->create(['building_id' => $buildingC1->id, 'code' => 'L-1']);
    $this->l2 = Room::factory()->create(['building_id' => $buildingC1->id, 'code' => 'L-2']);
});

/**
 * Un grupo vivo de Sistemas en el campus, de una materia de 4 horas, con sus sesiones en el salón.
 *
 * @param  list<array{0: int, 1: string, 2: string}>  $sessions
 */
function publishedViewGroup(Campus $campus, string $code, string $subject, ?Teacher $teacher, array $sessions, Room $room): Group
{
    $group = Group::factory()->create([
        'term_id' => test()->term->id,
        'campus_id' => $campus->id,
        'modality_id' => modalityOf($campus->is(test()->c1) ? 'EAD' : 'PRES')->id,
        'subject_id' => Subject::factory()->create(['department_id' => test()->sc->id, 'name' => $subject, 'theory_hours' => 4, 'practice_hours' => 0])->id,
        'code' => $code,
        'teacher_id' => $teacher?->id,
    ]);

    foreach ($sessions as [$day, $start, $end]) {
        GroupSession::factory()->on($day, $start, $end)->inRoom($room)->create(['group_id' => $group->id]);
    }

    return $group;
}

/**
 * Un grupo vivo tal como lo congela la publicación en el payload, con su campus: su id, clave, materia, docente, carrera dueña y
 * sesiones con su salón de ahora.
 *
 * @return array<string, mixed>
 */
function publishedViewFreeze(Group $group): array
{
    $group->load(['subject', 'teacher', 'ownerCareer', 'studyPlan', 'sessions.room.building']);

    $group->load('campus');

    return LoadSnapshotFactory::group([
        'id' => $group->id,
        'code' => $group->code,
        'campus' => ['id' => $group->campus->id, 'code' => $group->campus->code, 'name' => $group->campus->name],
        'capacity' => $group->capacity,
        'subject' => [
            'id' => $group->subject->id, 'code' => $group->subject->code, 'name' => $group->subject->name, 'credits' => $group->subject->credits,
            'theory_hours' => $group->subject->theory_hours, 'practice_hours' => $group->subject->practice_hours, 'department_id' => $group->department_id,
        ],
        'teacher' => $group->teacher === null ? null : ['id' => $group->teacher->id, 'rfc' => $group->teacher->rfc, 'name' => $group->teacher->name],
        'placements' => [LoadSnapshotFactory::placement([
            'career' => ['id' => $group->ownerCareer->id, 'code' => $group->ownerCareer->code, 'name' => $group->ownerCareer->name],
            'study_plan' => ['id' => $group->studyPlan->id, 'code' => $group->studyPlan->code],
            'semester' => $group->semester,
            'seats' => $group->capacity,
        ])],
        'sessions' => $group->sessions->sortBy([['day', 'asc'], ['starts_at', 'asc']])->map(fn (GroupSession $session): array => LoadSnapshotFactory::session([
            'day' => $session->day,
            'starts_at' => substr((string) $session->starts_at, 0, 5),
            'ends_at' => substr((string) $session->ends_at, 0, 5),
            'kind' => $session->kind->value,
            'kind_label' => $session->kind->label(),
            'room' => $session->room === null ? null : [
                'id' => $session->room->id,
                'code' => $session->room->code,
                'name' => $session->room->name,
                'building' => ['id' => $session->room->building->id, 'code' => $session->room->building->code, 'name' => $session->room->building->name],
                'owner_department_id' => $session->room->department_id,
            ],
        ]))->values()->all(),
    ]);
}

/**
 * Publica la carga como la publicación real: congela sus grupos vivos no cancelados en una versión con esa fecha y deja
 * la carga en el estado dado.
 */
function publishedViewPublish(AcademicLoad $load, int $version, string $at, LoadStatus $status = LoadStatus::Published): LoadSnapshot
{
    $groups = Group::query()->active()
        ->where('term_id', $load->term_id)
        ->where('modality_id', $load->modality_id)
        ->where('department_id', $load->department_id)
        ->orderBy('code')
        ->get()
        ->map(publishedViewFreeze(...))
        ->all();

    $snapshot = LoadSnapshot::factory()->for($load)->publishedAt(CarbonImmutable::parse($at))->withGroups($groups)->create(['version' => $version]);
    $load->update(['status' => $status]);

    return $snapshot;
}

function publishedViewRank(Group $group, Teacher $teacher, int $rank): StaffingRanking
{
    return StaffingRanking::factory()->create([
        'group_id' => $group->id,
        'teacher_id' => $teacher->id,
        'rank' => $rank,
        'score' => 20 - $rank,
        'breakdown' => ['base_hours' => 40, 'history_count' => 5, 'qualification' => 'preferente', 'evaluation' => 4.5, 'remaining_hours' => 8, 'flags' => []],
    ]);
}

/**
 * El texto de un elemento de la página, con los espacios juntos; null si no está.
 */
function publishedViewText(Testable $page, string $selector): ?string
{
    $node = domOf($page->html())->querySelector($selector);

    return $node === null ? null : trim(preg_replace('/\s+/u', ' ', $node->textContent));
}

/**
 * Cómo viene el radio del grupo en el modal «Solicitar cambio»: 'enabled', 'disabled' o 'missing'.
 */
function publishedViewOption(Testable $page, string $groupId): string
{
    $option = domOf($page->html())->querySelector('input[name="request-group"][value="'.$groupId.'"]');

    return $option === null ? 'missing' : ($option->hasAttribute('disabled') ? 'disabled' : 'enabled');
}

function publishedViewMoveTo(Group $group, Room $room): void
{
    GroupSession::query()->where('group_id', $group->id)->update(['room_id' => $room->id]);
}

describe('Mi carga', function () {
    it('con la carga publicada muestra lo publicado aunque después cambien en lo vivo el salón o el docente', function () {
        $basics = publishedViewGroup($this->c2, '1A', 'Fundamentos de Programación', $this->me, [[1, '07:00', '09:00']], $this->a101);
        $calculus = publishedViewGroup($this->c2, '1B', 'Cálculo Diferencial', $this->me, [[2, '07:00', '09:00']], $this->a101);
        publishedViewPublish($this->scC2, 1, '2026-12-08 10:00');

        publishedViewMoveTo($basics, $this->b202);
        $calculus->update(['teacher_id' => $this->colleague->id]);
        publishedViewGroup($this->c2, '1C', 'Redes de Computadoras', $this->me, [[3, '07:00', '09:00']], $this->a101);

        $page = Livewire::actingAs($this->user)->test('staffing::pages.my-load')
            ->assertOk()
            ->assertSeeInOrder(['Lunes', 'Fundamentos de Programación', '7:00–9:00', '1A', 'A-101', 'C2'])
            ->assertSeeInOrder(['Martes', 'Cálculo Diferencial', '1B'])
            ->assertDontSee('B-202')
            ->assertDontSee('Redes de Computadoras');

        expect(publishedViewText($page, '[data-testid="my-load-published"]'))->toBe('Publicado el 8 dic. 2026 · v1')
            ->and(publishedViewText($page, '[data-testid="hours-assigned"]'))->toBe('8 h')
            ->and(publishedViewText($page, '[data-testid="hours-assigned"] + span'))->toBe('2 grupos');
    });

    it('sin publicar muestra lo vivo y no dice que esté publicada', function () {
        $basics = publishedViewGroup($this->c2, '1A', 'Fundamentos de Programación', $this->me, [[1, '07:00', '09:00']], $this->a101);
        publishedViewMoveTo($basics, $this->b202);

        Livewire::actingAs($this->user)->test('staffing::pages.my-load')
            ->assertSeeInOrder(['Fundamentos de Programación', 'B-202'])
            ->assertDontSee('A-101')
            ->assertDontSeeHtml('data-testid="my-load-published"');
    });

    it('con una carga publicada en un campus y otra sin publicar en otro, cada una sale de su fuente y las horas suman lo que muestra', function () {
        $basics = publishedViewGroup($this->c2, '1A', 'Fundamentos de Programación', $this->me, [[1, '07:00', '09:00']], $this->a101);
        publishedViewPublish($this->scC2, 1, '2026-12-08 10:00');
        publishedViewMoveTo($basics, $this->b202);
        $structures = publishedViewGroup($this->c1, '3B', 'Estructura de Datos', $this->me, [[2, '07:00', '09:00']], $this->l1);
        publishedViewMoveTo($structures, $this->l2);

        $page = Livewire::actingAs($this->user)->test('staffing::pages.my-load')
            ->assertSeeInOrder(['Lunes', 'Fundamentos de Programación', 'A-101', 'C2'])
            ->assertSeeInOrder(['Martes', 'Estructura de Datos', 'L-2', 'C1'])
            ->assertDontSee('B-202')
            ->assertDontSee('L-1');

        expect(publishedViewText($page, '[data-testid="my-load-published"]'))->toBe('C2: publicado el 8 dic. 2026 · v1')
            ->and(publishedViewText($page, '[data-testid="hours-assigned"]'))->toBe('8 h');
    });

    it('reabierta después de publicar, sigue mostrando la última versión publicada', function () {
        $basics = publishedViewGroup($this->c2, '1A', 'Fundamentos de Programación', $this->me, [[1, '07:00', '09:00']], $this->a101);
        publishedViewPublish($this->scC2, 1, '2026-12-08 10:00');
        publishedViewMoveTo($basics, $this->b202);
        publishedViewPublish($this->scC2, 2, '2026-12-11 09:00', LoadStatus::Adjusting);
        publishedViewMoveTo($basics, $this->c303);

        $page = Livewire::actingAs($this->user)->test('staffing::pages.my-load')
            ->assertSee('B-202')
            ->assertDontSee('A-101')
            ->assertDontSee('C-303');

        expect(publishedViewText($page, '[data-testid="my-load-published"]'))->toBe('Publicado el 11 dic. 2026 · v2');
    });

    it('SC-09: el traslado justo se marca sobre lo que muestra, también entre un grupo publicado y uno vivo', function () {
        $basics = publishedViewGroup($this->c2, '1A', 'Fundamentos de Programación', $this->me, [[1, '07:00', '09:00']], $this->a101);
        publishedViewPublish($this->scC2, 1, '2026-12-08 10:00');
        GroupSession::query()->where('group_id', $basics->id)->update(['ends_at' => '08:00']);
        publishedViewGroup($this->c1, '3B', 'Estructura de Datos', $this->me, [[1, '09:30', '10:30']], $this->l1);

        $transfer = domOf(Livewire::actingAs($this->user)->test('staffing::pages.my-load'))->querySelector('[data-testid=transfer]');

        expect(trim(preg_replace('/\s+/', ' ', $transfer->textContent)))->toBe('Traslado C2 → C1 · 30 min')
            ->and($transfer->getAttribute('data-short'))->toBe('true');
    });

    it('con la carga publicada no deja pedir cambios, como antes', function () {
        publishedViewGroup($this->c2, '1A', 'Fundamentos de Programación', $this->me, [[1, '07:00', '09:00']], $this->a101);
        publishedViewPublish($this->scC2, 1, '2026-12-08 10:00');

        Livewire::actingAs($this->user)->test('staffing::pages.my-load')
            ->assertSeeHtml('data-testid="request-change" disabled')
            ->assertSee('La carga está en '.LoadStatus::Published->label().': los cambios se piden en Asignación o Ajustes.');
    });

    it('reabierta, deja pedir en los grupos publicados que siguen a su nombre; el reasignado o cancelado queda deshabilitado sin romper la pantalla', function () {
        $basics = publishedViewGroup($this->c2, '1A', 'Fundamentos de Programación', $this->me, [[1, '07:00', '09:00']], $this->a101);
        $calculus = publishedViewGroup($this->c2, '1B', 'Cálculo Diferencial', $this->me, [[2, '07:00', '09:00']], $this->a101);
        $chemistry = publishedViewGroup($this->c2, '1C', 'Química', $this->me, [[3, '07:00', '09:00']], $this->a101);
        publishedViewPublish($this->scC2, 1, '2026-12-08 10:00', LoadStatus::Adjusting);
        $calculus->update(['teacher_id' => $this->colleague->id]);
        $chemistry->update(['status' => GroupStatus::Cancelled]);
        $late = publishedViewGroup($this->c2, '1D', 'Redes de Computadoras', $this->me, [[4, '07:00', '09:00']], $this->a101);

        $page = Livewire::actingAs($this->user)->test('staffing::pages.my-load')
            ->assertOk()
            ->assertSee('Cálculo Diferencial')
            ->assertSee('Química')
            ->assertDontSee('Redes de Computadoras')
            ->assertDontSee('sin abrir')
            ->assertDontSeeHtml('data-testid="request-change" disabled')
            ->call('openRequest')
            ->assertSet('requestGroupId', $basics->id)
            ->assertSee('Reasignado o cancelado después de publicar');

        expect(publishedViewOption($page, $basics->id))->toBe('enabled')
            ->and(publishedViewOption($page, $calculus->id))->toBe('disabled')
            ->and(publishedViewOption($page, $chemistry->id))->toBe('disabled')
            ->and(publishedViewOption($page, $late->id))->toBe('missing');
    });

    it('un docente no ve los grupos publicados de otro', function () {
        publishedViewGroup($this->c2, '1A', 'Fundamentos de mi colega', $this->colleague, [[1, '07:00', '09:00']], $this->a101);
        publishedViewPublish($this->scC2, 1, '2026-12-08 10:00');

        Livewire::actingAs($this->user)->test('staffing::pages.my-load')
            ->assertDontSee('Fundamentos de mi colega')
            ->assertSee('Todavía no tienes grupos asignados')
            ->assertDontSeeHtml('data-testid="my-load-published"');
    });
});

describe('Oferta', function () {
    it('con la carga publicada muestra lo publicado y el lugar vivo de cada grupo; sin ranking, sin lugar', function () {
        $taught = publishedViewGroup($this->c2, '1A', 'Fundamentos de Programación', $this->colleague, [[1, '07:00', '09:00']], $this->a101);
        $open = publishedViewGroup($this->c2, '1B', 'Cálculo Diferencial', null, [[2, '07:00', '09:00']], $this->a101);
        publishedViewRank($taught, $this->me, 3);
        publishedViewRank($taught, $this->colleague, 1);
        $openRank = publishedViewRank($open, $this->me, 1);
        publishedViewPublish($this->scC2, 1, '2026-12-08 10:00');

        publishedViewMoveTo($taught, $this->b202);
        $taught->update(['teacher_id' => null]);
        $open->update(['status' => GroupStatus::Cancelled]);
        $openRank->delete();
        publishedViewGroup($this->c2, '1C', 'Redes de Computadoras', null, [[3, '07:00', '09:00']], $this->a101);

        $page = Livewire::actingAs($this->user)->test('staffing::pages.offer')
            ->assertOk()
            ->assertSee('Cálculo Diferencial')
            ->assertDontSee('B-202')
            ->assertDontSee('Redes de Computadoras');

        expect(publishedViewText($page, '[data-testid="offer-group-'.$taught->id.'"]'))->toContain('A-101')->toContain('Óscar Nájera')
            ->and(publishedViewText($page, '[data-testid="offer-group-'.$taught->id.'"] [data-testid="offer-position"]'))->toBe('3 de 2')
            ->and(publishedViewText($page, '[data-testid="offer-group-'.$open->id.'"] [data-testid="offer-position"]'))->toBe('Sin lugar')
            ->and(publishedViewText($page, '[data-testid="offer-published"]'))->toBe('Publicado el 8 dic. 2026 · v1');

        expect(publishedViewText($page->call('open', $taught->id), '[data-testid="offer-drawer"] dl'))->toContain('SalónA-101 CampusC2 Campus II Docente Óscar Nájera');
    });

    it('sin publicar muestra lo vivo y no dice que esté publicada', function () {
        $taught = publishedViewGroup($this->c2, '1A', 'Fundamentos de Programación', $this->colleague, [[1, '07:00', '09:00']], $this->a101);
        publishedViewMoveTo($taught, $this->b202);

        $page = Livewire::actingAs($this->user)->test('staffing::pages.offer')->assertDontSeeHtml('data-testid="offer-published"');

        expect(publishedViewText($page, '[data-testid="offer-group-'.$taught->id.'"]'))->toContain('B-202')->not->toContain('A-101');
    });

    it('con la carga publicada en un campus y sin publicar en otro, cada ficha sale de su fuente', function () {
        $published = publishedViewGroup($this->c2, '1A', 'Fundamentos de Programación', $this->colleague, [[1, '07:00', '09:00']], $this->a101);
        publishedViewPublish($this->scC2, 1, '2026-12-08 10:00');
        publishedViewMoveTo($published, $this->b202);
        $live = publishedViewGroup($this->c1, '3B', 'Estructura de Datos', $this->colleague, [[2, '07:00', '09:00']], $this->l1);
        publishedViewMoveTo($live, $this->l2);

        $page = Livewire::actingAs($this->user)->test('staffing::pages.offer');

        expect(publishedViewText($page, '[data-testid="offer-group-'.$published->id.'"]'))->toContain('A-101')->not->toContain('B-202')
            ->and(publishedViewText($page, '[data-testid="offer-group-'.$live->id.'"]'))->toContain('L-2')
            ->and(publishedViewText($page, '[data-testid="offer-published"]'))->toBe('C2: publicado el 8 dic. 2026 · v1');
    });

    it('reabierta después de publicar, sigue mostrando la última versión publicada', function () {
        $taught = publishedViewGroup($this->c2, '1A', 'Fundamentos de Programación', $this->colleague, [[1, '07:00', '09:00']], $this->a101);
        publishedViewPublish($this->scC2, 1, '2026-12-08 10:00');
        publishedViewMoveTo($taught, $this->b202);
        publishedViewPublish($this->scC2, 2, '2026-12-11 09:00', LoadStatus::Adjusting);
        publishedViewMoveTo($taught, $this->c303);

        $page = Livewire::actingAs($this->user)->test('staffing::pages.offer');

        expect(publishedViewText($page, '[data-testid="offer-group-'.$taught->id.'"]'))->toContain('B-202')->not->toContain('A-101')->not->toContain('C-303')
            ->and(publishedViewText($page, '[data-testid="offer-published"]'))->toBe('Publicado el 11 dic. 2026 · v2');
    });

    it('un docente de otro departamento no ve lo publicado de este', function () {
        $industrial = Teacher::factory()->adscribedTo(Department::factory()->create(['code' => 'IND', 'name' => 'Industrial']))->create();
        $user = User::factory()->withRole(Role::Docente)->create();
        $industrial->update(['user_id' => $user->id]);
        publishedViewGroup($this->c2, '1A', 'Fundamentos de Programación', $this->colleague, [[1, '07:00', '09:00']], $this->a101);
        publishedViewPublish($this->scC2, 1, '2026-12-08 10:00');

        Livewire::actingAs($user)->test('staffing::pages.offer')
            ->assertDontSee('Fundamentos de Programación')
            ->assertDontSeeHtml('data-testid="offer-published"');
    });
});
