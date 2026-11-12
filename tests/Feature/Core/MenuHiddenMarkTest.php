<?php

use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Enums\NavigationArea;
use App\Modules\Core\Enums\NavigationGroup;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Core\Navigation\Navigation;
use App\Modules\Core\Navigation\NavigationItem;
use App\Modules\Core\Navigation\RecentPages;
use Dom\Element;
use Dom\HTMLDocument;
use Livewire\Livewire;

/*
| La marca de lo oculto para los demás (MN-02, opción B «Insignia "Oculta"», elegida el 12 de noviembre de 2026):
| el admin ve en el menú lo que el periodo activo no abre, con una insignia «Oculta» y el motivo para el lector de
| pantalla (aria-describedby) y para la vista (data-why, el motivo flotante); un grupo entero oculto por un solo
| motivo lo dice una vez en su encabezado; un área entera oculta lo dice junto a su nombre y en su icono. En la
| pantalla misma, un aviso arriba del contenido. Quien no es admin no ve nada de eso.
*/

beforeEach(function () {
    $this->campus = Campus::factory()->create(['code' => 'C2']);
});

/**
 * Un registro hecho a mano con motivos fijos:
 * - Académico lleva encabezados (cuatro grupos, nueve entradas). Validación está oculta entera por un motivo (A);
 *   Propuesta, entera por dos (B y C); Horarios está disponible, y en Asignación solo A1 está oculta (B).
 * - Servicios escolares, sin encabezados, oculta entera por dos motivos (S1 por A y S2 por B).
 * - Recursos humanos, sin encabezados, oculta entera por un solo motivo (A).
 * - Administración, disponible.
 */
function markedNavigation(): Navigation
{
    $because = fn (string $reason): Closure => static fn (Actor $context): ?string => $reason;
    $entry = fn (string $label, NavigationArea $area, ?NavigationGroup $group = null, ?string $reason = null): NavigationItem => new NavigationItem(
        $label, 'home', 'o-home', $area, group: $group, whyUnavailable: $reason === null ? null : $because($reason),
    );

    return new Navigation([
        $entry('V1', NavigationArea::Academico, NavigationGroup::Validacion, 'Motivo A'),
        $entry('V2', NavigationArea::Academico, NavigationGroup::Validacion, 'Motivo A'),
        $entry('P1', NavigationArea::Academico, NavigationGroup::Propuesta, 'Motivo B'),
        $entry('P2', NavigationArea::Academico, NavigationGroup::Propuesta, 'Motivo C'),
        $entry('H1', NavigationArea::Academico, NavigationGroup::Horarios),
        $entry('H2', NavigationArea::Academico, NavigationGroup::Horarios),
        $entry('H3', NavigationArea::Academico, NavigationGroup::Horarios),
        $entry('A1', NavigationArea::Academico, NavigationGroup::Asignacion, 'Motivo B'),
        $entry('A2', NavigationArea::Academico, NavigationGroup::Asignacion),
        $entry('S1', NavigationArea::ServiciosEscolares, reason: 'Motivo A'),
        $entry('S2', NavigationArea::ServiciosEscolares, reason: 'Motivo B'),
        $entry('R1', NavigationArea::RecursosHumanos, reason: 'Motivo A'),
        $entry('R2', NavigationArea::RecursosHumanos, reason: 'Motivo A'),
        $entry('C1', NavigationArea::Administracion, NavigationGroup::Institucion),
    ]);
}

function markedMenuFor(User $user): HTMLDocument
{
    app()->instance(Navigation::class, markedNavigation());
    test()->actingAs($user);

    return domOf(Livewire::test('core::components.navigation', ['route' => 'login']));
}

function menuEntryIn(HTMLDocument $page, string $label): ?Element
{
    return $page->querySelector('#nexus-menu a[data-label="'.$label.'"]');
}

/**
 * El texto con que el lector de pantalla describe una entrada (aria-describedby), o null si no lleva.
 */
function menuDescriptionOf(HTMLDocument $page, Element $entry): ?string
{
    $id = $entry->getAttribute('aria-describedby');

    return $id === null ? null : textOf($page->getElementById($id));
}

describe('MN-02: la marca del admin', function () {
    beforeEach(function () {
        $this->page = markedMenuFor(User::factory()->admin()->create());
    });

    it('una entrada oculta suelta es un enlace normal con la insignia «Oculta», su motivo descrito y su motivo flotante', function () {
        $entry = menuEntryIn($this->page, 'A1');
        $badge = $entry->querySelector('[data-menu-badge]');
        $description = $this->page->getElementById($entry->getAttribute('aria-describedby'));

        expect($entry->getAttribute('href'))->toBe(route('home'))
            ->and($badge->getAttribute('class'))->toContain('badge')->toContain('badge-neutral')->toContain('badge-soft')
            ->and(textOf($badge))->toBe('Oculta')
            ->and($badge->querySelector('svg'))->not->toBeNull()
            ->and($badge->getAttribute('aria-hidden'))->toBe('true')
            ->and($description->hasAttribute('hidden'))->toBeTrue()
            ->and(textOf($description))->toBe('Oculta para los demás: Motivo B')
            ->and($entry->getAttribute('data-why'))->toBe('Oculta para los demás: Motivo B');
    });

    it('lo disponible no lleva insignia, descripción ni motivo', function () {
        $entry = menuEntryIn($this->page, 'H1');

        expect($entry->querySelector('[data-menu-badge]'))->toBeNull()
            ->and($entry->hasAttribute('aria-describedby'))->toBeFalse()
            ->and($entry->hasAttribute('data-why'))->toBeFalse()
            ->and($this->page->querySelector('[data-menu-group="horarios"] [data-menu-badge]'))->toBeNull();
    });

    it('un grupo entero oculto por un solo motivo lo dice una vez en su encabezado; sus entradas, sin insignia, conservan la descripción', function () {
        $heading = $this->page->querySelector('[data-menu-group="validacion"]');
        $badge = $heading->querySelector('[data-menu-badge]');

        expect(textOf($badge))->toBe('Oculto')
            ->and($badge->getAttribute('data-why'))->toBe('Oculto para los demás: Motivo A')
            ->and(textOf($heading))->toContain('oculto para los demás: Motivo A');

        foreach (['V1', 'V2'] as $label) {
            $entry = menuEntryIn($this->page, $label);

            expect($entry->querySelector('[data-menu-badge]'))->toBeNull()
                ->and(menuDescriptionOf($this->page, $entry))->toBe('Oculta para los demás: Motivo A')
                ->and($entry->getAttribute('data-why'))->toBe('Oculta para los demás: Motivo A');
        }
    });

    it('un grupo entero oculto por varios motivos lleva la insignia en el encabezado y cada entrada la suya', function () {
        expect($this->page->querySelector('[data-menu-group="propuesta"] [data-menu-badge]')->getAttribute('data-why'))->toBe('Oculto para los demás: Motivo B. Motivo C')
            ->and(textOf(menuEntryIn($this->page, 'P1')->querySelector('[data-menu-badge]')))->toBe('Oculta')
            ->and(menuDescriptionOf($this->page, menuEntryIn($this->page, 'P2')))->toBe('Oculta para los demás: Motivo C');
    });

    it('un grupo con algo disponible no se marca en su encabezado', function () {
        expect($this->page->querySelector('[data-menu-group="asignacion"] [data-menu-badge]'))->toBeNull()
            ->and(menuEntryIn($this->page, 'A2')->querySelector('[data-menu-badge]'))->toBeNull();
    });

    it('un área entera oculta lleva la insignia junto a su nombre y la marca en su icono, que lo dice en su nombre y su tooltip', function () {
        $name = $this->page->querySelector('[data-menu-area="recursos_humanos"] [data-menu-flip="name"]');
        $icon = $this->page->querySelector('[data-menu-area="recursos_humanos"] [data-menu-flip="icon"]');
        $group = $this->page->querySelector('[role="group"][data-menu-area="recursos_humanos"]');

        expect(textOf($name->querySelector('[data-menu-badge]')))->toBe('Oculta')
            ->and($name->querySelector('[data-menu-badge]')->getAttribute('data-why'))->toBe('Oculta para los demás: Motivo A')
            ->and($icon->getAttribute('aria-label'))->toBe('Recursos humanos, oculta para los demás')
            ->and($icon->getAttribute('data-tip'))->toBe('Recursos humanos, oculta para los demás')
            ->and($icon->querySelector('[data-menu-hidden-mark]'))->not->toBeNull()
            ->and($group->getAttribute('aria-label'))->toBe('Recursos humanos')
            ->and(menuDescriptionOf($this->page, $group))->toBe('Oculta para los demás: Motivo A');
    });

    it('un área con algo disponible no se marca', function () {
        expect($this->page->querySelector('[data-menu-area="academico"] [data-menu-flip="name"] [data-menu-badge]'))->toBeNull()
            ->and($this->page->querySelector('[data-menu-area="academico"] [data-menu-hidden-mark]'))->toBeNull()
            ->and($this->page->querySelector('[data-menu-area="academico"] [data-menu-flip="icon"]')->getAttribute('aria-label'))->toBe('Académico');
    });

    it('en la hoja del celular, el motivo va escrito bajo el encabezado del grupo cubierto y bajo la entrada suelta', function () {
        $notes = array_map(fn (Element $note): string => textOf($note), iterator_to_array($this->page->querySelectorAll('#nexus-menu [data-menu-note]')));
        $validation = $this->page->querySelector('[data-menu-group="validacion"]');

        expect($notes)->toBe(['Motivo A', 'Motivo B', 'Motivo C', 'Motivo B', 'Motivo A', 'Motivo B', 'Motivo A'])
            ->and($validation->nextElementSibling->hasAttribute('data-menu-note'))->toBeTrue()
            ->and($validation->nextElementSibling->getAttribute('aria-hidden'))->toBe('true');
    });

    it('en la hoja, un área sin encabezados oculta entera por un solo motivo lo dice una vez, bajo su nombre', function () {
        $name = $this->page->querySelector('[data-menu-area="recursos_humanos"] [data-menu-flip="name"]');
        $followedByNote = fn (string $label): bool => menuEntryIn($this->page, $label)->nextElementSibling?->hasAttribute('data-menu-note') ?? false;

        expect($name->nextElementSibling->hasAttribute('data-menu-note'))->toBeTrue()
            ->and(textOf($name->nextElementSibling))->toBe('Motivo A')
            ->and($followedByNote('R1'))->toBeFalse()
            ->and($followedByNote('R2'))->toBeFalse()
            // Con dos motivos, cada entrada lleva el suyo.
            ->and($followedByNote('S1'))->toBeTrue()
            ->and($this->page->querySelector('[data-menu-area="servicios_escolares"] [data-menu-flip="name"]')->nextElementSibling->hasAttribute('data-menu-note'))->toBeFalse();
    });
});

it('MN-02: quien no es admin no ve lo oculto ni ninguna marca', function () {
    $page = markedMenuFor(User::factory()->withRole(Role::Docente, ['campus_id' => $this->campus->id])->create());

    expect(menuEntryIn($page, 'A1'))->toBeNull()
        ->and(menuEntryIn($page, 'V1'))->toBeNull()
        ->and(menuEntryIn($page, 'H1'))->not->toBeNull()
        ->and($page->querySelector('[data-menu-area="recursos_humanos"]'))->toBeNull()
        ->and($page->querySelector('[data-menu-badge], [data-menu-note], [data-menu-hidden-mark], [data-why]'))->toBeNull();
});

describe('MN-02: el motivo de una entrada para quien la ve marcada', function () {
    it('lo da al admin si la entrada no está disponible, y null si lo está', function () {
        $this->actingAs(User::factory()->admin()->create());
        $navigation = markedNavigation();
        [$hidden, $shown] = [$navigation->forRoute('home'), new NavigationItem('Libre', 'home', 'o-home', NavigationArea::Academico)];

        expect($navigation->hiddenReasonFor($hidden, app(Actor::class)))->toBe('Motivo A')
            ->and($navigation->hiddenReasonFor($shown, app(Actor::class)))->toBeNull();
    });

    it('no lo da a quien no ve lo no disponible, ni de una entrada que no puede ver', function () {
        $navigation = markedNavigation();
        $hidden = $navigation->forRoute('home');
        $forbidden = new NavigationItem('Prohibida', 'home', 'o-home', NavigationArea::Academico,
            visible: static fn (): bool => false, whyUnavailable: static fn (): string => 'Motivo A');

        $this->actingAs(User::factory()->withRole(Role::Docente, ['campus_id' => $this->campus->id])->create());
        expect($navigation->hiddenReasonFor($hidden, app(Actor::class)))->toBeNull();

        $this->actingAs(User::factory()->admin()->create());
        app(Actor::class)->refresh();
        expect($navigation->hiddenReasonFor($forbidden, app(Actor::class)))->toBeNull();
    });
});

it('MN-02: el icono de un área no lleva a una página que el menú ya le esconde: la del coordinador, sin carga en curso, va a la principal', function () {
    $term = Term::factory()->current()->create(['name' => 'Ene-Jun 2027']);
    $load = AcademicLoad::factory()->for($term)->create(['campus_id' => $this->campus->id]);
    $this->actingAs(User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->campus->id, 'career_id' => careerKey(20)])->create());
    app(RecentPages::class)->remember('academic-load.proposal', '/propuesta?carrera=x');

    $load->update(['status' => LoadStatus::Published]);
    $icon = domOf(Livewire::test('core::components.navigation', ['route' => 'students.index']))
        ->querySelector('#nexus-menu [data-menu-area="academico"] [data-menu-flip="icon"]');

    expect($icon->getAttribute('href'))->toBe(route('published.index'));
});

it('MN-02: el aviso no evalúa la disponibilidad para quien no ve lo no disponible; para el admin, una vez', function () {
    $calls = 0;
    app()->instance(Navigation::class, new Navigation([
        new NavigationItem('Contada', 'core.campuses', 'o-home', NavigationArea::Administracion, group: NavigationGroup::Institucion,
            whyUnavailable: static function () use (&$calls): string {
                $calls++;

                return 'Motivo A';
            }),
    ]));

    $this->actingAs(User::factory()->withRole(Role::Docente, ['campus_id' => $this->campus->id])->create());
    $silent = Livewire::test('core::components.hidden-notice', ['route' => 'core.campuses']);

    expect($calls)->toBe(0)
        ->and(domOf($silent)->querySelector('[role="note"]'))->toBeNull();

    $this->actingAs(User::factory()->admin()->create());
    app(Actor::class)->refresh();
    $note = Livewire::test('core::components.hidden-notice', ['route' => 'core.campuses']);

    expect($calls)->toBe(1)
        ->and(textOf(domOf($note)->querySelector('[role="note"]')))->toContain('Motivo A');
});

/*
| El aviso en la pantalla: el admin que abre una pantalla que los demás no ven en el periodo lo lee arriba del
| contenido. Sin carga en curso en el periodo, Avance del cierre está oculta (MN-04).
*/
describe('MN-02: el aviso de la pantalla oculta', function () {
    beforeEach(function () {
        Term::factory()->current()->create(['name' => 'Ene-Jun 2027']);
    });

    it('lo ve el admin en una pantalla que los demás no ven, arriba del contenido', function () {
        $page = domOf($this->actingAs(User::factory()->admin()->create())->get(route('staffing.closing-board'))->assertOk());
        $note = $page->querySelector('#nexus-content [role="note"]');

        expect(textOf($note))->toBe('Oculta para los demás. No hay carga en curso en Ene-Jun 2027. Tú la ves porque administras Nexus; en el menú de los demás no aparece.')
            ->and($note->querySelector('svg'))->not->toBeNull();
    });

    it('no sale en una pantalla disponible', function () {
        $page = domOf($this->actingAs(User::factory()->admin()->create())->get(route('core.campuses'))->assertOk());

        expect($page->querySelector('#nexus-content [role="note"]'))->toBeNull();
    });

    it('no lo ve otro rol, aunque la pantalla abra por su permiso', function () {
        $page = domOf($this->actingAs(User::factory()->withRole(Role::Subdireccion)->create())->get(route('staffing.closing-board'))->assertOk());

        expect($page->querySelector('#nexus-content [role="note"]'))->toBeNull()
            ->and($page->querySelector('#nexus-menu a[data-label="Avance del cierre"]'))->toBeNull();
    });
});
