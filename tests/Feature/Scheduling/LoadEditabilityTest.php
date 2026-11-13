<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Scheduling\Enums\GroupStatus;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Rules\LoadEditability;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

/*
| LoadEditability: la única regla de «grupo editable» para sesiones (3.5) y cupos (3.6). Un grupo sin carga no se edita
| (supuesto por confirmar); un deadlock de la base sale como error de validación.
*/

beforeEach(function () {
    $this->term = Term::factory()->create();
    $this->campus = Campus::factory()->create(['name' => 'Campus II']);
    $this->department = Department::factory()->create(['name' => 'Sistemas y Computación']);
    $this->subject = Subject::factory()->create(['department_id' => $this->department->id]);
    $this->group = Group::factory()->create(['term_id' => $this->term->id, 'campus_id' => $this->campus->id, 'department_id' => $this->department->id, 'subject_id' => $this->subject->id, 'code' => 'D']);
    $this->rule = app(LoadEditability::class);
});

it('rechaza un grupo sin carga, con el mismo mensaje para horarios y cupos', function () {
    expect(fn () => $this->rule->ensure($this->group))
        ->toThrow(ValidationException::class, 'Sistemas y Computación no tiene carga en Presencial en este periodo: no se editan horarios.')
        ->and(fn () => $this->rule->ensure($this->group, 'cupos', 'quotas'))
        ->toThrow(fn (ValidationException $e) => expect($e->errors()['quotas'])->toBe(['Sistemas y Computación no tiene carga en Presencial en este periodo: no se editan cupos.']));
});

it('deja pasar un grupo con la carga en propuesta, asignación o ajustes', function (LoadStatus $status) {
    AcademicLoad::factory()->inStatus($status)->create(['term_id' => $this->term->id, 'modality_id' => modalityOf('PRES')->id, 'department_id' => $this->department->id]);

    $this->rule->ensure($this->group);

    expect($this->rule->blockedReasons([$this->group]))->toBe([$this->group->id => null]);
})->with([LoadStatus::Proposed, LoadStatus::Staffing, LoadStatus::Adjusting]);

it('blockedReasons da el motivo de cada grupo bloqueado', function () {
    $load = AcademicLoad::factory()->inStatus(LoadStatus::Closed)->create(['term_id' => $this->term->id, 'modality_id' => modalityOf('PRES')->id, 'department_id' => $this->department->id]);
    $cancelled = Group::factory()->create(['term_id' => $this->term->id, 'campus_id' => $this->campus->id, 'department_id' => $this->department->id, 'subject_id' => $this->subject->id, 'code' => 'E', 'status' => GroupStatus::Cancelled]);

    $reasons = $this->rule->blockedReasons([$this->group, $cancelled]);

    expect($reasons[$this->group->id])->toBe('La carga de Sistemas y Computación en Presencial está en cerrada: no se editan horarios.')
        ->and($reasons[$cancelled->id])->toBe('El grupo E está cancelado: no se editan horarios.');

    $load->update(['status' => LoadStatus::Proposed]);

    expect($this->rule->blockedReasons([$this->group]))->toBe([$this->group->id => null]);
});

it('traduce también el deadlock que Laravel envuelve al salir de una transacción anidada', function () {
    $deadlock = new QueryException('pgsql', 'update ...', [], new PDOException('deadlock detected', 0));
    $deadlock->errorInfo = ['40P01', 7, 'deadlock detected'];

    expect(fn () => $this->rule->guard(fn () => throw new DeadlockException($deadlock->getMessage(), 0, $deadlock), 'status'))
        ->toThrow(fn (ValidationException $e) => expect($e->errors()['status'])->toBe(['Otra persona está cambiando esta carga; intenta de nuevo.']));
});

it('traduce un deadlock de la base a un error de validación y deja pasar los demás errores', function () {
    $deadlock = new QueryException('pgsql', 'update ...', [], new PDOException('deadlock detected', 0));
    $deadlock->errorInfo = ['40P01', 7, 'deadlock detected'];

    expect(fn () => $this->rule->guard(fn () => throw $deadlock))
        ->toThrow(fn (ValidationException $e) => expect($e->errors()['session'])->toBe(['Otra persona está cambiando esta carga; intenta de nuevo.']))
        ->and(fn () => $this->rule->guard(fn () => throw new QueryException('pgsql', 'select', [], new PDOException('otro')), 'quotas'))
        ->toThrow(QueryException::class);
});
