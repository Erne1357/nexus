<?php

use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\Core\Actions\SendAccessInvitation;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\RoleAssignment;
use App\Modules\Core\Notifications\AccessInvitation;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Staffing\Actions\Selection\PublishSelection;
use App\Modules\Staffing\Enums\SelectionStatus;
use App\Modules\Staffing\Jobs\InviteSelectionTeachersJob;
use App\Modules\Staffing\Models\Selection;
use App\Modules\Staffing\Models\SelectionEntry;
use App\Modules\Staffing\Queries\SelectionMonitorQuery;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\Staffing\SelectionWorld;

/*
| SEL-13 (confirmado el 24 de noviembre de 2026: «Lo ideal sería que el Jefe valide el catálogo, pero si no lo hace, sí
| invitación personal»): al publicar la selección, cada docente de la cola sin acceso y sin una invitación vigente
| recibe la invitación de acceso de siempre (AC-07): a su correo institucional si su departamento ya validó el catálogo
| de docentes (AC-09) y, si no, a su correo personal. Quien no tiene ningún correo queda en el monitor como «Sin acceso:
| sin correo». Nombres y correos ficticios.
*/

beforeEach(function () {
    config(['nexus.institutional_email_domain' => 'cdjuarez.tecnm.mx']);
    $this->world = SelectionWorld::create(SelectionStatus::Draft);
    $this->world->term->update(['starts_on' => now()->addMonth()->toDateString(), 'ends_on' => now()->addMonths(6)->toDateString()]);
    $this->world->selection->update(['starts_at' => CarbonImmutable::now()->addDays(3)->setTime(9, 0)]);
    $this->world->group('A');
});

/**
 * Un docente de la cola, con usuario sin contraseña salvo que `$password` diga otra cosa.
 *
 * @param  array<string, mixed>  $teacher
 * @param  array<string, mixed>  $user
 */
function accessTeacher(string $name, array $teacher = [], array $user = []): Teacher
{
    $record = test()->world->teacher(name: $name, slotStartsAt: CarbonImmutable::now()->addDays(3), position: SelectionEntry::query()->count() + 1);
    $record->update($teacher);
    $record->user->forceFill(['password' => null, 'access_invited_at' => null, ...$user])->save();

    return $record->refresh();
}

function publishWorldSelection(): Selection
{
    return app(PublishSelection::class)->handle(test()->world->selection, test()->world->admin);
}

it('SEL-13: sin el catálogo de docentes validado, la invitación va a su correo personal', function () {
    Notification::fake();
    $ana = accessTeacher('Ana Personal Ficticia', ['email' => 'ana.personal@gmail.com', 'institutional_email' => null]);

    publishWorldSelection();

    Notification::assertSentOnDemand(AccessInvitation::class, fn (AccessInvitation $notice, array $channels, AnonymousNotifiable $notifiable): bool => $notifiable->routes['mail'] === 'ana.personal@gmail.com');
    expect($ana->user->fresh()->access_invited_at)->not->toBeNull()
        ->and($ana->user->fresh()->email)->toBe('ana.personal@gmail.com');
});

it('SEL-13: con el catálogo de docentes validado, la invitación va a su correo institucional', function () {
    Notification::fake();
    CatalogReview::factory()->validated()->create(['term_id' => $this->world->term->id, 'department_id' => $this->world->department->id]);
    accessTeacher('Beto Institucional Ficticio', ['email' => 'beto.personal@gmail.com', 'institutional_email' => 'beto.ficticio@cdjuarez.tecnm.mx']);

    publishWorldSelection();

    Notification::assertSentOnDemand(AccessInvitation::class, fn (AccessInvitation $notice, array $channels, AnonymousNotifiable $notifiable): bool => $notifiable->routes['mail'] === 'beto.ficticio@cdjuarez.tecnm.mx');
    Notification::assertSentOnDemandTimes(AccessInvitation::class, 1);
});

it('SEL-13: no invita a quien ya tiene acceso ni a quien tiene una invitación vigente; sin correo no invita y lo dice el monitor', function () {
    Notification::fake();
    accessTeacher('Carla Con Acceso Ficticia', ['email' => 'carla@gmail.com'], ['password' => bcrypt('secreta-ficticia')]);
    accessTeacher('Dario Invitado Ficticio', ['email' => 'dario@gmail.com'], ['access_invited_at' => now()->subDay(), 'email' => 'dario@gmail.com']);
    accessTeacher('Elena Sin Correo Ficticia', ['email' => null, 'institutional_email' => null], ['email' => null]);
    accessTeacher('Fabio Pendiente Ficticio', ['email' => 'fabio@gmail.com']);

    $published = publishWorldSelection();

    Notification::assertSentOnDemandTimes(AccessInvitation::class, 1);
    Notification::assertSentOnDemand(AccessInvitation::class, fn (AccessInvitation $notice, array $channels, AnonymousNotifiable $notifiable): bool => $notifiable->routes['mail'] === 'fabio@gmail.com');

    $log = Activity::query()->where('event', 'selection_teachers_invited')->sole();
    expect($log->properties['invited'])->toBe(1)
        ->and($log->properties['without_email'])->toBe(1);

    $access = app(SelectionMonitorQuery::class)->for($published, CarbonImmutable::now())['access'];
    expect($access['counts'])->toBe(['access' => 1, 'invited' => 2, 'pending' => 0, 'no_email' => 1])
        ->and(collect($access['missing'])->pluck('name')->all())->toBe(['Elena Sin Correo Ficticia']);

    Livewire::actingAs($this->world->admin)->test('staffing::pages.selection-admin', ['seleccion' => $published->id])
        ->assertSeeHtml('data-testid="monitor-access"')
        ->assertSee('Sin acceso: sin correo')
        ->assertSee('Elena Sin Correo Ficticia');
});

it('SEL-13: un docente con otro puesto no se invita en automático y queda pendiente en el monitor', function () {
    Notification::fake();
    $jefe = accessTeacher('Gael Jefe Ficticio', ['email' => 'gael@gmail.com']);
    RoleAssignment::factory()->for($jefe->user)->create(['role' => Role::JefeDepartamento, 'org_unit_id' => $this->world->department->id]);

    $published = publishWorldSelection();

    Notification::assertSentOnDemandTimes(AccessInvitation::class, 0);
    $access = app(SelectionMonitorQuery::class)->for($published, CarbonImmutable::now())['access'];
    expect($access['counts']['pending'])->toBe(1)
        ->and($access['missing'][0]['reason'])->toBe(SendAccessInvitation::STAFF);
});

it('SEL-13: las invitaciones van a la cola después de confirmar la publicación; si no se publica, no sale ninguna', function () {
    Queue::fake();
    accessTeacher('Hilda Ficticia', ['email' => 'hilda@gmail.com']);
    $draft = Selection::factory()->create(['term_id' => $this->world->term->id, 'department_id' => $this->world->department->id, 'starts_at' => now()->subDay()]);

    publishWorldSelection();

    Queue::assertPushed(InviteSelectionTeachersJob::class, fn (InviteSelectionTeachersJob $job): bool => $job->selectionId === $this->world->selection->id);

    expect(fn () => app(PublishSelection::class)->handle($draft, $this->world->admin))->toThrow(ValidationException::class);
    Queue::assertPushed(InviteSelectionTeachersJob::class, 1);
});
