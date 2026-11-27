<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherPosition;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Staffing\Actions\ChangeRequests\ProposeChangeAlternatives;
use App\Modules\Staffing\Actions\ChangeRequests\RequestChange;
use App\Modules\Staffing\Enums\ChangeRequestKind;
use App\Modules\Staffing\Models\ChangeRequest;

/**
 * El mundo de la pantalla de alternativas del motor (tarea 8.7): el periodo actual, Campus II, Sistemas y Computación
 * con su carga en asignación y dos grupos de 4 h de teoría (lunes a jueves, una hora):
 *
 * - G, de ISC (semestre 1), con Marisol Quiñones a las 7:00 en el salón A-1;
 * - H, de IIND (semestre 3), con Iván Gutiérrez a las 9:00 en el mismo salón A-1.
 *
 * El docente de G pide pasarlo a las 9:00 (altRequest): G conserva su salón, así que choca con H en A-1 (HC-02) y la
 * cadena mueve a H, que es de otra carrera. Los usuarios: los jefes de SC y de CB, los coordinadores de ISC y de IIND.
 *
 * @return object{term: Term, campus: Campus, sc: Department, cb: Department, isc: Career, iind: Career, roomA: Room, roomB: Room, teacher: Teacher, teacherUser: User, otherTeacher: Teacher, otherTeacherUser: User, group: Group, other: Group, load: AcademicLoad, jefe: User, jefeCb: User, coordIsc: User, coordIind: User}
 */
function altWorld(): object
{
    config(['nexus.institutional_email_domain' => 'cdjuarez.tecnm.mx']);

    $term = Term::factory()->current()->create(['code' => '20271', 'name' => 'Ene-Jun 2027', 'starts_on' => '2027-01-18']);
    $campus = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    $sc = Department::factory()->create(['code' => 'SC', 'name' => 'Sistemas y Computación']);
    $cb = Department::factory()->create(['code' => 'CB', 'name' => 'Ciencias Básicas']);
    $isc = Career::factory()->create(['code' => 'ISC', 'name' => 'Ingeniería en Sistemas Computacionales', 'department_id' => $sc->id]);
    $iind = Career::factory()->create(['code' => 'IIND', 'name' => 'Ingeniería Industrial', 'department_id' => $sc->id]);
    CareerCampus::factory()->create(['career_id' => $isc->id, 'campus_id' => $campus->id]);
    CareerCampus::factory()->create(['career_id' => $iind->id, 'campus_id' => $campus->id]);
    $building = Building::factory()->create(['campus_id' => $campus->id]);
    $roomA = Room::factory()->create(['building_id' => $building->id, 'code' => 'A-1', 'capacity' => 40]);
    $roomB = Room::factory()->create(['building_id' => $building->id, 'code' => 'A-2', 'capacity' => 40]);

    $person = function (string $name, string $mail): array {
        $user = User::factory()->withRole(Role::Docente)->create(['name' => $name]);
        $teacher = Teacher::factory()->create(['user_id' => $user->id, 'name' => $name, 'institutional_email' => "{$mail}@cdjuarez.tecnm.mx"]);
        TeacherPosition::factory()->for($teacher)->tiempoCompleto()->create();

        return [$teacher, $user];
    };

    [$teacher, $teacherUser] = $person('Marisol Quiñones Duarte', 'marisol.quinones');
    [$otherTeacher, $otherTeacherUser] = $person('Iván Gutiérrez Salcido', 'ivan.gutierrez');

    $make = function (string $code, string $subject, Career $career, int $semester, Teacher $teacher, string $start) use ($term, $campus, $sc, $roomA): Group {
        $group = Group::factory()->create([
            'term_id' => $term->id, 'campus_id' => $campus->id, 'modality_id' => modalityOf('PRES')->id, 'department_id' => $sc->id,
            'subject_id' => Subject::factory()->create(['department_id' => $sc->id, 'name' => $subject, 'theory_hours' => 4, 'practice_hours' => 0])->id,
            'owner_career_id' => $career->id, 'study_plan_id' => StudyPlan::factory()->create(['career_id' => $career->id])->id,
            'semester' => $semester, 'code' => $code, 'capacity' => 30, 'teacher_id' => $teacher->id,
        ]);
        $end = sprintf('%02d:00', (int) substr($start, 0, 2) + 1);

        foreach ([1, 2, 3, 4] as $day) {
            GroupSession::factory()->for($group)->inRoom($roomA)->on($day, $start, $end)->create();
        }

        return $group;
    };

    return (object) [
        'term' => $term, 'campus' => $campus, 'sc' => $sc, 'cb' => $cb, 'isc' => $isc, 'iind' => $iind, 'roomA' => $roomA, 'roomB' => $roomB,
        'teacher' => $teacher, 'teacherUser' => $teacherUser, 'otherTeacher' => $otherTeacher, 'otherTeacherUser' => $otherTeacherUser,
        'group' => $make('G', 'Fundamentos de Programación', $isc, 1, $teacher, '07:00'),
        'other' => $make('H', 'Investigación de Operaciones', $iind, 3, $otherTeacher, '09:00'),
        'load' => AcademicLoad::factory()->create(['term_id' => $term->id, 'modality_id' => modalityOf('PRES')->id, 'department_id' => $sc->id, 'status' => LoadStatus::Staffing]),
        'jefe' => User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $sc->id])->create(['name' => 'Alfredo Ríos Carrasco']),
        'jefeCb' => User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $cb->id])->create(['name' => 'Laura Peña Ochoa']),
        'coordIsc' => User::factory()->withRole(Role::Coordinador, ['modality_id' => modalityOf('PRES')->id, 'career_id' => $isc->id])->create(['name' => 'Karla Beltrán Ochoa']),
        'coordIind' => User::factory()->withRole(Role::Coordinador, ['modality_id' => modalityOf('PRES')->id, 'career_id' => $iind->id])->create(['name' => 'Raúl Tena Prieto']),
    ];
}

/**
 * El docente de G pide pasarlo a `$start` de lunes a jueves; a las 9:00 choca con H en A-1 (HC-02).
 */
function altRequest(object $w, string $start = '09:00'): ChangeRequest
{
    $end = sprintf('%02d:00', (int) substr($start, 0, 2) + 1);
    $sessions = array_map(fn (int $day): array => ['day' => $day, 'starts_at' => $start, 'ends_at' => $end], [1, 2, 3, 4]);

    return app(RequestChange::class)->handle($w->group, $w->teacherUser, ChangeRequestKind::Schedule, "Pasar a las {$start}", 'Otro trabajo por la mañana', ['sessions' => $sessions])->fresh();
}

/**
 * La solicitud de G con las alternativas del motor ya pedidas por el jefe.
 */
function altProposed(object $w): ChangeRequest
{
    return app(ProposeChangeAlternatives::class)->handle(altRequest($w), $w->jefe)->fresh();
}

/**
 * El id de la primera alternativa de un tipo (`chain` o `alone`).
 */
function altId(ChangeRequest $request, string $kind = 'chain'): string
{
    return collect($request->alternatives['items'])->firstWhere('kind', $kind)['id'];
}

/**
 * Las horas de inicio de las sesiones vivas de un grupo, sin repetir.
 *
 * @return list<string>
 */
function altLiveStarts(Group $group): array
{
    return GroupSession::query()->where('group_id', $group->id)->orderBy('starts_at')->pluck('starts_at')
        ->map(fn ($time): string => substr((string) $time, 0, 5))->unique()->values()->all();
}
