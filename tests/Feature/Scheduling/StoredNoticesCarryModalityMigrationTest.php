<?php

use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
| Contraer (ronda 2 de la carga por modalidad, ADR-0025): antes de quitar el puente `campuses.modality_id`, los avisos
| guardados de la propuesta y de los acuerdos que llevan solo el campus pasan a llevar la modalidad de ese campus, sin
| campus; un préstamo de salón lleva su campus en el filtro de la pantalla (MS14). Se prueba sobre la forma vieja: el
| `down()` de la contracción de campus (que devuelve el puente), avisos con `DB::table()` y `up()`.
*/

beforeEach(function () {
    (require base_path('app/Modules/Core/Database/Migrations/2026_11_12_211102_contract_campuses_to_places.php'))->down();
    $this->migration = require base_path('app/Modules/Scheduling/Database/Migrations/2026_11_12_211101_carry_modality_on_stored_notices.php');
    $this->c1 = campusBeforeContraction('C1');
    $this->c2 = campusBeforeContraction('C2');
});

/**
 * Un aviso guardado con la forma de antes: el campus y no la modalidad.
 *
 * @param  array<string, string>  $parameters
 */
function noticeBeforeModality(string $type, string $campusId, array $parameters, ?string $modalityId = null): string
{
    $id = (string) Str::uuid7();

    DB::table('notifications')->insert([
        'id' => $id,
        'type' => $type,
        'notifiable_type' => User::class,
        'notifiable_id' => User::factory()->create()->id,
        'data' => json_encode([
            'title' => 'Aviso', 'body' => 'Cuerpo', 'icon' => 'o-bell', 'tone' => 'info', 'route' => 'scheduling.agreements',
            'parameters' => $parameters, 'term_id' => null, 'campus_id' => $campusId,
            ...($modalityId === null ? [] : ['modality_id' => $modalityId]),
        ]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

/**
 * @return array<string, mixed>
 */
function noticeData(string $id): array
{
    return json_decode(DB::table('notifications')->where('id', $id)->value('data'), true);
}

it('MS14: los avisos guardados de la propuesta con solo el campus pasan a llevar la modalidad de ese campus, sin campus', function () {
    $submitted = noticeBeforeModality('App\\Modules\\AcademicLoad\\Notifications\\ProposalSubmitted', $this->c2->id, ['carrera' => 'ISC']);
    $returned = noticeBeforeModality('App\\Modules\\AcademicLoad\\Notifications\\ProposalReturned', $this->c1->id, ['carrera' => 'ISC']);

    $this->migration->up();

    foreach ([$submitted, $returned] as $id) {
        expect(noticeData($id))->toMatchArray(['modality_id' => modalityOf('PRES')->id, 'campus_id' => null, 'parameters' => ['carrera' => 'ISC']]);
    }
});

it('MS14: los avisos guardados de un préstamo de salón llevan la modalidad y el campus en el filtro; los de un grupo compartido, solo la modalidad', function () {
    $loan = InterdepartmentalAgreement::factory()->create(['campus_id' => $this->c2->id, 'modality_id' => modalityOf('PRES')->id]);
    $shared = InterdepartmentalAgreement::factory()->sharedGroup()->create(['modality_id' => modalityOf('PRES')->id]);
    $received = noticeBeforeModality('App\\Modules\\Scheduling\\Notifications\\AgreementReceived', $this->c2->id, ['acuerdo' => $loan->id]);
    $cancelled = noticeBeforeModality('App\\Modules\\Scheduling\\Notifications\\LoanCancelled', $this->c2->id, ['acuerdo' => $loan->id]);
    $answered = noticeBeforeModality('App\\Modules\\Scheduling\\Notifications\\AgreementAnswered', $this->c2->id, ['acuerdo' => $shared->id]);

    $this->migration->up();

    foreach ([$received, $cancelled] as $id) {
        expect(noticeData($id))->toMatchArray(['modality_id' => modalityOf('PRES')->id, 'campus_id' => null, 'parameters' => ['acuerdo' => $loan->id, 'campus' => 'C2']]);
    }

    expect(noticeData($answered))->toMatchArray(['modality_id' => modalityOf('PRES')->id, 'campus_id' => null, 'parameters' => ['acuerdo' => $shared->id]]);
});

it('MS14: no toca los avisos que ya llevan la modalidad ni los que son de un campus por diseño', function () {
    $withModality = noticeBeforeModality('App\\Modules\\Scheduling\\Notifications\\AgreementReceived', $this->c2->id, ['acuerdo' => (string) Str::uuid7()], modalityOf('EAD')->id);
    $catalogs = noticeBeforeModality('App\\Modules\\CatalogValidation\\Notifications\\CatalogReviewsPending', $this->c2->id, ['departamento' => 'x']);
    $before = [noticeData($withModality), noticeData($catalogs)];

    $this->migration->up();

    expect([noticeData($withModality), noticeData($catalogs)])->toBe($before);
});
