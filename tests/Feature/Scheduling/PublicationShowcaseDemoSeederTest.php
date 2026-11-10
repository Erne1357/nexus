<?php

use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherPosition;
use App\Modules\Scheduling\Database\Seeders\PublicationShowcaseDemoSeeder;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;

/*
| La carga publicada en varias versiones de la demo mezclada (`todo`, tarea 4.3): pasa por el proceso real (justificar,
| cerrar, publicar, reabrir, volver a asignación, cambiar salones) y cada versión nueva cambia de salón algunas sesiones.
*/

beforeEach(function () {
    $this->term = Term::factory()->create(['code' => '20271']);
    $this->campus = Campus::factory()->create(['code' => 'C2']);
    User::factory()->create(['username' => 'admin']);
    User::factory()->create(['username' => 'subdir']);
    $this->department = Department::factory()->create(['code' => 'CEA']);
    User::factory()->create(['username' => 'jefe.cea']);
    $this->load = AcademicLoad::factory()->create(['term_id' => $this->term->id, 'campus_id' => $this->campus->id, 'department_id' => $this->department->id, 'status' => LoadStatus::Closed]);

    $building = Building::factory()->create(['campus_id' => $this->campus->id]);
    $rooms = Room::factory()->capacity(40)->count(3)->create(['building_id' => $building->id, 'department_id' => $this->department->id]);
    $subject = Subject::factory()->create(['department_id' => $this->department->id, 'theory_hours' => 2, 'practice_hours' => 0]);

    $this->groups = collect([1, 2])->map(function (int $day) use ($rooms, $subject): Group {
        $teacher = Teacher::factory()->create();
        TeacherPosition::factory()->asignatura(20)->create(['teacher_id' => $teacher->id]);
        $group = Group::factory()->withQuota()->create([
            'term_id' => $this->term->id, 'campus_id' => $this->campus->id, 'teacher_id' => $teacher->id, 'capacity' => 30, 'subject_id' => $subject->id,
        ]);
        CareerCampus::factory()->create(['career_id' => $group->owner_career_id, 'campus_id' => $this->campus->id]);
        GroupSession::factory()->for($group)->inRoom($rooms[$day - 1])->on($day, '07:00', '09:00')->create();

        return $group;
    });
});

it('publica la carga cerrada en tres versiones y cada una cambia de salón alguna sesión', function () {
    app(PublicationShowcaseDemoSeeder::class)->run('C2', 'CEA', '20271', 3);

    $load = $this->load->fresh();
    $rooms = $load->snapshots->map(fn ($snapshot): array => collect($snapshot->payload['groups'])
        ->mapWithKeys(fn (array $group): array => [$group['id'] => array_column(array_column($group['sessions'], 'room'), 'id')])->sortKeys()->all());

    expect($load->status)->toBe(LoadStatus::Published)
        ->and($load->current_version)->toBe(3)
        ->and($load->snapshots->pluck('version')->all())->toBe([1, 2, 3])
        ->and($rooms[1])->not->toBe($rooms[0])
        ->and($rooms[2])->not->toBe($rooms[1]);
});

it('con reopenAtEnd deja la carga en ajustes con su última versión publicada', function () {
    app(PublicationShowcaseDemoSeeder::class)->run('C2', 'CEA', '20271', 2, reopenAtEnd: true);

    $load = $this->load->fresh();

    expect($load->status)->toBe(LoadStatus::Adjusting)
        ->and($load->current_version)->toBe(2);
});

it('una carga en asignación con un grupo sin docente lo justifica, la cierra y la publica', function () {
    $this->load->update(['status' => LoadStatus::Staffing]);
    $this->groups[0]->update(['teacher_id' => null]);

    app(PublicationShowcaseDemoSeeder::class)->run('C2', 'CEA', '20271', 1);

    expect($this->load->fresh()->status)->toBe(LoadStatus::Published)
        ->and($this->groups[0]->fresh()->close_justification)->not->toBeNull()
        ->and(collect($this->load->fresh()->snapshots->sole()->payload['groups'])->firstWhere('id', $this->groups[0]->id)['teacher'])->toBeNull();
});

it('se niega con una carga que no está en asignación ni cerrada', function () {
    $this->load->update(['status' => LoadStatus::Draft]);

    app(PublicationShowcaseDemoSeeder::class)->run('C2', 'CEA', '20271', 3);
})->throws(RuntimeException::class, 'la demo de versiones empieza en asignación o cerrada');
