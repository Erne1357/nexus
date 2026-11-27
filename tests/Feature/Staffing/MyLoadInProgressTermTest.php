<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Database\Factories\LoadSnapshotFactory;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Models\HistoricalGroup;
use App\Modules\AcademicLoad\Models\LoadSnapshot;
use App\Modules\Core\Actions\UpdateSetting;
use App\Modules\Core\Authorization\AcademicLoadVisibility;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Core\Settings\SettingRegistry;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Staffing\Queries\MyLoadQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
| «Mi carga» del semestre en curso (#755, confirmado el 20 de noviembre de 2026): el periodo cuyas fechas contienen hoy,
| no el activo que se planea. Con grupos de Nexus se ven esos; si no, el horario del SII por RFC, en solo lectura. El
| periodo en planeación se ofrece en un selector solo con la carga visible y con grupos del docente en él (supuesto por
| confirmar).
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-20 10:00', 'America/Ciudad_Juarez'));
    $this->planning = Term::factory()->current()->create(['code' => '20271', 'name' => 'Ene-Jun 2027', 'starts_on' => '2027-01-18', 'ends_on' => '2027-06-11']);
    $this->running = Term::factory()->create(['code' => '20263', 'name' => 'Ago-Dic 2026', 'starts_on' => '2026-08-17', 'ends_on' => '2026-12-11']);
    $this->campus = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    $this->dept = Department::factory()->create(['code' => 'SC']);
    $this->me = Teacher::factory()->adscribedTo($this->dept)->create(['rfc' => 'AAAA800101AA1']);
    $this->user = User::factory()->withRole(Role::Docente)->create();
    $this->me->update(['user_id' => $this->user->id]);
    $this->subject = Subject::factory()->create(['department_id' => $this->dept->id, 'name' => 'Fundamentos de Programación', 'theory_hours' => 4, 'practice_hours' => 0]);
    $this->room = Room::factory()->create(['building_id' => Building::factory()->for($this->campus)->create()->id, 'code' => '701']);
});

function mlTermSii(array $sessions, array $attrs = []): HistoricalGroup
{
    $group = HistoricalGroup::factory()->fromSii()->create(['term_code' => '20263', 'campus_id' => test()->campus->id, 'subject_id' => test()->subject->id, 'group_code' => 'C', ...$attrs]);

    foreach ($sessions as $session) {
        DB::table('historical_group_sessions')->insert([...$session, 'historical_group_id' => $group->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    return $group;
}

function mlTermNexus(Term $term, string $code, Teacher $teacher): Group
{
    $group = Group::factory()->create(['term_id' => $term->id, 'campus_id' => test()->campus->id, 'subject_id' => test()->subject->id, 'code' => $code, 'teacher_id' => $teacher->id]);
    GroupSession::factory()->on(2, '09:00', '11:00')->create(['group_id' => $group->id]);

    return $group;
}

/**
 * Publica la carga del periodo en curso como la publicación real: congela el grupo vivo en una versión.
 */
function myLoadInProgressPublish(Group $group): LoadSnapshot
{
    $group->load(['subject', 'teacher', 'ownerCareer', 'studyPlan', 'sessions.room.building', 'campus']);
    $load = AcademicLoad::factory()->create(['term_id' => $group->term_id, 'modality_id' => $group->modality_id, 'department_id' => $group->department_id, 'status' => LoadStatus::Published]);
    $frozen = LoadSnapshotFactory::group([
        'id' => $group->id,
        'code' => $group->code,
        'campus' => ['id' => $group->campus->id, 'code' => $group->campus->code, 'name' => $group->campus->name],
        'capacity' => $group->capacity,
        'subject' => [
            'id' => $group->subject->id, 'code' => $group->subject->code, 'name' => $group->subject->name, 'credits' => $group->subject->credits,
            'theory_hours' => $group->subject->theory_hours, 'practice_hours' => $group->subject->practice_hours, 'department_id' => $group->department_id,
        ],
        'teacher' => ['id' => $group->teacher->id, 'rfc' => $group->teacher->rfc, 'name' => $group->teacher->name],
        'placements' => [LoadSnapshotFactory::placement([
            'career' => ['id' => $group->ownerCareer->id, 'code' => $group->ownerCareer->code, 'name' => $group->ownerCareer->name],
            'study_plan' => ['id' => $group->studyPlan->id, 'code' => $group->studyPlan->code],
            'semester' => $group->semester,
            'seats' => $group->capacity,
        ])],
        'sessions' => $group->sessions->map(fn (GroupSession $session): array => LoadSnapshotFactory::session([
            'day' => $session->day,
            'starts_at' => substr((string) $session->starts_at, 0, 5),
            'ends_at' => substr((string) $session->ends_at, 0, 5),
            'kind' => $session->kind->value,
            'kind_label' => $session->kind->label(),
            'room' => null,
        ]))->values()->all(),
    ]);

    return LoadSnapshot::factory()->for($load)->publishedAt(CarbonImmutable::parse('2026-08-01 10:00'))->withGroups([$frozen])->create(['version' => 1]);
}

function mlTermVisible(bool $visible): void
{
    app(UpdateSetting::class)->handle(app(SettingRegistry::class)->get(AcademicLoadVisibility::SETTING), $visible);
    app(Actor::class)->refresh();
}

function mlTermSessions(): array
{
    return [
        ['day' => 1, 'starts_at' => '09:00', 'ends_at' => '11:00', 'room_code' => '701', 'teacher_rfc' => 'AAAA800101AA1'],
        ['day' => 3, 'starts_at' => '09:00', 'ends_at' => '11:00', 'room_code' => '701', 'teacher_rfc' => 'AAAA800101AA1'],
    ];
}

it('TP-03: el periodo en curso es el de consulta de la credencial: el que contiene hoy y, entre semestres, el que acaba de terminar', function () {
    $this->actingAs($this->user);

    expect(app(Actor::class)->viewTerm()?->code)->toBe('20263');

    $this->travelTo(CarbonImmutable::parse('2027-02-01 10:00', 'America/Ciudad_Juarez'));
    app(Actor::class)->refresh();
    expect(app(Actor::class)->viewTerm()?->code)->toBe('20271');

    $this->travelTo(CarbonImmutable::parse('2026-12-20 10:00', 'America/Ciudad_Juarez'));
    app(Actor::class)->refresh();
    expect(app(Actor::class)->viewTerm()?->code)->toBe('20263');
});

it('TP-04: Mi carga en curso sigue el periodo de consulta elegido en la credencial', function () {
    $old = Term::factory()->create(['code' => '20253', 'name' => 'Ago-Dic 2025', 'starts_on' => '2025-08-18', 'ends_on' => '2025-12-12']);
    $group = HistoricalGroup::factory()->fromSii()->create(['term_code' => '20253', 'campus_id' => $this->campus->id, 'subject_id' => $this->subject->id, 'group_code' => 'V']);
    DB::table('historical_group_sessions')->insert(['day' => 1, 'starts_at' => '09:00', 'ends_at' => '11:00', 'room_code' => '701', 'teacher_rfc' => 'AAAA800101AA1', 'historical_group_id' => $group->id, 'created_at' => now(), 'updated_at' => now()]);
    mlTermSii(mlTermSessions());

    session([Actor::VIEW_TERM => $old->id]);

    Livewire::actingAs($this->user)->test('staffing::pages.my-load')
        ->assertSee('Tus grupos de Ago-Dic 2025');
});

it('con el SII muestra el horario del periodo en curso por RFC, con materia, grupo, horario y salón, en solo lectura', function () {
    mlTermSii(mlTermSessions());
    mlTermSii([['day' => 2, 'starts_at' => '07:00', 'ends_at' => '09:00', 'room_code' => '701', 'teacher_rfc' => 'ZZZZ000000ZZ0']], ['group_code' => 'OTRO']);

    $data = app(MyLoadQuery::class)->forTeacher($this->me, $this->running);

    expect($data['source'])->toBe('sii')
        ->and($data['groups'])->toHaveCount(1)
        ->and($data['groups'][0])->toMatchArray(['code' => 'C', 'subject_name' => 'Fundamentos de Programación', 'campus_code' => 'C2'])
        ->and($data['groups'][0]['schedule'])->toBe([['days' => 'Lu Mi', 'time' => '9:00–11:00']])
        ->and($data['days'][1]['blocks'][0]['room'])->toBe('701')
        ->and($data['hours']['assigned'])->toBe(4);

    Livewire::actingAs($this->user)->test('staffing::pages.my-load')
        ->assertSee('Tus grupos de Ago-Dic 2026')
        ->assertSee('Horario del SII del periodo Ago-Dic 2026')
        ->assertSee('Fundamentos de Programación')
        ->assertSee('701')
        ->assertDontSeeHtml('data-testid="request-change"')
        ->assertDontSeeHtml('data-testid="my-load-term-picker"');
});

it('PL-02: en el horario del SII una tutoría ocupa su día con «no cuenta en tu carga» y no suma en sus asignadas', function () {
    mlTermSii(mlTermSessions());
    $tutoring = Subject::factory()->create(['department_id' => $this->dept->id, 'name' => 'Tutorías', 'theory_hours' => 1, 'practice_hours' => 0, 'counts_teacher_hours' => false]);
    $sii = mlTermSii([['day' => 5, 'starts_at' => '07:00', 'ends_at' => '08:00', 'room_code' => '701', 'teacher_rfc' => 'AAAA800101AA1']], ['group_code' => 'T', 'subject_id' => $tutoring->id]);

    $data = app(MyLoadQuery::class)->forTeacher($this->me, $this->running);
    $block = collect($data['days'][5]['blocks'])->firstWhere('group_id', $sii->id);

    expect($data['source'])->toBe('sii')
        ->and($data['hours']['assigned'])->toBe(4)
        ->and($block['counts_teacher_hours'])->toBeFalse();

    Livewire::actingAs($this->user)->test('staffing::pages.my-load')
        ->assertSeeHtml('data-testid="no-teacher-hours-'.$sii->id.'"');
});

it('un grupo paralelo del SII (el mismo grupo para otra carrera) no se cuenta otra vez: ni grupo ni horas de más', function () {
    mlTermSii(mlTermSessions());
    $otherPlan = Subject::factory()->create(['department_id' => $this->dept->id, 'name' => 'Fundamentos de Programación', 'theory_hours' => 4, 'practice_hours' => 0]);
    mlTermSii(mlTermSessions(), ['subject_id' => $otherPlan->id, 'parallel_of' => 'AED-1285 C']);

    $data = app(MyLoadQuery::class)->forTeacher($this->me, $this->running);

    expect($data['groups'])->toHaveCount(1)
        ->and($data['hours']['assigned'])->toBe(4)
        ->and($data['days'][1]['blocks'])->toHaveCount(1);
});

it('con grupos de Nexus en el periodo en curso usa esos y no el SII', function () {
    mlTermNexus($this->running, 'N1', $this->me);
    mlTermSii(mlTermSessions());

    $data = app(MyLoadQuery::class)->forTeacher($this->me, $this->running);

    expect($data['source'])->toBe('nexus')->and(array_column($data['groups'], 'code'))->toBe(['N1']);

    Livewire::actingAs($this->user)->test('staffing::pages.my-load')->assertDontSee('Horario del SII');
});

it('con la carga publicada de Nexus del periodo en curso ve esa versión, con su sello, y no el SII', function () {
    $group = mlTermNexus($this->running, 'PUB', $this->me);
    myLoadInProgressPublish($group);
    mlTermSii(mlTermSessions());

    $data = app(MyLoadQuery::class)->forTeacher($this->me, $this->running);

    expect($data['source'])->toBe('nexus')->and(array_column($data['groups'], 'code'))->toBe(['PUB'])->and($data['published'])->toHaveCount(1);

    Livewire::actingAs($this->user)->test('staffing::pages.my-load')
        ->assertSeeHtml('data-testid="my-load-published"')
        ->assertDontSee('Horario del SII')
        ->assertDontSeeHtml('data-testid="request-change"');
});

it('en Mi carga no se piden cambios sobre el periodo en curso: ni un grupo del SII ni uno de Nexus fuera del periodo en planeación', function () {
    $sii = mlTermSii(mlTermSessions());
    Livewire::actingAs($this->user)->test('staffing::pages.my-load')
        ->call('openRequest', groupId: $sii->id)->assertForbidden();
    // Un 403 deja el componente sin snapshot: el envío se prueba en una instancia nueva.
    Livewire::actingAs($this->user)->test('staffing::pages.my-load')
        ->set('requestGroupId', $sii->id)->set('proposal', 'Martes')->set('reason', 'Motivo')->call('sendRequest')->assertForbidden();

    $old = mlTermNexus($this->running, 'V1', $this->me);

    Livewire::actingAs($this->user)->test('staffing::pages.my-load')
        ->call('openRequest', groupId: $old->id)->assertForbidden();
    Livewire::actingAs($this->user)->test('staffing::pages.my-load')
        ->set('requestGroupId', $old->id)->set('proposal', 'Martes')->set('reason', 'Motivo')->call('sendRequest')->assertForbidden();
});

it('ofrece el selector con el periodo en planeación solo si el docente ya tiene grupos en él', function () {
    mlTermSii(mlTermSessions());

    Livewire::actingAs($this->user)->test('staffing::pages.my-load')->assertDontSeeHtml('data-testid="my-load-term-picker"');

    $planned = mlTermNexus($this->planning, 'P1', $this->me);

    Livewire::actingAs($this->user)->test('staffing::pages.my-load')
        ->assertSeeHtml('data-testid="my-load-term-picker"')
        ->assertSee('Ago-Dic 2026 (en curso)')
        ->assertSee('Horario del SII')
        ->set('period', $this->planning->id)
        ->assertSee('Tus grupos de Ene-Jun 2027')
        ->assertDontSee('Horario del SII')
        ->assertSeeHtml('data-testid="block-'.$planned->id.'"');
});

it('con la carga académica oculta no ofrece el periodo en planeación ni por URL ni por acción, y todo queda en solo lectura', function () {
    mlTermSii(mlTermSessions());
    $planned = mlTermNexus($this->planning, 'P1', $this->me);
    mlTermVisible(false);

    Livewire::withQueryParams(['periodo' => $this->planning->id])->actingAs($this->user)->test('staffing::pages.my-load')
        ->assertDontSeeHtml('data-testid="my-load-term-picker"')
        ->assertSee('Tus grupos de Ago-Dic 2026')
        ->assertDontSeeHtml('data-testid="block-'.$planned->id.'"')
        ->assertDontSeeHtml('data-testid="request-change"')
        ->assertSee('Las solicitudes de cambio no están disponibles por ahora.')
        ->set('period', $this->planning->id)
        ->assertSee('Tus grupos de Ago-Dic 2026')
        ->assertDontSeeHtml('data-testid="block-'.$planned->id.'"');
});

it('con la carga visible y el periodo en planeación elegido, el docente sigue pudiendo pedir cambios', function () {
    mlTermNexus($this->planning, 'P1', $this->me);

    Livewire::actingAs($this->user)->test('staffing::pages.my-load')
        ->set('period', $this->planning->id)
        ->assertSeeHtml('data-testid="request-change"');
});

it('alcance: un docente no ve la carga de otro y quien no es docente no ve grupos', function () {
    mlTermSii(mlTermSessions());
    $other = Teacher::factory()->adscribedTo($this->dept)->create(['rfc' => 'BBBB800101BB1']);
    $otherUser = User::factory()->withRole(Role::Docente)->create();
    $other->update(['user_id' => $otherUser->id]);

    Livewire::actingAs($otherUser)->test('staffing::pages.my-load')->assertDontSee('Fundamentos de Programación')->assertSee('Todavía no tienes grupos asignados');

    Livewire::actingAs(User::factory()->withRole(Role::Docente)->create())->test('staffing::pages.my-load')
        ->assertSee('no está ligado a una ficha de docente')
        ->assertDontSee('Fundamentos de Programación');
});
