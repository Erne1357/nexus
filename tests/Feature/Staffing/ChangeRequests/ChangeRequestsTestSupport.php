<?php

use App\Modules\Academic\Models\Career;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Staffing\Models\ChangeRequest;

/**
 * Un grupo con docente (con usuario) cuya carga del departamento está en `$status`, y los usuarios del flujo.
 *
 * @return array{group: Group, load: AcademicLoad, teacher: Teacher, teacherUser: User, jefe: User, coordinador: User}
 */
function changeScenario(LoadStatus $status = LoadStatus::Staffing): array
{
    $campus = Campus::factory()->create();
    $term = Term::factory()->create();
    $career = Career::factory()->create();
    $teacherUser = User::factory()->withRole(Role::Docente)->create();
    $teacher = Teacher::factory()->create(['user_id' => $teacherUser->id]);
    $group = Group::factory()->create([
        'term_id' => $term->id,
        'campus_id' => $campus->id,
        'owner_career_id' => $career->id,
        'teacher_id' => $teacher->id,
    ]);
    $load = AcademicLoad::factory()->create([
        'term_id' => $term->id,
        'campus_id' => $campus->id,
        'department_id' => $group->department_id,
        'status' => $status,
    ]);

    return [
        'group' => $group,
        'load' => $load,
        'teacher' => $teacher,
        'teacherUser' => $teacherUser,
        'jefe' => User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $group->department_id])->create(),
        'coordinador' => User::factory()->withRole(Role::Coordinador, ['campus_id' => $campus->id, 'career_id' => $career->id])->create(),
    ];
}

/**
 * Una solicitud ya creada para el grupo, en el estado dado.
 *
 * @param  array<string, mixed>  $attributes
 */
function requestFor(Group $group, string $state = 'pending', array $attributes = []): ChangeRequest
{
    return ChangeRequest::factory()->forGroup($group)->{$state}()->create($attributes);
}
