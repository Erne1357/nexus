<?php

use App\Modules\Core\Actions\SetTermStage;
use App\Modules\Core\Enums\Stage;
use App\Modules\Core\Events\TermCalendarChanged;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermStage;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| SetTermStage: upsert de la ventana de una etapa, general o de un campus.
*/

it('crea la ventana general y la cambia después sin duplicarla', function () {
    $term = Term::factory()->create();

    app(SetTermStage::class)->handle($term, Stage::Proposal, null, '2026-11-02', '2026-11-13');
    app(SetTermStage::class)->handle($term, Stage::Proposal, null, '2026-11-02', '2026-11-20');

    $window = TermStage::sole();

    expect($window->campus_id)->toBeNull()
        ->and($window->stage)->toBe(Stage::Proposal)
        ->and($window->ends_on->toDateString())->toBe('2026-11-20');
});

it('crea una ventana por campus aparte de la general', function () {
    $term = Term::factory()->create();
    $campus = Campus::factory()->create();

    app(SetTermStage::class)->handle($term, Stage::Proposal, null, '2026-11-02', '2026-11-13');
    app(SetTermStage::class)->handle($term, Stage::Proposal, $campus, '2026-11-05', '2026-11-16');

    expect(TermStage::count())->toBe(2)
        ->and(TermStage::where('campus_id', $campus->id)->sole()->starts_on->toDateString())->toBe('2026-11-05');
});

it('no restringe las fechas contra las del periodo', function () {
    $term = Term::factory()->create(['starts_on' => '2027-01-18', 'ends_on' => '2027-06-11']);

    app(SetTermStage::class)->handle($term, Stage::CatalogValidation, null, '2026-09-01', '2026-09-30');

    expect(TermStage::count())->toBe(1);
});

it('rechaza un fin anterior al inicio sobre ends_on', function () {
    $term = Term::factory()->create();

    expect(fn () => app(SetTermStage::class)->handle($term, Stage::Proposal, null, '2026-11-13', '2026-11-02'))
        ->toThrow(ValidationException::class, 'La fecha de fin no puede ser anterior');
});

it('rechaza un campus inactivo o borrado', function () {
    $term = Term::factory()->create();
    $inactive = Campus::factory()->inactive()->create();
    $deleted = Campus::factory()->create();
    $deleted->delete();

    foreach ([$inactive, $deleted] as $campus) {
        expect(fn () => app(SetTermStage::class)->handle($term, Stage::Proposal, $campus, '2026-11-02', '2026-11-13'))
            ->toThrow(ValidationException::class, 'El campus debe estar activo.');
    }

    expect(TermStage::count())->toBe(0);
});

it('registra en la bitácora la creación y el cambio con las fechas anteriores y nuevas', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    $term = Term::factory()->create(['code' => '20271']);

    app(SetTermStage::class)->handle($term, Stage::Proposal, null, '2026-11-02', '2026-11-13');
    app(SetTermStage::class)->handle($term, Stage::Proposal, null, '2026-11-02', '2026-11-20');

    [$created, $updated] = Activity::orderBy('id')->get()->all();

    expect($created->event)->toBe('created')
        ->and($created->description)->toBe('Fijó la etapa Propuesta por carrera (general) del periodo 20271')
        ->and($created->causer->is($admin))->toBeTrue()
        ->and($updated->event)->toBe('updated')
        ->and($updated->attribute_changes['old'])->toBe(['starts_on' => '2026-11-02', 'ends_on' => '2026-11-13'])
        ->and($updated->attribute_changes['attributes']['ends_on'])->toBe('2026-11-20');
});

it('si las fechas no cambian no registra ni avisa', function () {
    $term = Term::factory()->create();
    app(SetTermStage::class)->handle($term, Stage::Proposal, null, '2026-11-02', '2026-11-13');
    Event::fake([TermCalendarChanged::class]);
    $before = Activity::count();

    app(SetTermStage::class)->handle($term, Stage::Proposal, null, '2026-11-02', '2026-11-13');

    expect(Activity::count())->toBe($before);
    Event::assertNotDispatched(TermCalendarChanged::class);
});

it('avisa con TermCalendarChanged solo después del commit', function () {
    $heard = [];
    Event::listen(TermCalendarChanged::class, function (TermCalendarChanged $event) use (&$heard): void {
        $heard[] = $event->campus?->code;
    });
    $term = Term::factory()->create();
    $campus = Campus::factory()->create(['code' => 'C2']);

    try {
        DB::transaction(function () use ($term): void {
            app(SetTermStage::class)->handle($term, Stage::Proposal, null, '2026-11-02', '2026-11-13');

            throw new RuntimeException('Falla después.');
        });
    } catch (RuntimeException) {
        //
    }

    expect($heard)->toBe([]);

    app(SetTermStage::class)->handle($term, Stage::Proposal, $campus, '2026-11-02', '2026-11-13');

    expect($heard)->toBe(['C2']);
});
