<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Models\HistoricalGroup;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherDepartment;
use App\Modules\Scheduling\Queries\CampusMapQuery;
use App\Modules\Students\Models\KardexRecord;
use App\Modules\Students\Models\Student;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
| MP-04: al alumno el mapa le muestra solo las clases de los grupos que cursa en el periodo de consulta (su kárdex lleva
| la clave y el grupo del SII), los docentes del departamento académico de su carrera y de sus propias clases, y los
| lugares completos. El personal ve todo.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-20 10:00', 'America/Ciudad_Juarez'));
    $this->campus = Campus::factory()->create(['code' => 'C1']);
    $building = Building::factory()->for($this->campus)->create(['code' => 'SC', 'name' => 'Edificio Sistemas', 'map_key' => 'Edificio Sistemas']);
    Room::factory()->create(['building_id' => $building->id, 'code' => '701', 'map_key' => 'Edificio Sistemas|701']);
    $this->term = Term::factory()->create(['code' => '20263', 'name' => 'Ago-Dic 2026', 'starts_on' => '2026-08-17', 'ends_on' => '2026-12-11']);
    $this->deptA = Department::factory()->create(['code' => '110600', 'name' => 'Sistemas']);
    $this->deptB = Department::factory()->create(['code' => '110300', 'name' => 'Industrial']);
    $career = Career::factory()->create(['department_id' => $this->deptA->id]);
    $plan = StudyPlan::factory()->create(['career_id' => $career->id]);
    $this->subject1 = Subject::factory()->create(['code' => 'AED-1285', 'name' => 'Programación', 'department_id' => $this->deptA->id]);
    $this->subject2 = Subject::factory()->create(['code' => 'ACF-0901', 'name' => 'Cálculo', 'department_id' => $this->deptA->id]);
    $this->own = makeTeacher('AAAA800101AA1', 'Docente Propio', $this->deptB);
    $this->sameDept = makeTeacher('BBBB800101AA1', 'Docente Mismo Depto', $this->deptA);
    $this->other = makeTeacher('CCCC800101AA1', 'Docente Ajeno', $this->deptB);
    mapGroup($this, '1PR', 'C', $this->subject1, $this->own);
    mapGroup($this, '1PR', 'D', $this->subject1, $this->sameDept);
    mapGroup($this, '2CA', 'A', $this->subject2, $this->other);
    $this->student = Student::factory()->for($plan)->create(['campus_id' => $this->campus->id, 'career_id' => $career->id]);
    $this->user = User::factory()->withRole(Role::Alumno, ['campus_id' => $this->campus->id])->create();
    $this->student->forceFill(['user_id' => $this->user->id])->save();
});

function makeTeacher(string $rfc, string $name, Department $department): Teacher
{
    $teacher = Teacher::factory()->create(['rfc' => $rfc, 'name' => $name]);
    TeacherDepartment::factory()->create(['teacher_id' => $teacher->id, 'department_id' => $department->id, 'is_primary' => true]);

    return $teacher;
}

function mapGroup(object $test, string $sii, string $group, Subject $subject, Teacher $teacher): void
{
    $historical = HistoricalGroup::factory()->fromSii()->create(['term_code' => '20263', 'campus_id' => $test->campus->id, 'subject_id' => $subject->id, 'group_code' => $group, 'sii_subject_code' => $sii]);
    DB::table('historical_group_sessions')->insert(['historical_group_id' => $historical->id, 'day' => 1, 'starts_at' => '09:00', 'ends_at' => '10:00', 'room_code' => '701', 'teacher_rfc' => $teacher->rfc, 'created_at' => now(), 'updated_at' => now()]);
}

function mapFor(User $user, Role $role): array
{
    test()->actingAs($user)->withSession([Actor::ACTIVE_ROLE => $role->value]);

    return app(CampusMapQuery::class)->forActor(app(Actor::class));
}

it('MP-04: el alumno ve solo las clases de los grupos que cursa y los docentes de su departamento y de sus clases', function () {
    KardexRecord::factory()->create(['student_id' => $this->student->id, 'subject_id' => $this->subject1->id, 'term_code' => '20263', 'sii_subject_code' => '1PR', 'sii_group_code' => 'C']);

    $map = mapFor($this->user, Role::Alumno);

    expect($map['scope'])->toBe('student')
        ->and(array_column($map['classes'], 'group'))->toBe(['AED-1285·C'])
        ->and(array_column($map['groups'], 'id'))->toBe(['AED-1285·C'])
        ->and(array_column($map['subjects'], 'id'))->toBe(['AED-1285'])
        ->and(collect($map['profs'])->pluck('name')->sort()->values()->all())->toBe(['Docente Mismo Depto', 'Docente Propio'])
        ->and($map['rooms'])->toHaveKey('Edificio Sistemas|701')
        ->and($map['buildings'])->toHaveKey('Edificio Sistemas');
});

it('MP-04: sin clases inscritas en el periodo, el alumno ve los lugares y ninguna clase', function () {
    KardexRecord::factory()->create(['student_id' => $this->student->id, 'subject_id' => $this->subject1->id, 'term_code' => '20261', 'sii_subject_code' => '1PR', 'sii_group_code' => 'C']);
    KardexRecord::factory()->create(['student_id' => $this->student->id, 'subject_id' => $this->subject2->id, 'term_code' => '20263', 'sii_subject_code' => '2CA', 'sii_group_code' => null]);

    $map = mapFor($this->user, Role::Alumno);

    expect($map['classes'])->toBe([])->and($map['groups'])->toBe([])->and($map['subjects'])->toBe([])
        ->and($map['rooms'])->toHaveKey('Edificio Sistemas|701');
});

it('MP-04: el personal ve todas las clases, sin recorte', function () {
    $staff = User::factory()->withRole(Role::Subdireccion)->create();

    $map = mapFor($staff, Role::Subdireccion);

    expect($map['scope'])->toBeNull()->and($map['classes'])->toHaveCount(3)->and($map['profs'])->toHaveCount(3);
});

it('MP-04: el recorte del alumno no entra en la caché compartida: el personal ve todo después de que el alumno consultó', function () {
    mapFor($this->user, Role::Alumno);
    $staff = User::factory()->withRole(Role::Subdireccion)->create();

    expect(mapFor($staff, Role::Subdireccion)['classes'])->toHaveCount(3);
});

it('MP-04: un alumno nunca ve los grupos de otro alumno', function () {
    $otherStudent = Student::factory()->create(['campus_id' => $this->campus->id]);
    KardexRecord::factory()->create(['student_id' => $otherStudent->id, 'subject_id' => $this->subject2->id, 'term_code' => '20263', 'sii_subject_code' => '2CA', 'sii_group_code' => 'A']);

    expect(mapFor($this->user, Role::Alumno)['classes'])->toBe([]);
});

it('MP-04: el alumno de otro campus con el mismo grupo «A» no ve el «A» del campus del mapa', function () {
    $other = Campus::factory()->create(['code' => 'C2']);
    $this->student->update(['campus_id' => $other->id]);
    KardexRecord::factory()->create(['student_id' => $this->student->id, 'subject_id' => $this->subject2->id, 'term_code' => '20263', 'sii_subject_code' => '2CA', 'sii_group_code' => 'A']);

    expect(mapFor($this->user, Role::Alumno)['classes'])->toBe([]);
});

it('MP-04: el selector de salón pasa por forActor: un alumno nunca recibe el mapa completo', function () {
    KardexRecord::factory()->create(['student_id' => $this->student->id, 'subject_id' => $this->subject1->id, 'term_code' => '20263', 'sii_subject_code' => '1PR', 'sii_group_code' => 'C']);
    config(['nexus.campus_map_3d' => true]);
    actingAsRole($this->user, Role::Alumno);

    $map = Livewire\Livewire::test('scheduling::components.map-picker')->viewData('map');

    expect($map['scope'])->toBe('student')->and(array_column($map['classes'], 'group'))->toBe(['AED-1285·C']);
});
