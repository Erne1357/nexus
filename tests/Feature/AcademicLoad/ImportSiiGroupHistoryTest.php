<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Actions\History\ImportSiiGroupHistory;
use App\Modules\AcademicLoad\Enums\HistoricalGroupSource;
use App\Modules\AcademicLoad\Models\HistoricalGroup;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use Illuminate\Support\Facades\DB;

/*
| Historial de grupos del SII (tarea 5.6): `academic-load:import-sii-groups` lleva lo ya migrado (`sii_archivo.grupos` y
| `sii_archivo.horarios`) a `historical_groups` con fuente `sii`. Las pruebas arman un `sii_archivo` mínimo dentro de la
| transacción de la prueba (DDL transaccional de PostgreSQL), así que no deja nada.
*/

beforeEach(function () {
    DB::unprepared('create schema if not exists sii_archivo');
    DB::unprepared('drop table if exists sii_archivo.grupos');
    DB::unprepared('drop table if exists sii_archivo.horarios');
    DB::unprepared('create table sii_archivo.grupos (_linea serial primary key, periodo text not null, materia text not null, grupo text not null, estatus_grupo text not null default \'\', capacidad_grupo text not null default \'\', alumnos_inscritos text not null default \'\', paralelo_de text not null default \'\', exclusivo_carrera text not null default \'\', exclusivo_reticula text not null default \'\')');
    DB::unprepared('drop table if exists sii_archivo.materias_claves_tecnm');
    DB::unprepared('create table sii_archivo.materias_claves_tecnm (_linea serial primary key, carrera text not null default \'\', reticula text not null default \'\', materia text not null, clave_tecnm text not null default \'\')');
    DB::unprepared('create table sii_archivo.horarios (_linea serial primary key, periodo text not null, tipo_horario text not null default \'D\', materia text not null, grupo text not null, aula text not null default \'\', rfc text not null default \'\', dia_semana text not null default \'2\', hora_inicial text not null default \'1900-01-01 07:00:00.0\', hora_final text not null default \'1900-01-01 08:00:00.0\')');

    $plans = tempnam(sys_get_temp_dir(), 'planes');
    file_put_contents($plans, "sii_carrera,sii_reticula,plan,carrera,modalidad,incluir,alumnos_vigentes,nota\n10,6,ISIC-2004-296,HGCAR,presencial,si,1,\nIGE,43,IGEM-2009-201,HGCAR,distancia,si,1,\n");
    $this->plansFile = $plans;
    app()->bind(ImportSiiGroupHistory::class, fn (): ImportSiiGroupHistory => new ImportSiiGroupHistory($plans));

    $this->campus = Campus::factory()->create(['code' => 'HC2']);
    $this->building = Building::factory()->for($this->campus)->create();
    Room::factory()->create(['building_id' => $this->building->id, 'code' => 'A-101']);
    $this->subject = Subject::factory()->create(['code' => 'HGMAT1']);
    $this->career = Career::factory()->create(['code' => 'HGCAR']);
});

afterEach(function () {
    @unlink($this->plansFile);
});

function historicalGroupsSiiGroup(string $term, string $subject, string $group, array $extra = []): void
{
    DB::table('sii_archivo.grupos')->insert(['periodo' => $term, 'materia' => $subject, 'grupo' => $group, 'capacidad_grupo' => '35', 'alumnos_inscritos' => '30', ...$extra]);
}

it('importa la materia del SII como subjects.code, con su cupo e inscritos', function () {
    historicalGroupsSiiGroup('20261', 'HGMAT1', 'A');

    $this->artisan('academic-load:import-sii-groups')->assertSuccessful();

    $row = HistoricalGroup::query()->sole();

    expect($row->subject_id)->toBe($this->subject->id)
        ->and($row->term_code)->toBe('20261')->and($row->group_code)->toBe('A')
        ->and($row->capacity)->toBe(35)->and($row->enrolled)->toBe(30)
        ->and($row->source)->toBe(HistoricalGroupSource::Sii)
        ->and($row->modality_id)->toBe(Modality::query()->where('code', 'PRES')->value('id'))
        ->and($row->load_snapshot_id)->toBeNull();
});

it('liga la clave del SII a la materia de Nexus por su clave TecNM, y dos claves del SII de la misma materia no chocan en el grupo A', function () {
    $tecnm = Subject::factory()->create(['code' => 'ACF-2301']);
    DB::table('sii_archivo.materias_claves_tecnm')->insert([
        ['carrera' => 'II2', 'reticula' => '27', 'materia' => '3FA', 'clave_tecnm' => 'ACF-2301'],
        ['carrera' => 'IM2', 'reticula' => '28', 'materia' => '2GA', 'clave_tecnm' => 'ACF-2301'],
    ]);
    historicalGroupsSiiGroup('20261', '3FA', 'A');
    historicalGroupsSiiGroup('20261', '2GA', 'A');

    $this->artisan('academic-load:import-sii-groups')->assertSuccessful();

    expect(HistoricalGroup::query()->where('subject_id', $tecnm->id)->orderBy('sii_subject_code')->pluck('sii_subject_code')->all())->toBe(['2GA', '3FA']);
});

it('ignora la clave TecNM que es la propia clave del SII y la menor gana entre varias', function () {
    $low = Subject::factory()->create(['code' => 'AAA-1000']);
    Subject::factory()->create(['code' => 'BBB-2000']);
    DB::table('sii_archivo.materias_claves_tecnm')->insert([
        ['materia' => 'HGMAT1', 'clave_tecnm' => 'HGMAT1'],
        ['materia' => 'X1', 'clave_tecnm' => 'BBB-2000'],
        ['materia' => 'X1', 'clave_tecnm' => 'AAA-1000'],
    ]);
    historicalGroupsSiiGroup('20261', 'HGMAT1', 'A');
    historicalGroupsSiiGroup('20261', 'X1', 'A');

    $this->artisan('academic-load:import-sii-groups')->assertSuccessful();

    expect(HistoricalGroup::query()->where('sii_subject_code', 'HGMAT1')->value('subject_id'))->toBe($this->subject->id)
        ->and(HistoricalGroup::query()->where('sii_subject_code', 'X1')->value('subject_id'))->toBe($low->id);
});

it('toma el campus del aula del horario del grupo, sin importar espacios ni mayúsculas', function () {
    historicalGroupsSiiGroup('20261', 'HGMAT1', 'A');
    historicalGroupsSiiGroup('20261', 'HGMAT1', 'B');
    DB::table('sii_archivo.horarios')->insert([
        ['periodo' => '20261', 'materia' => 'HGMAT1', 'grupo' => 'A', 'aula' => 'a-101'],
        ['periodo' => '20261', 'materia' => 'HGMAT1', 'grupo' => 'B', 'aula' => 'ZZ9'],
    ]);

    $this->artisan('academic-load:import-sii-groups')->assertSuccessful();

    expect(HistoricalGroup::query()->where('group_code', 'A')->value('campus_id'))->toBe($this->campus->id)
        ->and(HistoricalGroup::query()->where('group_code', 'B')->value('campus_id'))->toBeNull();
});

it('lleva la carrera y modalidad del grupo exclusivo y el paralelo, y los demás quedan en PRES sin carrera', function () {
    historicalGroupsSiiGroup('20261', 'HGMAT1', 'A', ['exclusivo_carrera' => 'IGE', 'exclusivo_reticula' => '43', 'paralelo_de' => 'HGMAT1    B']);
    historicalGroupsSiiGroup('20261', 'HGMAT1', 'B');

    $this->artisan('academic-load:import-sii-groups')->assertSuccessful();

    $exclusive = HistoricalGroup::query()->where('group_code', 'A')->sole();
    $open = HistoricalGroup::query()->where('group_code', 'B')->sole();

    expect($exclusive->career_id)->toBe($this->career->id)
        ->and($exclusive->modality->code)->toBe('EAD')
        ->and($exclusive->parallel_of)->toBe('HGMAT1 B')
        ->and($open->career_id)->toBeNull()->and($open->modality->code)->toBe('PRES');
});

it('cuenta y reporta una materia que Nexus no tiene, sin crearla', function () {
    historicalGroupsSiiGroup('20261', 'HGMAT1', 'A');
    historicalGroupsSiiGroup('20261', 'NOEXISTE', 'A');
    historicalGroupsSiiGroup('20261', 'NOEXISTE', 'B');

    $this->artisan('academic-load:import-sii-groups')
        ->expectsOutputToContain('1 materias del SII que Nexus no tiene (2 grupos sin importar): NOEXISTE')
        ->assertSuccessful();

    expect(HistoricalGroup::query()->count())->toBe(1)->and(Subject::query()->where('code', 'NOEXISTE')->exists())->toBeFalse();
});

it('reimportar no duplica y reemplaza lo que cambió el archivo', function () {
    historicalGroupsSiiGroup('20261', 'HGMAT1', 'A');
    $this->artisan('academic-load:import-sii-groups')->assertSuccessful();

    DB::table('sii_archivo.grupos')->update(['capacidad_grupo' => '40']);
    historicalGroupsSiiGroup('20261', 'HGMAT1', 'B');
    $this->artisan('academic-load:import-sii-groups')->assertSuccessful();

    expect(HistoricalGroup::query()->count())->toBe(2)
        ->and(HistoricalGroup::query()->where('group_code', 'A')->value('capacity'))->toBe(40)
        ->and(DB::table('activity_log')->where('event', 'group_history_imported')->count())->toBe(2);
});

it('con --dry-run reporta sin escribir', function () {
    historicalGroupsSiiGroup('20261', 'HGMAT1', 'A');

    $this->artisan('academic-load:import-sii-groups --dry-run')->expectsOutputToContain('Grupos: 1')->assertSuccessful();

    expect(HistoricalGroup::query()->count())->toBe(0)->and(DB::table('activity_log')->where('event', 'group_history_imported')->count())->toBe(0);
});

it('ignora el verano y reporta los periodos que no importa', function () {
    historicalGroupsSiiGroup('20262', 'HGMAT1', 'A');
    historicalGroupsSiiGroup('20263', 'HGMAT1', 'A');

    $this->artisan('academic-load:import-sii-groups --periodos=20262,20263')->expectsOutputToContain('20262')->assertSuccessful();

    expect(HistoricalGroup::query()->pluck('term_code')->all())->toBe(['20263']);
});

it('no toca las filas nexus ni los periodos que no importa', function () {
    historicalGroupsSiiGroup('20261', 'HGMAT1', 'A');
    $other = HistoricalGroup::factory()->fromSii()->create(['term_code' => '20253', 'subject_id' => $this->subject->id]);
    $nexus = HistoricalGroup::factory()->fromNexus()->create(['term_code' => '20261', 'subject_id' => $this->subject->id]);

    $this->artisan('academic-load:import-sii-groups --periodos=20261')->assertSuccessful();

    expect(HistoricalGroup::query()->whereKey([$other->id, $nexus->id])->count())->toBe(2)->and(HistoricalGroup::query()->count())->toBe(3);
});

it('un grupo con estatus se cuenta como no abierto y un cupo ilegible queda en cero', function () {
    historicalGroupsSiiGroup('20261', 'HGMAT1', 'A', ['estatus_grupo' => 'C']);
    historicalGroupsSiiGroup('20261', 'HGMAT1', 'B', ['capacidad_grupo' => 'x', 'alumnos_inscritos' => '']);

    $this->artisan('academic-load:import-sii-groups')->expectsOutputToContain('Cancelados (estatus): 1')->assertSuccessful();

    $row = HistoricalGroup::query()->sole();

    expect($row->group_code)->toBe('B')->and($row->capacity)->toBe(0)->and($row->enrolled)->toBeNull();
});

it('falla con un mensaje claro si la base no tiene el archivo del SII', function () {
    DB::unprepared('drop table sii_archivo.horarios');

    $this->artisan('academic-load:import-sii-groups')->expectsOutputToContain('No hay archivo del SII')->assertFailed();
});

function historicalGroupsSiiSession(array $extra = []): void
{
    DB::table('sii_archivo.horarios')->insert(['periodo' => '20261', 'materia' => 'HGMAT1', 'grupo' => 'A', ...$extra]);
}

it('importa las sesiones de clase: día de Nexus = día del SII menos 1, horas, aula normalizada y RFC', function () {
    historicalGroupsSiiGroup('20261', 'HGMAT1', 'A');
    historicalGroupsSiiSession(['dia_semana' => '2', 'hora_inicial' => '1900-01-01 16:00:00.0', 'hora_final' => '1900-01-01 17:30:00.0', 'aula' => 'lab a1', 'rfc' => ' ABCD800101XX1 ']);
    historicalGroupsSiiSession(['dia_semana' => '7', 'aula' => '']);

    $this->artisan('academic-load:import-sii-groups')->assertSuccessful();

    $sessions = HistoricalGroup::query()->sole()->sessions()->orderBy('day')->get();

    expect($sessions)->toHaveCount(2)
        ->and($sessions[0]->day)->toBe(1)->and($sessions[0]->starts_at)->toBe('16:00:00')->and($sessions[0]->ends_at)->toBe('17:30:00')
        ->and($sessions[0]->room_code)->toBe('LABA1')->and($sessions[0]->teacher_rfc)->toBe('ABCD800101XX1')
        ->and($sessions[1]->day)->toBe(6)->and($sessions[1]->room_code)->toBeNull()->and($sessions[1]->teacher_rfc)->toBeNull();
});

it('ignora lo que no es clase y los días fuera de lunes a sábado, y lo avisa agregado', function () {
    historicalGroupsSiiGroup('20261', 'HGMAT1', 'A');
    historicalGroupsSiiSession();
    historicalGroupsSiiSession(['tipo_horario' => 'A', 'dia_semana' => '3']);
    historicalGroupsSiiSession(['dia_semana' => '1']);
    historicalGroupsSiiSession(['dia_semana' => '13']);
    historicalGroupsSiiSession(['dia_semana' => '4', 'hora_inicial' => 'x']);

    $this->artisan('academic-load:import-sii-groups')
        ->expectsOutputToContain('Ignoradas (día fuera de lunes a sábado u hora ilegible): 3')
        ->assertSuccessful();

    expect(DB::table('historical_group_sessions')->count())->toBe(1);
});

it('no duplica las sesiones al reimportar y las reemplaza por las del SII', function () {
    historicalGroupsSiiGroup('20261', 'HGMAT1', 'A');
    historicalGroupsSiiSession();
    historicalGroupsSiiSession();

    $this->artisan('academic-load:import-sii-groups')->assertSuccessful();
    $this->artisan('academic-load:import-sii-groups')->assertSuccessful();

    expect(DB::table('historical_group_sessions')->count())->toBe(1);

    DB::table('sii_archivo.horarios')->update(['dia_semana' => '3']);
    $this->artisan('academic-load:import-sii-groups')->assertSuccessful();

    expect(DB::table('historical_group_sessions')->pluck('day')->all())->toBe([2]);
});

it('solo importa las sesiones de los periodos pedidos con --periodos', function () {
    historicalGroupsSiiGroup('20261', 'HGMAT1', 'A');
    historicalGroupsSiiGroup('20253', 'HGMAT1', 'A');
    historicalGroupsSiiSession();
    historicalGroupsSiiSession(['periodo' => '20253']);

    $this->artisan('academic-load:import-sii-groups', ['--periodos' => '20261'])->assertSuccessful();

    expect(DB::table('historical_group_sessions')->count())->toBe(1)
        ->and(HistoricalGroup::query()->sole()->term_code)->toBe('20261');
});

it('borra las sesiones junto con su grupo', function () {
    historicalGroupsSiiGroup('20261', 'HGMAT1', 'A');
    historicalGroupsSiiSession();
    $this->artisan('academic-load:import-sii-groups')->assertSuccessful();

    HistoricalGroup::query()->delete();

    expect(DB::table('historical_group_sessions')->count())->toBe(0);
});

it('importa dos periodos en una corrida y reimportar solo uno no toca el otro', function () {
    historicalGroupsSiiGroup('20261', 'HGMAT1', 'A');
    historicalGroupsSiiGroup('20253', 'HGMAT1', 'A');
    historicalGroupsSiiSession();
    historicalGroupsSiiSession(['periodo' => '20253', 'dia_semana' => '4']);

    $this->artisan('academic-load:import-sii-groups')->assertSuccessful();

    expect(HistoricalGroup::query()->count())->toBe(2)
        ->and(DB::table('historical_group_sessions')->count())->toBe(2);

    $untouched = HistoricalGroup::query()->where('term_code', '20253')->value('id');
    DB::table('sii_archivo.horarios')->where('periodo', '20261')->update(['dia_semana' => '5']);

    $this->artisan('academic-load:import-sii-groups', ['--periodos' => '20261'])->assertSuccessful();

    expect(HistoricalGroup::query()->count())->toBe(2)
        ->and(HistoricalGroup::query()->where('term_code', '20253')->value('id'))->toBe($untouched)
        ->and(DB::table('historical_group_sessions')->count())->toBe(2)
        ->and(HistoricalGroup::query()->where('term_code', '20261')->sole()->sessions()->value('day'))->toBe(4);
});
