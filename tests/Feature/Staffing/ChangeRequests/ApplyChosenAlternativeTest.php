<?php

use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\Core\Models\Campus;
use App\Modules\Scheduling\Actions\Sessions\ReplaceGroupSessions;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Solver\Actions\ProposePlacements;
use App\Modules\Solver\Queries\BuildSolverInput;
use App\Modules\Solver\Rules\InputFingerprint;
use App\Modules\Staffing\Actions\ChangeRequests\ApplyChosenAlternative;
use App\Modules\Staffing\Actions\ChangeRequests\ApproveChangeRequest;
use App\Modules\Staffing\Actions\ChangeRequests\ChooseChangeAlternative;
use App\Modules\Staffing\Enums\ChangeRequestStatus;
use App\Modules\Staffing\Notifications\ChangeAlternativeMovesCareerGroup;
use App\Modules\Staffing\Notifications\ChangeRequestApplied;
use App\Modules\Staffing\Notifications\ChangeRequestMovedTeacherGroups;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/ChangeAlternativesSupport.php';

/*
| Aplicar la alternativa elegida (tarea 8.7; CR-03 y CR-04) y los avisos que decidió el usuario el 18 de noviembre de
| 2026: al elegir una cadena que mueve un grupo de otra carrera se avisa a su coordinación; al aplicar se escriben las
| sesiones de todos los grupos de la alternativa, la solicitud queda aplicada y se avisa a los docentes cuyos grupos
| cambiaron de horario.
*/

beforeEach(function () {
    $this->w = altWorld();
});

it('CR-04: aplicar escribe las sesiones de todos los grupos de la alternativa, también el de otra carrera, y la marca aplicada', function () {
    $request = altProposed($this->w);
    app(ChooseChangeAlternative::class)->handle($request, $this->w->jefe, altId($request));
    $chain = collect($request->fresh()->alternatives['items'])->firstWhere('id', altId($request));
    $otherStarts = collect(collect($chain['groups'])->firstWhere('group_id', $this->w->other->id)['sessions'])->pluck('starts_at')->unique()->sort()->values()->all();

    $result = app(ApplyChosenAlternative::class)->handle($request->fresh(), $this->w->coordIsc);

    expect($result->request->status)->toBe(ChangeRequestStatus::Applied)
        ->and($result->request->applied_by)->toBe($this->w->coordIsc->id)
        ->and(altLiveStarts($this->w->group))->toBe(['09:00'])
        ->and(altLiveStarts($this->w->other))->toBe($otherStarts)
        ->and(GroupSession::query()->where('group_id', $this->w->other->id)->where('room_id', $this->w->roomA->id)->where('starts_at', '09:00')->exists())->toBeFalse()
        ->and($result->vetoReasons)->toBe([])
        ->and($result->returnedToStaffing)->toBeTrue()
        ->and($this->w->load->fresh()->status)->toBe(LoadStatus::Staffing)
        ->and(Activity::query()->where('subject_id', $request->id)->where('event', 'applied')->exists())->toBeTrue()
        ->and(Activity::query()->where('event', ReplaceGroupSessions::EVENT)->whereIn('subject_id', [$this->w->group->id, $this->w->other->id])->count())->toBe(2);
});

it('CR-04: si el horario cambió desde que se eligió, no escribe nada y dice «Esto cambió…»', function () {
    $request = altProposed($this->w);
    app(ChooseChangeAlternative::class)->handle($request, $this->w->jefe, altId($request));
    GroupSession::query()->where('group_id', $this->w->other->id)->where('day', 4)->update(['starts_at' => '12:00', 'ends_at' => '13:00']);

    try {
        app(ApplyChosenAlternative::class)->handle($request->fresh(), $this->w->coordIsc);
        $this->fail('Debió rechazar la alternativa vencida.');
    } catch (ValidationException $e) {
        expect($e->errors()['alternative'][0])->toStartWith('Esto cambió');
    }

    expect($request->fresh()->status)->toBe(ChangeRequestStatus::Approved)
        ->and(altLiveStarts($this->w->group))->toBe(['07:00']);
});

it('CR-04: no aplica una solicitud aprobada sin alternativa elegida ni una pendiente', function (string $case) {
    $request = $case === 'sin alternativa'
        ? app(ApproveChangeRequest::class)->handle(altRequest($this->w, '11:00'), $this->w->jefe)
        : altProposed($this->w);

    app(ApplyChosenAlternative::class)->handle($request->fresh(), $this->w->coordIsc);
})->with(['sin alternativa', 'pendiente'])->throws(ValidationException::class);

it('CR-02: una alternativa que mueve un grupo de otro campus (calculada antes del límite por campus) ya no se elige', function () {
    $request = altProposed($this->w);
    $other = Group::factory()->create(['term_id' => $this->w->term->id, 'modality_id' => modalityOf('PRES')->id, 'campus_id' => Campus::factory()->create()->id,
        'subject_id' => Subject::factory()->create(['department_id' => $this->w->sc->id])->id, 'department_id' => $this->w->sc->id]);
    $alternatives = $request->alternatives;
    $movable = [...collect($alternatives['items'])->firstWhere('id', altId($request))['movable_group_ids'], $other->id];
    // Con la huella de esa entrada, como si se hubiera calculado así: solo la revisión del campus la descarta.
    $fingerprint = InputFingerprint::of(app(BuildSolverInput::class)->forGroups($this->w->term, modalityOf('PRES'), $movable, [], null, ProposePlacements::TIME_LIMIT_SECONDS));
    $alternatives['items'] = array_map(fn (array $item): array => $item['id'] === altId($request) ? [...$item, 'movable_group_ids' => $movable, 'fingerprint' => $fingerprint] : $item, $alternatives['items']);
    $request->forceFill(['alternatives' => $alternatives])->save();

    try {
        app(ChooseChangeAlternative::class)->handle($request->fresh(), $this->w->jefe, altId($request));
        $this->fail('Debió rechazar la alternativa con un grupo de otro campus.');
    } catch (ValidationException $e) {
        expect($e->errors()['alternative'][0])->toStartWith('Esto cambió');
    }

    expect($request->fresh()->status)->toBe(ChangeRequestStatus::Pending);
});

it('alcance: solo aplica quien aplica según el tipo (la coordinación de la carrera dueña en uno de horario)', function (string $who) {
    $request = altProposed($this->w);
    app(ChooseChangeAlternative::class)->handle($request, $this->w->jefe, altId($request));
    $actor = match ($who) {
        'otra coordinación' => $this->w->coordIind,
        'jefe de otro departamento' => $this->w->jefeCb,
        'docente' => $this->w->teacherUser,
    };

    expect(fn () => app(ApplyChosenAlternative::class)->handle($request->fresh(), $actor))->toThrow(AuthorizationException::class)
        ->and($request->fresh()->status)->toBe(ChangeRequestStatus::Approved)
        ->and(altLiveStarts($this->w->group))->toBe(['07:00']);
})->with(['otra coordinación', 'jefe de otro departamento', 'docente']);

it('al aplicar avisa a cada docente cuyo grupo cambió de horario, con la campana y su correo institucional', function () {
    $request = altProposed($this->w);
    app(ChooseChangeAlternative::class)->handle($request, $this->w->jefe, altId($request));
    Notification::fake();

    app(ApplyChosenAlternative::class)->handle($request->fresh(), $this->w->coordIsc);

    Notification::assertSentTo($this->w->teacherUser, ChangeRequestApplied::class);
    Notification::assertSentTo($this->w->teacherUser, ChangeRequestMovedTeacherGroups::class,
        fn (ChangeRequestMovedTeacherGroups $notice, array $channels): bool => $channels === ['database', 'mail'] && str_contains(implode(' ', $notice->lines()), 'G'));
    Notification::assertSentTo($this->w->otherTeacherUser, ChangeRequestMovedTeacherGroups::class,
        fn (ChangeRequestMovedTeacherGroups $notice): bool => str_contains(implode(' ', $notice->lines()), 'H') && str_contains(implode(' ', $notice->lines()), '9:00'));
    Notification::assertNotSentTo($this->w->coordIind, ChangeRequestMovedTeacherGroups::class);
});

it('al elegir una cadena que mueve un grupo de otra carrera avisa a su coordinación: qué grupo, de dónde a dónde y por qué solicitud', function () {
    $request = altProposed($this->w);
    Notification::fake();

    app(ChooseChangeAlternative::class)->handle($request, $this->w->jefe, altId($request));

    Notification::assertSentTo($this->w->coordIind, ChangeAlternativeMovesCareerGroup::class, function (ChangeAlternativeMovesCareerGroup $notice, array $channels): bool {
        $message = $notice->message($this->w->coordIind);
        $text = $message->title.' '.$message->body;

        return in_array('database', $channels, true)
            && str_contains($text, 'H')
            && str_contains($text, '9:00')
            && str_contains($text, 'G')
            && $message->route === 'scheduling.editor';
    });
    Notification::assertNotSentTo($this->w->coordIsc, ChangeAlternativeMovesCareerGroup::class);
});

it('elegir al grupo solo, o una cadena con grupos de la misma carrera, no avisa a ninguna coordinación', function () {
    $request = altProposed($this->w);
    Notification::fake();

    app(ChooseChangeAlternative::class)->handle($request, $this->w->jefe, altId($request, 'alone'));

    Notification::assertNotSentTo([$this->w->coordIind, $this->w->coordIsc], ChangeAlternativeMovesCareerGroup::class);
});
