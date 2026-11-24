<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Core\Actions\UpdateSetting;
use App\Modules\Core\Authorization\AcademicLoadVisibility;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Enums\NavigationArea;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\RoleAssignment;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Core\Navigation\Navigation;
use App\Modules\Core\Navigation\NavigationItem;
use App\Modules\Core\Notifications\NotificationMessage;
use App\Modules\Core\Settings\SettingRegistry;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherDepartment;
use App\Modules\Staffing\Actions\Selection\AdvanceSelections;
use App\Modules\Staffing\Enums\SelectionEntryStatus;
use App\Modules\Staffing\Enums\SelectionStatus;
use App\Modules\Staffing\Models\GroupTeacherAssignment;
use App\Modules\Staffing\Notifications\SelectionFinishedWithPending;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\Staffing\SelectionWorld;

/*
| Decisión 8 (confirmado el 24 de noviembre de 2026): lo que siga sin resolver al terminar la selección lo asignan a mano
| el jefe o el JPD. En producción la carga académica está oculta (MN-05) y «Asignación docente» no abre, así que el
| camino es el horario de la selección: «Mi espacio › Orden de selección» para quien tiene `staffing.selection.view`, la
| selección terminada de solo lectura para el docente y el drawer «Asignar a…» con «Quitar docente» para la jefatura.
| Nombres ficticios.
*/

beforeEach(function () {
    $this->freezeTime();
    $this->world = SelectionWorld::create(SelectionStatus::Finished);
    Term::query()->whereKeyNot($this->world->term->id)->update(['is_current' => false]);
    $this->world->term->update(['is_current' => true]);
    $this->world->selection->update(['starts_at' => now()->subDays(2), 'finished_at' => now()->subHour()]);
    $this->uno = finishedTeacher($this->world->teacher(base: 5, slotStartsAt: CarbonImmutable::now()->subDays(2), name: 'Docente Uno Ficticio', entry: ['status' => SelectionEntryStatus::Done]));
    $this->dos = finishedTeacher($this->world->teacher(base: 5, slotStartsAt: CarbonImmutable::now()->subDays(2), position: 2, name: 'Docente Dos Ficticio', entry: ['status' => SelectionEntryStatus::Unresolved]));
    $this->group = $this->world->group('A', [[1, '09:00', '10:00'], [3, '09:00', '10:00']]);
    $this->jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->world->department->id])->create(['name' => 'Jefa Ficticia']);

    app(UpdateSetting::class)->handle(app(SettingRegistry::class)->get(AcademicLoadVisibility::SETTING), false);
});

function finishedTeacher(Teacher $teacher): Teacher
{
    RoleAssignment::factory()->for($teacher->user)->create(['role' => Role::Docente]);

    return $teacher;
}

/**
 * Las etiquetas de «Mi espacio» que ve el usuario.
 *
 * @return list<string>
 */
function myspaceOf(User $user): array
{
    test()->actingAs($user);
    $items = app(Navigation::class)->visibleTo(app(Actor::class));

    return array_values(array_map(fn (NavigationItem $item): string => $item->label, array_filter($items, fn (NavigationItem $item): bool => $item->area === NavigationArea::MiEspacio)));
}

/**
 * El snapshot del drawer del grupo en la página ya pintada.
 */
function drawerSnapshotIn(TestResponse $page): string
{
    preg_match_all('/wire:snapshot="([^"]+)"/', (string) $page->getContent(), $matches);

    return collect($matches[1])->map(fn (string $encoded): string => html_entity_decode($encoded, ENT_QUOTES | ENT_HTML5))
        ->first(fn (string $snapshot): bool => (json_decode($snapshot, true)['memo']['name'] ?? null) === 'staffing::components.selection-group-drawer');
}

/**
 * Cambia propiedades y llama métodos del drawer por el endpoint de Livewire de verdad (con los middleware persistentes de
 * la ruta de la página, EnsureAcademicLoadIsVisible). Devuelve la respuesta y el snapshot nuevo.
 *
 * @param  array<string, mixed>  $updates
 * @param  list<array{0: string, 1: list<mixed>}>  $calls
 * @return array{0: TestResponse, 1: ?string}
 */
function drawerCall(string $snapshot, array $updates, array $calls): array
{
    $response = test()->withHeaders(['X-Livewire' => 'true'])->postJson(app('livewire')->getUpdateUri(), ['components' => [[
        'snapshot' => $snapshot,
        'updates' => $updates,
        'calls' => array_map(fn (array $call): array => ['path' => '', 'method' => $call[0], 'params' => $call[1]], $calls),
    ]]]);

    return [$response, $response->json('components.0.snapshot')];
}

it('decisión 8: con la carga oculta, el jefe abre la selección terminada desde «Orden de selección», asigna y luego quita un docente', function () {
    expect(app(AcademicLoadVisibility::class)->isHidden())->toBeTrue()
        ->and(myspaceOf($this->jefe))->toBe(['Resumen', 'Orden de selección']);

    $page = $this->actingAs($this->jefe)->get(route('staffing.offer'))
        ->assertOk()
        ->assertSeeHtml('data-testid="selection-timetable"')
        ->assertSee('La selección terminó')
        ->assertSee('1 sin resolver');

    [$opened, $snapshot] = drawerCall(drawerSnapshotIn($page), [], [['open', [$this->group->id]]]);
    $opened->assertOk();
    [$assigned, $snapshot] = drawerCall($snapshot, ['teacherId' => $this->dos->id, 'reason' => 'Pendiente de la ronda extra'], [['assign', []]]);
    $assigned->assertOk();

    expect($this->group->fresh()->teacher_id)->toBe($this->dos->id)
        ->and(GroupTeacherAssignment::query()->current()->where('group_id', $this->group->id)->sole()->assigned_by)->toBe($this->jefe->id);

    [$reopened, $snapshot] = drawerCall($snapshot, [], [['open', [$this->group->id]], ['confirmUnassign', []]]);
    $reopened->assertOk();
    [$removed] = drawerCall($snapshot, ['unassignReason' => 'Se reparte con otro docente'], [['unassign', []]]);
    $removed->assertOk();

    expect($this->group->fresh()->teacher_id)->toBeNull()
        ->and(Activity::query()->where('event', 'teacher_unassigned')->sole()->properties['reason'])->toBe('Se reparte con otro docente');
});

it('«Quitar docente» pide el motivo en el drawer y sin él no quita a nadie', function () {
    $this->group->update(['teacher_id' => $this->dos->id]);

    Livewire::actingAs($this->jefe)->test('staffing::components.selection-group-drawer', ['selectionId' => $this->world->selection->id])
        ->call('open', $this->group->id)
        ->assertSet('mode', 'assign')
        ->assertSeeHtml('data-testid="selection-unassign-open"')
        ->call('confirmUnassign')
        ->assertSet('mode', 'unassign')
        ->assertSee('Quitar a Docente Dos Ficticio')
        ->call('unassign')
        ->assertHasErrors('unassignReason');

    expect($this->group->fresh()->teacher_id)->toBe($this->dos->id);
});

it('el docente de la cola ve la selección terminada de solo lectura', function () {
    $this->group->update(['teacher_id' => $this->dos->id]);

    $this->actingAs($this->uno->user)->get(route('staffing.offer'))
        ->assertOk()
        ->assertSee('La selección terminó')
        ->assertSeeHtml('data-testid="selection-timetable"')
        ->assertDontSeeHtml('data-testid="selection-finish"');

    Livewire::actingAs($this->uno->user)->test('staffing::components.selection-group-drawer', ['selectionId' => $this->world->selection->id])
        ->call('open', $this->group->id)
        ->assertSet('mode', 'group')
        ->assertSee('La selección terminó')
        ->assertDontSeeHtml('data-testid="selection-take"')
        ->assertDontSeeHtml('data-testid="selection-unassign-open"')
        ->call('unassign')
        ->assertForbidden();

    expect($this->group->fresh()->teacher_id)->toBe($this->dos->id);
});

it('un docente de otro departamento recibe 403 en el drawer de la selección terminada y no quita ni asigna', function () {
    $this->group->update(['teacher_id' => $this->dos->id]);
    $other = Teacher::factory()->create(['name' => 'Docente Ajeno Ficticio', 'user_id' => User::factory()->create()->id]);
    TeacherDepartment::factory()->create(['teacher_id' => $other->id, 'department_id' => Department::factory()->create()->id]);
    finishedTeacher($other);

    $this->actingAs($other->user)->get(route('staffing.offer'))->assertOk()->assertDontSeeHtml('data-testid="selection-timetable"');
    Livewire::actingAs($other->user)->test('staffing::components.selection-group-drawer', ['selectionId' => $this->world->selection->id])->assertForbidden();

    expect($this->group->fresh()->teacher_id)->toBe($this->dos->id)
        ->and(myspaceOf($other->user))->toBe(['Resumen', 'Mi carga', 'Oferta']);
});

it('«Orden de selección» sale en Mi espacio solo con una selección a la vista, y no al admin ni al jefe de otro departamento', function () {
    $otherHead = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => Department::factory()->create()->id])->create();

    expect(myspaceOf($otherHead))->toBe(['Resumen'])
        ->and(myspaceOf($this->world->admin))->toBe(['Resumen']);

    $this->world->selection->update(['status' => SelectionStatus::Cancelled]);

    expect(myspaceOf($this->jefe))->toBe(['Resumen']);
});

it('una selección terminada de otro periodo no reemplaza la oferta del docente', function () {
    $this->world->term->update(['is_current' => false]);
    Term::factory()->current()->create();

    $this->actingAs($this->uno->user)->get(route('staffing.offer'))
        ->assertOk()
        ->assertDontSeeHtml('data-testid="selection-timetable"');
});

it('decisión 8: el aviso de pendientes lleva a la selección terminada, que el jefe abre aunque no sea su periodo de carga', function () {
    Notification::fake();
    $this->world->selection->update(['status' => SelectionStatus::ExtraRound, 'finished_at' => null, 'extra_round_starts_at' => now()->subHour(), 'extra_round_ends_at' => now()->subMinute()]);
    $this->world->entryOf($this->dos)->update(['status' => SelectionEntryStatus::PendingExtra]);
    $this->world->term->update(['is_current' => false]);
    Term::factory()->current()->create();

    app(AdvanceSelections::class)->handle(CarbonImmutable::now());

    $notice = Notification::sent($this->jefe, SelectionFinishedWithPending::class)->sole();
    $message = NotificationMessage::fromArray($notice->toDatabase($this->jefe));

    expect($this->world->selection->fresh()->status)->toBe(SelectionStatus::Finished)
        ->and(SelectionFinishedWithPending::unavailableReason(app(Actor::class)->forUser($this->jefe), $message))->toBeNull();

    $this->actingAs($this->jefe)->get(route($message->route, $message->parameters))
        ->assertOk()
        ->assertSee('La selección terminó')
        ->assertSee('Docente Dos Ficticio');
    $this->actingAs($this->jefe)->get($notice->toMail($this->jefe)->actionUrl)->assertOk()->assertSee('La selección terminó');
});

it('SEL-14: en «Oferta», el docente sin resolver confirma su carga con el mismo modal; el que ya terminó no', function () {
    $this->group->update(['teacher_id' => $this->dos->id]);

    Livewire::actingAs($this->uno->user)->test('staffing::pages.offer')
        ->assertDontSeeHtml('data-testid="selection-confirm"')
        ->call('confirm')
        ->assertHasErrors('group');

    Livewire::actingAs($this->dos->user)->test('staffing::pages.offer')
        ->assertSeeHtml('data-testid="selection-confirm"')
        ->assertSee('Quedaste sin resolver')
        ->set('showFinish', true)
        ->assertSee('¿Confirmar tu carga?')
        ->assertSeeHtml('data-testid="selection-confirm-submit"')
        ->call('confirm')
        ->assertHasNoErrors()
        ->assertSet('showFinish', false);

    expect($this->world->entryOf($this->dos)->status)->toBe(SelectionEntryStatus::Done)
        ->and($this->world->entryOf($this->uno)->status)->toBe(SelectionEntryStatus::Done);
});

it('«Quitar docente» rechaza un motivo de más de 500 caracteres', function () {
    $this->group->update(['teacher_id' => $this->dos->id]);

    Livewire::actingAs($this->jefe)->test('staffing::components.selection-group-drawer', ['selectionId' => $this->world->selection->id])
        ->call('open', $this->group->id)
        ->call('confirmUnassign')
        ->set('unassignReason', str_repeat('a', 501))
        ->call('unassign')
        ->assertHasErrors(['unassignReason' => 'max']);

    expect($this->group->fresh()->teacher_id)->toBe($this->dos->id);
});

it('decisión 8: un JPD que también está en la cola ve la selección terminada como jefatura, con «Asignar a…»', function () {
    $this->world->entryOf($this->uno)->update(['status' => SelectionEntryStatus::Done]);
    RoleAssignment::factory()->for($this->uno->user)->create(['role' => Role::JefeProyectoDocente, 'org_unit_id' => $this->world->department->id, 'modality_id' => $this->world->selection->modality_id]);
    $this->uno->user->forceFill(['last_role' => Role::JefeProyectoDocente->value])->save();

    $page = Livewire::actingAs($this->uno->user)->test('staffing::pages.offer');

    $page->assertSee('1 sin resolver')
        ->assertSee('Asignas o quitas el docente de cualquier grupo.')
        ->assertDontSee('Solo consulta: los cambios los hacen');

    Livewire::actingAs($this->uno->user)->test('staffing::components.selection-group-drawer', ['selectionId' => $this->world->selection->id])
        ->call('open', $this->group->id)
        ->assertSet('mode', 'assign');
});

it('un jefe de otro departamento que pasa `?seleccion=<id>` no ve nada de esa selección', function () {
    $otherHead = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => Department::factory()->create()->id])->create();

    $this->actingAs($otherHead)->get(route('staffing.offer', ['seleccion' => $this->world->selection->id]))->assertForbidden();
    Livewire::withQueryParams(['seleccion' => $this->world->selection->id])->actingAs($otherHead)->test('staffing::pages.offer')->assertForbidden();
});
