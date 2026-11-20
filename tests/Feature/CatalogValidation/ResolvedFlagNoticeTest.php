<?php

use App\Modules\Academic\Models\Department;
use App\Modules\CatalogValidation\Actions\ResolveFlag;
use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Enums\ReviewFlag;
use App\Modules\CatalogValidation\Listeners\NotifyFlaggerOnFlagResolved;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\CatalogValidation\Models\CatalogReviewFlag;
use App\Modules\CatalogValidation\Notifications\CatalogReviewFlagResolvedNotice;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Core\Notifications\NotificationMessage;
use App\Modules\Faculty\Models\Teacher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| CV10 (D7, confirmado el 14 de noviembre de 2026): al resolver una observación se escribe, si se quiere, qué se hizo; la
| nota queda con quién y cuándo y en la bitácora, y el jefe que la puso recibe un aviso en la campana con esa
| nota (NotifyFlaggerOnFlagResolved).
*/

beforeEach(function () {
    $this->term = Term::factory()->current()->create(['code' => '20271', 'name' => 'Ene-Jun 2027']);
    $this->sis = Department::factory()->create(['code' => 'SIS', 'name' => 'Sistemas y Computación']);
    $this->jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->sis->id])->create();
    $this->admin = User::factory()->admin()->create(['name' => 'Laura Admin']);
    $this->review = CatalogReview::factory()->general(Catalog::Teachers)->create(['term_id' => $this->term->id, 'department_id' => $this->sis->id]);
    $this->teacher = Teacher::factory()->adscribedTo($this->sis)->create(['rfc' => 'TOAA800101AA1', 'name' => 'Ana Torres']);
    $this->flag = CatalogReviewFlag::factory()->for($this->review, 'review')->on($this->teacher)->create(['flag' => ReviewFlag::Inactive, 'flagged_by' => $this->jefe->id]);
});

describe('CV10: la nota de lo que se hizo', function () {
    it('queda con quién y cuándo, recortada, y en la bitácora', function () {
        app(ResolveFlag::class)->handle($this->review, $this->flag, $this->admin, '  Se dio de baja en el SII.  ');

        $entry = Activity::query()->where('event', 'resolved')->sole();

        expect($this->flag->fresh())
            ->resolution_note->toBe('Se dio de baja en el SII.')
            ->resolved_by->toBe($this->admin->id)
            ->and($entry->attribute_changes['attributes']['resolution_note'])->toBe('Se dio de baja en el SII.')
            ->and($entry->attribute_changes['old']['resolution_note'])->toBeNull();
    });

    it('es opcional: en blanco queda vacía', function (?string $note) {
        app(ResolveFlag::class)->handle($this->review, $this->flag, $this->admin, $note);

        expect($this->flag->fresh())->resolution_note->toBeNull()->resolved_at->not->toBeNull();
    })->with(['sin nota' => null, 'en blanco' => '   ']);

    it('rechaza una nota de más de 1000 caracteres y no resuelve', function () {
        expect(fn () => app(ResolveFlag::class)->handle($this->review, $this->flag, $this->admin, Str::repeat('a', 1001)))
            ->toThrow(ValidationException::class)
            ->and($this->flag->fresh()->resolved_at)->toBeNull();
    });

    it('la base exige que una nota vaya con la resolución', function () {
        expect(fn () => DB::table('catalog_review_flags')->where('id', $this->flag->id)->update(['resolution_note' => 'Hecho']))
            ->toThrow(QueryException::class);
    });
});

describe('CV10: el aviso al jefe que la puso', function () {
    it('le llega en la campana con la nota y el registro, y lleva a su revisión', function () {
        Notification::fake();

        app(ResolveFlag::class)->handle($this->review, $this->flag, $this->admin, 'Se dio de baja en el SII.');

        Notification::assertSentTo($this->jefe, CatalogReviewFlagResolvedNotice::class, function (CatalogReviewFlagResolvedNotice $notice, array $channels) {
            $message = $notice->message($this->jefe);

            return $channels === ['database']
                && $message->title === 'Se resolvió tu observación'
                && $message->body === 'Sobre TOAA800101AA1 · Ana Torres («Debe desactivarse»): Se dio de baja en el SII.'
                && $message->route === 'catalog-validation.review'
                && $message->parameters === ['department' => $this->sis->id, 'catalogo' => 'teachers']
                && $message->termId === $this->term->id
                && $message->campusId === null
                && $notice->addressedRole() === Role::JefeDepartamento;
        });
        Notification::assertNotSentTo($this->admin, CatalogReviewFlagResolvedNotice::class);
    });

    it('sin nota dice quién la resolvió', function () {
        Notification::fake();

        app(ResolveFlag::class)->handle($this->review, $this->flag, $this->admin);

        Notification::assertSentTo($this->jefe, CatalogReviewFlagResolvedNotice::class, fn (CatalogReviewFlagResolvedNotice $notice) => $notice->message($this->jefe)->body === 'Sobre TOAA800101AA1 · Ana Torres («Debe desactivarse»). La resolvió Laura Admin, sin nota.');
    });

    it('no avisa a quien la resolvió él mismo, a un usuario inactivo, a quien ya no es jefe del departamento ni dos veces', function () {
        Notification::fake();

        app(ResolveFlag::class)->handle($this->review, $this->flag, $this->jefe, 'Ya quedó.');
        Notification::assertNothingSent();

        $other = CatalogReviewFlag::factory()->for($this->review, 'review')->on($this->teacher)->create(['flagged_by' => $this->jefe->id]);
        $this->jefe->update(['is_active' => false]);
        app(ResolveFlag::class)->handle($this->review, $other, $this->admin);
        app(ResolveFlag::class)->handle($this->review, $other->fresh(), $this->admin);
        Notification::assertNothingSent();

        $formerHead = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => Department::factory()->create()->id])->create();
        $third = CatalogReviewFlag::factory()->for($this->review, 'review')->on($this->teacher)->create(['flagged_by' => $formerHead->id]);
        app(ResolveFlag::class)->handle($this->review, $third, $this->admin);
        Notification::assertNothingSent();
    });

    it('lo manda un listener en cola después del commit', function () {
        expect(app(NotifyFlaggerOnFlagResolved::class))->toBeInstanceOf(ShouldQueue::class);

        app(ResolveFlag::class)->handle($this->review, $this->flag, $this->admin, 'Hecho');

        expect($this->jefe->notifications()->sole()->data)->toMatchArray(['title' => 'Se resolvió tu observación', 'role' => 'jefe_departamento']);
    });

    it('no se puede abrir si el departamento ya no existe o el usuario ya no es su jefe', function () {
        $message = new NotificationMessage('t', 'b', 'o-check', 'catalog-validation.review', ['department' => $this->sis->id, 'catalogo' => 'teachers']);
        $context = fn (User $user): Actor => app(Actor::class)->forUser($user);

        expect(CatalogReviewFlagResolvedNotice::unavailableReason($context($this->jefe), $message))->toBeNull()
            ->and(CatalogReviewFlagResolvedNotice::unavailableReason($context(User::factory()->withRole(Role::JefeDepartamento, ['department_id' => Department::factory()->create()->id])->create()), $message))
            ->toBe('Ya no eres jefe de Sistemas y Computación.');

        $this->sis->delete();

        expect(CatalogReviewFlagResolvedNotice::unavailableReason($context($this->jefe), $message))->toBe('Ese departamento ya no existe.');
    });
});

describe('en la revisión', function () {
    it('Subdirección resuelve con la nota desde el drawer del registro y la nota se ve después', function () {
        $subdireccion = User::factory()->withRole(Role::Subdireccion, [])->create();
        $page = fn () => Livewire\Livewire::withQueryParams(['department' => $this->sis->id, 'catalogo' => 'teachers'])->actingAs($subdireccion)->test('catalog-validation::pages.review');

        $page()->call('edit', $this->teacher->id)
            ->assertSeeHtml('data-testid="resolve-submit-'.$this->flag->id.'"')
            ->call('resolveFlag', $this->flag->id, 'Se dio de baja en el SII.')
            ->assertHasNoErrors();

        expect($this->flag->fresh()->resolution_note)->toBe('Se dio de baja en el SII.');
        $page()->call('edit', $this->teacher->id)->assertSee('Qué se hizo: Se dio de baja en el SII.');
    });

    it('CV10: una nota que no es texto es un error de validación, no un error del servidor', function () {
        $subdireccion = User::factory()->withRole(Role::Subdireccion, [])->create();

        Livewire\Livewire::withQueryParams(['department' => $this->sis->id, 'catalogo' => 'teachers'])->actingAs($subdireccion)
            ->test('catalog-validation::pages.review')
            ->call('edit', $this->teacher->id)
            ->call('resolveFlag', $this->flag->id, ['no es texto'])
            ->assertHasErrors('note');

        Livewire\Livewire::actingAs($this->admin)->test('catalog-validation::pages.progress')
            ->call('resolveFlag', $this->flag->id, 42)
            ->assertHasErrors('note');

        expect($this->flag->fresh()->resolved_at)->toBeNull();
    });
});
