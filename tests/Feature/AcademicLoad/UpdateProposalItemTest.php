<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Actions\Proposals\AcceptProposalReview;
use App\Modules\AcademicLoad\Actions\Proposals\ReturnProposalReview;
use App\Modules\AcademicLoad\Actions\Proposals\UpdateProposalItem;
use App\Modules\AcademicLoad\Enums\ProposalReviewStatus;
use App\Modules\AcademicLoad\Enums\ProposalStatus;
use App\Modules\AcademicLoad\Events\ProposalItemUpdated;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\AcademicLoad\Models\ProposalItem;
use App\Modules\AcademicLoad\Models\ProposalReview;
use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\CampusProfile;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| UpdateProposalItem corrige una fila de la propuesta de carrera: alumnos esperados, grupos y notas
| (reglas-de-negocio.md, "Propuesta de carrera"). Solo en borrador o regresada, y nunca las materias de un
| departamento que ya aceptó su parte, también si la aceptó con la propuesta regresada (confirmado el 2 de
| noviembre de 2026). Bloquea la propuesta y luego la parte, como las Actions que la deciden, para no cruzarse con
| una aceptación (supuesto por confirmar). Esperados de 0 a 1000, grupos de 0 a 30 y notas de hasta 500
| caracteres (supuesto por confirmar).
*/

beforeEach(function () {
    $this->actor = User::factory()->create();
    $this->department = Department::factory()->create(['code' => 'CB']);
    $this->proposal = CareerProposal::factory()->create([
        'career_id' => Career::factory()->create(['code' => 'ISC'])->id,
        'campus_id' => Campus::factory()->create(['code' => 'C2'])->id,
    ]);
    $this->item = ProposalItem::factory()->create([
        'career_proposal_id' => $this->proposal->id,
        'subject_id' => Subject::factory()->create(['code' => 'ACF-0901', 'department_id' => $this->department->id])->id,
        'eligible_students' => 40,
        'expected_students' => 40,
        'requested_groups' => 2,
    ]);
});

function updateProposalItem(int $expected, int $groups, ?string $notes = null, ?ProposalItem $item = null): ProposalItem
{
    return app(UpdateProposalItem::class)->handle($item ?? test()->item, $expected, $groups, $notes, test()->actor);
}

/**
 * Los errores de la ValidationException que lanza `$update`, o null si no lanza.
 *
 * @return array<string, list<string>>|null
 */
function proposalItemErrors(Closure $update): ?array
{
    try {
        $update();
    } catch (ValidationException $e) {
        return $e->errors();
    }

    return null;
}

it('corrige esperados, grupos y notas en borrador, y deja bitácora de lo que cambió', function () {
    $item = updateProposalItem(45, 1, '  Un solo grupo grande  ');

    expect($item->fresh()->only(['eligible_students', 'expected_students', 'requested_groups', 'notes']))->toBe([
        'eligible_students' => 40,
        'expected_students' => 45,
        'requested_groups' => 1,
        'notes' => 'Un solo grupo grande',
    ]);

    $activity = Activity::query()->sole();
    expect($activity->subject_id)->toBe($this->item->id)
        ->and($activity->causer_id)->toBe($this->actor->id)
        ->and($activity->event)->toBe('updated')
        ->and($activity->attribute_changes['attributes'])->toBe(['expected_students' => 45, 'requested_groups' => 1, 'notes' => 'Un solo grupo grande'])
        ->and($activity->attribute_changes['old'])->toBe(['expected_students' => 40, 'requested_groups' => 2, 'notes' => null])
        ->and($activity->properties->all())->toBe(['career_proposal_id' => $this->proposal->id, 'subject_id' => $this->item->subject_id])
        ->and($activity->description)->toBe('Cambió ACF-0901 en la propuesta de ISC en C2');
});

it('registra solo lo que cambió y guarda unas notas vacías como nulas', function () {
    $this->item->update(['notes' => 'Antes']);

    updateProposalItem(40, 3, '   ');

    expect($this->item->fresh()->notes)->toBeNull()
        ->and(Activity::query()->sole()->attribute_changes['attributes'])->toBe(['requested_groups' => 3, 'notes' => null]);
});

it('sin cambios no escribe, no registra ni avisa', function () {
    Event::fake([ProposalItemUpdated::class]);

    updateProposalItem(40, 2);

    expect(Activity::query()->count())->toBe(0);
    Event::assertNotDispatched(ProposalItemUpdated::class);
});

it('avisa con ProposalItemUpdated y lo que cambió, solo después del commit', function () {
    Event::fake([ProposalItemUpdated::class]);

    updateProposalItem(40, 0);

    Event::assertDispatched(ProposalItemUpdated::class, fn (ProposalItemUpdated $event): bool => $event->item->is($this->item)
        && $event->changed === ['requested_groups']);
});

it('no avisa si la transacción externa se revierte', function () {
    Event::fake([ProposalItemUpdated::class]);

    try {
        DB::transaction(function () {
            updateProposalItem(10, 1);

            throw new RuntimeException('revertir');
        });
    } catch (RuntimeException) {
    }

    Event::assertNotDispatched(ProposalItemUpdated::class);
    expect($this->item->fresh()->expected_students)->toBe(40);
});

it('en una propuesta regresada se corrige lo de un departamento que no ha aceptado', function () {
    $this->proposal->update(['status' => ProposalStatus::Returned]);
    ProposalReview::factory()->returned()->create(['career_proposal_id' => $this->proposal->id, 'department_id' => $this->department->id]);
    ProposalReview::factory()->accepted()->create(['career_proposal_id' => $this->proposal->id]);

    updateProposalItem(40, 3);

    expect($this->item->fresh()->requested_groups)->toBe(3);
});

it('no cambia las materias de un departamento que ya aceptó su parte', function () {
    $this->proposal->update(['status' => ProposalStatus::Returned]);
    ProposalReview::factory()->accepted()->create(['career_proposal_id' => $this->proposal->id, 'department_id' => $this->department->id]);

    expect(proposalItemErrors(fn () => updateProposalItem(40, 3)))->toBe(['item' => ['CB ya aceptó su parte: sus materias ya no cambian.']])
        ->and($this->item->fresh()->requested_groups)->toBe(2)
        ->and(Activity::query()->count())->toBe(0);
});

it('con la propuesta regresada, la parte que un departamento acepta mientras tanto congela sus materias', function () {
    $this->proposal->campus->update(['settings' => [...CampusProfile::DEFAULTS, 'uses_rooms' => false]]);
    $sc = Department::factory()->create(['code' => 'SC']);
    $networks = ProposalItem::factory()->create([
        'career_proposal_id' => $this->proposal->id,
        'subject_id' => Subject::factory()->create(['department_id' => $sc->id])->id,
        'requested_groups' => 1,
    ]);
    $this->proposal->update(['status' => ProposalStatus::Submitted]);
    $cbPart = ProposalReview::factory()->create(['career_proposal_id' => $this->proposal->id, 'department_id' => $this->department->id]);
    $scPart = ProposalReview::factory()->create(['career_proposal_id' => $this->proposal->id, 'department_id' => $sc->id]);
    AcademicLoad::factory()->create(['term_id' => $this->proposal->term_id, 'campus_id' => $this->proposal->campus_id, 'department_id' => $this->department->id]);
    foreach (Catalog::general() as $catalog) {
        CatalogReview::factory()->general($catalog)->validated()->create(['term_id' => $this->proposal->term_id, 'department_id' => $this->department->id]);
    }

    app(ReturnProposalReview::class)->handle($scPart, 'Pide dos grupos de Redes.', $this->actor);
    updateProposalItem(40, 3);
    app(AcceptProposalReview::class)->handle($cbPart, $this->actor);

    expect($this->proposal->fresh()->status)->toBe(ProposalStatus::Returned)
        ->and($cbPart->fresh()->status)->toBe(ProposalReviewStatus::Accepted)
        ->and(proposalItemErrors(fn () => updateProposalItem(40, 4)))->toBe(['item' => ['CB ya aceptó su parte: sus materias ya no cambian.']])
        ->and($this->item->fresh()->requested_groups)->toBe(3);

    updateProposalItem(30, 2, item: $networks);

    expect($networks->fresh()->requested_groups)->toBe(2);
});

it('bloquea la propuesta, luego la parte del departamento de la materia y luego la fila', function () {
    $this->proposal->update(['status' => ProposalStatus::Returned]);
    ProposalReview::factory()->create(['career_proposal_id' => $this->proposal->id, 'department_id' => $this->department->id]);
    ProposalReview::factory()->returned()->create(['career_proposal_id' => $this->proposal->id]);
    $sql = [];
    DB::listen(function ($query) use (&$sql) {
        $sql[] = $query->sql;
    });

    updateProposalItem(40, 3);

    $locked = collect($sql)
        ->filter(fn (string $s): bool => str_ends_with($s, 'for update'))
        ->map(fn (string $s): string => preg_match('/from "(\w+)"/', $s, $match) ? $match[1] : $s)
        ->values()
        ->all();
    expect($locked)->toBe(['career_proposals', 'proposal_reviews', 'proposal_items'])
        ->and(collect($sql)->filter(fn (string $s): bool => str_contains($s, 'for share')))->toBeEmpty();
});

it('no cambia una propuesta enviada ni una aceptada', function (ProposalStatus $status, string $message) {
    $this->proposal->update(['status' => $status]);

    expect(proposalItemErrors(fn () => updateProposalItem(40, 3)))->toBe(['status' => [$message]])
        ->and($this->item->fresh()->requested_groups)->toBe(2);
})->with([
    'enviada' => [ProposalStatus::Submitted, 'La propuesta ya se envió: solo se cambia si un departamento la regresa.'],
    'aceptada' => [ProposalStatus::Accepted, 'La propuesta ya se aceptó: ya no cambia.'],
]);

it('usa el estado de la propuesta en la base y no el del modelo que llegó', function () {
    $item = ProposalItem::query()->with('proposal')->findOrFail($this->item->id);
    DB::table('career_proposals')->where('id', $this->proposal->id)->update(['status' => 'submitted']);

    expect(proposalItemErrors(fn () => updateProposalItem(40, 3, item: $item)))->toHaveKey('status');
});

it('toma el valor anterior de la base aunque la fila llegue desactualizada', function () {
    $stale = ProposalItem::query()->findOrFail($this->item->id);
    DB::table('proposal_items')->where('id', $this->item->id)->update(['requested_groups' => 5]);

    updateProposalItem(40, 1, item: $stale);

    expect(Activity::query()->sole()->attribute_changes['old'])->toBe(['requested_groups' => 5]);
});

it('rechaza esperados, grupos y notas fuera de rango, cada uno en su campo, sin escribir', function (int $expected, int $groups, ?string $notes, array $errors) {
    expect(proposalItemErrors(fn () => updateProposalItem($expected, $groups, $notes)))->toBe($errors)
        ->and($this->item->fresh()->requested_groups)->toBe(2);
})->with([
    'esperados negativos' => [-1, 1, null, ['expected_students' => ['Los alumnos esperados van de 0 a 1000.']]],
    'esperados de más' => [1001, 1, null, ['expected_students' => ['Los alumnos esperados van de 0 a 1000.']]],
    'grupos negativos' => [40, -1, null, ['requested_groups' => ['Los grupos solicitados van de 0 a 30.']]],
    'grupos de más' => [40, 31, null, ['requested_groups' => ['Los grupos solicitados van de 0 a 30.']]],
    'notas largas' => [40, 1, fn () => str_repeat('a', 501), ['notes' => ['Las notas llevan hasta 500 caracteres.']]],
    'todo' => [-1, 31, fn () => str_repeat('a', 501), [
        'expected_students' => ['Los alumnos esperados van de 0 a 1000.'],
        'requested_groups' => ['Los grupos solicitados van de 0 a 30.'],
        'notes' => ['Las notas llevan hasta 500 caracteres.'],
    ]],
]);

it('acepta los extremos del rango', function () {
    updateProposalItem(1000, 30, str_repeat('ñ', 500));
    updateProposalItem(0, 0);

    expect($this->item->fresh()->only(['expected_students', 'requested_groups']))->toBe(['expected_students' => 0, 'requested_groups' => 0]);
});
