<?php

use App\Modules\Academic\Database\Seeders\AcademicDemoSeeder;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\Core\Database\Seeders\AdminUserSeeder;
use App\Modules\Core\Database\Seeders\CampusSeeder;
use App\Modules\Core\Database\Seeders\TermOpeningDemoSeeder;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermOpening;
use App\Modules\Core\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Auth;

/*
| TermOpeningDemoSeeder: abre periodos con OpenTerm como admin, por modalidad (M4); las cargas y revisiones salen de
| los listeners.
*/

beforeEach(function () {
    $seeder = (new DatabaseSeeder)->setContainer(app());
    $seeder->call([CampusSeeder::class, AdminUserSeeder::class]);
    $seeder->callWith(AcademicDemoSeeder::class, ['campuses' => ['C2']]);
    Term::factory()->create(['code' => '20271']);
    Term::factory()->create(['code' => '20263']);
});

it('M4: abre los periodos pedidos en las modalidades pedidas, a nombre de admin', function () {
    (new TermOpeningDemoSeeder)->run(['20271' => ['PRES'], '20263' => ['PRES']]);

    $admin = User::where('username', 'admin')->sole();

    expect(TermOpening::count())->toBe(2)
        ->and(TermOpening::query()->pluck('opened_by')->unique()->all())->toBe([$admin->getKey()])
        ->and(TermOpening::query()->pluck('modality_id')->unique()->all())->toBe([modalityOf('PRES')->getKey()])
        ->and(AcademicLoad::count())->toBeGreaterThan(0)
        ->and(CatalogReview::count())->toBe(0)
        ->and(Auth::guard()->check())->toBeFalse();
});

it('es idempotente', function () {
    (new TermOpeningDemoSeeder)->run(['20271' => ['PRES']]);
    $counts = [TermOpening::count(), AcademicLoad::count(), CatalogReview::count()];

    (new TermOpeningDemoSeeder)->run(['20271' => ['PRES']]);

    expect([TermOpening::count(), AcademicLoad::count(), CatalogReview::count()])->toBe($counts);
});

it('se salta una modalidad inactiva o inexistente y un periodo inexistente', function () {
    expect(Modality::where('code', 'EAD')->sole()->is_active)->toBeFalse();

    (new TermOpeningDemoSeeder)->run(['20271' => ['EAD', 'M999'], '29991' => ['PRES']]);

    expect(TermOpening::count())->toBe(0);
});

it('no cambia el periodo actual', function () {
    Term::where('code', '20271')->update(['is_current' => true]);

    (new TermOpeningDemoSeeder)->run(['20263' => ['PRES']]);

    expect(Term::where('code', '20271')->sole()->is_current)->toBeTrue()
        ->and(Term::where('code', '20263')->sole()->is_current)->toBeFalse();
});

it('se niega a correr en producción', function () {
    $this->app->detectEnvironment(fn (): string => 'production');

    expect(fn () => (new TermOpeningDemoSeeder)->run())->toThrow(RuntimeException::class);
    expect(TermOpening::count())->toBe(0);
});
