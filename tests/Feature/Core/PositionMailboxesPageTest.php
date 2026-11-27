<?php

use App\Modules\Core\Enums\Role;
use App\Modules\Core\Jobs\ReleaseHeldPositionMailsJob;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\HeldPositionMail;
use App\Modules\Core\Models\OrgUnit;
use App\Modules\Core\Models\PositionMailbox;
use App\Modules\Core\Models\User;
use App\Modules\Core\Notifications\PositionRef;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/*
| «Buzones de puesto» (CE-02, tarea 1.63), en Configuración: solo el admin la ve y la usa (`core.mail.view_mailboxes`,
| `core.mail.manage_mailboxes`, candados de CF-03). Lista los buzones con su puesto y alcance, muestra los correos que
| esperan porque su puesto no tiene buzón y deja capturar, editar y desactivar un buzón en un cajón.
*/

beforeEach(function () {
    config(['nexus.institutional_email_domain' => 'cdjuarez.tecnm.mx', 'nexus.institutional_email_domains' => ['cdjuarez.tecnm.mx']]);
    $this->unit = OrgUnit::factory()->create(['name' => 'Departamento de Sistemas de prueba']);
    $this->admin = User::factory()->admin()->create();
});

describe('el admin', function () {
    beforeEach(function () {
        $this->actingAs($this->admin);
    });

    it('CE-02: ve la pantalla en Configuración con sus buzones, su puesto y su estado', function () {
        PositionMailbox::factory()->create(['org_unit_id' => $this->unit->id, 'email' => 'jefatura_sis@cdjuarez.tecnm.mx']);
        PositionMailbox::factory()->inactive()->create(['email' => 'vieja@cdjuarez.tecnm.mx']);

        $this->get(route('core.position-mailboxes'))
            ->assertOk()
            ->assertSee('Buzones de puesto')
            ->assertSeeInOrder(['Departamento de Sistemas de prueba', 'jefatura_sis@cdjuarez.tecnm.mx', 'Activo'])
            ->assertSee('vieja@cdjuarez.tecnm.mx')
            ->assertSee('Inactivo');
    });

    it('CE-02: muestra los correos en espera sin buzón por puesto y «Capturar buzón» abre el cajón con ese puesto', function () {
        HeldPositionMail::factory()->count(2)->create(['org_unit_id' => $this->unit->id]);
        $key = PositionRef::of(Role::JefeDepartamento, unit: $this->unit->id)->key();

        Livewire::test('core::pages.position-mailboxes')
            ->assertSee('Correos en espera sin buzón')
            ->assertSee('2 correos')
            ->assertSee('Departamento de Sistemas de prueba')
            ->call('capture', $key)
            ->assertSet('showDrawer', true)
            ->assertSet('role', 'jefe_departamento')
            ->assertSet('unit', $this->unit->id)
            ->assertSet('career', '');
    });

    it('CE-02: captura un buzón desde el cajón y lo dice en el aviso', function () {
        $page = Livewire::test('core::pages.position-mailboxes')
            ->call('create')
            ->set('role', 'jefe_departamento')
            ->set('unit', $this->unit->id)
            ->set('email', 'Jefatura_SIS@cdjuarez.tecnm.mx')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showDrawer', false);

        expect(PositionMailbox::query()->sole())->email->toBe('jefatura_sis@cdjuarez.tecnm.mx')->org_unit_id->toBe($this->unit->id)
            ->and(lastToastOf($page)['title'])->toBe('Buzón capturado');
    });

    it('CE-02: los errores salen junto a su campo, en español', function () {
        Livewire::test('core::pages.position-mailboxes')
            ->call('create')
            ->call('save')
            ->assertHasErrors(['role' => 'required', 'email' => 'required'])
            ->assertSee('Elige el puesto.')
            ->set('role', 'jefe_departamento')
            ->set('email', 'jefatura@gmail.com')
            ->call('save')
            ->assertHasErrors('email')
            ->assertSee('El buzón tiene que ser un correo institucional (@cdjuarez.tecnm.mx).');

        expect(PositionMailbox::query()->count())->toBe(0);
    });

    it('CE-02: al cambiar de puesto se vacía el alcance', function () {
        Livewire::test('core::pages.position-mailboxes')
            ->call('create')
            ->set('role', 'jefe_departamento')
            ->set('unit', $this->unit->id)
            ->set('role', 'coordinador')
            ->assertSet('unit', '')
            ->assertSee('Todas las carreras');
    });

    it('CE-02: edita y desactiva un buzón; uno inactivo se reactiva al guardarlo', function () {
        $mailbox = PositionMailbox::factory()->create(['org_unit_id' => $this->unit->id, 'email' => 'jefatura_sis@cdjuarez.tecnm.mx']);

        $page = Livewire::test('core::pages.position-mailboxes')
            ->call('edit', $mailbox->id)
            ->assertSet('email', 'jefatura_sis@cdjuarez.tecnm.mx')
            ->set('email', 'jefatura.sis@cdjuarez.tecnm.mx')
            ->call('save')
            ->assertHasNoErrors();

        expect($mailbox->fresh()->email)->toBe('jefatura.sis@cdjuarez.tecnm.mx');

        $page->call('deactivate', $mailbox->id);

        expect($mailbox->fresh()->is_active)->toBeFalse()
            ->and(lastToastOf($page)['title'])->toBe('Buzón desactivado');

        $page->call('edit', $mailbox->id)->call('save');

        expect($mailbox->fresh()->is_active)->toBeTrue();
    });

    it('CE-02: lista los correos que esperan o fallaron; reintenta un fallido y descarta uno en espera, con bitácora', function () {
        Queue::fake();
        $failed = HeldPositionMail::factory()->failed()->create(['org_unit_id' => $this->unit->id, 'subject' => 'Correo que falló']);
        $waiting = HeldPositionMail::factory()->create(['org_unit_id' => $this->unit->id, 'subject' => 'Correo que espera']);
        HeldPositionMail::factory()->create(['subject' => 'Correo ya tomado', 'released_at' => now()]);

        $page = Livewire::test('core::pages.position-mailboxes')
            ->assertSeeInOrder(['Correos de puesto en espera o con falla', 'Correo que falló', 'Falló', 'Correo que espera', 'Sin buzón'])
            ->assertDontSee('Correo ya tomado')
            ->call('retry', $failed->id);

        expect($failed->fresh())->failed_at->toBeNull()->attempts->toBe(0)->released_at->toBeNull()
            ->and(lastToastOf($page)['title'])->toBe('Correo de vuelta en la espera')
            ->and(Activity::query()->where('event', 'position-mail-retried')->sole()->causer_id)->toBe($this->admin->id);
        Queue::assertPushed(ReleaseHeldPositionMailsJob::class);

        $page->call('discard', $waiting->id);

        expect($waiting->fresh()->discarded_at)->not->toBeNull()
            ->and(HeldPositionMail::query()->whereKey($waiting->id)->exists())->toBeTrue()
            ->and(Activity::query()->where('event', 'position-mail-discarded')->sole()->subject_id)->toBe($waiting->id);

        $page->call('retry', $waiting->id)->assertForbidden();
    });

    it('CE-02: un id que no existe o que no es uuid no se encuentra', function () {
        Livewire::test('core::pages.position-mailboxes')->call('edit', 'no-es-uuid')->assertNotFound();
    });

    it('CE-02: la entrada del menú está en Configuración', function () {
        $this->get(route('core.position-mailboxes'))->assertSee(route('core.position-mailboxes'), false);
    });
});

describe('cualquier otro puesto', function () {
    it('CF-03: no ve la pantalla ni la entrada del menú', function (Role $role, array $scope) {
        $user = User::factory()->withRole($role, ['campus_id' => Campus::factory()->create()->id, ...$scope])->create();

        expect($user->can('viewAny', PositionMailbox::class))->toBeFalse()
            ->and($user->can('create', PositionMailbox::class))->toBeFalse();

        $this->actingAs($user)->get(route('core.position-mailboxes'))->assertForbidden();
        $this->actingAs($user)->get(route('core.summary'))->assertDontSee('Buzones de puesto');
    })->with([
        'subdirección' => [Role::Subdireccion, []],
        'directivo' => [Role::Directivo, []],
        'jefe de departamento' => [Role::JefeDepartamento, fn () => ['department_id' => departmentKey(10)]],
        'coordinador' => [Role::Coordinador, fn () => ['career_id' => careerKey(20)]],
        'docente' => [Role::Docente, []],
    ]);

    it('CF-03: cada acción responde 403 aunque se llame directo sobre una página que abrió el admin', function (string $method, array $arguments) {
        $mailbox = PositionMailbox::factory()->create(['org_unit_id' => $this->unit->id]);
        $failed = HeldPositionMail::factory()->failed()->create();
        $waiting = HeldPositionMail::factory()->create();
        $jefe = User::factory()->withRole(Role::JefeDepartamento, ['org_unit_id' => $this->unit->id])->create();
        $page = Livewire::actingAs($this->admin)->test('core::pages.position-mailboxes');
        $ids = ['mailbox' => $mailbox->id, 'failed' => $failed->id, 'waiting' => $waiting->id];

        $this->actingAs($jefe);
        $page->call($method, ...array_map(fn (mixed $argument): mixed => $ids[$argument] ?? $argument, $arguments))->assertForbidden();

        expect($mailbox->fresh())->is_active->toBeTrue()->email->toBe($mailbox->email)
            ->and($failed->fresh()->failed_at)->not->toBeNull()
            ->and($waiting->fresh()->discarded_at)->toBeNull();
    })->with([
        'agregar' => ['create', []],
        'capturar' => ['capture', ['jefe_departamento|*|*|*|*']],
        'editar' => ['edit', ['mailbox']],
        'desactivar' => ['deactivate', ['mailbox']],
        'guardar' => ['save', []],
        'reintentar' => ['retry', ['failed']],
        'descartar' => ['discard', ['waiting']],
    ]);

    it('CF-03: un admin desactivado no la ve, y el invitado va al acceso', function () {
        $inactive = User::factory()->admin()->create(['is_active' => false]);

        expect($inactive->can('viewAny', PositionMailbox::class))->toBeFalse()
            ->and($this->actingAs($inactive)->get(route('core.position-mailboxes'))->isOk())->toBeFalse();

        auth()->logout();
        $this->get(route('core.position-mailboxes'))->assertRedirect(route('login'));
    });
});
