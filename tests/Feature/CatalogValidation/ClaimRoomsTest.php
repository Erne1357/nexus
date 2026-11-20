<?php

use App\Modules\Academic\Models\Department;
use App\Modules\CatalogValidation\Actions\ClaimRooms;
use App\Modules\CatalogValidation\Actions\CreateTermReviews;
use App\Modules\CatalogValidation\Actions\ReleaseRoomClaim;
use App\Modules\CatalogValidation\Actions\ReopenReview;
use App\Modules\CatalogValidation\Actions\ValidateReview;
use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Enums\ReviewStatus;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\CatalogValidation\Models\CatalogReviewMark;
use App\Modules\CatalogValidation\Models\CatalogReviewRoomClaim;
use App\Modules\CatalogValidation\Queries\CatalogContentsQuery;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermOpening;
use App\Modules\Core\Models\User;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Facilities\Queries\RoomQuery;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/*
| CV16 (confirmado el 20 de noviembre de 2026): «Reclamar salones sin dueño» desde la revisión de Salones del jefe de
| departamento. Aparecen los salones activos sin dueño de todos los campus; el primero que reclama se lo queda; lo
| reclamado entra a su revisión por revisar (CV7). Solo suelta los que reclamó, mientras la revisión no esté validada. Con
| la revisión validada no se reclama (hay que reabrirla). Reclamar no es obligatorio para validar.
*/

beforeEach(function () {
    $this->term = Term::factory()->current()->create(['code' => '20271', 'name' => 'Ene-Jun 2027']);
    $this->c1 = Campus::factory()->create(['code' => 'C1', 'name' => 'Campus I']);
    $this->c2 = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    $this->sis = Department::factory()->create(['code' => 'SIS', 'name' => 'Sistemas y Computación']);
    $this->ele = Department::factory()->create(['code' => 'ELE', 'name' => 'Eléctrica-Electrónica']);
    TermOpening::factory()->create(['term_id' => $this->term->id, 'modality_id' => modalityOf('PRES')->id]);
    app(CreateTermReviews::class)->handle($this->term, Catalog::cases());

    $this->jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->sis->id])->create();
    $this->otro = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->ele->id])->create();
    $this->e1 = Building::factory()->create(['campus_id' => $this->c1->id, 'code' => 'E1', 'name' => 'Edificio 1']);
    $this->e2 = Building::factory()->create(['campus_id' => $this->c2->id, 'code' => 'E2', 'name' => 'Edificio 2']);
    $this->a = Room::factory()->for($this->e1)->create(['code' => 'A-101']);
    $this->b = Room::factory()->for($this->e1)->create(['code' => 'A-102']);
    $this->c = Room::factory()->for($this->e2)->create(['code' => 'B-201']);
});

function claimReview(?Department $department = null): CatalogReview
{
    return CatalogReview::query()->where('term_id', test()->term->id)->where('department_id', ($department ?? test()->sis)->id)->where('catalog', Catalog::Rooms)->sole();
}

function claimPage(?User $user = null, array $query = ['catalogo' => 'rooms'])
{
    return Livewire::withQueryParams($query)->actingAs($user ?? test()->jefe)->test('catalog-validation::pages.review');
}

function claimErrors(Closure $action): array
{
    try {
        $action();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    return [];
}

describe('RoomQuery::unowned', function () {
    it('CV16: lista los salones activos sin dueño de todos los campus; no los inactivos, borrados ni los que tienen dueño', function () {
        Room::factory()->for($this->e1)->inactive()->create(['code' => 'X-1']);
        tap(Room::factory()->for($this->e1)->create(['code' => 'X-2']))->delete();
        Room::factory()->for($this->e1)->ownedBy($this->ele)->create(['code' => 'X-3']);

        $result = app(RoomQuery::class)->unowned();

        expect($result['rooms']->pluck('code')->all())->toBe(['A-101', 'A-102', 'B-201'])
            ->and($result['total'])->toBe(3);
    });

    it('busca, filtra por campus y por edificio y limita', function () {
        $query = app(RoomQuery::class);

        expect($query->unowned('A-10')['rooms'])->toHaveCount(2)
            ->and($query->unowned(campusId: $this->c2->id)['rooms']->pluck('code')->all())->toBe(['B-201'])
            ->and($query->unowned(buildingId: $this->e1->id)['rooms'])->toHaveCount(2)
            ->and($query->unowned(campusId: 'no-es-uuid')['rooms'])->toHaveCount(0)
            ->and($query->unowned(limit: 1))->toMatchArray(['total' => 3])
            ->and($query->unowned(limit: 1)['rooms'])->toHaveCount(1);
    });
});

describe('ClaimRooms', function () {
    it('CV16: reclamar varios los pasa al departamento y entran a la revisión por revisar, con su bitácora', function () {
        $result = app(ClaimRooms::class)->handle(claimReview(), [$this->a->id, $this->c->id], $this->jefe);

        expect($result['claimed'])->toEqualCanonicalizing(['C1 · A-101', 'C2 · B-201'])->and($result['skipped'])->toBe([])
            ->and($this->a->fresh()->department_id)->toBe($this->sis->id)
            ->and($this->c->fresh()->department_id)->toBe($this->sis->id)
            ->and($this->b->fresh()->department_id)->toBeNull()
            ->and(CatalogReviewRoomClaim::query()->active()->where('claimed_by', $this->jefe->id)->count())->toBe(2)
            ->and(collect(app(CatalogContentsQuery::class)->records(claimReview()))->pluck('id')->all())->toContain($this->a->id, $this->c->id)
            ->and(CatalogReviewMark::query()->count())->toBe(0);

        $entry = Activity::query()->where('event', 'claimed')->sole();
        expect($entry->subject->is(claimReview()))->toBeTrue()->and($entry->causer->is($this->jefe))->toBeTrue()
            ->and(Activity::query()->where('event', 'updated')->where('subject_id', $this->a->id)->exists())->toBeTrue();
    });

    it('CV16: el primero que reclama se lo queda; el segundo lo omite y se le avisa', function () {
        app(ClaimRooms::class)->handle(claimReview($this->ele), [$this->a->id], $this->otro);

        $result = app(ClaimRooms::class)->handle(claimReview(), [$this->a->id, $this->b->id], $this->jefe);

        expect($result)->toBe(['claimed' => ['C1 · A-102'], 'skipped' => ['C1 · A-101']])
            ->and($this->a->fresh()->department_id)->toBe($this->ele->id)
            ->and(CatalogReviewRoomClaim::query()->where('room_id', $this->a->id)->count())->toBe(1)
            ->and(app(RoomQuery::class)->unowned()['rooms']->pluck('code')->all())->toBe(['B-201']);
    });

    it('CV16: un salón que se inactivó o se borró entre abrir el drawer y confirmar se omite', function () {
        $this->a->update(['is_active' => false]);
        $this->b->delete();

        $result = app(ClaimRooms::class)->handle(claimReview(), [$this->a->id, $this->b->id, $this->c->id], $this->jefe);

        expect($result['claimed'])->toBe(['C2 · B-201'])->and($result['skipped'])->toHaveCount(2)
            ->and($this->a->fresh()->department_id)->toBeNull();
    });

    it('CV16: con la revisión validada se rechaza (hay que reabrirla) y no escribe nada', function () {
        $review = claimReview();
        $review->update(['status' => ReviewStatus::Validated, 'validated_by' => $this->jefe->id, 'validated_at' => now()]);

        expect(claimErrors(fn () => app(ClaimRooms::class)->handle($review, [$this->a->id], $this->jefe)))->toHaveKey('status')
            ->and($this->a->fresh()->department_id)->toBeNull()
            ->and(CatalogReviewRoomClaim::query()->count())->toBe(0);

        app(ReopenReview::class)->handle($review->fresh(), $this->jefe);

        expect(app(ClaimRooms::class)->handle($review->fresh(), [$this->a->id], $this->jefe)['claimed'])->toBe(['C1 · A-101']);
    });

    it('pide elegir al menos un salón y solo vale en la revisión de salones', function () {
        expect(claimErrors(fn () => app(ClaimRooms::class)->handle(claimReview(), [], $this->jefe)))->toHaveKey('claim');

        $teachers = CatalogReview::query()->where('department_id', $this->sis->id)->where('catalog', Catalog::Teachers)->sole();
        expect(claimErrors(fn () => app(ClaimRooms::class)->handle($teachers, [$this->a->id], $this->jefe)))->toHaveKey('claim');
    });

    it('CV16, alcance: ni el jefe de otro departamento, ni un coordinador, ni un docente, ni el admin reclaman para tu departamento', function (User $user) {
        expect(fn () => app(ClaimRooms::class)->handle(claimReview(), [$this->a->id], $user))->toThrow(AuthorizationException::class)
            ->and($this->a->fresh()->department_id)->toBeNull();
    })->with([
        'jefe de otro departamento' => fn () => test()->otro,
        'coordinador' => fn () => User::factory()->withRole(Role::Coordinador, ['campus_id' => test()->c1->id, 'career_id' => careerKey(20)])->create(),
        'docente' => fn () => User::factory()->withRole(Role::Docente)->create(),
        'admin' => fn () => User::factory()->withRole(Role::Admin)->create(),
    ]);
});

describe('ReleaseRoomClaim', function () {
    it('CV16: suelta un salón que reclamó: sin dueño, sale de la revisión, pierde su marca y se puede reclamar otra vez', function () {
        app(ClaimRooms::class)->handle(claimReview(), [$this->a->id], $this->jefe);
        CatalogReviewMark::query()->create(['catalog_review_id' => claimReview()->id, 'record_id' => $this->a->id, 'reviewed_by' => $this->jefe->id, 'reviewed_at' => now()]);

        app(ReleaseRoomClaim::class)->handle(claimReview(), $this->a->id, $this->jefe);

        expect($this->a->fresh()->department_id)->toBeNull()
            ->and(CatalogReviewRoomClaim::query()->active()->count())->toBe(0)
            ->and(CatalogReviewRoomClaim::query()->whereNotNull('released_at')->count())->toBe(1)
            ->and(CatalogReviewMark::query()->count())->toBe(0)
            ->and(collect(app(CatalogContentsQuery::class)->records(claimReview()))->pluck('id')->all())->not->toContain($this->a->id)
            ->and(Activity::query()->where('event', 'released')->sole()->causer->is($this->jefe))->toBeTrue();

        expect(app(ClaimRooms::class)->handle(claimReview(), [$this->a->id], $this->jefe)['claimed'])->toBe(['C1 · A-101'])
            ->and(CatalogReviewRoomClaim::query()->active()->count())->toBe(1);
    });

    it('CV16: no suelta los que ya eran del departamento (eso lo hace el admin)', function () {
        $own = Room::factory()->for($this->e1)->ownedBy($this->sis)->create(['code' => 'OWN-1']);

        expect(claimErrors(fn () => app(ReleaseRoomClaim::class)->handle(claimReview(), $own->id, $this->jefe)))->toHaveKey('claim')
            ->and($own->fresh()->department_id)->toBe($this->sis->id);
    });

    it('CV16: solo mientras la revisión no esté validada', function () {
        app(ClaimRooms::class)->handle(claimReview(), [$this->a->id], $this->jefe);
        $review = claimReview();
        $review->update(['status' => ReviewStatus::Validated, 'validated_by' => $this->jefe->id, 'validated_at' => now()]);

        expect(claimErrors(fn () => app(ReleaseRoomClaim::class)->handle($review, $this->a->id, $this->jefe)))->toHaveKey('status')
            ->and($this->a->fresh()->department_id)->toBe($this->sis->id);
    });

    it('CV16, alcance: otro jefe no suelta los que reclamaste tú', function () {
        app(ClaimRooms::class)->handle(claimReview(), [$this->a->id], $this->jefe);

        expect(fn () => app(ReleaseRoomClaim::class)->handle(claimReview(), $this->a->id, $this->otro))->toThrow(AuthorizationException::class)
            ->and(fn () => app(ReleaseRoomClaim::class)->handle(claimReview($this->ele), $this->a->id, $this->otro))->toThrow(ValidationException::class)
            ->and($this->a->fresh()->department_id)->toBe($this->sis->id);
    });
});

describe('validación sin reclamar', function () {
    it('CV16: reclamar no es obligatorio: con 0 salones se valida en un paso', function () {
        $review = claimReview();

        claimPage(query: [])->assertOk();
        $review->update(['status' => ReviewStatus::InReview]);
        app(ValidateReview::class)->handle($review, $this->jefe);

        expect($review->fresh()->status)->toBe(ReviewStatus::Validated);
    });
});

describe('pantalla de la revisión de Salones', function () {
    it('CV16: el jefe ve el botón con el conteo y el drawer lista los salones sin dueño', function () {
        claimPage()->assertSeeHtml('data-testid="claim-rooms"')->assertSee('Reclamar salones sin dueño (3)')
            ->call('openClaim')->assertSet('showClaim', true)
            ->assertSee('C1 · A-101')->assertSee('C2 · B-201')
            ->set('claimCampus', $this->c2->id)->assertSee('B-201')->assertDontSee('A-101')
            ->set('claimCampus', '')->set('claimSearch', 'A-102')->assertSee('A-102')->assertDontSee('B-201');
    });

    it('CV16: reclama desde la pantalla, los ve con la insignia «Reclamado» y los suelta con su confirmación', function () {
        claimPage()->call('openClaim')
            ->call('claimRooms', [$this->a->id, $this->b->id])->assertHasNoErrors()
            ->assertSee('Reclamado')->assertSee('A-101')
            ->call('askRelease', $this->a->id)->assertSet('showRelease', true)->assertSee('Vas a soltar el salón')
            ->call('releaseRoom')->assertHasNoErrors()->assertSet('showRelease', false);

        expect($this->a->fresh()->department_id)->toBeNull()->and($this->b->fresh()->department_id)->toBe($this->sis->id);
    });

    it('CV16: avisa de los omitidos cuando otro jefe reclamó primero', function () {
        $page = claimPage()->call('openClaim');
        app(ClaimRooms::class)->handle(claimReview($this->ele), [$this->a->id], $this->otro);

        $page->call('claimRooms', [$this->a->id, $this->b->id])->assertHasNoErrors();

        expect($this->a->fresh()->department_id)->toBe($this->ele->id)->and($this->b->fresh()->department_id)->toBe($this->sis->id);
    });

    it('CV16: con la revisión validada el botón lo explica y el servidor lo rechaza', function () {
        claimReview()->update(['status' => ReviewStatus::Validated, 'validated_by' => $this->jefe->id, 'validated_at' => now()]);

        claimPage()->assertSeeHtml('data-testid="claim-why"')->assertSee('reábrela para reclamar salones')
            ->call('openClaim')->assertHasErrors('status')->assertSet('showClaim', false)
            ->call('claimRooms', [$this->a->id])->assertHasErrors('claim');

        expect($this->a->fresh()->department_id)->toBeNull();
    });

    it('CV16, alcance: Subdirección y el admin ven la revisión sin el botón y no reclaman; otro jefe no abre la tuya', function () {
        $admin = User::factory()->withRole(Role::Admin)->create();
        $page = claimPage($admin, ['department' => $this->sis->id, 'catalogo' => 'rooms'])->assertDontSeeHtml('data-testid="claim-rooms"');
        $page->call('openClaim')->assertForbidden();

        claimPage($this->otro, ['department' => $this->sis->id, 'catalogo' => 'rooms'])->assertForbidden();
    });

    it('CV16: en otra pestaña no hay botón ni se reclama', function () {
        claimPage(query: ['catalogo' => 'teachers'])->assertDontSeeHtml('data-testid="claim-rooms"')->call('openClaim')->assertNotFound();
    });
});
