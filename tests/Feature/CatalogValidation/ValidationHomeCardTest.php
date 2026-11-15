<?php

use App\Modules\Academic\Models\Department;
use App\Modules\CatalogValidation\Actions\CreateTermReviews;
use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Enums\ReviewStatus;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Enums\Stage;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermStage;
use App\Modules\Core\Models\User;
use App\Modules\Core\Queries\TermCalendarQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

/*
| CV-S13 (supuesto por confirmar): la tarjeta de Inicio de la validación de catálogos, que pone CatalogValidation al
| contestar HomeCardsRequested, solo mientras la validación del periodo activo está abierta. Al jefe, cuántos catálogos
| le faltan y la fecha límite (D6); a Subdirección y al admin, cuántos departamentos validaron todo.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-18 10:00', 'America/Ciudad_Juarez'));
    $this->term = Term::factory()->current()->create(['code' => '20271']);
    $this->sis = Department::factory()->create(['code' => 'SIS']);
    $this->ele = Department::factory()->create(['code' => 'ELE']);
    Department::factory()->inactive()->create(['code' => 'OLD']);
    $this->jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->sis->id])->create();

    app(CreateTermReviews::class)->handle($this->term, [Catalog::Teachers, Catalog::Subjects, Catalog::Careers]);
});

function homeAs(User $user, Role $role)
{
    actingAsRole($user, $role);

    return Livewire::test('core::pages.home');
}

it('CV-S13: al jefe le dice cuántos catálogos le faltan, la fecha límite y lo lleva a validar', function () {
    TermStage::factory()->create(['term_id' => $this->term->id, 'stage' => Stage::CatalogValidation, 'starts_on' => '2026-11-16', 'ends_on' => '2026-11-27']);
    Cache::forget(TermCalendarQuery::key($this->term->id));
    CatalogReview::query()->where('department_id', $this->sis->id)->where('catalog', Catalog::Teachers)->update(['status' => ReviewStatus::Validated, 'validated_at' => now()]);

    homeAs($this->jefe, Role::JefeDepartamento)
        ->assertSeeHtml('data-testid="home-card-catalog-validation"')
        ->assertSee('Tienes 2 catálogos por validar · fecha límite 27 nov')
        ->assertSee('Ir a validar')
        ->assertSeeHtml('href="'.route('catalog-validation.review').'"');
});

it('CV-S13: a Subdirección y al admin, cuántos departamentos activos validaron todo', function (string $who) {
    CatalogReview::query()->where('department_id', $this->ele->id)->update(['status' => ReviewStatus::Validated, 'validated_at' => now()]);
    [$user, $role] = $who === 'admin' ? [User::factory()->admin()->create(), Role::Admin] : [User::factory()->withRole(Role::Subdireccion, [])->create(), Role::Subdireccion];

    homeAs($user, $role)
        ->assertSee('1 de 2 departamentos validados')
        ->assertSee('Ver avance')
        ->assertSeeHtml('href="'.route('catalog-validation.progress').'"');
})->with(['admin', 'subdirección']);

it('sin validación abierta, o al jefe que ya validó todo, no hay tarjeta', function () {
    CatalogReview::query()->where('department_id', $this->sis->id)->update(['status' => ReviewStatus::Validated, 'validated_at' => now()]);

    $subdireccion = User::factory()->withRole(Role::Subdireccion, [])->create();

    homeAs($this->jefe, Role::JefeDepartamento)->assertDontSeeHtml('data-testid="home-card-catalog-validation"');
    homeAs($subdireccion, Role::Subdireccion)->assertSeeHtml('data-testid="home-card-catalog-validation"');

    CatalogReview::query()->update(['status' => ReviewStatus::Validated, 'validated_at' => now()]);

    homeAs($subdireccion, Role::Subdireccion)->assertDontSeeHtml('data-testid="home-card-catalog-validation"');
});

it('quien no valida ni ve el avance no la ve', function () {
    $docente = User::factory()->withRole(Role::Docente, [])->create();

    homeAs($docente, Role::Docente)->assertDontSeeHtml('data-testid="home-card-catalog-validation"');
});
