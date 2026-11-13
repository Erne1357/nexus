<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Validation\ScheduleValidator;
use App\Modules\Scheduling\Validation\SessionCandidate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
| Rendimiento del validador (fase 3, «Pruebas de la fase»): validar la carga de una modalidad completa (estimado 400
| grupos) en menos de 5 segundos, y que el editor responda en menos de 300 ms por movimiento.
*/

beforeEach(function () {
    $this->term = Term::factory()->create();
    $this->campus = Campus::factory()->create();
    $building = Building::factory()->create(['campus_id' => $this->campus->id]);
    $subjects = Subject::factory()->count(60)->create();
    $rooms = Room::factory()->count(40)->create(['building_id' => $building->id]);
    $teachers = Teacher::factory()->count(80)->create();
    $plans = Career::factory()->count(6)->create()->map(fn (Career $career) => StudyPlan::factory()->create(['career_id' => $career->id]));
    $now = now();

    $groups = [];
    $sessions = [];

    for ($i = 0; $i < 400; $i++) {
        $subject = $subjects[$i % 60];
        $plan = $plans[$i % 6];
        $id = (string) Str::uuid7();

        $groups[] = [
            'id' => $id, 'term_id' => $this->term->id, 'campus_id' => $this->campus->id, 'modality_id' => modalityOf('PRES')->id, 'subject_id' => $subject->id,
            'department_id' => $subject->department_id, 'owner_career_id' => $plan->career_id, 'study_plan_id' => $plan->id,
            'semester' => $i % 9 + 1, 'code' => "G{$i}", 'teacher_id' => $teachers[$i % 80]->id, 'capacity' => 30,
            'status' => 'planned', 'created_at' => $now, 'updated_at' => $now,
        ];

        $start = 7 + 2 * (intdiv($i, 40) % 2);

        $sessions[] = [
            'group_id' => $id, 'day' => intdiv($i, 80) + 1, 'starts_at' => sprintf('%02d:00', $start), 'ends_at' => sprintf('%02d:00', $start + 2),
            'room_id' => $rooms[$i % 40]->id, 'kind' => 'teoria', 'term_id' => $this->term->id, 'campus_id' => $this->campus->id,
            'created_at' => $now, 'updated_at' => $now,
        ];
    }

    DB::table('groups')->insert($groups);
    DB::table('group_sessions')->insert($sessions);
});

it('valida la carga de una modalidad de 400 grupos en menos de 5 segundos', function () {
    $started = hrtime(true);

    $result = app(ScheduleValidator::class)->validateLoad($this->term, modalityOf('PRES'));

    $seconds = (hrtime(true) - $started) / 1e9;

    expect($seconds)->toBeLessThan(5.0)
        ->and($result->forRule('HC-01'))->toBe([])
        ->and($result->forRule('HC-02'))->toBe([]);
});

it('con la foto leída, el editor responde una sesión candidata en menos de 300 ms', function () {
    $validator = app(ScheduleValidator::class);
    $group = Group::query()->where('code', 'G0')->sole();
    $validator->validateGroup($group);

    $started = hrtime(true);
    $validator->checkSession($group, new SessionCandidate(2, '11:00', '13:00'));
    $milliseconds = (hrtime(true) - $started) / 1e6;

    expect($milliseconds)->toBeLessThan(300.0);
});
