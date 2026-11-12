<?php

use App\Modules\AcademicLoad\Enums\AcademicLoadPermission;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Students\Enums\StudentsPermission;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| M10: una modalidad inactiva solo deja de poder trabajarse, no de verse a sus alumnos. Un nombramiento sin modalidad
| cubre solo las modalidades activas (MS8), salvo para la lectura de alumnos (ReachesInactiveModalities). Con los
| permisos reales de los módulos. EAD se desactiva en estas pruebas.
*/

beforeEach(function () {
    $this->campus = Campus::factory()->create(['code' => 'C2']);
    $this->pres = modalityOf('PRES');
    $this->ead = modalityOf('EAD');
    $this->ead->update(['is_active' => false]);
    $this->unit = departmentKey(10);
    $this->career = careerKey(20);
});

function reachActor(User $user): Actor
{
    app()->forgetScopedInstances();
    test()->actingAs($user);

    return app(Actor::class);
}

it('M10: el jefe sin modalidad ve a los alumnos de una modalidad inactiva; quien fija PRES o la inactiva no', function () {
    $onEad = Target::of(modality: $this->ead->id, campus: $this->campus->id, unit: $this->unit, career: $this->career);

    $head = reachActor(User::factory()->withRole(Role::JefeDepartamento, ['org_unit_id' => $this->unit])->create());
    $headSees = $head->allows(StudentsPermission::StudentView, $onEad);
    $ofEad = reachActor(User::factory()->withRole(Role::Coordinador, ['modality_id' => $this->ead->id, 'career_id' => $this->career])->create());
    $ofEadSees = $ofEad->allows(StudentsPermission::StudentView, $onEad);
    $ofPres = reachActor(User::factory()->withRole(Role::Coordinador, ['modality_id' => $this->pres->id, 'career_id' => $this->career])->create());
    $ofPresSees = $ofPres->allows(StudentsPermission::StudentView, $onEad);

    expect($headSees)->toBeTrue()->and($ofEadSees)->toBeFalse()->and($ofPresSees)->toBeFalse();
});

it('M10: Subdirección, sin modalidad, no reabre ni ve la carga de una modalidad inactiva, con allows() y con constrain()', function () {
    Schema::create('modality_probes_m10', function (Blueprint $table): void {
        $table->id();
        $table->uuid('modality_id')->nullable();
        $table->uuid('unit_id')->nullable();
        $table->uuid('campus_id')->nullable();
        $table->uuid('career_id')->nullable();
    });
    foreach ([$this->pres, $this->ead] as $modality) {
        DB::table('modality_probes_m10')->insert(['modality_id' => $modality->id, 'unit_id' => $this->unit, 'campus_id' => $this->campus->id, 'career_id' => $this->career]);
    }
    $probe = new class extends Model
    {
        protected $table = 'modality_probes_m10';

        public $timestamps = false;
    };
    $columns = ['modality' => 'modality_probes_m10.modality_id', 'unit' => 'modality_probes_m10.unit_id', 'campus' => 'modality_probes_m10.campus_id', 'career' => 'modality_probes_m10.career_id'];
    $onEad = Target::of(modality: $this->ead->id, unit: $this->unit);
    $onPres = Target::of(modality: $this->pres->id, unit: $this->unit);

    $head = reachActor(User::factory()->withRole(Role::Subdireccion)->create());
    $result = [
        'reopen EAD' => $head->allows(AcademicLoadPermission::LoadReopen, $onEad),
        'reopen PRES' => $head->allows(AcademicLoadPermission::LoadReopen, $onPres),
        'view EAD' => $head->allows(AcademicLoadPermission::LoadView, $onEad),
        'view PRES' => $head->allows(AcademicLoadPermission::LoadView, $onPres),
        'reopen rows' => $head->constrain($probe->newQuery(), AcademicLoadPermission::LoadReopen, $columns)->count(),
        'view rows' => $head->constrain($probe->newQuery(), AcademicLoadPermission::LoadView, $columns)->count(),
        'students rows' => $head->constrain($probe->newQuery(), StudentsPermission::StudentView, $columns)->count(),
    ];

    expect($result)->toBe(['reopen EAD' => false, 'reopen PRES' => true, 'view EAD' => false, 'view PRES' => true, 'reopen rows' => 1, 'view rows' => 1, 'students rows' => 2]);
});
