<?php

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Enums\RepublicationAudience;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Notifications\LoadRepublished;
use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Notifications\CatalogReviewsPending;
use App\Modules\Core\Authorization\AssignmentScope;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Core\Notifications\NexusNotification;
use App\Modules\Core\Notifications\PositionNotice;
use App\Modules\Staffing\Notifications\ChangeAlternativeMovesCareerGroup;
use App\Modules\Staffing\Notifications\SelectionFinishedWithPending;

/*
| CE-02: los cuatro avisos que compiten al puesto (la validación de catálogos, la republicación a una coordinación o a
| otra jefatura, el grupo movido por una solicitud y la selección terminada con pendientes) son avisos de puesto: su
| puesto es el nombramiento por el que quien lo recibe cubre lo avisado (el rol y su alcance, que le pasa NotifyHolders),
| y su correo va al buzón de ese puesto. Sin nombramiento (no se mandaron a un puesto, o un aviso en cola de antes),
| siguen a la persona. El aviso de la republicación al docente es de persona.
*/

beforeEach(function () {
    $this->department = Department::factory()->create(['code' => 'SIS', 'name' => 'Sistemas y Computación']);
    $this->term = Term::factory()->create();
    $this->load = AcademicLoad::factory()->create(['term_id' => $this->term->id]);
});

/**
 * Los cuatro avisos de puesto, cada uno como lo arma su módulo.
 *
 * @return array<string, array{0: Closure(): NexusNotification}>
 */
function positionNotices(): array
{
    return [
        'validación de catálogos' => [fn () => new CatalogReviewsPending(test()->term, test()->department, [Catalog::Teachers])],
        'republicación a una coordinación' => [fn () => new LoadRepublished(test()->load, 2, null, RepublicationAudience::Career, 'ISC', [], 'published.index', [])],
        'republicación a otra jefatura' => [fn () => new LoadRepublished(test()->load, 2, null, RepublicationAudience::Department, 'Sistemas', [], 'published.index', [])],
        'grupo movido' => [fn () => new ChangeAlternativeMovesCareerGroup(['group_id' => fakeKey(1), 'label' => 'SCD-1015 · 5A', 'subject' => 'Redes', 'career' => 'ISC', 'campus' => 'C2', 'from' => 'L 7:00', 'to' => 'M 9:00'], 'SCD-1015 · 4A', 'A', fakeKey(2), test()->load->modality_id)],
        'selección con pendientes' => [fn () => new SelectionFinishedWithPending(test()->term->id, test()->load->modality_id, 'Sistemas y Computación', fakeKey(7), ['Docente Uno'])],
    ];
}

it('CE-02: el puesto del aviso es el nombramiento de quien lo recibe, con las columnas que su rol admite', function (Closure $make) {
    $coordinator = new AssignmentScope(Role::Coordinador, fakeKey(5), null, careerKey(1), $this->load->modality_id);
    $head = new AssignmentScope(Role::JefeDepartamento, null, $this->department->id, null, null);

    expect($make())->toBeInstanceOf(PositionNotice::class)
        ->and($make()->addressedTo(Role::Coordinador, $coordinator)->position()->columns())->toBe(['org_unit_id' => null, 'career_id' => careerKey(1), 'campus_id' => fakeKey(5), 'modality_id' => $this->load->modality_id])
        ->and($make()->addressedTo(Role::JefeDepartamento, $head)->position()->columns())->toBe(['org_unit_id' => $this->department->id, 'career_id' => null, 'campus_id' => null, 'modality_id' => null])
        ->and($make()->addressedTo(Role::JefeDepartamento)->position())->toBeNull()
        ->and($make()->position())->toBeNull();
})->with(positionNotices());

it('CE-02: el aviso de la republicación al docente es de persona', function () {
    $notice = (new LoadRepublished($this->load, 2, null, RepublicationAudience::Teacher, 'Ana', [], 'published.index', []))
        ->addressedTo(Role::Docente, new AssignmentScope(Role::Docente, null, null, null, null));

    expect($notice->position())->toBeNull();
});

it('CE-02: un aviso de puesto va a la campana y al correo aunque el puesto no tenga buzón; la copia que comparte el correo, solo a la campana', function () {
    $user = User::factory()->create(['email' => null]);
    $notice = (new CatalogReviewsPending($this->term, $this->department, [Catalog::Teachers]))
        ->addressedTo(Role::JefeDepartamento, new AssignmentScope(Role::JefeDepartamento, null, $this->department->id, null, null));

    expect($notice->via($user))->toBe(['database', 'mail'])
        ->and($user->routeNotificationFor('mail', $notice))->toBeNull()
        ->and((clone $notice)->sharesPositionMail()->via($user))->toBe(['database']);
});

it('CE-02: un aviso en cola de antes, sin el nombramiento, sigue a la persona', function () {
    $user = User::factory()->create(['email' => 'coordinadora@cdjuarez.tecnm.mx']);
    $legacy = (new ChangeAlternativeMovesCareerGroup(['group_id' => fakeKey(1), 'label' => 'SCD-1015 · 5A', 'subject' => null, 'career' => 'ISC', 'campus' => 'C2', 'from' => 'L 7:00', 'to' => 'M 9:00'], 'SCD-1015 · 4A', 'A', fakeKey(2), $this->load->modality_id))
        ->addressedTo(Role::Coordinador);
    $restored = unserialize(serialize($legacy));

    expect($restored->position())->toBeNull()
        ->and($restored->via($user))->toBe(['database', 'mail'])
        ->and($user->routeNotificationFor('mail', $restored))->toBe('coordinadora@cdjuarez.tecnm.mx');
});
