<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Actions\Proposals\ReturnProposalReview;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Enums\ProposalReviewStatus;
use App\Modules\AcademicLoad\Enums\ProposalStatus;
use App\Modules\AcademicLoad\Events\CareerProposalReturned;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\AcademicLoad\Models\ProposalReview;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| ReturnProposalReview: el departamento regresa su parte de una propuesta de carrera con comentario y la propuesta
| vuelve al coordinador (reglas-de-negocio.md, "Propuesta de carrera"; confirmado el 2 de noviembre de 2026).
| Regresar no exige los catálogos validados (confirmado). Solo se decide una parte pendiente de una propuesta
| enviada, y el comentario es obligatorio, recortado y de hasta 1000 caracteres (supuestos por confirmar).
*/

beforeEach(function () {
    $this->actor = User::factory()->create();
    $this->term = Term::factory()->create(['code' => '20271']);
    $this->campus = Campus::factory()->create(['code' => 'C2']);
    $this->cb = Department::factory()->create(['code' => 'CB']);
    $this->proposal = CareerProposal::factory()->submitted()->create([
        'term_id' => $this->term->id,
        'campus_id' => $this->campus->id,
        'career_id' => Career::factory()->create(['code' => 'ISC'])->id,
    ]);
    $this->part = ProposalReview::factory()->create(['career_proposal_id' => $this->proposal->id, 'department_id' => $this->cb->id]);
    $this->accepted = ProposalReview::factory()->accepted()->create(['career_proposal_id' => $this->proposal->id]);
    $this->pending = ProposalReview::factory()->create(['career_proposal_id' => $this->proposal->id]);
    $this->load = AcademicLoad::factory()->create(['term_id' => $this->term->id, 'campus_id' => $this->campus->id, 'department_id' => $this->cb->id]);
});

function returnPart(string $comment = 'Pide un grupo menos de Cálculo.', ?ProposalReview $part = null): ProposalReview
{
    return app(ReturnProposalReview::class)->handle($part ?? test()->part, $comment, test()->actor);
}

/**
 * Los errores de la ValidationException al regresar, o null si no lanza.
 *
 * @return array<string, list<string>>|null
 */
function returnErrors(string $comment = 'Pide un grupo menos de Cálculo.', ?ProposalReview $part = null): ?array
{
    try {
        returnPart($comment, $part);
    } catch (ValidationException $e) {
        return $e->errors();
    }

    return null;
}

it('regresa la parte con su comentario recortado y la propuesta vuelve al coordinador', function () {
    $part = returnPart('  Pide un grupo menos de Cálculo.  ');

    $fresh = $part->fresh();
    expect($fresh->status)->toBe(ProposalReviewStatus::Returned)
        ->and($fresh->comment)->toBe('Pide un grupo menos de Cálculo.')
        ->and($fresh->decided_by)->toBe($this->actor->id)
        ->and($fresh->decided_at)->not->toBeNull()
        ->and($this->proposal->fresh()->status)->toBe(ProposalStatus::Returned);
});

it('deja bitácora de la parte con el comentario y el cambio de la propuesta', function () {
    returnPart();

    $activity = Activity::query()->sole();
    expect($activity->subject_id)->toBe($this->part->id)
        ->and($activity->causer_id)->toBe($this->actor->id)
        ->and($activity->event)->toBe('returned')
        ->and($activity->attribute_changes['old'])->toBe(['status' => 'pending', 'comment' => null])
        ->and($activity->attribute_changes['attributes'])->toBe(['status' => 'returned', 'comment' => 'Pide un grupo menos de Cálculo.'])
        ->and($activity->properties->all())->toBe([
            'career_proposal_id' => $this->proposal->id,
            'proposal_status' => ['old' => 'submitted', 'attributes' => 'returned'],
        ])
        ->and($activity->description)->toBe('Regresó la parte de CB en la propuesta de ISC en C2 para el periodo 20271');
});

it('no toca las otras partes ni la carga del departamento', function () {
    returnPart();

    expect($this->accepted->fresh()->status)->toBe(ProposalReviewStatus::Accepted)
        ->and($this->pending->fresh()->status)->toBe(ProposalReviewStatus::Pending)
        ->and($this->load->fresh()->status)->toBe(LoadStatus::Draft)
        ->and($this->load->transitions()->count())->toBe(0);
});

it('regresar no exige los catálogos validados ni la carga del departamento', function () {
    $this->load->delete();

    expect(returnPart()->status)->toBe(ProposalReviewStatus::Returned);
});

it('exige un comentario de hasta 1000 caracteres y no escribe nada', function (string $comment, string $message) {
    expect(returnErrors($comment))->toBe(['comment' => [$message]])
        ->and($this->part->fresh()->status)->toBe(ProposalReviewStatus::Pending)
        ->and($this->proposal->fresh()->status)->toBe(ProposalStatus::Submitted)
        ->and(Activity::query()->count())->toBe(0);
})->with([
    'vacío' => ['', 'Escribe por qué regresas la propuesta.'],
    'solo espacios' => ['   ', 'Escribe por qué regresas la propuesta.'],
    'muy largo' => [fn () => str_repeat('a', 1001), 'El comentario lleva hasta 1000 caracteres.'],
]);

it('acepta un comentario de 1000 caracteres', function () {
    expect(returnPart(str_repeat('ñ', 1000))->fresh()->comment)->toBe(str_repeat('ñ', 1000));
});

it('no decide una parte ya aceptada o regresada', function (string $state, string $message) {
    $this->part->update(match ($state) {
        'accepted' => ['status' => ProposalReviewStatus::Accepted, 'decided_by' => $this->actor->id, 'decided_at' => now()],
        'returned' => ['status' => ProposalReviewStatus::Returned, 'comment' => 'Antes.', 'decided_by' => $this->actor->id, 'decided_at' => now()],
    });

    expect(returnErrors())->toBe(['status' => [$message]])
        ->and($this->part->fresh()->status->value)->toBe($state)
        ->and($this->proposal->fresh()->status)->toBe(ProposalStatus::Submitted);
})->with([
    'aceptada' => ['accepted', 'CB ya aceptó su parte.'],
    'regresada' => ['returned', 'CB ya regresó su parte.'],
]);

it('no decide con la propuesta en borrador, regresada o aceptada', function (ProposalStatus $status, string $message) {
    $this->proposal->update(['status' => $status]);

    expect(returnErrors())->toBe(['status' => [$message]])
        ->and($this->part->fresh()->status)->toBe(ProposalReviewStatus::Pending);
})->with([
    'borrador' => [ProposalStatus::Draft, 'La propuesta todavía no se envía.'],
    'regresada' => [ProposalStatus::Returned, 'La propuesta está regresada: se decide cuando el coordinador la reenvíe.'],
    'aceptada' => [ProposalStatus::Accepted, 'La propuesta ya se aceptó.'],
]);

it('usa el estado de la base y no el del modelo que llegó', function () {
    $stale = ProposalReview::query()->findOrFail($this->part->id);
    DB::table('career_proposals')->where('id', $this->proposal->id)->update(['status' => 'returned']);

    expect(returnErrors(part: $stale))->toBe(['status' => ['La propuesta está regresada: se decide cuando el coordinador la reenvíe.']]);
});

it('bloquea la propuesta y luego la parte', function () {
    $sql = [];
    DB::listen(function ($query) use (&$sql) {
        $sql[] = $query->sql;
    });

    returnPart();

    $locked = collect($sql)
        ->filter(fn (string $s): bool => str_ends_with($s, 'for update'))
        ->map(fn (string $s): string => preg_match('/from "(\w+)"/', $s, $match) ? $match[1] : $s)
        ->values()
        ->all();
    expect($locked)->toBe(['career_proposals', 'proposal_reviews']);
});

it('avisa con CareerProposalReturned, el departamento y el comentario, solo después del commit', function () {
    Event::fake([CareerProposalReturned::class]);

    returnPart('  Sin docentes para Cálculo.  ');

    Event::assertDispatched(CareerProposalReturned::class, fn (CareerProposalReturned $event): bool => $event->proposal->is($this->proposal)
        && $event->department->is($this->cb)
        && $event->comment === 'Sin docentes para Cálculo.');
});

it('no avisa si la transacción externa se revierte', function () {
    Event::fake([CareerProposalReturned::class]);

    try {
        DB::transaction(function () {
            returnPart();

            throw new RuntimeException('revertir');
        });
    } catch (RuntimeException) {
    }

    Event::assertNotDispatched(CareerProposalReturned::class);
    expect($this->part->fresh()->status)->toBe(ProposalReviewStatus::Pending)
        ->and($this->proposal->fresh()->status)->toBe(ProposalStatus::Submitted);
});
