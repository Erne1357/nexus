<?php

use App\Modules\Academic\Database\Seeders\AcademicDemoSeeder;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\Core\Database\Seeders\AdminUserSeeder;
use App\Modules\Core\Database\Seeders\CampusSeeder;
use App\Modules\Core\Database\Seeders\RoleSeeder;
use App\Modules\Core\Database\Seeders\TermOpeningDemoSeeder;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermOpening;
use App\Modules\Core\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Auth;

/*
| TermOpeningDemoSeeder: abre periodos con OpenTerm como admin; las cargas y revisiones salen de los listeners.
*/

beforeEach(function () {
    $seeder = (new DatabaseSeeder)->setContainer(app());
    $seeder->call([RoleSeeder::class, CampusSeeder::class, AdminUserSeeder::class]);
    $seeder->callWith(AcademicDemoSeeder::class, ['campuses' => ['C2']]);
    Term::factory()->create(['code' => '20271']);
    Term::factory()->create(['code' => '20263']);
});

it('abre los periodos pedidos en los campus pedidos, a nombre de admin', function () {
    (new TermOpeningDemoSeeder)->run(['20271' => ['C2'], '20263' => ['C2']]);

    $admin = User::where('username', 'admin')->sole();

    expect(TermOpening::count())->toBe(2)
        ->and(TermOpening::query()->pluck('opened_by')->unique()->all())->toBe([$admin->getKey()])
        ->and(AcademicLoad::count())->toBeGreaterThan(0)
        ->and(CatalogReview::count())->toBeGreaterThan(0)
        ->and(Auth::guard()->check())->toBeFalse();
});

it('es idempotente', function () {
    (new TermOpeningDemoSeeder)->run(['20271' => ['C2']]);
    $counts = [TermOpening::count(), AcademicLoad::count(), CatalogReview::count()];

    (new TermOpeningDemoSeeder)->run(['20271' => ['C2']]);

    expect([TermOpening::count(), AcademicLoad::count(), CatalogReview::count()])->toBe($counts);
});

it('se salta un campus inactivo o inexistente y un periodo inexistente', function () {
    Campus::where('code', 'C1')->update(['is_active' => false]);

    (new TermOpeningDemoSeeder)->run(['20271' => ['C1', 'C9'], '29991' => ['C2']]);

    expect(TermOpening::count())->toBe(0);
});

it('no cambia el periodo actual', function () {
    Term::where('code', '20271')->update(['is_current' => true]);

    (new TermOpeningDemoSeeder)->run(['20263' => ['C2']]);

    expect(Term::where('code', '20271')->sole()->is_current)->toBeTrue()
        ->and(Term::where('code', '20263')->sole()->is_current)->toBeFalse();
});

it('se niega a correr en producción', function () {
    $this->app->detectEnvironment(fn (): string => 'production');

    expect(fn () => (new TermOpeningDemoSeeder)->run())->toThrow(RuntimeException::class);
    expect(TermOpening::count())->toBe(0);
});
