<?php

use App\Modules\Core\Enums\Role;
use App\Modules\Core\Livewire\Concerns\WithModalityScope;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermOpening;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\Blade;
use Livewire\Component;
use Livewire\Exceptions\MethodNotFoundException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

/*
| La modalidad de una pantalla (M4; MS16, supuesto por confirmar): la pantalla que trabaja sobre una modalidad la lleva
| en su URL (`?modalidad=<clave>`) con el concern WithModalityScope y la pinta con x-core::modality-scope, que no se ve
| si hay una sola opción. Las opciones son las modalidades activas donde trabaja con su perfil (Actor::modalities()).
*/

function modalityScopePage(): Component
{
    return new class extends Component
    {
        use WithModalityScope;

        /**
         * @return array{options: list<string>, required: string}
         */
        public function shown(): array
        {
            return [
                'options' => $this->modalityOptions()->pluck('code')->all(),
                'required' => $this->requiredModality()->code,
            ];
        }

        public function render(): string
        {
            return '<div>{{ $modalidad }}</div>';
        }
    };
}

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
});

describe('WithModalityScope', function () {
    it('MS16: con solo PRES activa, la modalidad de la pantalla es PRES', function () {
        $this->actingAs($this->admin);

        expect(Livewire::test(modalityScopePage())->instance()->shown())->toBe(['options' => ['PRES'], 'required' => 'PRES']);
    });

    it('MS16: con PRES y EAD activas, ?modalidad=EAD elige EAD', function () {
        modalityOf('EAD')->update(['is_active' => true]);
        $this->actingAs($this->admin);

        expect(Livewire::withQueryParams(['modalidad' => 'EAD'])->test(modalityScopePage())->instance()->shown())
            ->toBe(['options' => ['EAD', 'PRES'], 'required' => 'EAD']);
    });

    it('MS30: sin elegir, con varias, toma la primera por clave donde el periodo activo está abierto, no una sin abrir', function () {
        modalityOf('EAD')->update(['is_active' => true]);
        $term = Term::factory()->current()->create();
        TermOpening::query()->create(['term_id' => $term->id, 'modality_id' => modalityOf('PRES')->id, 'opened_by' => $this->admin->id, 'opened_at' => now()]);
        $this->actingAs($this->admin);

        expect(Livewire::test(modalityScopePage())->instance()->shown()['required'])->toBe('PRES')
            ->and(Livewire::withQueryParams(['modalidad' => 'EAD'])->test(modalityScopePage())->instance()->shown()['required'])->toBe('EAD');
    });

    it('sin elegir, o con una modalidad que no ofrece, y sin periodo abierto, toma la primera por clave', function (string $code) {
        modalityOf('EAD')->update(['is_active' => true]);
        $this->actingAs($this->admin);

        expect(Livewire::withQueryParams(['modalidad' => $code])->test(modalityScopePage())->instance()->shown()['required'])->toBe('EAD');
    })->with(['inactiva' => 'ENL', 'no existe' => 'XX', 'vacía' => '']);

    it('cambia de modalidad al fijar la propiedad', function () {
        modalityOf('EAD')->update(['is_active' => true]);
        $this->actingAs($this->admin);

        expect(Livewire::test(modalityScopePage())->set('modalidad', 'PRES')->instance()->shown()['required'])->toBe('PRES');
    });

    it('solo ofrece las modalidades de sus nombramientos: un coordinador de PRES no elige EAD', function () {
        modalityOf('EAD')->update(['is_active' => true]);
        $this->actingAs(User::factory()->withRole(Role::Coordinador, ['modality_id' => modalityOf('PRES')->id, 'career_id' => careerKey(20)])->create());

        expect(Livewire::withQueryParams(['modalidad' => 'EAD'])->test(modalityScopePage())->instance()->shown())
            ->toBe(['options' => ['PRES'], 'required' => 'PRES']);
    });

    it('sin ninguna modalidad activa, la pantalla responde 403', function () {
        Modality::query()->update(['is_active' => false]);
        $this->actingAs($this->admin);

        expect(fn () => Livewire::test(modalityScopePage())->instance()->shown())
            ->toThrow(HttpException::class, 'No trabajas en ninguna modalidad activa.');
    });

    it('sus métodos no se pueden llamar desde el navegador', function (string $method) {
        $this->actingAs($this->admin);

        expect(fn () => Livewire::test(modalityScopePage())->call($method))->toThrow(MethodNotFoundException::class);
    })->with(['modalityOptions', 'requiredModality']);
});

describe('x-core::modality-scope', function () {
    it('MS16: no pinta nada con una sola opción', function () {
        $html = Blade::render('<x-core::modality-scope :options="$options" selected="PRES" />', ['options' => collect([modalityOf('PRES')])]);

        expect(trim($html))->toBe('');
    });

    it('MS16: con varias opciones es el select «Modalidad» del renglón de selectores (maqueta U2, opción A), que fija ?modalidad= al elegir', function () {
        $html = Blade::render('<x-core::modality-scope :options="$options" selected="EAD" />', ['options' => collect([modalityOf('EAD'), modalityOf('PRES')])]);
        $dom = domOf($html);
        $select = $dom->querySelector('select');

        expect(textOf($dom->querySelector('legend')))->toBe('Modalidad')
            ->and($dom->querySelector('[role="group"]'))->toBeNull()
            ->and($select->getAttribute('aria-label'))->toBe('Modalidad')
            ->and($select->getAttribute('wire:change'))->toBe("\$set('modalidad', \$event.target.value)")
            ->and(array_map(fn ($option): string => textOf($option), iterator_to_array($select->querySelectorAll('option'))))->toBe(['EAD', 'Presencial'])
            ->and($select->querySelector('option[value="EAD"]')?->hasAttribute('selected'))->toBeTrue()
            ->and($select->querySelector('option[value="PRES"]')?->hasAttribute('selected'))->toBeFalse();
    });

    it('con más de cuatro opciones sigue siendo el select que cambia al elegir', function () {
        $options = collect(['M1', 'M2', 'M3', 'M4', 'M5'])->map(fn (string $code): Modality => Modality::factory()->make(['code' => $code, 'name' => "Modalidad {$code}"]));

        $select = domOf(Blade::render('<x-core::modality-scope :options="$options" selected="M3" />', ['options' => $options]))->querySelector('select');

        expect($select->getAttribute('wire:change'))->toBe("\$set('modalidad', \$event.target.value)")
            ->and($select->getAttribute('aria-label'))->toBe('Modalidad')
            ->and($select->querySelector('option[value="M3"]')?->hasAttribute('selected'))->toBeTrue()
            ->and($select->querySelector('option[value=""]'))->toBeNull();
    });
});
