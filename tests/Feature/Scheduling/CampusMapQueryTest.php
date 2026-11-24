<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Models\HistoricalGroup;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherDepartment;
use App\Modules\Scheduling\Queries\CampusMapQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/*
| CampusMapQuery (tarea 1.24.1): los datos reales del mapa del campus del Inicio. El campus del mapa es el de los
| edificios con map_key; el periodo, el que contiene el día (o el último que terminó, S1); las clases salen de la
| carga publicada de Nexus o, si no hay, de las sesiones históricas del SII, con el aula resuelta por la clave del salón
| y el docente por su RFC.
*/

beforeEach(function () {
    $this->campus = Campus::factory()->create(['code' => 'C1']);
    $this->other = Campus::factory()->create(['code' => 'C2']);
    $this->building = Building::factory()->for($this->campus)->create(['code' => 'SC', 'name' => 'Edificio Sistemas', 'map_key' => 'Edificio Sistemas']);
    $this->department = Department::factory()->create(['code' => '110600', 'name' => 'Sistemas y Computación']);
    $this->room = Room::factory()->create(['building_id' => $this->building->id, 'code' => '701', 'map_key' => 'Edificio Sistemas|701', 'capacity' => 35, 'department_id' => $this->department->id]);
    $this->term = Term::factory()->create(['code' => '20263', 'name' => 'Ago-Dic 2026', 'starts_on' => '2026-08-17', 'ends_on' => '2026-12-11']);
    $this->subject = Subject::factory()->create(['code' => 'AED-1285', 'name' => 'Fundamentos de Programación', 'department_id' => $this->department->id, 'credits' => 5]);
    $this->teacher = Teacher::factory()->create(['rfc' => 'AAAA800101AA1', 'name' => 'Docente Uno', 'institutional_email' => 'uno@cdjuarez.tecnm.mx']);
    TeacherDepartment::factory()->create(['teacher_id' => $this->teacher->id, 'department_id' => $this->department->id, 'is_primary' => true]);
    $this->day = CarbonImmutable::parse('2026-11-20', 'America/Ciudad_Juarez');
});

function siiGroup(array $attributes, array $sessions): HistoricalGroup
{
    $group = HistoricalGroup::factory()->fromSii()->create($attributes);

    foreach ($sessions as $session) {
        DB::table('historical_group_sessions')->insert([...$session, 'historical_group_id' => $group->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    return $group;
}

it('toma el periodo dado y junta las sesiones del SII en clases por hora con sus días', function () {
    siiGroup(
        ['term_code' => '20263', 'campus_id' => $this->campus->id, 'subject_id' => $this->subject->id, 'group_code' => 'C'],
        collect(range(1, 5))->map(fn (int $day): array => ['day' => $day, 'starts_at' => '09:00', 'ends_at' => '10:00', 'room_code' => '701', 'teacher_rfc' => 'AAAA800101AA1'])->all(),
    );

    $map = app(CampusMapQuery::class)->forTerm($this->term);

    expect($map['term'])->toBe(['code' => '20263', 'name' => 'Ago-Dic 2026'])
        ->and($map['source'])->toBe('sii')
        ->and($map['buildings'])->toBe(['Edificio Sistemas' => ['code' => 'SC', 'name' => 'Edificio Sistemas']])
        ->and($map['rooms']['Edificio Sistemas|701'])->toMatchArray(['code' => '701', 'capacity' => 35, 'owner' => '110600'])
        ->and($map['subjects'])->toBe([['id' => 'AED-1285', 'name' => 'Fundamentos de Programación', 'area' => '110600', 'cred' => 5]])
        ->and($map['groups'])->toHaveCount(1)
        ->and($map['groups'][0])->toMatchArray(['id' => 'AED-1285·C', 'code' => 'C', 'area' => '110600', 'turno' => 'Matutino'])
        ->and($map['profs'])->toBe([['id' => $this->teacher->id, 'name' => 'Docente Uno', 'title' => $this->teacher->degree, 'area' => '110600', 'email' => 'uno@cdjuarez.tecnm.mx']])
        ->and($map['classes'])->toBe([[
            'subject' => 'AED-1285',
            'group' => 'AED-1285·C',
            'prof' => $this->teacher->id,
            'room' => 'Edificio Sistemas|701',
            'roomLabel' => '701',
            'days' => [0, 1, 2, 3, 4],
            'hour' => 9,
            'end' => 10,
        ]]);
});

it('deja sin salón del mapa ni docente lo que no se resuelve, sin perder la clase', function () {
    siiGroup(
        ['term_code' => '20263', 'campus_id' => $this->campus->id, 'subject_id' => $this->subject->id, 'group_code' => 'X'],
        [['day' => 2, 'starts_at' => '07:00', 'ends_at' => '08:40', 'room_code' => 'LABA1', 'teacher_rfc' => 'ZZZZ000000ZZ0']],
    );

    $class = app(CampusMapQuery::class)->forTerm($this->term)['classes'][0];

    expect($class)->toMatchArray(['room' => null, 'roomLabel' => 'LABA1', 'prof' => null, 'days' => [1], 'hour' => 7, 'end' => 9]);
});

it('solo trae las clases del campus del mapa y del periodo en curso', function () {
    siiGroup(['term_code' => '20263', 'campus_id' => $this->other->id, 'subject_id' => $this->subject->id, 'group_code' => 'A'],
        [['day' => 1, 'starts_at' => '07:00', 'ends_at' => '08:00', 'room_code' => '701', 'teacher_rfc' => null]]);
    siiGroup(['term_code' => '20261', 'campus_id' => $this->campus->id, 'subject_id' => $this->subject->id, 'group_code' => 'B'],
        [['day' => 1, 'starts_at' => '08:00', 'ends_at' => '09:00', 'room_code' => '701', 'teacher_rfc' => null]]);

    $map = app(CampusMapQuery::class)->forTerm($this->term);

    expect($map['classes'])->toBe([])->and($map['groups'])->toBe([]);
});

it('sin periodo el mapa sale vacío (MP-01)', function () {
    $map = app(CampusMapQuery::class)->forTerm(null);

    expect($map['term'])->toBeNull()->and($map['classes'])->toBe([])->and($map['source'])->toBeNull();
});

it('sin edificios del mapa no trae salones ni clases', function () {
    Building::query()->update(['map_key' => null]);
    Room::query()->update(['map_key' => null]);

    $map = app(CampusMapQuery::class)->forTerm($this->term);

    expect($map['source'])->toBeNull()->and($map['rooms'])->toBe([])->and($map['classes'])->toBe([]);
});

it('da la ubicación y el contacto de los departamentos con su edificio y oficina del mapa', function () {
    DB::table('departments')->where('id', $this->department->id)->update([
        'map_key' => 'Edificio Sistemas|Departamento Sistemas',
        'phone' => '(656) 688-2500 Ext. 2461',
        'email' => 'jefatura_syc@cdjuarez.tecnm.mx',
    ]);

    $department = collect(app(CampusMapQuery::class)->forTerm($this->term)['departments'])->firstWhere('id', '110600');

    expect($department)->toMatchArray([
        'name' => 'Sistemas y Computación',
        'b' => 'Edificio Sistemas',
        'room' => 'Departamento Sistemas',
        'phone' => '(656) 688-2500 Ext. 2461',
        'email' => 'jefatura_syc@cdjuarez.tecnm.mx',
    ]);
});

it('toma la carrera del grupo histórico para el grupo del mapa', function () {
    $career = Career::factory()->create(['code' => 'ISC', 'department_id' => $this->department->id]);
    siiGroup(['term_code' => '20263', 'campus_id' => $this->campus->id, 'subject_id' => $this->subject->id, 'group_code' => 'N', 'career_id' => $career->id],
        [['day' => 1, 'starts_at' => '20:00', 'ends_at' => '21:00', 'room_code' => '701', 'teacher_rfc' => null]]);

    expect(app(CampusMapQuery::class)->forTerm($this->term)['groups'][0])->toMatchArray(['career' => 'ISC', 'turno' => 'Vespertino']);
});

it('manda el correo institucional del docente a todo el que tiene sesión (confirmado el 20 de noviembre de 2026)', function () {
    siiGroup(['term_code' => '20263', 'campus_id' => $this->campus->id, 'subject_id' => $this->subject->id, 'group_code' => 'C'],
        [['day' => 1, 'starts_at' => '09:00', 'ends_at' => '10:00', 'room_code' => '701', 'teacher_rfc' => 'AAAA800101AA1']]);

    expect(json_encode(app(CampusMapQuery::class)->forTerm($this->term)))->toContain('uno@cdjuarez.tecnm.mx');
});

it('MP-01: cada periodo trae sus clases y uno sin clases sale vacío', function () {
    $older = Term::factory()->create(['code' => '20253', 'name' => 'Ago-Dic 2025', 'starts_on' => '2025-08-18', 'ends_on' => '2025-12-12']);
    siiGroup(['term_code' => '20253', 'campus_id' => $this->campus->id, 'subject_id' => $this->subject->id, 'group_code' => 'Z'],
        [['day' => 1, 'starts_at' => '07:00', 'ends_at' => '08:00', 'room_code' => '701', 'teacher_rfc' => null]]);

    $query = app(CampusMapQuery::class);

    expect($query->forTerm($older)['classes'])->toHaveCount(1)
        ->and($query->forTerm($older)['term']['code'])->toBe('20253')
        ->and($query->forTerm($this->term)['classes'])->toBe([])
        ->and($query->forTerm($this->term)['source'])->toBe('sii');
});

it('MP-01: un periodo estable se guarda 30 días y uno reciente 10 minutos', function () {
    $ttls = [];
    Cache::shouldReceive('remember')->andReturnUsing(function (string $key, int $ttl, Closure $callback) use (&$ttls) {
        $ttls[$key] = $ttl;

        return $callback();
    });

    $query = app(CampusMapQuery::class);

    $this->travelTo(CarbonImmutable::parse('2026-08-20 10:00', 'America/Ciudad_Juarez'));
    $query->forTerm($this->term);
    $recent = collect($ttls)->first(fn ($ttl, $key) => str_contains($key, ':v2:'));

    $this->travelTo(CarbonImmutable::parse('2026-08-24 10:00', 'America/Ciudad_Juarez'));
    $ttls = [];
    $query->forTerm($this->term);
    $sevenDays = collect($ttls)->first(fn ($ttl, $key) => str_contains($key, ':v2:'));

    $this->travelTo(CarbonImmutable::parse('2027-01-10 10:00', 'America/Ciudad_Juarez'));
    $ttls = [];
    $query->forTerm($this->term);
    $ended = collect($ttls)->first(fn ($ttl, $key) => str_contains($key, ':v2:'));

    expect($recent)->toBe(CampusMapQuery::LIVE_TTL_SECONDS)
        ->and($sevenDays)->toBe(CampusMapQuery::STABLE_TTL_SECONDS)
        ->and($ended)->toBe(CampusMapQuery::STABLE_TTL_SECONDS);
});

it('MP-01: un cambio en una sesión de la carga publicada se ve pasada la ventana de la huella', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-20 10:00', 'America/Ciudad_Juarez'));
    $other = Room::factory()->create(['building_id' => $this->building->id, 'code' => '702', 'map_key' => 'Edificio Sistemas|702']);
    $group = HistoricalGroup::factory()->fromSii()->create(['term_code' => '20263', 'campus_id' => $this->campus->id, 'subject_id' => $this->subject->id, 'group_code' => 'H']);
    $sessionId = DB::table('historical_group_sessions')->insertGetId([
        'historical_group_id' => $group->id, 'day' => 1, 'starts_at' => '09:00', 'ends_at' => '10:00', 'room_code' => '701',
        'teacher_rfc' => null, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $query = app(CampusMapQuery::class);
    $before = $query->fingerprint($this->term, $this->campus->id);

    expect($query->forTerm($this->term)['classes'][0]['room'])->toBe('Edificio Sistemas|701');

    $this->travel(5)->seconds();
    DB::table('historical_group_sessions')->where('id', $sessionId)->update(['room_code' => '702', 'updated_at' => now()]);

    // Dentro de la ventana de 60 s la huella vieja sigue; pasada, la llave cambia y el salón nuevo aparece.
    expect($query->fingerprint($this->term, $this->campus->id))->toBe($before);

    $this->travel(61)->seconds();

    expect($query->fingerprint($this->term, $this->campus->id))->not->toBe($before)
        ->and($query->forTerm($this->term)['classes'][0]['room'])->toBe('Edificio Sistemas|702');
});
