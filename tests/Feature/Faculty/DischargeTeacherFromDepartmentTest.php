<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Actions\DischargeTeacherFromDepartment;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherDepartment;
use Spatie\Activitylog\Models\Activity;

/*
| La baja del docente por el jefe (CV8, confirmado el 12 de noviembre de 2026), en la validación y en «Quitar de mi
| departamento»: se da de baja de ese departamento y, si ya no está adscrito a otro, su cuenta se inhabilita (queda dado
| de baja, SetTeacherActive); si sí, solo se quita de ese departamento y, si hacía falta, tiene un nuevo departamento
| principal (RemoveAdscription). A un docente que ocupa un puesto solo lo quitan el admin o la Subdirección (CV-S8).
| Agregar a un docente que ya existe a su departamento es del jefe que da de alta ahí (CV-S9); a uno dado de baja que
| ocupa un puesto solo lo reactivan el admin o la Subdirección (confirmado el 12 de noviembre de 2026).
*/

beforeEach(function () {
    $this->sc = Department::factory()->create(['code' => 'SC']);
    $this->ii = Department::factory()->create(['code' => 'II']);
});

function dischargeFrom(Teacher $teacher, Department $department): array
{
    return app(DischargeTeacherFromDepartment::class)->handle(TeacherDepartment::query()->where('teacher_id', $teacher->id)->where('department_id', $department->id)->sole());
}

it('CV8: con otra adscripción solo lo quita de ese departamento y la principal pasa a la que le queda; sigue activo', function () {
    $user = User::factory()->create();
    $teacher = Teacher::factory()->adscribedTo($this->sc)->adscribedTo($this->ii, primary: false)->create(['user_id' => $user->id]);

    $result = dischargeFrom($teacher, $this->sc);

    expect($result['remaining'])->toBe(1)
        ->and($result['deactivated'])->toBeFalse()
        ->and($teacher->fresh()->is_active)->toBeTrue()
        ->and($user->fresh()->is_active)->toBeTrue()
        ->and($teacher->adscriptions()->sole()->department_id)->toBe($this->ii->id)
        ->and($teacher->adscriptions()->sole()->is_primary)->toBeTrue();
});

it('CV8: sin otra adscripción queda dado de baja y su cuenta se inhabilita, con bitácora', function () {
    $user = User::factory()->create();
    $teacher = Teacher::factory()->adscribedTo($this->sc)->create(['user_id' => $user->id, 'rfc' => 'AAAA800101AA1']);

    $result = dischargeFrom($teacher, $this->sc);

    expect($result['remaining'])->toBe(0)
        ->and($result['deactivated'])->toBeTrue()
        ->and($teacher->fresh()->is_active)->toBeFalse()
        ->and($teacher->adscriptions()->count())->toBe(0)
        ->and($user->fresh()->is_active)->toBeFalse()
        ->and(Activity::query()->where('subject_id', $teacher->id)->pluck('description')->all())->toContain('Dio de baja al docente AAAA800101AA1');
});

it('CV8: sin otra adscripción, a un docente ya inactivo solo lo quita del departamento', function () {
    $teacher = Teacher::factory()->inactive()->adscribedTo($this->sc)->create();

    expect(dischargeFrom($teacher, $this->sc)['deactivated'])->toBeFalse()
        ->and($teacher->fresh()->is_active)->toBeFalse()
        ->and($teacher->adscriptions()->count())->toBe(0);
});

describe('quién da de baja y quién agrega', function () {
    beforeEach(function () {
        $this->jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->sc->id])->create();
        $this->teacher = Teacher::factory()->adscribedTo($this->sc)->create(['user_id' => User::factory()->create()->id]);
        $this->adscription = $this->teacher->adscriptions()->sole();
    });

    it('CV8: el jefe del departamento da de baja a un docente de su departamento; el de otro, no', function () {
        $other = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->ii->id])->create();

        expect($this->jefe->can('removeAdscription', [Teacher::class, $this->adscription]))->toBeTrue()
            ->and($other->can('removeAdscription', [Teacher::class, $this->adscription]))->toBeFalse();
    });

    it('CV-S8: a un docente que ocupa un puesto solo lo dan de baja el admin o la Subdirección', function (Closure $staff) {
        $teacher = Teacher::factory()->adscribedTo($this->sc)->create(['user_id' => $staff($this)->id]);
        $adscription = $teacher->adscriptions()->sole();

        expect($this->jefe->can('removeAdscription', [Teacher::class, $adscription]))->toBeFalse()
            ->and(User::factory()->admin()->create()->can('removeAdscription', [Teacher::class, $adscription]))->toBeTrue()
            ->and(User::factory()->withRole(Role::Subdireccion)->create()->can('removeAdscription', [Teacher::class, $adscription]))->toBeTrue();
    })->with([
        'jefe de otro departamento' => fn ($test) => User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $test->ii->id])->create(),
        'coordinador' => fn ($test) => User::factory()->withRole(Role::Coordinador, ['campus_id' => Campus::factory()->create()->id, 'career_id' => careerKey(21)])->create(),
    ]);

    it('nadie se da de baja a sí mismo', function () {
        $own = Teacher::factory()->adscribedTo($this->sc)->create(['user_id' => $this->jefe->id]);

        expect($this->jefe->can('removeAdscription', [Teacher::class, $own->adscriptions()->sole()]))->toBeFalse();
    });

    it('CV-S9: a un docente dado de baja que ocupa un puesto solo lo reactivan el admin o la Subdirección', function () {
        $staff = Teacher::factory()->inactive()->create(['user_id' => User::factory()->withRole(Role::Coordinador, ['campus_id' => Campus::factory()->create()->id, 'career_id' => careerKey(22)])->create()->id]);
        $plain = Teacher::factory()->inactive()->create(['user_id' => User::factory()->create()->id]);

        expect($this->jefe->can('adscribeToDepartment', [Teacher::class, $this->sc, $staff]))->toBeFalse()
            ->and($this->jefe->can('adscribeToDepartment', [Teacher::class, $this->sc, $plain]))->toBeTrue()
            ->and(User::factory()->admin()->create()->can('adscribeToDepartment', [Teacher::class, $this->sc, $staff]))->toBeTrue()
            ->and(User::factory()->withRole(Role::Subdireccion)->create()->can('adscribeToDepartment', [Teacher::class, $this->sc, $staff]))->toBeTrue();
    });

    it('CV-S9: agregar a un docente que ya existe es del jefe de ese departamento, no de otro ni del coordinador', function () {
        $coordinador = User::factory()->withRole(Role::Coordinador, ['campus_id' => Campus::factory()->create()->id, 'career_id' => careerKey(20)])->create();

        expect($this->jefe->can('adscribeToDepartment', [Teacher::class, $this->sc]))->toBeTrue()
            ->and($this->jefe->can('adscribeToDepartment', [Teacher::class, $this->ii]))->toBeFalse()
            ->and($coordinador->can('adscribeToDepartment', [Teacher::class, $this->sc]))->toBeFalse();
    });
});
