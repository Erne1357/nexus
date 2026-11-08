<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Scheduling\Actions\Sessions\RemoveGroupSession;
use App\Modules\Scheduling\Events\GroupScheduleChanged;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| RemoveGroupSession (tarea 3.5): quita una sesión completa del grupo (supuesto por confirmar: todos sus bloques), con
| la carga de su departamento en propuesta, asignación o ajustes y el grupo sin cancelar. Deja la bitácora con sus
| horas y avisa con GroupScheduleChanged después del commit.
*/

beforeEach(function () {
    $this->user = User::factory()->admin()->create();
    $this->term = Term::factory()->create();
    $this->campus = Campus::factory()->create(['name' => 'Campus II']);
    $this->department = Department::factory()->create(['name' => 'Sistemas y Computación']);
    $this->load = AcademicLoad::factory()->inStatus(LoadStatus::Adjusting)->create([
        'term_id' => $this->term->id, 'campus_id' => $this->campus->id, 'department_id' => $this->department->id,
    ]);
    $this->group = Group::factory()->create([
        'term_id' => $this->term->id,
        'campus_id' => $this->campus->id,
        'subject_id' => Subject::factory()->create(['name' => 'Cálculo Diferencial', 'department_id' => $this->department->id])->id,
        'code' => 'D',
    ]);
    $this->session = GroupSession::factory()->for($this->group)->on(5, '07:00', '10:00')->create();
});

function removeSession(GroupSession $session): void
{
    app(RemoveGroupSession::class)->handle($session, test()->user);
}

it('quita la sesión completa y deja la bitácora en el grupo con sus horas', function () {
    $kept = GroupSession::factory()->for($this->group)->on(1, '07:00', '09:00')->create();

    removeSession($this->session);

    $entry = Activity::query()->where('event', 'like', 'session_%')->sole();

    expect($this->group->sessions()->pluck('id')->all())->toBe([$kept->id])
        ->and($entry)
        ->event->toBe('session_removed')
        ->description->toBe('Quitó la sesión de Cálculo Diferencial, grupo D del viernes de 07:00 a 10:00')
        ->and($entry->causer->is($this->user))->toBeTrue()
        ->and($entry->subject->is($this->group))->toBeTrue()
        ->and($entry->attribute_changes->toArray())->toBe([
            'old' => ['day' => 5, 'starts_at' => '07:00', 'ends_at' => '10:00', 'kind' => 'teoria', 'room_id' => null],
        ]);
});

it('con la carga fuera de propuesta, asignación o ajustes, o el grupo cancelado, no la quita', function (Closure $setUp, string $message) {
    $setUp($this);

    expect(fn () => removeSession($this->session))
        ->toThrow(ValidationException::class, $message)
        ->and(GroupSession::query()->whereKey($this->session->id)->exists())->toBeTrue()
        ->and(Activity::query()->where('event', 'like', 'session_%')->count())->toBe(0);
})->with([
    'carga publicada' => [fn ($test) => $test->load->update(['status' => LoadStatus::Published]), 'La carga de Sistemas y Computación en Campus II está en publicada: no se editan horarios.'],
    'grupo cancelado' => [fn ($test) => $test->group->update(['status' => 'cancelled']), 'El grupo D está cancelado: no se editan horarios.'],
]);

it('avisa con GroupScheduleChanged solo después del commit', function () {
    $heard = [];
    Event::listen(GroupScheduleChanged::class, function (GroupScheduleChanged $event) use (&$heard): void {
        $heard[] = $event->group->id;
    });

    try {
        DB::transaction(function (): void {
            removeSession($this->session);

            throw new RuntimeException('Falla algo después, en la misma transacción.');
        });
    } catch (RuntimeException) {
        //
    }

    expect($heard)->toBe([])
        ->and(GroupSession::query()->whereKey($this->session->id)->exists())->toBeTrue();

    removeSession($this->session);

    expect($heard)->toBe([$this->group->id]);
});
