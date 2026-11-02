<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Enums\ProposalStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\AcademicLoad\Models\ProposalItem;
use App\Modules\AcademicLoad\Models\ProposalReview;
use App\Modules\AcademicLoad\Queries\DepartmentInbox;
use App\Modules\AcademicLoad\Queries\DepartmentInboxQuery;
use App\Modules\AcademicLoad\Rules\ProposalAnomalies;
use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Enums\SettingScope;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\CampusProfile;
use App\Modules\Core\Models\Setting;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Core\Queries\ScopedContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/*
| DepartmentInboxQuery da a la bandeja del departamento lo que muestra (reglas-de-negocio.md, "Propuesta de
| carrera"): el estado de su carga y sus catálogos sin validar (HC-14, que bloquea aceptar); las carreras del
| campus con el estado de lo que le piden y sus totales, pendientes primero; las filas de cada carrera con el total
| de la materia en todas, y por materia la sugerencia de paralelos (confirmado el 2 de noviembre de 2026). Sin
| consultas por fila.
*/

beforeEach(function () {
    $this->term = Term::factory()->create();
    $this->campus = Campus::factory()->create(['settings' => [...CampusProfile::DEFAULTS, 'uses_rooms' => false]]);
    $this->cb = Department::factory()->create(['code' => 'CB']);
    $this->sc = Department::factory()->create(['code' => 'SC']);
    $this->calculo = Subject::factory()->create(['code' => 'ACF-0901', 'name' => 'Cálculo Diferencial', 'department_id' => $this->cb->id, 'needs_lab' => false]);
    $this->fisica = Subject::factory()->create(['code' => 'ACF-0902', 'name' => 'Física', 'department_id' => $this->cb->id, 'needs_lab' => true]);
});

function inboxOf(?Department $department = null): DepartmentInbox
{
    return app(DepartmentInboxQuery::class)->inbox(test()->term, test()->campus, $department ?? test()->cb);
}

/**
 * Una carrera ofrecida en el campus de la prueba.
 */
function offeredCareer(string $code, bool $active = true): Career
{
    $career = Career::factory()->create(['code' => $code]);
    CareerCampus::factory()->create(['career_id' => $career->id, 'campus_id' => test()->campus->id, 'is_active' => $active]);

    return $career;
}

/**
 * La propuesta de la carrera en el periodo y campus de la prueba, en el estado dado.
 *
 * @param  array<string, mixed>  $attributes
 */
function inboxProposal(Career $career, ProposalStatus $status = ProposalStatus::Submitted, array $attributes = []): CareerProposal
{
    return CareerProposal::factory()->submitted()->create([
        'term_id' => test()->term->id,
        'campus_id' => test()->campus->id,
        'career_id' => $career->id,
        'status' => $status,
        ...$attributes,
    ]);
}

/**
 * La parte de un departamento en una propuesta: pendiente, aceptada o regresada con comentario.
 */
function inboxPart(CareerProposal $proposal, Department $department, string $state = 'pending', string $comment = 'Revisa cuántos grupos pides.'): ProposalReview
{
    $factory = match ($state) {
        'pending' => ProposalReview::factory(),
        'accepted' => ProposalReview::factory()->accepted(),
        'returned' => ProposalReview::factory()->returned($comment),
    };

    return $factory->create(['career_proposal_id' => $proposal->id, 'department_id' => $department->id]);
}

/**
 * Una fila de la propuesta con una materia.
 *
 * @param  array<string, mixed>  $attributes
 */
function inboxRow(CareerProposal $proposal, Subject $subject, int $expected, int $groups, int $semester = 1, array $attributes = []): ProposalItem
{
    return ProposalItem::factory()->create([
        'career_proposal_id' => $proposal->id,
        'subject_id' => $subject->id,
        'semester' => $semester,
        'eligible_students' => $expected + 5,
        'expected_students' => $expected,
        'requested_groups' => $groups,
        ...$attributes,
    ]);
}

/**
 * Las carreras de la bandeja: [clave, estado, se decide, materias, grupos, esperados, comentario].
 *
 * @return list<array{0: string, 1: string, 2: bool, 3: int, 4: int, 5: int, 6: ?string}>
 */
function inboxCareersOf(DepartmentInbox $inbox): array
{
    return array_map(fn (array $row): array => [
        $row['career']->code,
        $row['status']->value,
        $row['decidable'],
        $row['subjects'],
        $row['groups'],
        $row['expected'],
        $row['review']?->comment,
    ], $inbox->careers);
}

describe('con lo que piden varias carreras', function () {
    beforeEach(function () {
        $this->isc = offeredCareer('ISC');
        $this->iind = offeredCareer('IIND');
        $this->ige = offeredCareer('IGE');
        $this->arq = offeredCareer('ARQ');
        $this->iem = offeredCareer('IEM');
        offeredCareer('LAD', active: false);

        $this->iscProposal = inboxProposal($this->isc);
        inboxPart($this->iscProposal, $this->cb);
        inboxPart($this->iscProposal, $this->sc);
        inboxRow($this->iscProposal, $this->fisica, 30, 1, semester: 2, attributes: ['notes' => 'Con laboratorio']);
        inboxRow($this->iscProposal, $this->calculo, 60, 2);
        inboxRow($this->iscProposal, Subject::factory()->create(['code' => 'ACF-0903', 'department_id' => $this->cb->id]), 10, 0);
        inboxRow($this->iscProposal, Subject::factory()->create(['code' => 'SCD-1015', 'department_id' => $this->sc->id]), 30, 1);

        $iind = inboxProposal($this->iind, ProposalStatus::Accepted);
        inboxPart($iind, $this->cb, 'accepted');
        inboxRow($iind, $this->calculo, 40, 2);

        $ige = inboxProposal($this->ige, ProposalStatus::Returned);
        inboxPart($ige, $this->cb, 'returned', 'Pide un grupo de Cálculo, no dos.');
        inboxRow($ige, $this->calculo, 20, 2);

        $arq = inboxProposal($this->arq, ProposalStatus::Draft, ['submitted_by' => null, 'submitted_at' => null]);
        inboxRow($arq, $this->calculo, 50, 2);

        $iem = inboxProposal($this->iem);
        inboxPart($iem, $this->sc);
        inboxRow($iem, Subject::factory()->create(['department_id' => $this->sc->id]), 30, 1);
    });

    it('da las carreras ofrecidas con el estado de lo que piden y sus totales, pendientes primero', function () {
        expect(inboxCareersOf(inboxOf()))->toBe([
            ['ISC', 'pending', true, 2, 3, 90, null],
            ['IGE', 'returned', false, 1, 2, 20, 'Pide un grupo de Cálculo, no dos.'],
            ['IIND', 'accepted', false, 1, 2, 40, null],
            ['ARQ', 'not_submitted', false, 0, 0, 0, null],
            ['IEM', 'not_submitted', false, 0, 0, 0, null],
        ]);
    });

    it('da la propuesta y la parte de cada carrera, para decidirla, con la propuesta ya cargada en la parte', function () {
        $isc = inboxOf()->career($this->isc->id);

        expect($isc['proposal']->id)->toBe($this->iscProposal->id)
            ->and($isc['review']->department_id)->toBe($this->cb->id)
            ->and($isc['review']->relationLoaded('proposal'))->toBeTrue()
            ->and($isc['review']->proposal)->toBe($isc['proposal'])
            ->and(inboxOf()->career($this->arq->id)['review'])->toBeNull()
            ->and(inboxOf()->career('no-es-uuid'))->toBeNull();
    });

    it('da las filas de la carrera elegida por semestre y clave, con el total de la materia en las carreras que cuentan', function () {
        $rows = inboxOf()->rowsFor($this->isc->id);

        expect(array_map(fn (array $row): array => [
            $row['item']->subject->code,
            $row['item']->subject->name,
            $row['item']->subject->needs_lab,
            $row['item']->semester,
            $row['item']->eligible_students,
            $row['item']->expected_students,
            $row['item']->requested_groups,
            $row['item']->notes,
            $row['total'],
        ], $rows))->toBe([
            ['ACF-0901', 'Cálculo Diferencial', false, 1, 65, 60, 2, null, ['careers' => 2, 'groups' => 4, 'expected' => 100]],
            ['ACF-0902', 'Física', true, 2, 35, 30, 1, 'Con laboratorio', ['careers' => 1, 'groups' => 1, 'expected' => 30]],
        ]);
    });

    it('marca lo raro de cada fila sin bloquear (anomalías), con el mínimo por grupo del parámetro global', function () {
        expect(array_column(inboxOf()->rowsFor($this->isc->id), 'anomalies'))->toBe([[], []]);

        Setting::query()->create(['scope' => SettingScope::Global, 'scope_id' => null, 'key' => ProposalAnomalies::SETTING, 'value' => 40]);

        expect(array_column(inboxOf()->rowsFor($this->isc->id), 'anomalies'))->toBe([['2 grupos para 60 alumnos'], ['1 grupo para 30 alumnos']]);
    });

    it('las filas de una parte regresada se ven, y su total no la cuenta; sin parte no hay filas', function () {
        $inbox = inboxOf();

        expect(array_map(fn (array $row): array => [$row['item']->requested_groups, $row['total']['groups']], $inbox->rowsFor($this->ige->id)))->toBe([[2, 4]])
            ->and($inbox->rowsFor($this->arq->id))->toBe([])
            ->and($inbox->rowsFor($this->iem->id))->toBe([])
            ->and($inbox->rowsFor('no-es-uuid'))->toBe([]);
    });

    it('sugiere los paralelos de cada materia con las partes pendientes de propuestas enviadas y las aceptadas', function () {
        $subjects = inboxOf()->subjects;

        expect(array_map(fn (array $row): string => $row['subject']->code, $subjects))->toBe(['ACF-0901', 'ACF-0902']);

        $calculo = $subjects[0]['parallels'];
        expect($subjects[0]['expected'])->toBe(100)
            ->and($calculo->size)->toBe(35)
            ->and($calculo->careers)->toBe([
                'IIND' => ['expected' => 40, 'requested' => 2, 'full' => 1, 'remainder' => 5],
                'ISC' => ['expected' => 60, 'requested' => 2, 'full' => 1, 'remainder' => 25],
            ])
            ->and($calculo->mixedShares)->toBe(['IIND' => 5, 'ISC' => 25])
            ->and($calculo->requested)->toBe(4)
            ->and($calculo->suggested)->toBe(3)
            ->and($calculo->saved())->toBe(1);

        $fisica = $subjects[1]['parallels'];
        expect($fisica->size)->toBe(25)
            ->and($fisica->careers)->toBe(['ISC' => ['expected' => 30, 'requested' => 1, 'full' => 1, 'remainder' => 5]])
            ->and($fisica->suggested)->toBe(2);
    });

    it('usa los tamaños del parámetro demand.target_group_size', function () {
        DB::table('settings')->insert(['id' => fakeKey(900), 'scope' => 'global', 'scope_id' => null, 'key' => 'demand.target_group_size', 'value' => json_encode(['default' => 50, 'lab' => 30]), 'created_at' => now(), 'updated_at' => now()]);

        $subjects = inboxOf()->subjects;

        expect($subjects[0]['parallels']->size)->toBe(50)
            ->and($subjects[0]['parallels']->suggested)->toBe(2)
            ->and($subjects[1]['parallels']->size)->toBe(30);
    });

    it('no ve lo que las carreras piden a otro departamento', function () {
        $inbox = inboxOf($this->sc);

        expect(inboxCareersOf($inbox))->toBe([
            ['IEM', 'pending', true, 1, 1, 30, null],
            ['ISC', 'pending', true, 1, 1, 30, null],
            ['ARQ', 'not_submitted', false, 0, 0, 0, null],
            ['IGE', 'not_submitted', false, 0, 0, 0, null],
            ['IIND', 'not_submitted', false, 0, 0, 0, null],
        ])->and(array_map(fn (array $row): string => $row['subject']->code, $inbox->subjects))->toHaveCount(2);
    });

    it('no consulta por fila: las mismas consultas con más carreras, partes y materias', function () {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            inboxOf()->rowsFor($this->isc->id);
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };
        $few = $count();

        foreach (range(1, 6) as $n) {
            $proposal = inboxProposal(offeredCareer(sprintf('CAR%02d', $n)));
            inboxPart($proposal, $this->cb, $n % 2 === 0 ? 'accepted' : 'pending');
            inboxRow($proposal, Subject::factory()->create(['department_id' => $this->cb->id]), 30 + $n, 1);
            inboxRow($proposal, $this->calculo, 10 * $n, 1);
        }

        expect($count())->toBe($few);
    });
});

it('una parte pendiente de una propuesta que otro departamento regresó se ve pendiente, se decide y cuenta para los paralelos y el total de la materia', function () {
    $isc = offeredCareer('ISC');
    $proposal = inboxProposal($isc, ProposalStatus::Returned);
    inboxPart($proposal, $this->cb);
    inboxPart($proposal, $this->sc, 'returned');
    inboxRow($proposal, $this->calculo, 60, 2);
    $iind = inboxProposal(offeredCareer('IIND'), ProposalStatus::Accepted);
    inboxPart($iind, $this->cb, 'accepted');
    inboxRow($iind, $this->calculo, 40, 2);

    $inbox = inboxOf();

    expect(inboxCareersOf($inbox))->toBe([
        ['ISC', 'pending', true, 1, 2, 60, null],
        ['IIND', 'accepted', false, 1, 2, 40, null],
    ])
        ->and($inbox->subjects[0]['parallels']->careers)->toBe([
            'IIND' => ['expected' => 40, 'requested' => 2, 'full' => 1, 'remainder' => 5],
            'ISC' => ['expected' => 60, 'requested' => 2, 'full' => 1, 'remainder' => 25],
        ])
        ->and($inbox->subjects[0]['parallels']->suggested)->toBe(3)
        ->and($inbox->rowsFor($isc->id)[0]['total'])->toBe(['careers' => 2, 'groups' => 4, 'expected' => 100]);
});

it('una parte aceptada de una propuesta regresada sigue contando para los paralelos', function () {
    $isc = offeredCareer('ISC');
    $proposal = inboxProposal($isc, ProposalStatus::Returned);
    inboxPart($proposal, $this->cb, 'accepted');
    inboxPart($proposal, $this->sc, 'returned');
    inboxRow($proposal, $this->calculo, 60, 2);

    expect(inboxOf()->subjects[0]['parallels']->requested)->toBe(2);
});

it('muestra la carrera con una parte del departamento aunque ya no se ofrezca en el campus', function () {
    $career = offeredCareer('ISC', active: false);
    inboxPart(inboxProposal($career), $this->cb);

    expect(array_column(inboxCareersOf(inboxOf()), 0))->toBe(['ISC']);
});

it('no cuenta las propuestas de otro periodo ni de otro campus', function () {
    $isc = offeredCareer('ISC');
    inboxPart(inboxProposal($isc, attributes: ['term_id' => Term::factory()->create()->id]), $this->cb);
    inboxPart(inboxProposal($isc, attributes: ['campus_id' => Campus::factory()->create()->id]), $this->cb);

    $inbox = inboxOf();

    expect(inboxCareersOf($inbox))->toBe([['ISC', 'not_submitted', false, 0, 0, 0, null]])
        ->and($inbox->subjects)->toBe([]);
});

it('da la carga del departamento en el campus y bloquea aceptar sin ella', function () {
    expect(inboxOf()->load)->toBeNull()
        ->and(inboxOf()->canAccept())->toBeFalse();

    $load = AcademicLoad::factory()->inStatus(LoadStatus::Proposed)->create(['term_id' => $this->term->id, 'campus_id' => $this->campus->id, 'department_id' => $this->cb->id]);
    AcademicLoad::factory()->create(['term_id' => $this->term->id, 'department_id' => $this->cb->id]);
    foreach (Catalog::general() as $catalog) {
        CatalogReview::factory()->general($catalog)->validated()->create(['term_id' => $this->term->id, 'department_id' => $this->cb->id]);
    }

    $inbox = inboxOf();

    expect($inbox->load->id)->toBe($load->id)
        ->and($inbox->load->status)->toBe(LoadStatus::Proposed)
        ->and($inbox->pendingCatalogs)->toBe([])
        ->and($inbox->canAccept())->toBeTrue();
});

it('HC-14: avisa de los catálogos del departamento sin validar y bloquea aceptar', function () {
    AcademicLoad::factory()->create(['term_id' => $this->term->id, 'campus_id' => $this->campus->id, 'department_id' => $this->cb->id]);
    CatalogReview::factory()->general(Catalog::Teachers)->validated()->create(['term_id' => $this->term->id, 'department_id' => $this->cb->id]);

    $inbox = inboxOf();

    expect($inbox->pendingCatalogs)->toBe([Catalog::Subjects, Catalog::StudyPlans, Catalog::Careers])
        ->and($inbox->canAccept())->toBeFalse();
});

it('HC-14: en un campus que usa salones también avisa de los salones', function () {
    $this->campus->update(['settings' => CampusProfile::DEFAULTS]);

    expect(inboxOf()->pendingCatalogs)->toContain(Catalog::Rooms);
});

it('busca la parte del departamento por id del cliente, sin fallar con un id que no es uuid', function () {
    $proposal = inboxProposal(offeredCareer('ISC'));
    $part = inboxPart($proposal, $this->cb);
    $other = inboxPart($proposal, $this->sc);
    $query = app(DepartmentInboxQuery::class);

    expect($query->reviewOrFail($this->cb, $part->id)->id)->toBe($part->id)
        ->and(fn () => $query->reviewOrFail($this->cb, $other->id))->toThrow(ModelNotFoundException::class)
        ->and(fn () => $query->reviewOrFail($this->cb, 'no-es-uuid'))->toThrow(ModelNotFoundException::class);
});

describe('departamentos de la bandeja de un usuario', function () {
    it('da al jefe solo su departamento, y a subdirección y al admin todos', function () {
        $jefe = User::factory()->withRole(Role::JefeDepartamento, ['campus_id' => $this->campus->id, 'department_id' => $this->cb->id])->create();
        $subdireccion = User::factory()->withRole(Role::Subdireccion)->create();
        $coordinador = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->campus->id, 'career_id' => offeredCareer('ISC')->id])->create();
        $codes = fn (User $user): array => app(DepartmentInboxQuery::class)
            ->departmentsFor(app(ScopedContext::class)->forUser($user), $this->campus)->pluck('code')->all();

        expect($codes($jefe))->toBe(['CB'])
            ->and($codes($subdireccion))->toContain('CB', 'SC')
            ->and($codes(User::factory()->admin()->create()))->toContain('CB', 'SC')
            ->and($codes($coordinador))->toBe([]);
    });

    it('no da el departamento a un jefe de otro campus', function () {
        $otro = Campus::factory()->create();
        $jefe = User::factory()->withRole(Role::JefeDepartamento, ['campus_id' => $otro->id, 'department_id' => $this->cb->id])->create();

        expect(app(DepartmentInboxQuery::class)->departmentsFor(app(ScopedContext::class)->forUser($jefe), $this->campus))->toBeEmpty();
    });
});

describe('departamentos desactivados y partes de otro periodo o campus', function () {
    it('incluye al departamento desactivado solo si tiene partes en el periodo y campus', function () {
        $this->cb->update(['is_active' => false]);
        $this->sc->update(['is_active' => false]);
        $isc = offeredCareer('ISC');
        inboxPart(inboxProposal($isc), $this->cb);
        $subdireccion = app(ScopedContext::class)->forUser(User::factory()->withRole(Role::Subdireccion)->create());
        $codes = fn (?Term $term) => app(DepartmentInboxQuery::class)->departmentsFor($subdireccion, $this->campus, $term)->pluck('code')->all();

        expect($codes($this->term))->toContain('CB')->not->toContain('SC')
            ->and($codes(null))->not->toContain('CB');
    });

    it('la parte se busca solo en el periodo y campus dados', function () {
        $part = inboxPart(inboxProposal(offeredCareer('ISC')), $this->cb);
        $query = app(DepartmentInboxQuery::class);

        expect($query->reviewOrFail($this->cb, $part->id, $this->term->id, $this->campus->id)->id)->toBe($part->id)
            ->and(fn () => $query->reviewOrFail($this->cb, $part->id, Term::factory()->create()->id, $this->campus->id))->toThrow(ModelNotFoundException::class)
            ->and(fn () => $query->reviewOrFail($this->cb, $part->id, $this->term->id, Campus::factory()->create()->id))->toThrow(ModelNotFoundException::class);
    });
});
