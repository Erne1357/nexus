<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Actions\Agreements\ProposeSharedGroup;
use App\Modules\AcademicLoad\Enums\AgreementKind;
use App\Modules\AcademicLoad\Enums\AgreementStatus;
use App\Modules\AcademicLoad\Events\AgreementRequested;
use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| ProposeSharedGroup: el departamento que imparte un grupo propone el cupo de una carrera de otro departamento, y lo
| decide el departamento de la carrera (reglas-de-negocio.md, "Acuerdos entre departamentos"; HC-07; confirmado el
| 7 de noviembre de 2026).
*/

beforeEach(function () {
    $this->actor = User::factory()->create();
    $this->term = Term::factory()->create(['code' => '20271']);
    $this->campus = Campus::factory()->create(['code' => 'C2']);
    $this->from = Department::factory()->create(['code' => 'CB']);
    $this->to = Department::factory()->create(['code' => 'SIS']);
    $this->career = Career::factory()->create(['code' => 'ISC', 'department_id' => $this->to->id]);
    $this->groupId = (string) Str::uuid7();
});

function proposeShared(array $overrides = []): InterdepartmentalAgreement
{
    $t = test();
    $args = [
        'term' => $t->term, 'campus' => $t->campus, 'from' => $t->from, 'groupId' => $t->groupId, 'career' => $t->career,
        'seats' => 12, 'actor' => $t->actor, ...$overrides,
    ];

    return app(ProposeSharedGroup::class)->handle(...$args);
}

/**
 * @return array<string, list<string>>|null
 */
function proposeSharedErrors(array $overrides = []): ?array
{
    try {
        proposeShared($overrides);
    } catch (ValidationException $e) {
        return $e->errors();
    }

    return null;
}

it('crea el acuerdo propuesto dirigido al departamento de la carrera', function () {
    $agreement = proposeShared()->fresh();

    expect($agreement->kind)->toBe(AgreementKind::SharedGroup)
        ->and($agreement->status)->toBe(AgreementStatus::Proposed)
        ->and($agreement->from_department_id)->toBe($this->from->id)
        ->and($agreement->to_department_id)->toBe($this->to->id)
        ->and($agreement->career_id)->toBe($this->career->id)
        ->and($agreement->max_seats)->toBe(12)
        ->and($agreement->group_id)->toBe($this->groupId)
        ->and($agreement->room_id)->toBeNull()
        ->and($agreement->requested_by)->toBe($this->actor->id);
});

it('deja bitácora proposed con quién lo propuso', function () {
    $agreement = proposeShared();

    $activity = Activity::query()->sole();
    expect($activity->subject_id)->toBe($agreement->id)
        ->and($activity->causer_id)->toBe($this->actor->id)
        ->and($activity->event)->toBe('proposed')
        ->and($activity->attribute_changes['attributes'])->toMatchArray(['status' => 'proposed', 'career_id' => $this->career->id, 'max_seats' => 12])
        ->and($activity->description)->toBe('CB propuso a SIS 12 lugares de ISC en un grupo de C2 para el periodo 20271');
});

it('rechaza si la carrera ya tiene un acuerdo vivo en el grupo, sin abortar la transacción de quien llama', function (AgreementStatus $status) {
    InterdepartmentalAgreement::factory()->sharedGroup()->inStatus($status)->create([
        'term_id' => $this->term->id, 'campus_id' => $this->campus->id, 'group_id' => $this->groupId,
        'from_department_id' => $this->from->id, 'to_department_id' => $this->to->id, 'career_id' => $this->career->id,
    ]);

    DB::transaction(function () {
        expect(proposeSharedErrors())->toBe(['career_id' => ['La carrera ya tiene un acuerdo en este grupo.']]);
    });
    expect(InterdepartmentalAgreement::query()->count())->toBe(1)
        ->and(Activity::query()->count())->toBe(0);
})->with([AgreementStatus::Proposed, AgreementStatus::Accepted]);

it('un acuerdo rechazado o cancelado no estorba: propone uno nuevo', function (AgreementStatus $status) {
    InterdepartmentalAgreement::factory()->sharedGroup()->inStatus($status)->create([
        'term_id' => $this->term->id, 'campus_id' => $this->campus->id, 'group_id' => $this->groupId,
        'from_department_id' => $this->from->id, 'to_department_id' => $this->to->id, 'career_id' => $this->career->id,
    ]);

    expect(proposeShared()->status)->toBe(AgreementStatus::Proposed);
})->with([AgreementStatus::Rejected, AgreementStatus::Cancelled]);

it('si el índice único gana la carrera, el mensaje es el mismo', function () {
    InterdepartmentalAgreement::factory()->sharedGroup()->create([
        'term_id' => $this->term->id, 'campus_id' => $this->campus->id, 'group_id' => $this->groupId,
        'from_department_id' => $this->from->id, 'to_department_id' => $this->to->id, 'career_id' => $this->career->id,
    ]);
    // Simula la carrera: la lectura previa no ve el acuerdo vivo (otro proceso lo crea después).
    InterdepartmentalAgreement::addGlobalScope('hide', fn ($query) => $query->whereRaw('false'));

    try {
        expect(proposeSharedErrors())->toBe(['career_id' => ['La carrera ya tiene un acuerdo en este grupo.']]);
    } finally {
        InterdepartmentalAgreement::clearBootedModels();
    }
});

it('rechaza una carrera del propio departamento sin escribir', function () {
    $own = Career::factory()->create(['department_id' => $this->from->id]);

    expect(proposeSharedErrors(['career' => $own]))->toBe(['career_id' => ['La carrera es de tu departamento: su cupo no necesita acuerdo.']])
        ->and(InterdepartmentalAgreement::query()->count())->toBe(0);
});

it('exige lugares de 1 a 999', function (int $seats) {
    expect(proposeSharedErrors(['seats' => $seats]))->toBe(['max_seats' => ['Los lugares van de 1 a 999.']]);
})->with([0, -1, 1000]);

it('avisa con AgreementRequested solo después del commit', function () {
    Event::fake([AgreementRequested::class]);

    try {
        DB::transaction(function () {
            proposeShared();

            throw new RuntimeException('revertir');
        });
    } catch (RuntimeException) {
    }
    Event::assertNotDispatched(AgreementRequested::class);

    $agreement = proposeShared();
    Event::assertDispatched(AgreementRequested::class, fn (AgreementRequested $event): bool => $event->agreement->is($agreement));
});
