<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Queries\ScopedContext;
use App\Modules\Faculty\Actions\RemoveAdscription;
use App\Modules\Faculty\Actions\SyncTeacherAccess;
use App\Modules\Faculty\Events\AdscriptionChanged;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherDepartment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| «Quitar de mi departamento» (RemoveAdscription; reglas-de-negocio.md, "Adscripciones"): quita la adscripción con
| borrado lógico; si era la principal, la principal pasa a la adscripción más antigua que le quede, y si no le queda
| ninguna el docente sigue activo y sin departamento (confirmado el 30 de octubre de 2026). Deja bitácora de la
| quitada y de la principal nueva, y avisa con AdscriptionChanged después del commit.
*/

beforeEach(function () {
    $this->sis = Department::factory()->create(['code' => 'SIS']);
    $this->ind = Department::factory()->create(['code' => 'IND']);
    $this->civ = Department::factory()->create(['code' => 'CIV']);
});

function adscriptionOf(Teacher $teacher, Department $department): TeacherDepartment
{
    return TeacherDepartment::withTrashed()->where('teacher_id', $teacher->id)->where('department_id', $department->id)->sole();
}

/**
 * La adscripción que pasó a ser la principal, o null.
 */
function removeAdscription(TeacherDepartment $adscription): ?TeacherDepartment
{
    return app(RemoveAdscription::class)->handle($adscription)['promoted'];
}

/**
 * Un docente con SIS (la principal, de hace tres años), CIV (de hace un año) e IND (de hace dos).
 */
function teacherInThreeDepartments(Department $sis, Department $ind, Department $civ): Teacher
{
    $teacher = Teacher::factory()->create(['employee_number' => '1234567']);
    TeacherDepartment::factory()->for($teacher)->for($sis)->create(['is_primary' => true, 'created_at' => now()->subYears(3)]);
    TeacherDepartment::factory()->for($teacher)->for($civ)->create(['is_primary' => false, 'created_at' => now()->subYear()]);
    TeacherDepartment::factory()->for($teacher)->for($ind)->create(['is_primary' => false, 'created_at' => now()->subYears(2)]);

    return $teacher;
}

it('quitar la principal pasa la principal a la adscripción más antigua que le queda', function () {
    $teacher = teacherInThreeDepartments($this->sis, $this->ind, $this->civ);

    $primary = removeAdscription(adscriptionOf($teacher, $this->sis));

    $removed = adscriptionOf($teacher, $this->sis);

    expect($primary->is(adscriptionOf($teacher, $this->ind)))->toBeTrue()
        ->and($removed->trashed())->toBeTrue()
        ->and($removed->is_primary)->toBeFalse()
        ->and($teacher->fresh()->primaryAdscription->department_id)->toBe($this->ind->id)
        ->and(adscriptionOf($teacher, $this->civ)->is_primary)->toBeFalse()
        ->and($teacher->adscriptions()->pluck('department_id')->sort()->values()->all())->toBe(collect([$this->ind->id, $this->civ->id])->sort()->values()->all());
});

it('a igual antigüedad, la principal pasa a la de id menor', function () {
    $teacher = Teacher::factory()->create();
    $at = now()->subYear();
    TeacherDepartment::factory()->for($teacher)->for($this->sis)->create(['id' => fakeKey(1), 'is_primary' => true, 'created_at' => $at]);
    TeacherDepartment::factory()->for($teacher)->for($this->civ)->create(['id' => fakeKey(3), 'is_primary' => false, 'created_at' => $at]);
    TeacherDepartment::factory()->for($teacher)->for($this->ind)->create(['id' => fakeKey(2), 'is_primary' => false, 'created_at' => $at]);

    expect(removeAdscription(adscriptionOf($teacher, $this->sis))->id)->toBe(fakeKey(2));
});

it('deja en la bitácora la adscripción quitada y la principal nueva', function () {
    $teacher = teacherInThreeDepartments($this->sis, $this->ind, $this->civ);
    $sis = adscriptionOf($teacher, $this->sis);
    $ind = adscriptionOf($teacher, $this->ind);

    removeAdscription($sis);

    $removed = Activity::where('subject_id', $sis->id)->sole();
    $promoted = Activity::where('subject_id', $ind->id)->sole();

    expect(Activity::count())->toBe(2)
        ->and($removed->event)->toBe('deleted')
        ->and($removed->description)->toBe('Quitó al docente 1234567 del departamento SIS; su adscripción principal pasa a IND')
        ->and($removed->attribute_changes->all())->toBe(['old' => ['department_id' => $this->sis->id, 'is_primary' => true]])
        ->and($removed->properties->all())->toBe(['teacher_id' => $teacher->id, 'primary_adscription_id' => $ind->id])
        ->and($promoted->event)->toBe('updated')
        ->and($promoted->description)->toBe('Hizo principal la adscripción del docente 1234567 a IND al quitarlo de SIS')
        ->and($promoted->attribute_changes->all())->toBe(['attributes' => ['is_primary' => true], 'old' => ['is_primary' => false]])
        ->and($promoted->properties->all())->toBe(['teacher_id' => $teacher->id]);
});

it('quitar la única deja al docente activo y sin departamento, con su usuario, su rol y su alcance', function () {
    $teacher = Teacher::factory()->adscribedTo($this->sis)->create(['employee_number' => '1234567']);
    app(SyncTeacherAccess::class)->handle($teacher);
    $user = $teacher->fresh()->user;
    $sis = adscriptionOf($teacher, $this->sis);

    expect(removeAdscription($sis))->toBeNull();

    $teacher->refresh();
    $entry = Activity::where('subject_id', $sis->id)->sole();

    expect($teacher->is_active)->toBeTrue()
        ->and($teacher->adscriptions()->exists())->toBeFalse()
        ->and($teacher->primaryAdscription)->toBeNull()
        ->and($user->fresh()->is_active)->toBeTrue()
        ->and($user->fresh()->hasRole(Role::Docente->value))->toBeTrue()
        ->and(app(ScopedContext::class)->forUser($user->fresh())->has(Role::Docente))->toBeTrue()
        ->and($entry->description)->toBe('Quitó al docente 1234567 del departamento SIS; queda sin departamento')
        ->and($entry->properties->all())->toBe(['teacher_id' => $teacher->id, 'primary_adscription_id' => null]);
});

it('quitar una que no es la principal no cambia la principal', function () {
    $teacher = teacherInThreeDepartments($this->sis, $this->ind, $this->civ);
    $sis = adscriptionOf($teacher, $this->sis);
    $ind = adscriptionOf($teacher, $this->ind);

    expect(removeAdscription($ind))->toBeNull();

    $entry = Activity::sole();

    expect($teacher->fresh()->primaryAdscription->is($sis))->toBeTrue()
        ->and(adscriptionOf($teacher, $this->ind)->trashed())->toBeTrue()
        ->and(adscriptionOf($teacher, $this->civ)->is_primary)->toBeFalse()
        ->and($entry->subject_id)->toBe($ind->id)
        ->and($entry->description)->toBe('Quitó al docente 1234567 del departamento IND')
        ->and($entry->attribute_changes->all())->toBe(['old' => ['department_id' => $this->ind->id, 'is_primary' => false]])
        ->and($entry->properties->all())->toBe(['teacher_id' => $teacher->id, 'primary_adscription_id' => $sis->id]);
});

it('una adscripción ya quitada es un error en adscription y no deja otra bitácora', function () {
    $teacher = Teacher::factory()->adscribedTo($this->sis)->adscribedTo($this->ind, false)->create();
    $ind = adscriptionOf($teacher, $this->ind);
    removeAdscription($ind);

    expect(fn () => removeAdscription($ind))->toThrow(ValidationException::class, 'Esa adscripción ya se quitó.')
        ->and(Activity::count())->toBe(1);
});

it('volver a poner la quitada no estorba a la principal nueva', function () {
    $teacher = teacherInThreeDepartments($this->sis, $this->ind, $this->civ);
    $sis = adscriptionOf($teacher, $this->sis);
    removeAdscription($sis);

    $sis->fresh()->restore();

    expect($teacher->fresh()->primaryAdscription->department_id)->toBe($this->ind->id)
        ->and($teacher->adscriptions()->count())->toBe(3);
});

it('avisa con AdscriptionChanged solo después del commit', function () {
    $heard = [];
    Event::listen(AdscriptionChanged::class, function (AdscriptionChanged $event) use (&$heard): void {
        $heard[] = $event->adscription->id;
    });
    $teacher = teacherInThreeDepartments($this->sis, $this->ind, $this->civ);
    $sis = adscriptionOf($teacher, $this->sis);

    try {
        DB::transaction(function () use ($sis): void {
            removeAdscription($sis);

            throw new RuntimeException('Falla algo después, en la misma transacción.');
        });
    } catch (RuntimeException) {
        //
    }

    expect($heard)->toBe([])
        ->and(adscriptionOf($teacher, $this->sis)->trashed())->toBeFalse()
        ->and($teacher->fresh()->primaryAdscription->is($sis))->toBeTrue();

    removeAdscription($sis);

    expect($heard)->toBe([$sis->id]);
});

it('devuelve la principal nueva, cuántas adscripciones le quedan y el resultado con el mismo texto de la bitácora', function () {
    $teacher = teacherInThreeDepartments($this->sis, $this->ind, $this->civ);
    $alone = Teacher::factory()->adscribedTo($this->sis)->create();

    $primaryMoved = app(RemoveAdscription::class)->handle(adscriptionOf($teacher, $this->sis));
    $notPrimary = app(RemoveAdscription::class)->handle(adscriptionOf($teacher, $this->civ));
    $last = app(RemoveAdscription::class)->handle(adscriptionOf($alone, $this->sis));

    expect($primaryMoved['promoted']->is(adscriptionOf($teacher, $this->ind)))->toBeTrue()
        ->and($primaryMoved)->remaining->toBe(2)->outcome->toBe('su adscripción principal pasa a IND')
        ->and($notPrimary)->promoted->toBeNull()->remaining->toBe(1)->outcome->toBeNull()
        ->and($last)->promoted->toBeNull()->remaining->toBe(0)->outcome->toBe('queda sin departamento');
});
