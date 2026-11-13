<?php

use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\AcademicLoadServiceProvider;
use App\Modules\AcademicLoad\Database\Seeders\PublicationDemoSeeder;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Demo\DemoCheck;
use App\Modules\Core\Demo\DemoContext;
use App\Modules\Core\Enums\DemoCheckpoint;
use App\Modules\Core\Enums\DemoVolume;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherPosition;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Scheduling\SchedulingServiceProvider;

/*
| Punto 9 de la demo, `reanalisis` (tarea 4.3): cada carga cerrada de los campus de la demo sale publicada con su versión
| 1, como la publicaría el jefe del departamento; las cargas en borrador (sin grupos, P9) no se tocan. Sus revisiones lo
| confirman contra los grupos vivos y el historial docente.
*/

beforeEach(function () {
    $this->term = Term::factory()->create(['code' => '20271']);
    $this->campus = Campus::factory()->create(['code' => 'C2']);
    User::factory()->create(['username' => 'admin']);
    $this->department = Department::factory()->create(['code' => 'SC']);
    $this->jefe = User::factory()->create(['username' => 'jefe.sc', 'name' => 'Jefatura de Sistemas']);
    $this->closed = AcademicLoad::factory()->create(['term_id' => $this->term->id, 'modality_id' => $this->campus->modality_id, 'department_id' => $this->department->id, 'status' => LoadStatus::Closed]);
    $this->draft = AcademicLoad::factory()->create(['term_id' => $this->term->id, 'modality_id' => $this->campus->modality_id, 'status' => LoadStatus::Draft]);

    $teacher = Teacher::factory()->create();
    TeacherPosition::factory()->asignatura(20)->create(['teacher_id' => $teacher->id]);
    $room = Room::factory()->capacity(40)->create(['building_id' => Building::factory()->create(['campus_id' => $this->campus->id])->id, 'department_id' => $this->department->id]);
    $group = Group::factory()->withQuota()->create([
        'term_id' => $this->term->id, 'campus_id' => $this->campus->id, 'teacher_id' => $teacher->id, 'capacity' => 30,
        'subject_id' => Subject::factory()->create(['department_id' => $this->department->id, 'theory_hours' => 2, 'practice_hours' => 0])->id,
    ]);
    CareerCampus::factory()->create(['career_id' => $group->owner_career_id, 'campus_id' => $this->campus->id]);
    GroupSession::factory()->for($group)->inRoom($room)->on(1, '07:00', '09:00')->create();
});

/**
 * Las revisiones propias del punto 9 de AcademicLoad y Scheduling (no las que valen desde puntos anteriores), con su
 * resultado.
 *
 * @return array<string, true|string>
 */
function reanalysisChecks(): array
{
    $demo = new DemoContext(DemoVolume::Demo, ['C2'], '20271');
    $checks = [...app()->getProvider(AcademicLoadServiceProvider::class)->demoChecks(), ...app()->getProvider(SchedulingServiceProvider::class)->demoChecks()];

    return collect($checks)
        ->filter(fn (DemoCheck $check): bool => $check->at === [DemoCheckpoint::Reanalysis])
        ->mapWithKeys(fn (DemoCheck $check): array => [$check->label => ($check->passes)($demo)])
        ->all();
}

it('publica cada carga cerrada como su jefe y deja en borrador la que no tiene grupos', function () {
    app(PublicationDemoSeeder::class)->run(['C2'], '20271');

    $closed = $this->closed->fresh();

    expect($closed->status)->toBe(LoadStatus::Published)
        ->and($closed->current_version)->toBe(1)
        ->and($closed->published_by)->toBe($this->jefe->id)
        ->and($closed->snapshots()->sole()->payload['groups'])->toHaveCount(1)
        ->and($this->draft->fresh()->status)->toBe(LoadStatus::Draft);
});

it('las revisiones del punto 9 pasan después de publicar y fallan antes', function () {
    expect(reanalysisChecks())->each->not->toBeTrue();

    app(PublicationDemoSeeder::class)->run(['C2'], '20271');

    expect(reanalysisChecks())->each->toBeTrue()
        ->and(reanalysisChecks())->toHaveCount(3);
});

it('el punto 9 lleva su paso de publicación y empieza el re-análisis', function () {
    $steps = collect(app()->getProvider(AcademicLoadServiceProvider::class)->demoSteps())->filter(fn ($step): bool => $step->reaches === DemoCheckpoint::Reanalysis);

    expect($steps)->toHaveCount(1)
        ->and($steps->first()->label)->toBe('Cargas cerradas publicadas con su versión 1');
});
