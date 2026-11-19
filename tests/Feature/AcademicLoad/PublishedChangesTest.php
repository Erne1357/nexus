<?php

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Database\Factories\LoadSnapshotFactory;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Enums\SnapshotReason;
use App\Modules\AcademicLoad\Exports\CampusWorkbook;
use App\Modules\AcademicLoad\Exports\DepartmentPdf;
use App\Modules\AcademicLoad\Exports\PublishedPdf;
use App\Modules\AcademicLoad\Exports\SemesterPdf;
use App\Modules\AcademicLoad\Exports\TeacherPdf;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Models\LoadSnapshot;
use App\Modules\AcademicLoad\Queries\PublishedScheduleQuery;
use App\Modules\AcademicLoad\Rules\SnapshotDiff;
use App\Modules\AcademicLoad\Rules\SnapshotPayload;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use OpenSpout\Reader\XLSX\Options;
use OpenSpout\Reader\XLSX\Reader;

/*
| Qué cambió en la consulta pública, en los PDF y en el Excel (tarea 8.5; decisión del usuario del 17 de noviembre de
| 2026): el público ve «Actualizado el …» con la fecha y la hora de la versión vigente y, en cada grupo que cambió contra
| la versión anterior de su carga, la marca «Cambió» con qué cambió (horario, salón, docente), «Grupo nuevo» o
| «Cancelado». Nunca el número de versión ni cupos: las versiones son solo para el personal con sesión, y `?version=` no
| existe en lo público.
*/

/**
 * Un grupo de la carga de Ciencias Básicas, en el semestre 3 de ISC.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function publishedChangesGroup(string $id, string $subject, string $name, string $code, array $overrides = []): array
{
    return LoadSnapshotFactory::group([
        'id' => $id,
        'code' => $code,
        'subject' => ['id' => 'sub-'.$subject, 'code' => $subject, 'name' => $name, 'credits' => 5, 'theory_hours' => 3, 'practice_hours' => 2, 'department_id' => (string) Str::uuid7()],
        ...$overrides,
    ]);
}

/**
 * Publica la versión siguiente de la carga con esos grupos y el diff contra la anterior, como PublishLoad (tarea 8.4).
 *
 * @param  list<array<string, mixed>>  $groups
 */
function publishedChangesRepublish(AcademicLoad $load, array $groups, string $at): LoadSnapshot
{
    $previous = LoadSnapshot::query()->where('academic_load_id', $load->id)->orderByDesc('version')->firstOrFail();
    $publisher = User::factory()->create();
    $publishedAt = CarbonImmutable::parse($at);
    $payload = LoadSnapshotFactory::payload($load, $previous->version + 1, $publisher, $groups, $publishedAt);
    $diff = SnapshotDiff::between(SnapshotPayload::normalize($previous->payload), SnapshotPayload::normalize($payload));

    return LoadSnapshot::factory()->for($load)->publishedAt($publishedAt)->create([
        'version' => $previous->version + 1, 'created_by' => $publisher->id, 'payload' => $payload, 'diff' => $diff, 'reason' => SnapshotReason::Reanalysis,
    ]);
}

/**
 * La ficha de un grupo en la página de un semestre, por su «CLAVE · grupo».
 */
function publishedChangesCard(string $html, string $label): string
{
    $start = strpos($html, 'data-group="'.$label.'"');
    expect($start)->not->toBeFalse("No está la ficha de {$label}");

    return Str::before(substr($html, $start), '</article>');
}

/**
 * El HTML que DomPDF recibe para ese PDF, armado con las versiones de su llave.
 */
function publishedChangesPdfHtml(PublishedPdf $export, Term $term): string
{
    $modality = modalityOf('PRES');
    $schedule = app(PublishedScheduleQuery::class)->scheduleOf($term, $modality, $export->snapshotIds);
    [$view, $data] = (fn (): array => $this->document($schedule, $term, $modality))->call($export);

    return view($view, [...$data, 'term' => $term, 'modality' => $modality, 'campus' => null])->render();
}

/**
 * Los renglones de un Excel (la primera hoja), también los vacíos.
 *
 * @return list<list<mixed>>
 */
function publishedChangesRows(string $bytes): array
{
    $path = tempnam(sys_get_temp_dir(), 'xlsx-test-');
    file_put_contents($path, $bytes);
    $reader = new Reader(new Options(SHOULD_PRESERVE_EMPTY_ROWS: true));
    $reader->open($path);
    $rows = [];

    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $rows[] = $row->toArray();
        }

        break;
    }

    $reader->close();
    unlink($path);

    return $rows;
}

beforeEach(function () {
    $this->term = Term::factory()->create(['code' => '20271', 'name' => 'Enero–Junio 2027', 'starts_on' => '2027-01-25']);
    $this->c2 = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    $campus = ['id' => $this->c2->id, 'code' => 'C2', 'name' => 'Campus II'];
    $this->career = ['id' => (string) Str::uuid7(), 'code' => 'ISC', 'name' => 'Ingeniería en Sistemas Computacionales'];
    $this->plan = ['id' => (string) Str::uuid7(), 'code' => 'ISIC-2010-224'];
    $this->laura = ['id' => (string) Str::uuid7(), 'rfc' => 'RIML800101AB1', 'name' => 'Laura Ríos Medina'];
    $this->ana = ['id' => (string) Str::uuid7(), 'rfc' => 'RUAA800101AB1', 'name' => 'Ana Ruiz'];
    $building = ['id' => (string) Str::uuid7(), 'code' => 'B', 'name' => 'Edificio B'];
    $this->b12 = ['id' => (string) Str::uuid7(), 'code' => 'B-12', 'name' => null, 'building' => $building, 'owner_department_id' => null];
    $this->b14 = ['id' => (string) Str::uuid7(), 'code' => 'B-14', 'name' => null, 'building' => $building, 'owner_department_id' => null];
    $placement = LoadSnapshotFactory::placement(['career' => $this->career, 'study_plan' => $this->plan, 'semester' => 3, 'seats' => 35]);
    $session = fn (int $day, array $room, string $starts = '07:00', string $ends = '09:00'): array => LoadSnapshotFactory::session(['day' => $day, 'starts_at' => $starts, 'ends_at' => $ends, 'room' => $room]);
    $group = fn (string $id, string $subject, string $name, string $code, array $overrides = []): array => publishedChangesGroup($id, $subject, $name, $code, ['campus' => $campus, 'placements' => [$placement], 'teacher' => null, ...$overrides]);

    $this->v1 = [
        'schedule' => $group('g-schedule', 'ACF-0904', 'Cálculo Vectorial', 'B', ['teacher' => $this->laura, 'sessions' => [$session(1, $this->b12)]]),
        'room' => $group('g-room', 'AED-1026', 'Estructura de Datos', 'D', ['teacher' => $this->laura, 'sessions' => [$session(2, $this->b12, '10:00', '12:00')]]),
        'teacher' => $group('g-teacher', 'ACF-0905', 'Ecuaciones Diferenciales', 'A', ['teacher' => $this->ana, 'sessions' => [$session(3, $this->b12)]]),
        'quota' => $group('g-quota', 'AEF-1031', 'Fundamentos de Bases de Datos', 'A', ['capacity' => 30, 'sessions' => [$session(4, $this->b12)]]),
        'cancelled' => $group('g-cancelled', 'ACF-0904', 'Cálculo Vectorial', 'C', ['teacher' => $this->ana, 'justification' => 'Docente en contratación', 'sessions' => [$session(5, $this->b12)]]),
    ];

    $this->load = AcademicLoad::factory()->for($this->term)->state(['modality_id' => modalityOf('PRES')->id])
        ->for(Department::factory()->create(['code' => 'CB', 'name' => 'Ciencias Básicas']))->inStatus(LoadStatus::Published)->create();
    LoadSnapshot::factory()->for($this->load)->publishedAt(CarbonImmutable::parse('2026-12-12 10:30'))->withGroups(array_values($this->v1))->create();

    $this->v2 = [
        'schedule' => [...$this->v1['schedule'], 'sessions' => [$session(2, $this->b12)]],
        'room' => [...$this->v1['room'], 'sessions' => [$session(2, $this->b14, '10:00', '12:00')]],
        'teacher' => [...$this->v1['teacher'], 'teacher' => $this->laura],
        'quota' => [...$this->v1['quota'], 'capacity' => 35],
        'added' => $group('g-added', 'ACF-0904', 'Cálculo Vectorial', 'E', ['sessions' => [$session(5, $this->b14)]]),
    ];
    $this->republish = fn () => publishedChangesRepublish($this->load, array_values($this->v2), '2027-01-20 13:05');

    $this->semesterUrl = fn (array $query = []): string => route('published.semester', ['periodo' => '20271', 'modalidad' => 'pres', 'carrera' => 'ISC', 'reticula' => 'ISIC-2010-224', 'semestre' => 3, ...$query]);
});

describe('la consulta pública', function () {
    it('dice «Actualizado el» con la fecha y la hora local de la versión vigente', function () {
        ($this->republish)();

        $this->get(($this->semesterUrl)())->assertOk()->assertSee('Actualizado el 20 de enero de 2027 a las 13:05');
        $this->get(route('published.index', ['periodo' => '20271']))->assertOk()->assertSee('Actualizado el 20 de enero de 2027 a las 13:05');
        $this->get(route('published.teacher', ['periodo' => '20271', 'modalidad' => 'pres', 'docente' => $this->laura['id']]))->assertOk()->assertSee('Actualizado el 20 de enero de 2027 a las 13:05');
    });

    it('marca cada grupo con lo que le cambió contra la versión anterior, con su detalle', function () {
        ($this->republish)();
        $html = $this->get(($this->semesterUrl)())->assertOk()->getContent();

        expect(publishedChangesCard($html, 'ACF-0904 · B'))->toContain('Cambió: horario')->toContain('Horario de lunes 7:00 a 9:00 a martes 7:00 a 9:00.')
            ->and(publishedChangesCard($html, 'AED-1026 · D'))->toContain('Cambió: salón')->toContain('Salón del martes 10:00 a 12:00 de B-12 a B-14.')
            ->and(publishedChangesCard($html, 'ACF-0905 · A'))->toContain('Cambió: docente')->toContain('Docente de Ana Ruiz a Laura Ríos Medina.')
            ->and(publishedChangesCard($html, 'ACF-0904 · E'))->toContain('Grupo nuevo')->not->toContain('Cambió')
            ->and(publishedChangesCard($html, 'AEF-1031 · A'))->not->toContain('Cambió')->not->toContain('Grupo nuevo');
    });

    it('muestra el grupo cancelado tachado y con «Cancelado», sin contarlo entre los grupos', function () {
        ($this->republish)();
        $html = $this->get(($this->semesterUrl)())->assertOk()->getContent();
        $card = publishedChangesCard($html, 'ACF-0904 · C');

        expect($card)->toContain('Cancelado')->toContain('line-through')->toContain('B-12')
            ->and($html)->toContain('4 materias · 5 grupos');
    });

    it('en la primera versión no hay marcas', function () {
        $html = $this->get(($this->semesterUrl)())->assertOk()->getContent();

        expect($html)->toContain('Actualizado el 12 de diciembre de 2026 a las 10:30')
            ->not->toContain('Cambió')->not->toContain('Grupo nuevo')->not->toContain('Cancelado');
    });

    it('una versión publicada antes de la tarea 8.4, sin diff guardado, también lleva sus marcas', function () {
        LoadSnapshot::factory()->for($this->load)->publishedAt(CarbonImmutable::parse('2027-01-20 13:05'))->withGroups(array_values($this->v2))->create(['version' => 2]);

        $html = $this->get(($this->semesterUrl)())->assertOk()->getContent();

        expect(publishedChangesCard($html, 'ACF-0904 · B'))->toContain('Cambió: horario')
            ->and(publishedChangesCard($html, 'ACF-0904 · C'))->toContain('Cancelado');
    });

    it('marca los grupos del docente y del salón que cambiaron', function () {
        ($this->republish)();

        $teacher = $this->get(route('published.teacher', ['periodo' => '20271', 'modalidad' => 'pres', 'docente' => $this->laura['id']]))->assertOk()->getContent();
        $room = $this->get(route('published.room', ['periodo' => '20271', 'modalidad' => 'pres', 'salon' => $this->b14['id']]))->assertOk()->getContent();

        expect($teacher)->toContain('Cambió: horario')->toContain('Cambió: salón')->toContain('Cambió: docente')
            ->and($room)->toContain('Cambió: salón')->toContain('Grupo nuevo');
    });

    it('no enseña el número de versión ni cupos en ninguna página pública', function () {
        ($this->republish)();
        $urls = [
            route('published.index', ['periodo' => '20271']),
            ($this->semesterUrl)(),
            route('published.teacher', ['periodo' => '20271', 'modalidad' => 'pres', 'docente' => $this->laura['id']]),
            route('published.room', ['periodo' => '20271', 'modalidad' => 'pres', 'salon' => $this->b14['id']]),
        ];

        foreach ($urls as $url) {
            $html = $this->get($url)->assertOk()->getContent();

            foreach (['versión', 'Versión', 'Versiones', 'cupo', 'Cupo', 'lugares', 'Capacidad', '>v2<', 'RIML800101AB1', route('academic-load.versions')] as $secret) {
                expect($html)->not->toContain($secret);
            }
        }
    });

    it('?version= no tiene efecto: siempre se ve la versión vigente', function () {
        ($this->republish)();

        $html = $this->get(($this->semesterUrl)(['version' => 1]))->assertOk()->getContent();

        expect(publishedChangesCard($html, 'ACF-0904 · B'))->toContain('Cambió: horario')->toContain('Martes')
            ->and($html)->toContain('Actualizado el 20 de enero de 2027 a las 13:05');
        $this->get(route('published.teacher', ['periodo' => '20271', 'modalidad' => 'pres', 'docente' => $this->ana['id'], 'version' => 1]))->assertNotFound();
    });

    it('con sesión la página pública se ve igual: las mismas marcas, sin versiones', function () {
        ($this->republish)();

        $html = $this->actingAs(User::factory()->admin()->create())->get(($this->semesterUrl)())->assertOk()->getContent();

        expect(publishedChangesCard($html, 'ACF-0904 · B'))->toContain('Cambió: horario')
            ->and($html)->not->toContain('Versión')->not->toContain('versión');
    });

    it('no guarda en caché el RFC ni la justificación de un grupo cancelado', function () {
        config(['cache.stores.array.serialize' => true]);
        Cache::purge('array');
        ($this->republish)();
        $query = app(PublishedScheduleQuery::class);
        $query->schedule($this->term, modalityOf('PRES'));

        $cached = json_encode(Cache::get('published-schedule:v3:'.$this->term->id.':'.modalityOf('PRES')->id.':'.$query->version($this->term, modalityOf('PRES'))), JSON_UNESCAPED_UNICODE);

        expect($cached)->toContain('g-cancelled')->toContain('Ana Ruiz')->not->toContain('RUAA800101AB1')->not->toContain('Docente en contratación');
    });

    it('arma las marcas con un número fijo de consultas, sin importar cuántas cargas se republicaron', function () {
        ($this->republish)();
        $count = function (): int {
            Cache::flush();
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get(($this->semesterUrl)())->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };
        $one = $count();

        foreach (['EE', 'II'] as $code) {
            $load = AcademicLoad::factory()->for($this->term)->state(['modality_id' => modalityOf('PRES')->id])->for(Department::factory()->create(['code' => $code]))->inStatus(LoadStatus::Published)->create();
            $groups = [publishedChangesGroup((string) Str::uuid7(), "{$code}-0001", 'Otra materia', 'A', ['placements' => [LoadSnapshotFactory::placement(['career' => $this->career, 'study_plan' => $this->plan, 'semester' => 3])]])];
            LoadSnapshot::factory()->for($load)->publishedAt(CarbonImmutable::parse('2026-12-12 10:30'))->withGroups([...$groups, publishedChangesGroup((string) Str::uuid7(), "{$code}-0002", 'Se cancela', 'B')])->create();
            publishedChangesRepublish($load, $groups, '2027-01-20 13:05');
        }

        expect($count())->toBe($one);
    });
});

describe('los PDF y el Excel', function () {
    it('el PDF del semestre dice la fecha en el encabezado y marca los grupos que cambiaron y el cancelado', function () {
        ($this->republish)();
        $query = app(PublishedScheduleQuery::class);
        $html = publishedChangesPdfHtml(SemesterPdf::find($query, $this->term, modalityOf('PRES'), null, 'ISC', 'ISIC-2010-224', 3), $this->term);

        expect(Str::between($html, '<header>', '</header>'))->toContain('Actualizado el 20 de enero de 2027 a las 13:05')
            ->and($html)->toContain('Cambió: horario')->toContain('Cambió: salón')->toContain('Cambió: docente')->toContain('Grupo nuevo')->toContain('Cancelado')
            ->not->toContain('versión')->not->toContain('cupo')->not->toContain('Cap.');
    });

    it('el PDF de la semana de un docente marca sus grupos que cambiaron', function () {
        ($this->republish)();
        $html = publishedChangesPdfHtml(TeacherPdf::find(app(PublishedScheduleQuery::class), $this->term, modalityOf('PRES'), null, $this->laura['id']), $this->term);

        expect(Str::between($html, '<header>', '</header>'))->toContain('Actualizado el 20 de enero de 2027 a las 13:05')
            ->and($html)->toContain('Cambió: horario')->toContain('Cambió: salón')->toContain('Cambió: docente');
    });

    it('el PDF del departamento también marca los cambios y el cancelado y lleva la fecha arriba', function () {
        ($this->republish)();
        $export = DepartmentPdf::fromRequest(app(PublishedScheduleQuery::class), $this->term, modalityOf('PRES'), Request::create('/', 'GET', ['departamento' => $this->load->department_id]));
        $html = publishedChangesPdfHtml($export, $this->term);

        expect(Str::between($html, '<header>', '</header>'))->toContain('Actualizado el 20 de enero de 2027 a las 13:05')
            ->and($html)->toContain('Cambió: horario')->toContain('Cancelado');
    });

    it('el Excel dice la fecha arriba y lleva la columna «Cambió» con el texto, sin versión', function () {
        ($this->republish)();

        $rows = publishedChangesRows(CampusWorkbook::fromRequest(app(PublishedScheduleQuery::class), $this->term, modalityOf('PRES'), Request::create('/'))->build());
        $header = array_search(CampusWorkbook::HEADER, $rows, true);
        $changed = collect(array_slice($rows, $header + 1))->mapWithKeys(fn (array $row): array => [$row[2].' · '.$row[4] => $row[count(CampusWorkbook::HEADER) - 1] ?? '']);

        expect($rows[0][0])->toBe('Actualizado el 20 de enero de 2027 a las 13:05')
            ->and($header)->toBe(2)
            ->and(array_last(CampusWorkbook::HEADER))->toBe('Cambió')
            ->and($changed['ACF-0904 · B'])->toBe('ACF-0904 Cálculo Vectorial, grupo B (C2): horario de lunes 7:00 a 9:00 a martes 7:00 a 9:00.')
            ->and($changed['ACF-0904 · E'])->toStartWith('Grupo nuevo: ACF-0904 Cálculo Vectorial, grupo E (C2)')
            ->and($changed['AEF-1031 · A'])->toBe('')
            ->and($changed->keys())->not->toContain('ACF-0904 · C')
            ->and(json_encode($rows, JSON_UNESCAPED_UNICODE))->not->toContain('versión')->not->toContain('Versión');
    });
});
