<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Enums\ProposalStatus;
use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\AcademicLoad\Models\ProposalItem;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermOpening;
use App\Modules\Core\Models\User;
use App\Modules\Demand\Enums\ForecastMethod;
use App\Modules\Demand\Models\DemandForecast;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/*
| La propuesta de carrera con la proyección de Nexus (tarea 5.6, opción C «Revisión de la proyección», elegida por el usuario
| el 17 de noviembre de 2026): la línea de la proyección en el encabezado, el aviso de recálculo, la marca de cada fila
| (Sugerido, Corregido, Cambió y la diferencia grande, con icono y texto) y la pestaña «Revisar proyección (N)» con una
| tarjeta por fila pendiente de las columnas del coordinador (M7), sus acciones y la evidencia.
*/

beforeEach(function () {
    $this->c1 = Campus::factory()->create(['code' => 'C1', 'name' => 'Campus I']);
    $this->c2 = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    $this->term = Term::factory()->current()->create(['code' => '20271', 'name' => 'Ene-Jun 2027']);
    TermOpening::factory()->create(['term_id' => $this->term->id, 'modality_id' => modalityOf('PRES')->id]);
    $this->career = Career::factory()->create(['code' => 'ISC', 'name' => 'Sistemas']);
    CareerCampus::factory()->create(['career_id' => $this->career->id, 'campus_id' => $this->c1->id]);
    CareerCampus::factory()->create(['career_id' => $this->career->id, 'campus_id' => $this->c2->id]);
    $this->cb = Department::factory()->create(['code' => 'CB', 'name' => 'Ciencias Básicas']);
    $this->proposal = CareerProposal::factory()->create(['term_id' => $this->term->id, 'career_id' => $this->career->id]);

    $this->ofC1 = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->c1->id, 'career_id' => $this->career->id])->create(['name' => 'Héctor Villalobos']);
    $this->ofC2 = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => $this->career->id])->create(['name' => 'Daniela Quintero']);
    $this->subdirection = User::factory()->withRole(Role::Subdireccion)->create();
});

/**
 * La fila de la materia `$code` en `$campus`, que nació con la proyección (50 esperados y 2 grupos), y su proyección vigente
 * (la misma, salvo `$forecast`).
 *
 * @param  array<string, mixed>  $item
 * @param  array<string, mixed>  $forecast
 */
function forecastPageRow(string $code, Campus $campus, array $item = [], array $forecast = [], int $semester = 1): ProposalItem
{
    $subject = Subject::query()->where('code', $code)->first()
        ?? Subject::factory()->create(['code' => $code, 'name' => "Materia {$code}", 'department_id' => test()->cb->id]);

    DemandForecast::factory()->create([
        'term_id' => test()->term->id,
        'modality_id' => test()->proposal->modality_id,
        'career_id' => test()->career->id,
        'subject_id' => $subject->id,
        'campus_id' => $campus->id,
        'eligible_students' => 48,
        'pending_prerequisite_students' => 6,
        'current_students' => 20,
        'non_enrollment_rate' => 0.125,
        'failure_rate' => 0.4,
        'history_terms_used' => 4,
        'expected_repeaters' => 8,
        'expected_students' => 50,
        'suggested_groups' => 2,
        'computed_at' => '2026-11-17 06:10:00',
        ...$forecast,
    ]);

    return ProposalItem::factory()->create([
        'career_proposal_id' => test()->proposal->id,
        'campus_id' => $campus->id,
        'subject_id' => $subject->id,
        'semester' => $semester,
        'eligible_students' => 60,
        'expected_students' => 50,
        'requested_groups' => 2,
        'suggested_expected_students' => 50,
        'suggested_groups' => 2,
        'forecast_method' => ForecastMethod::Formula,
        ...$item,
    ]);
}

/**
 * @param  array<string, string>  $query
 */
function forecastPageAs(User $user, array $query = []): Testable
{
    return Livewire::withQueryParams($query)->actingAs($user)->test('academic-load::pages.proposal');
}

/**
 * Una fila de C1 corregida cuya proyección cambió (de 50 · 2 a 90 · 3): «Usar 90 · 3» o «Mantener 40 · 2».
 */
function forecastPageChangedRow(string $code = 'ACF-0905', ?Campus $campus = null): ProposalItem
{
    return forecastPageRow($code, $campus ?? test()->c1, ['expected_students' => 40, 'captured_at' => now()], ['expected_students' => 90, 'suggested_groups' => 3]);
}

describe('encabezado y aviso', function () {
    it('dice de cuándo es la proyección, cuántos periodos mira y el tamaño de los grupos', function () {
        forecastPageRow('ACF-0901', $this->c1);

        forecastPageAs($this->ofC1)
            ->assertSeeHtml('data-testid="forecast-line"')
            ->assertSee('Proyección de Nexus del 17/11/2026 a las 6:10 · últimos 4 Ene-Jun · 35 por grupo, 25 con laboratorio')
            ->assertSeeHtml('data-testid="forecast-tabs"');
    });

    it('sin proyección lo dice y nada más cambia: ni pestañas ni marcas', function () {
        ProposalItem::factory()->create(['career_proposal_id' => $this->proposal->id, 'campus_id' => $this->c1->id, 'requested_groups' => 3, 'suggested_groups' => 1, 'suggested_expected_students' => 30, 'captured_at' => now()]);

        forecastPageAs($this->ofC1, ['vista' => 'tabla'])
            ->assertSee('Sin proyección de Nexus para esta propuesta.')
            ->assertDontSeeHtml('data-testid="forecast-tabs"')
            ->assertDontSeeHtml('data-forecast-mark')
            ->assertDontSeeHtml('data-testid="recalc-alert"');
    });

    it('avisa del recálculo solo si hay filas suyas cuya proyección cambió, con el botón «Revisar proyección»', function () {
        forecastPageRow('ACF-0901', $this->c1);
        forecastPageChangedRow('ACF-0902', $this->c2);

        forecastPageAs($this->ofC1)->assertDontSeeHtml('data-testid="recalc-alert"');

        forecastPageChangedRow();

        forecastPageAs($this->ofC1)
            ->assertSeeHtml('data-testid="recalc-alert"')
            ->assertSee('La proyección se recalculó el 17/11/2026 a las 6:10')
            ->assertSee('1 materia que corregiste')
            ->call('setView', 'revisar')
            ->assertSet('view', 'revisar');
    });
});

describe('marcas', function () {
    it('marca cada materia en la tabla con icono y texto: sugerido, corregido y por revisar', function () {
        forecastPageRow('ACF-0901', $this->c1);
        forecastPageRow('ACF-0902', $this->c1, ['expected_students' => 54, 'captured_at' => now()]);
        forecastPageChangedRow('ACF-0903');

        $html = forecastPageAs($this->ofC1, ['vista' => 'tabla'])->html();
        $dom = domOf($html);
        $mark = fn (string $code): string => textOf($dom->querySelector("[data-testid=row-{$code}] [data-forecast-mark]"));

        expect($mark('ACF-0901'))->toContain('Sugerido')
            ->and($mark('ACF-0902'))->toContain('Corregido')
            ->and($mark('ACF-0903'))->toContain('Revisar: la proyección cambió y 1 más');
    });

    it('resalta en la tabla la cifra corregida que se aparta del sugerido, sin depender del color', function () {
        $row = forecastPageRow('ACF-0901', $this->c1, ['requested_groups' => 3, 'captured_at' => now()]);

        $dom = domOf(forecastPageAs($this->ofC1, ['vista' => 'tabla'])->html());
        $groups = $dom->querySelector("[data-testid=row-ACF-0901] [data-cell=\"{$row->id}.requested_groups\"]");

        expect($groups->getAttribute('data-large'))->not->toBeNull()
            ->and(textOf($groups))->toContain('Se aparta del sugerido: Nexus sugiere 2');
    });

    it('marca el mosaico de la retícula: la proyección cambió, se aparta o corregido', function () {
        forecastPageRow('ACF-0901', $this->c1);
        forecastPageRow('ACF-0902', $this->c1, ['requested_groups' => 3, 'captured_at' => now()]);
        forecastPageChangedRow('ACF-0903');

        $dom = domOf(forecastPageAs($this->ofC1)->html());
        $mark = fn (string $code): ?string => $dom->querySelector("[data-testid=tile-{$code}] [data-forecast-mark]")?->getAttribute('data-forecast-mark');

        expect($mark('ACF-0901'))->toBeNull()
            ->and($mark('ACF-0902'))->toBe('large')
            ->and($mark('ACF-0903'))->toBe('changed');
    });
});

describe('pestaña Revisar proyección', function () {
    beforeEach(function () {
        $this->changed = forecastPageChangedRow();
        $this->large = forecastPageRow('ACF-0901', $this->c1, ['requested_groups' => 3, 'captured_at' => now()]);
        $this->fallback = forecastPageRow('ACF-0902', $this->c1, forecast: ['method' => ForecastMethod::DepartmentFallback]);
        forecastPageRow('ACF-0903', $this->c1);
        $this->otherCampus = forecastPageRow('ACF-0901', $this->c2, ['requested_groups' => 4, 'captured_at' => now()]);
    });

    it('cuenta las filas pendientes de su columna en la pestaña y por motivo en el filtro', function () {
        forecastPageAs($this->ofC1, ['vista' => 'revisar'])
            ->assertSet('view', 'revisar')
            ->assertSeeHtml('data-testid="review-count">3<')
            ->assertSeeHtml('data-testid="reason-count-todas">3<')
            ->assertSeeHtml('data-testid="reason-count-cambio">1<')
            ->assertSeeHtml('data-testid="reason-count-aparta">2<')
            ->assertSeeHtml('data-testid="reason-count-sin-historia">1<')
            ->assertSeeHtml('data-testid="review-card-'.$this->changed->id.'"')
            ->assertSeeHtml('data-testid="review-card-'.$this->large->id.'"')
            ->assertSeeHtml('data-testid="review-card-'.$this->fallback->id.'"')
            ->assertDontSeeHtml('data-testid="review-card-'.$this->otherCampus->id.'"')
            ->assertSee('En C2 hay 1 materia por revisar; las revisa Daniela Quintero.');
    });

    it('filtra por motivo y lo deja en la URL', function () {
        forecastPageAs($this->ofC1, ['vista' => 'revisar'])
            ->call('filterReason', 'cambio')
            ->assertSet('reason', 'cambio')
            ->assertSeeHtml('data-testid="review-card-'.$this->changed->id.'"')
            ->assertDontSeeHtml('data-testid="review-card-'.$this->fallback->id.'"')
            ->call('filterReason', 'otro')
            ->assertSet('reason', '');
    });

    it('la tarjeta dice lo capturado y lo sugerido, los elegibles, las tasas, los repetidores y la cuenta', function () {
        $card = textOf(domOf(forecastPageAs($this->ofC1, ['vista' => 'revisar', 'motivo' => 'aparta'])->html())->querySelector('[data-testid=review-card-'.$this->large->id.']'));

        expect($card)->toContain('Se aparta del sugerido · grupos +50 %')
            ->toContain('Capturaste 50 esperados y 3 grupos; Nexus sugiere 50 y 2.')
            ->toContain('60 posibles')
            ->toContain('48 en la proyección')
            ->toContain('6 dependen de aprobar algo que cursan hoy')
            ->toContain('no inscripción 13 %')
            ->toContain('reprobación 40 %')
            ->toContain('4 Ene-Jun')
            ->toContain('20 cursando hoy')
            ->toContain('= 8')
            ->toContain('48 × (1 − 0.125) + 8')
            ->toContain('= 50')
            ->toContain('50 ÷ 35');
    });

    it('«Usar N» toma la proyección nueva, deja bitácora y la marca revisada', function () {
        $page = forecastPageAs($this->ofC1, ['vista' => 'revisar'])->call('resolveChange', $this->changed->id, 'use');

        expect($this->changed->fresh()->only(['expected_students', 'requested_groups']))->toBe(['expected_students' => 90, 'requested_groups' => 3])
            ->and($this->changed->fresh()->reviewed_by)->toBe($this->ofC1->id)
            ->and(Activity::query()->where('subject_id', $this->changed->id)->sole()->causer_id)->toBe($this->ofC1->id)
            ->and(lastToastOf($page)['title'])->toBe('Usaste lo que sugiere Nexus');

        $page->assertDontSeeHtml('data-testid="review-card-'.$this->changed->id.'"')
            ->assertSeeHtml('data-testid="review-count">2<');
    });

    it('«Mantener» conserva lo capturado y la deja en «Revisadas»', function () {
        forecastPageAs($this->ofC1, ['vista' => 'revisar'])
            ->call('resolveChange', $this->changed->id, 'keep')
            ->assertSeeHtml('data-testid="reviewed-'.$this->changed->id.'"')
            ->assertSee('Revisadas (1)');

        expect($this->changed->fresh()->only(['expected_students', 'suggested_expected_students']))->toBe(['expected_students' => 40, 'suggested_expected_students' => 90]);
    });

    it('«Usar sugerido» pone lo sugerido y «Dejar» la marca revisada, cada una con su bitácora', function () {
        forecastPageAs($this->ofC1, ['vista' => 'revisar'])->call('applySuggestion', $this->large->id);

        expect($this->large->fresh()->requested_groups)->toBe(2)
            ->and($this->large->fresh()->reviewed_by)->toBe($this->ofC1->id);

        forecastPageAs($this->ofC1, ['vista' => 'revisar'])->call('markReviewed', $this->fallback->id)
            ->assertSeeHtml('data-testid="reviewed-'.$this->fallback->id.'"');

        expect($this->fallback->fresh()->reviewed_by)->toBe($this->ofC1->id)
            ->and(Activity::query()->where('subject_id', $this->fallback->id)->sole()->description)->toContain('Revisó la proyección de ACF-0902 de C1');
    });

    it('cada motivo lleva sus acciones', function () {
        $dom = domOf(forecastPageAs($this->ofC1, ['vista' => 'revisar'])->html());
        $actions = fn (ProposalItem $item): string => textOf($dom->querySelector("[data-testid=review-actions-{$item->id}]"));

        expect($actions($this->changed))->toContain('Usar 90 · 3')->toContain('Mantener 40 · 2')
            ->and($actions($this->large))->toContain('Usar sugerido 50 · 2')->toContain('Dejar 50 · 3')
            ->and($actions($this->fallback))->toContain('Está bien')->toContain('Abrir en el panel');
    });

    it('M7: el coordinador de C1 no revisa la fila de C2: no la ve como tarjeta y la Action se niega', function (string $method, array $arguments) {
        forecastPageAs($this->ofC1, ['vista' => 'revisar'])->call($method, $this->otherCampus->id, ...$arguments)->assertForbidden();

        expect($this->otherCampus->fresh()->only(['requested_groups', 'reviewed_at']))->toBe(['requested_groups' => 4, 'reviewed_at' => null]);
    })->with([
        'Dejar' => ['markReviewed', []],
        'Usar sugerido' => ['applySuggestion', []],
        'Mantener' => ['resolveChange', ['keep']],
    ]);

    it('subdirección ve las tarjetas de todas las columnas sin botones', function () {
        forecastPageAs($this->subdirection, ['vista' => 'revisar'])
            ->assertSeeHtml('data-testid="review-card-'.$this->otherCampus->id.'"')
            ->assertSeeHtml('data-testid="review-card-'.$this->changed->id.'"')
            ->assertDontSeeHtml('data-testid="review-actions-');
    });

    it('con la propuesta enviada se ve todo, sin acciones, y la Action se niega', function () {
        $this->proposal->forceFill(['status' => ProposalStatus::Submitted, 'submitted_at' => now(), 'submitted_by' => $this->ofC1->id])->save();

        $page = forecastPageAs($this->ofC1, ['vista' => 'revisar'])
            ->assertSeeHtml('data-testid="review-card-'.$this->changed->id.'"')
            ->assertDontSeeHtml('data-testid="review-actions-')
            ->assertDontSeeHtml('data-testid="recalc-alert"')
            ->call('markReviewed', $this->large->id);

        expect($this->large->fresh()->reviewed_at)->toBeNull()
            ->and(lastToastOf($page)['title'])->toBe('No se guardó');
    });

    it('la marca «Revisar» de la tabla lleva a su tarjeta', function () {
        forecastPageAs($this->ofC1, ['vista' => 'tabla'])
            ->call('reviewItem', $this->large->id)
            ->assertSet('view', 'revisar')
            ->assertSet('reason', '')
            ->assertDispatched('proposal-focus', id: 'review-'.$this->large->id);
    });

    it('«Propuesta» vuelve a la vista de antes', function () {
        forecastPageAs($this->ofC1, ['vista' => 'tabla'])
            ->call('setView', 'revisar')
            ->assertSet('view', 'revisar')
            ->call('showProposal')
            ->assertSet('view', 'tabla');
    });
});

it('con muchas pendientes muestra 20 tarjetas y «Mostrar más»', function () {
    foreach (range(1, 22) as $n) {
        forecastPageRow(sprintf('MAT-%04d', $n), $this->c1, ['requested_groups' => 5, 'captured_at' => now()]);
    }

    $page = forecastPageAs($this->ofC1, ['vista' => 'revisar']);

    expect(substr_count($page->html(), 'data-testid="review-card-'))->toBe(20);

    $page->assertSee('Mostrar 2 más')->call('showMoreReviews');

    expect(substr_count($page->html(), 'data-testid="review-card-'))->toBe(22);
});

it('el panel de la materia lleva, por campus, la proyección con su evidencia', function () {
    $row = forecastPageRow('ACF-0901', $this->c1, ['requested_groups' => 3, 'captured_at' => now()]);
    forecastPageRow('ACF-0901', $this->c2);

    $dom = domOf(forecastPageAs($this->ofC1)->call('edit', $row->subject_id)->html());

    expect(textOf($dom->querySelector('[data-testid=panel-forecast-C1]')))->toContain('Corregido')->toContain('48 × (1 − 0.125) + 8')
        ->and(textOf($dom->querySelector('[data-testid=panel-forecast-C2]')))->toContain('Sugerido');
});

it('la vista Propuesta no hace más consultas con más filas', function (string $view) {
    $count = function () use ($view): int {
        $page = forecastPageAs($this->ofC1, ['vista' => $view]);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $page->call('$refresh');
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };
    forecastPageRow('ACF-0901', $this->c1);
    forecastPageRow('ACF-0901', $this->c2);
    forecastPageChangedRow('ACF-0902');
    // La primera vez la petición lee la versión de las concesiones y arma su mapa (GrantTable, CF-01); se mide después.
    $count();
    $few = $count();

    foreach (range(3, 9) as $n) {
        forecastPageRow("ACF-090{$n}", $this->c1, ['requested_groups' => 4, 'captured_at' => now()], semester: $n);
        forecastPageRow("ACF-090{$n}", $this->c2);
    }

    expect($count())->toBe($few);
})->with(['reticula', 'tabla', 'revisar']);
