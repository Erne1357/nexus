<?php

use App\Modules\Core\Enums\Role;
use App\Modules\Core\Livewire\Concerns\WithCampusFilter;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\Blade;
use Livewire\Component;
use Livewire\Exceptions\MethodNotFoundException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

/*
| El campus de una pantalla (#415, A1): la credencial ya no lo elige, así que cada pantalla que lo necesita lo lleva en
| su URL (`?campus=<código>`) con el concern WithCampusFilter y lo pinta con x-core::campus-filter.
*/

beforeEach(function () {
    $this->c1 = Campus::factory()->create(['code' => 'C1', 'name' => 'Campus I']);
    $this->c2 = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    Campus::factory()->create(['code' => 'EAD', 'name' => 'EAD', 'is_active' => false]);
    $this->foreign = Campus::factory()->create(['code' => 'C9', 'name' => 'Campus ajeno']);

    $this->jefe = User::factory()
        ->withRole(Role::JefeProyectoDocente, ['campus_id' => $this->c1->id, 'department_id' => departmentKey(10)])
        ->withRole(Role::JefeProyectoDocente, ['campus_id' => $this->c2->id, 'department_id' => departmentKey(10)])
        ->create();
});

function campusFilterPage(): Component
{
    return new class extends Component
    {
        use WithCampusFilter;

        /**
         * @return array{options: list<string>, filter: ?string, required: string}
         */
        public function shown(): array
        {
            return [
                'options' => $this->campusOptions()->pluck('code')->all(),
                'filter' => $this->campusFilter()?->code,
                'required' => $this->requiredCampus()->code,
            ];
        }

        public function render(): string
        {
            return '<div>{{ $campus }}</div>';
        }
    };
}

describe('WithCampusFilter', function () {
    it('ofrece los campus activos donde trabaja con su perfil, por código', function () {
        $this->actingAs($this->jefe);

        expect(Livewire::test(campusFilterPage())->instance()->shown())
            ->toBe(['options' => ['C1', 'C2'], 'filter' => null, 'required' => 'C1']);
    });

    it('toma el campus de ?campus=', function () {
        $this->actingAs($this->jefe);

        expect(Livewire::withQueryParams(['campus' => 'C2'])->test(campusFilterPage())->instance()->shown())
            ->toBe(['options' => ['C1', 'C2'], 'filter' => 'C2', 'required' => 'C2']);
    });

    it('cambia de campus al fijar la propiedad, y con null vuelve a todos', function () {
        $this->actingAs($this->jefe);

        $page = Livewire::test(campusFilterPage())->set('campus', 'C2');

        expect($page->instance()->shown()['filter'])->toBe('C2')
            ->and($page->set('campus', null)->instance()->shown()['filter'])->toBeNull();
    });

    it('ignora un campus que no ofrece: ajeno, inactivo o que no existe', function (string $code) {
        $this->actingAs($this->jefe);

        expect(Livewire::withQueryParams(['campus' => $code])->test(campusFilterPage())->instance()->shown())
            ->toBe(['options' => ['C1', 'C2'], 'filter' => null, 'required' => 'C1']);
    })->with(['ajeno' => 'C9', 'inactivo' => 'EAD', 'no existe' => 'XX', 'vacío' => '']);

    it('AZ-02: recuerda el último campus elegido y lo usa cuando la URL no trae ?campus=', function () {
        $this->actingAs($this->jefe);

        Livewire::withQueryParams(['campus' => 'C2'])->test(campusFilterPage())->instance()->shown();

        expect(session('core.last_campus'))->toBe('C2')
            ->and(Livewire::test(campusFilterPage())->instance()->shown()['required'])->toBe('C2')
            ->and(Livewire::withQueryParams(['campus' => 'C1'])->test(campusFilterPage())->instance()->shown()['required'])->toBe('C1');
    });

    it('AZ-02: no recuerda un campus inválido, y uno recordado que ya no ofrece se cambia por el primero', function () {
        $this->actingAs($this->jefe);

        Livewire::withQueryParams(['campus' => 'C9'])->test(campusFilterPage())->instance()->shown();
        expect(session('core.last_campus'))->toBeNull();

        session(['core.last_campus' => 'C9']);
        expect(Livewire::test(campusFilterPage())->instance()->shown()['required'])->toBe('C1');
    });

    it('a quien trabaja en un solo campus le da ese, aunque la URL pida otro', function () {
        $this->actingAs(User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => careerKey(20)])->create());

        expect(Livewire::withQueryParams(['campus' => 'C1'])->test(campusFilterPage())->instance()->shown())
            ->toBe(['options' => ['C2'], 'filter' => null, 'required' => 'C2']);
    });

    it('sin ningún campus activo, la pantalla que pide uno responde 403', function () {
        Campus::query()->update(['is_active' => false]);
        $this->actingAs(User::factory()->admin()->create());

        expect(fn () => Livewire::test(campusFilterPage())->instance()->shown())
            ->toThrow(HttpException::class, 'No trabajas en ningún campus activo.');
    });

    it('sus métodos no se pueden llamar desde el navegador', function (string $method) {
        $this->actingAs($this->jefe);

        expect(fn () => Livewire::test(campusFilterPage())->call($method))->toThrow(MethodNotFoundException::class);
    })->with(['campusOptions', 'campusFilter', 'requiredCampus']);
});

describe('x-core::campus-filter', function () {
    it('no se pinta con un solo campus: no hay nada que elegir', function () {
        $html = Blade::render('<x-core::campus-filter :options="$options" selected="C2" all />', ['options' => collect([$this->c2])]);

        expect(trim($html))->toBe('');
    });

    it('con pocos campus es un filtro segmentado con «Todos», el elegido en primary', function () {
        $html = Blade::render('<x-core::campus-filter :options="$options" selected="C2" all />', ['options' => collect([$this->c1, $this->c2])]);
        $group = domOf($html)->querySelector('[role="group"]');
        $buttons = $group->querySelectorAll('button');

        expect($group->getAttribute('aria-label'))->toBe('Campus')
            ->and(array_map(fn ($button): string => textOf($button), iterator_to_array($buttons)))->toBe(['Todos', 'Campus I', 'Campus II'])
            ->and(array_map(fn ($button): string => $button->getAttribute('aria-pressed'), iterator_to_array($buttons)))->toBe(['false', 'false', 'true'])
            ->and($buttons->item(0)->getAttribute('wire:click'))->toBe("\$set('campus', null)")
            ->and($buttons->item(1)->getAttribute('wire:click'))->toBe("\$set('campus', 'C1')")
            ->and($buttons->item(2)->getAttribute('class'))->toContain('btn-primary')
            ->and($buttons->item(1)->getAttribute('class'))->not->toContain('btn-primary');
    });

    it('sin elegido, «Todos» va en primary', function () {
        $html = Blade::render('<x-core::campus-filter :options="$options" all />', ['options' => collect([$this->c1, $this->c2])]);

        expect(domOf($html)->querySelector('[data-testid="campus-filter-todos"]')->getAttribute('aria-pressed'))->toBe('true');
    });

    it('en una pantalla que necesita un campus no ofrece «Todos»', function () {
        $html = Blade::render('<x-core::campus-filter :options="$options" selected="C1" />', ['options' => collect([$this->c1, $this->c2])]);

        expect($html)->not->toContain('Todos')
            ->and($html)->toContain('data-testid="campus-filter-C1"');
    });

    it('con muchos campus es un select que cambia al elegir, con «Todos los campus» si la pantalla los permite', function () {
        $options = collect(['C1', 'C2', 'C3', 'C4'])->map(fn (string $code): Campus => Campus::factory()->make(['code' => $code, 'name' => "Campus {$code}"]));

        $html = Blade::render('<x-core::campus-filter :options="$options" selected="C3" all />', ['options' => $options]);
        $select = domOf($html)->querySelector('select');

        expect($select->getAttribute('wire:change'))->toBe("\$set('campus', \$event.target.value)")
            ->and($select->getAttribute('aria-label'))->toBe('Campus')
            ->and(textOf($select->querySelector('option[value=""]')))->toBe('Todos los campus')
            ->and($select->querySelector('option[value="C3"]')?->hasAttribute('selected'))->toBeTrue()
            ->and($select->querySelector('option[value="C1"]')?->hasAttribute('selected'))->toBeFalse()
            ->and($html)->not->toContain('role="group"');
    });

    it('en el select de una pantalla que necesita un campus, el que muestra va elegido y no hay «Todos»', function () {
        $options = collect(['C1', 'C2', 'C3', 'C4', 'C5'])->map(fn (string $code): Campus => Campus::factory()->make(['code' => $code, 'name' => "Campus {$code}"]));

        $select = domOf(Blade::render('<x-core::campus-filter :options="$options" selected="C1" />', ['options' => $options]))->querySelector('select');

        expect($select->querySelector('option[value=""]'))->toBeNull()
            ->and($select->querySelector('option[value="C1"]')?->hasAttribute('selected'))->toBeTrue();
    });
});
