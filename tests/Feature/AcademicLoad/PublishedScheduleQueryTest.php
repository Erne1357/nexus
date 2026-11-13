<?php

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Database\Factories\LoadSnapshotFactory;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Models\LoadSnapshot;
use App\Modules\AcademicLoad\Queries\PublishedGroup;
use App\Modules\AcademicLoad\Queries\PublishedScheduleQuery;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
| PublishedScheduleQuery (tarea 4.4): lee de `load_snapshots` la última versión de cada carga de una modalidad y periodo
| (M1: con los grupos de todos sus campus), y nunca las tablas vivas. Una carga reabierta conserva su última versión publicada para el público y los docentes
| (P3, 10 de noviembre de 2026). Lo que falta por publicar solo se lo dice al personal (P7).
*/

function publishedQueryLoad(Term $term, Campus $campus, string $department, LoadStatus $status): AcademicLoad
{
    return AcademicLoad::factory()
        ->for($term)
        ->state(['modality_id' => $campus->modality_id])
        ->for(Department::query()->firstWhere('code', $department) ?? Department::factory()->create(['code' => $department, 'name' => 'Departamento '.$department]))
        ->inStatus($status)
        ->create();
}

/**
 * @param  list<array<string, mixed>>  $groups
 */
function publishedQuerySnapshot(AcademicLoad $load, int $version, string $at, array $groups): LoadSnapshot
{
    return LoadSnapshot::factory()->for($load)->publishedAt(CarbonImmutable::parse($at))->withGroups($groups)->create(['version' => $version]);
}

function publishedQueryGroup(string $subject, string $code, ?array $teacher = null): array
{
    return LoadSnapshotFactory::group([
        'code' => $code,
        'subject' => ['id' => (string) Str::uuid7(), 'code' => $subject, 'name' => 'Materia '.$subject, 'credits' => 5, 'theory_hours' => 3, 'practice_hours' => 2, 'department_id' => (string) Str::uuid7()],
        'teacher' => $teacher,
    ]);
}

/**
 * @param  list<PublishedGroup>  $groups
 * @return list<string>
 */
function publishedQueryLabels(array $groups): array
{
    return array_map(fn (PublishedGroup $group): string => $group->label(), $groups);
}

beforeEach(function () {
    $this->term = Term::factory()->create(['code' => '20271', 'starts_on' => '2027-01-25']);
    $this->previousTerm = Term::factory()->create(['code' => '20263', 'starts_on' => '2026-08-17']);
    $this->c2 = Campus::factory()->create(['code' => 'C2']);
    $this->c1 = Campus::factory()->create(['code' => 'C1']);
    $this->laura = ['id' => (string) Str::uuid7(), 'rfc' => 'RIML800101AB1', 'name' => 'Laura Ríos Medina'];

    $basic = publishedQueryLoad($this->term, $this->c2, 'CB', LoadStatus::Published);
    publishedQuerySnapshot($basic, 1, '2026-12-08 10:00', [publishedQueryGroup('ACF-0904', 'B', $this->laura)]);
    publishedQuerySnapshot($basic, 2, '2026-12-11 09:00', [publishedQueryGroup('ACF-0904', 'D', $this->laura)]);

    $this->systems = publishedQueryLoad($this->term, $this->c2, 'SC', LoadStatus::Adjusting);
    publishedQuerySnapshot($this->systems, 1, '2026-12-12 10:30', [publishedQueryGroup('AED-1026', 'C')]);

    $this->industrial = publishedQueryLoad($this->term, $this->c2, 'II', LoadStatus::Staffing);
    publishedQueryLoad($this->term, $this->c2, 'EE', LoadStatus::Draft);

    publishedQuerySnapshot(publishedQueryLoad($this->term, $this->c1, 'CBI', LoadStatus::Published), 1, '2026-12-09 08:00', [publishedQueryGroup('ACF-0901', 'F', $this->laura)]);
    $this->presencial = modalityOf('PRES');
    publishedQuerySnapshot(publishedQueryLoad($this->previousTerm, $this->c2, 'CB', LoadStatus::Published), 1, '2026-06-01 08:00', [publishedQueryGroup('ACF-0903', 'H', $this->laura)]);
});

it('junta la última versión de cada carga de la modalidad y periodo, de todos los campus, también la de una carga reabierta', function () {
    $schedule = app(PublishedScheduleQuery::class)->schedule($this->term, $this->presencial);

    expect(publishedQueryLabels($schedule->groups()))->toBe(['ACF-0901 · F', 'ACF-0904 · D', 'AED-1026 · C'])
        ->and($schedule->isPublished($this->systems->department_id))->toBeTrue()
        ->and($schedule->isPublished($this->industrial->department_id))->toBeFalse()
        ->and($schedule->updatedAt($schedule->groups())?->toIso8601String())->toBe('2026-12-12T10:30:00-07:00');
});

it('sin versiones publicadas en la modalidad y periodo da una vista vacía', function () {
    expect(app(PublishedScheduleQuery::class)->schedule($this->term, modalityOf('EAD'))->isEmpty())->toBeTrue();
});

it('guarda lo publicado en caché y una versión nueva lo reemplaza', function () {
    config(['cache.stores.array.serialize' => true]);
    Cache::purge('array');
    $query = app(PublishedScheduleQuery::class);
    $query->schedule($this->term, $this->presencial);

    DB::enableQueryLog();
    $query->schedule($this->term, $this->presencial);
    $cachedQueries = count(DB::getQueryLog());
    DB::disableQueryLog();

    publishedQuerySnapshot($this->systems, 2, '2026-12-13 09:00', [publishedQueryGroup('AED-1026', 'G')]);

    expect($cachedQueries)->toBe(1)
        ->and(publishedQueryLabels($query->schedule($this->term, $this->presencial)->groups()))->toBe(['ACF-0901 · F', 'ACF-0904 · D', 'AED-1026 · G']);
});

it('la caché de una modalidad no se mezcla con la de otra modalidad ni otro periodo', function () {
    $query = app(PublishedScheduleQuery::class);
    $query->schedule($this->term, $this->presencial);

    expect($query->schedule($this->term, modalityOf('EAD'))->groups())->toBe([])
        ->and(publishedQueryLabels($query->schedule($this->previousTerm, $this->presencial)->groups()))->toBe(['ACF-0903 · H']);
});

it('no guarda en caché el RFC, la justificación ni quién publicó', function () {
    config(['cache.stores.array.serialize' => true]);
    Cache::purge('array');
    app(PublishedScheduleQuery::class)->schedule($this->term, $this->presencial);

    $cached = json_encode(Cache::get('published-schedule:v2:'.$this->term->id.':'.$this->presencial->id.':'.app(PublishedScheduleQuery::class)->version($this->term, $this->presencial)), JSON_UNESCAPED_UNICODE);

    expect($cached)->not->toContain('RIML800101AB1')
        ->not->toContain('published_by')
        ->not->toContain('justification')
        ->toContain('Laura Ríos Medina');
});

it('da los grupos publicados de un docente en todas las modalidades y campus del periodo', function () {
    expect(publishedQueryLabels(app(PublishedScheduleQuery::class)->forTeacher($this->term, $this->laura['id'])))
        ->toBe(['ACF-0901 · F', 'ACF-0904 · D']);
});

it('dice al personal qué cargas de la modalidad faltan por publicar, sin las que siguen en borrador', function () {
    $pending = app(PublishedScheduleQuery::class)->unpublished($this->term, $this->presencial);

    expect(array_map(fn (AcademicLoad $load): string => $load->department->code.' '.$load->status->value, $pending))->toBe(['II staffing']);
});

it('lista los periodos con algo publicado, del más reciente, cada uno con sus modalidades', function () {
    $contexts = app(PublishedScheduleQuery::class)->contexts();

    expect(array_map(fn (array $context): string => $context['term']->code.': '.implode(', ', array_map(fn (Modality $modality): string => $modality->code, $context['modalities'])), $contexts))
        ->toBe(['20271: PRES', '20263: PRES']);
});
