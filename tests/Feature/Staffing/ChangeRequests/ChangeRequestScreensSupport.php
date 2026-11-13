<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Staffing\Models\ChangeRequest;

/**
 * El mundo de las pantallas de solicitudes de cambio (tarea 3.11): un periodo actual, un solo campus (C2, así la
 * credencial lo elige sola), dos departamentos (SC y CB) con su carga en asignación, dos carreras (ISC e IIND), dos
 * docentes con usuario y los jefes y coordinadores de cada lado. Los grupos se crean con crGroup().
 *
 * @return object{slot: int, term: Term, campus: Campus, sc: Department, cb: Department, isc: Career, iind: Career, teacherUser: User, teacher: Teacher, otherTeacherUser: User, otherTeacher: Teacher, scLoad: AcademicLoad, cbLoad: AcademicLoad, jefeSc: User, jefeCb: User, coordIsc: User, coordIind: User}
 */
function crWorld(): object
{
    $term = Term::factory()->current()->create(['code' => '20271', 'name' => 'Ene-Jun 2027']);
    $campus = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    $sc = Department::factory()->create(['code' => 'SC', 'name' => 'Sistemas y Computación']);
    $cb = Department::factory()->create(['code' => 'CB', 'name' => 'Ciencias Básicas']);
    $isc = Career::factory()->create(['code' => 'ISC', 'name' => 'Ingeniería en Sistemas Computacionales']);
    $iind = Career::factory()->create(['code' => 'IIND', 'name' => 'Ingeniería Industrial']);
    $teacherUser = User::factory()->withRole(Role::Docente)->create(['name' => 'Marisol Quiñones Duarte']);
    $otherTeacherUser = User::factory()->withRole(Role::Docente)->create(['name' => 'Iván Gutiérrez Salcido']);

    return (object) [
        'slot' => 0,
        'term' => $term,
        'campus' => $campus,
        'sc' => $sc,
        'cb' => $cb,
        'isc' => $isc,
        'iind' => $iind,
        'teacherUser' => $teacherUser,
        'teacher' => Teacher::factory()->adscribedTo($sc)->create(['user_id' => $teacherUser->id, 'name' => 'Marisol Quiñones Duarte']),
        'otherTeacherUser' => $otherTeacherUser,
        'otherTeacher' => Teacher::factory()->adscribedTo($sc)->create(['user_id' => $otherTeacherUser->id, 'name' => 'Iván Gutiérrez Salcido']),
        'scLoad' => AcademicLoad::factory()->create(['term_id' => $term->id, 'modality_id' => modalityOf('PRES')->id, 'department_id' => $sc->id, 'status' => LoadStatus::Staffing]),
        'cbLoad' => AcademicLoad::factory()->create(['term_id' => $term->id, 'modality_id' => modalityOf('PRES')->id, 'department_id' => $cb->id, 'status' => LoadStatus::Staffing]),
        'jefeSc' => User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $sc->id])->create(['name' => 'Alfredo Ríos Carrasco']),
        'jefeCb' => User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $cb->id])->create(['name' => 'Laura Peña Ochoa']),
        'coordIsc' => User::factory()->withRole(Role::Coordinador, ['modality_id' => modalityOf('PRES')->id, 'career_id' => $isc->id])->create(['name' => 'Karla Beltrán Ochoa']),
        'coordIind' => User::factory()->withRole(Role::Coordinador, ['modality_id' => modalityOf('PRES')->id, 'career_id' => $iind->id])->create(['name' => 'Raúl Tena Prieto']),
    ];
}

/**
 * Un grupo del mundo de `$department`, de la carrera dueña `$career` y con `$teacher`. Sin `$sessions`, dos sesiones de
 * dos horas que no se empalman con las de los grupos anteriores del mundo (la base no deja que un docente tenga dos
 * sesiones a la vez): el primero, lunes y miércoles de 7 a 9; el segundo, martes y jueves de 7 a 9; luego de 9 a 11…
 *
 * @param  list<array{0: int, 1: string, 2: string}>|null  $sessions
 */
function crGroup(object $world, string $code, string $subjectName, Department $department, Career $career, ?Teacher $teacher, ?array $sessions = null): Group
{
    if ($sessions === null) {
        $slot = $world->slot++;
        $days = $slot % 2 === 0 ? [1, 3] : [2, 4];
        $start = sprintf('%02d:00', 7 + 2 * intdiv($slot, 2));
        $end = sprintf('%02d:00', 9 + 2 * intdiv($slot, 2));
        $sessions = [[$days[0], $start, $end], [$days[1], $start, $end]];
    }

    $subject = Subject::factory()->create(['department_id' => $department->id, 'name' => $subjectName]);
    $group = Group::factory()->create([
        'term_id' => $world->term->id,
        'campus_id' => $world->campus->id,
        'subject_id' => $subject->id,
        'department_id' => $department->id,
        'owner_career_id' => $career->id,
        'code' => $code,
        'teacher_id' => $teacher?->id,
    ]);

    foreach ($sessions as [$day, $start, $end]) {
        GroupSession::factory()->on($day, $start, $end)->create(['group_id' => $group->id]);
    }

    return $group;
}

/**
 * Una solicitud del docente del grupo, en el estado dado, con la foto de sus sesiones.
 *
 * @param  array<string, mixed>  $attributes
 */
function crRequest(Group $group, User $requester, string $state = 'pending', array $attributes = []): ChangeRequest
{
    $group->loadMissing('teacher', 'sessions');

    return ChangeRequest::factory()->forGroup($group)->{$state}()->create([
        'requested_by' => $requester->id,
        'teacher_id' => $group->teacher?->user_id === $requester->id ? $group->teacher_id : null,
        'current' => $group->sessions->map(fn (GroupSession $session): array => [
            'day' => $session->day,
            'starts_at' => substr((string) $session->starts_at, 0, 5),
            'ends_at' => substr((string) $session->ends_at, 0, 5),
            'room' => null,
        ])->values()->all(),
        ...$attributes,
    ]);
}
