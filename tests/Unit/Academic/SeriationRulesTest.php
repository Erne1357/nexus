<?php

use App\Modules\Academic\Rules\SeriationRules;

/*
| La regla de ciclos de la seriación (reglas-de-negocio.md, "Reglas de datos"), que comparten las Actions de la
| retícula y la plantilla `prerequisites`: una seriación cierra un ciclo si su requisito ya pide, en cadena, a la
| materia, o si la materia se pide a sí misma. Las aristas van de cada materia a las que pide en seriación.
*/

/**
 * Las aristas de una cadena: cada materia pide la anterior (`chainOf('A', 'B', 'C')`: B pide A y C pide B).
 *
 * @return array<string, array<string, true>>
 */
function chainOf(string ...$subjects): array
{
    $edges = [];

    foreach (array_slice($subjects, 1) as $index => $subject) {
        $edges[$subject][$subjects[$index]] = true;
    }

    return $edges;
}

it('cierra un ciclo si el requisito ya pide a la materia directamente', function () {
    $edges = chainOf('A', 'B');

    expect(SeriationRules::closesCycle($edges, 'A', 'B'))->toBeTrue()
        ->and(SeriationRules::cyclePath($edges, 'A', 'B'))->toBe(['B', 'A']);
});

it('cierra un ciclo si el requisito pide a la materia en una cadena de 4, y da la cadena del requisito a la materia', function () {
    $edges = chainOf('A', 'B', 'C', 'D');

    expect(SeriationRules::closesCycle($edges, 'A', 'D'))->toBeTrue()
        ->and(SeriationRules::cyclePath($edges, 'A', 'D'))->toBe(['D', 'C', 'B', 'A'])
        ->and(SeriationRules::closesCycle($edges, 'B', 'D'))->toBeTrue()
        ->and(SeriationRules::cyclePath($edges, 'B', 'D'))->toBe(['D', 'C', 'B']);
});

it('no cierra un ciclo si el requisito no llega a la materia', function () {
    $edges = chainOf('A', 'B', 'C', 'D');

    expect(SeriationRules::closesCycle($edges, 'D', 'A'))->toBeFalse()
        ->and(SeriationRules::closesCycle($edges, 'C', 'A'))->toBeFalse()
        ->and(SeriationRules::closesCycle($edges, 'E', 'A'))->toBeFalse()
        ->and(SeriationRules::closesCycle([], 'A', 'B'))->toBeFalse()
        ->and(SeriationRules::cyclePath($edges, 'D', 'A'))->toBeNull();
});

it('una materia que se pide a sí misma cierra un ciclo', function () {
    expect(SeriationRules::closesCycle([], 'A', 'A'))->toBeTrue()
        ->and(SeriationRules::cyclePath([], 'A', 'A'))->toBe(['A']);
});

it('con varios caminos da el más corto, y termina aunque la seriación ya tenga un ciclo ajeno', function () {
    $edges = chainOf('A', 'B', 'C', 'D') + ['E' => ['D' => true, 'A' => true], 'X' => ['Y' => true], 'Y' => ['X' => true]];

    expect(SeriationRules::cyclePath($edges, 'A', 'E'))->toBe(['E', 'A'])
        ->and(SeriationRules::closesCycle($edges, 'A', 'X'))->toBeFalse();
});
