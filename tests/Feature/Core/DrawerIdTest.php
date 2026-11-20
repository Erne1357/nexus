<?php

use Illuminate\Support\Facades\Blade;
use Livewire\Component;
use Livewire\Livewire;

/*
| Issue #754: el velo de cada drawer es un `<label for>` de su casilla, así que el id de la casilla debe ser único en la página
| (si no, un clic fuera abre otro drawer) y estable entre renders (si no, el morph de Livewire cambia `id` y `for` de un drawer
| abierto). Core\View\Components\Drawer lo da a partir de la huella de MaryUI, el componente y la ocurrencia en la petición.
*/

/**
 * Los ids de las casillas de los drawers, en orden, y los `for` de sus velos.
 *
 * @return array{ids: list<string>, fors: list<string>}
 */
function drawerIdsIn(string $html): array
{
    preg_match_all('/id="([^"]+)"[^>]*class="drawer-toggle"/s', $html, $ids);
    preg_match_all('/<label for="([^"]+)" class="drawer-overlay"/', $html, $fors);

    return ['ids' => $ids[1], 'fors' => $fors[1]];
}

it('dos drawers con los mismos atributos en una página tienen ids distintos y cada velo apunta a su casilla', function () {
    $html = Blade::render('<x-drawer right close-on-escape>uno</x-drawer><x-drawer right close-on-escape>dos</x-drawer>');
    $found = drawerIdsIn($html);

    expect($found['ids'])->toHaveCount(2)
        ->and($found['ids'][0])->not->toBe($found['ids'][1])
        ->and($found['fors'])->toBe($found['ids']);
});

it('el id es el mismo en otra petición con la misma página (determinista, sin azar)', function () {
    $template = '<x-drawer right close-on-escape>uno</x-drawer><x-drawer right close-on-escape>dos</x-drawer>';
    $first = drawerIdsIn(Blade::render($template))['ids'];

    app()->forgetScopedInstances();

    expect(drawerIdsIn(Blade::render($template))['ids'])->toBe($first);
});

it('un id explícito se respeta', function () {
    expect(drawerIdsIn(Blade::render('<x-drawer id="mi-drawer">x</x-drawer>'))['ids'])->toBe(['mi-drawer']);
});

it('una actualización de Livewire (wire:model.live) no cambia los ids de los drawers del componente', function () {
    $component = new class extends Component
    {
        public bool $open = true;

        public string $search = '';

        public function render(): string
        {
            return <<<'BLADE'
                <div>
                    <x-drawer wire:model="open" right close-on-escape><input wire:model.live="search" /></x-drawer>
                    <x-drawer right close-on-escape>otro</x-drawer>
                </div>
                BLADE;
        }
    };

    $test = Livewire::test($component);
    $before = drawerIdsIn($test->html())['ids'];

    app()->forgetScopedInstances();
    $after = drawerIdsIn($test->set('search', 'cál')->html())['ids'];

    expect($before)->toHaveCount(2)
        ->and($before[0])->not->toBe($before[1])
        ->and($after)->toBe($before);
});
