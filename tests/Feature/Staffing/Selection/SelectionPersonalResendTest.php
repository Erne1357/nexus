<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\User;
use App\Modules\Core\Notifications\AccessInvitation;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherDepartment;
use App\Modules\Staffing\Enums\SelectionStatus;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Support\Staffing\SelectionWorld;

/*
| SEL-13 (confirmado el 24 de noviembre de 2026: «El jefe o admin le reenvía la invitación»): al docente de la cola de
| una orden de selección (no cancelada) que no tiene correo institucional, el jefe de su departamento o el admin le
| reenvían la invitación de acceso a su correo personal, desde su ficha o desde el monitor. Fuera de cualquier selección,
| sin correo institucional sigue el motivo de siempre. Nombres y correos ficticios.
*/

beforeEach(function () {
    Notification::fake();
    config(['nexus.institutional_email_domain' => 'cdjuarez.tecnm.mx']);
    $this->world = SelectionWorld::create(SelectionStatus::Published);
    $this->world->selection->update(['starts_at' => CarbonImmutable::now()->addDay(), 'published_at' => now()]);
    $this->ana = $this->world->teacher(name: 'Ana Vencida Ficticia', slotStartsAt: CarbonImmutable::now()->addDay());
    $this->ana->update(['email' => 'ana.vencida@gmail.com', 'institutional_email' => null]);
    $this->ana->user->forceFill(['password' => null, 'email' => 'ana.vencida@gmail.com', 'access_invited_at' => now()->subDays(8)])->save();
    $this->jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->world->department->id])->create();
});

it('SEL-13: con el enlace vencido, el jefe le reenvía la invitación a su correo personal desde la ficha', function () {
    $before = $this->ana->user->fresh()->access_invited_at;

    $page = Livewire::actingAs($this->jefe)->test('faculty::pages.teacher', ['teacher' => $this->ana->id])
        ->assertSeeHtml('data-testid="resend-personal-invitation"')
        ->assertSee('Reenviar al correo personal')
        ->call('resendToPersonal');

    expect(lastToastOf($page))->type->toBe('success')
        ->and($this->ana->user->fresh()->access_invited_at->gt($before))->toBeTrue();
    Notification::assertSentOnDemand(AccessInvitation::class, fn (AccessInvitation $notice, array $channels, AnonymousNotifiable $notifiable): bool => $notifiable->routes['mail'] === 'ana.vencida@gmail.com');
});

it('SEL-13: el admin le reenvía la invitación al correo personal desde el monitor de la selección', function () {
    Livewire::actingAs($this->world->admin)->test('staffing::pages.selection-admin', ['seleccion' => $this->world->selection->id])
        ->assertSeeHtml('data-testid="monitor-resend-'.$this->ana->id.'"')
        ->call('resendInvitation', $this->ana->id)
        ->assertHasNoErrors();

    Notification::assertSentOnDemand(AccessInvitation::class, fn (AccessInvitation $notice, array $channels, AnonymousNotifiable $notifiable): bool => $notifiable->routes['mail'] === 'ana.vencida@gmail.com');
    expect($this->ana->user->fresh()->access_invited_at->isToday())->toBeTrue();
});

it('SEL-13: el jefe de otro departamento no le reenvía la invitación', function () {
    $foreign = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => Department::factory()->create()->id])->create();

    expect($foreign->can('inviteAccess', $this->ana))->toBeFalse();

    Livewire::actingAs($foreign)->test('faculty::pages.teacher', ['teacher' => $this->ana->id])->assertForbidden();
    Livewire::actingAs($foreign)->test('staffing::pages.selection-admin', ['seleccion' => $this->world->selection->id])->assertForbidden();

    Notification::assertSentOnDemandTimes(AccessInvitation::class, 0);
});

it('SEL-13: un docente fuera de cualquier selección sigue viendo el motivo de siempre', function () {
    $outsider = Teacher::factory()->create(['name' => 'Beto Fuera Ficticio', 'email' => 'beto.fuera@gmail.com', 'institutional_email' => null, 'user_id' => User::factory()->withRole(Role::Docente)->create(['password' => null])->id]);
    TeacherDepartment::factory()->create(['teacher_id' => $outsider->id, 'department_id' => $this->world->department->id]);

    $page = Livewire::actingAs($this->jefe)->test('faculty::pages.teacher', ['teacher' => $outsider->id])
        ->assertDontSeeHtml('data-testid="resend-personal-invitation"')
        ->assertSee('Falta su correo institucional')
        ->call('resendToPersonal');

    expect(lastToastOf($page))->type->toBe('error');
    Notification::assertSentOnDemandTimes(AccessInvitation::class, 0);
});

it('SEL-13: en una selección cancelada ya no se ofrece el correo personal', function () {
    $this->world->selection->update(['status' => SelectionStatus::Cancelled, 'cancelled_at' => now()]);

    Livewire::actingAs($this->jefe)->test('faculty::pages.teacher', ['teacher' => $this->ana->id])
        ->assertDontSeeHtml('data-testid="resend-personal-invitation"')
        ->assertSee('Falta su correo institucional');
});
