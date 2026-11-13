<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Faculty\Models\TeacherDepartment;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Staffing\Models\StaffingRanking;

/*
| `staffing:rank`: calcula en el momento el ranking docente de un periodo (todas sus modalidades y departamentos, o los
| que se indiquen; M1: cada departamento en una modalidad, con los grupos de todos sus campus), para los seeders y los
| operadores.
*/

beforeEach(function () {
    $this->term = Term::factory()->create(['code' => '20271', 'starts_on' => '2027-01-18', 'ends_on' => '2027-06-11']);
    $this->c2 = Campus::factory()->create(['code' => 'C2']);
    $this->c1 = Campus::factory()->create(['code' => 'C1']);
    $this->ead = Campus::factory()->create(['code' => 'X1']);
    $this->sistemas = Department::factory()->create(['code' => 'SC']);
    $this->basicas = Department::factory()->create(['code' => 'CB']);
    $groupIn = function (Campus $campus, Department $department, string $modality = 'PRES'): Group {
        TeacherDepartment::factory()->create(['department_id' => $department->id]);

        return Group::factory()->create([
            'term_id' => $this->term->id,
            'campus_id' => $campus->id,
            'modality_id' => modalityOf($modality)->id,
            'subject_id' => Subject::factory()->create(['department_id' => $department->id])->id,
        ]);
    };
    $this->groups = [
        'c2-sc' => $groupIn($this->c2, $this->sistemas),
        'c2-cb' => $groupIn($this->c2, $this->basicas),
        'c1-sc' => $groupIn($this->c1, $this->sistemas),
        'ead-sc' => $groupIn($this->ead, $this->sistemas, 'EAD'),
    ];
    $this->rankedGroups = fn (): array => collect($this->groups)
        ->filter(fn (Group $group): bool => StaffingRanking::query()->where('group_id', $group->id)->exists())
        ->keys()->sort()->values()->all();
});

it('Ranking docente: rankea todas las modalidades y departamentos del periodo y dice cuántos grupos', function () {
    $this->artisan('staffing:rank', ['term' => '20271'])
        ->expectsOutputToContain('4 grupos')
        ->assertSuccessful();

    expect(($this->rankedGroups)())->toBe(['c1-sc', 'c2-cb', 'c2-sc', 'ead-sc']);
});

it('M1: se acota con --modality y --department, con los grupos de C1 y C2 del departamento', function () {
    $this->artisan('staffing:rank', ['term' => '20271', '--modality' => 'PRES', '--department' => 'SC'])
        ->expectsOutputToContain('2 grupos')
        ->assertSuccessful();

    expect(($this->rankedGroups)())->toBe(['c1-sc', 'c2-sc']);
});

it('Ranking docente: falla con un periodo, modalidad o departamento que no existe', function (array $arguments, string $message) {
    $this->artisan('staffing:rank', $arguments)
        ->expectsOutputToContain($message)
        ->assertFailed();

    expect(StaffingRanking::query()->exists())->toBeFalse();
})->with([
    'periodo' => [['term' => '20991'], 'No existe el periodo 20991.'],
    'modalidad' => [['term' => '20271', '--modality' => 'XX'], 'No existe la modalidad XX.'],
    'departamento' => [['term' => '20271', '--department' => 'XX'], 'No existe el departamento XX.'],
]);
