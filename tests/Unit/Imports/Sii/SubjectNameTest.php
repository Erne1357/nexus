<?php

use App\Modules\Imports\Sii\SubjectName;

/*
| Nombre de una materia del SII (R12, supuesto por confirmar): el SII lo da en mayúsculas y casi siempre sin
| acentos. Nexus lo guarda en tipo título, con las palabras cortas en minúsculas salvo la primera, los números
| romanos en mayúsculas y los acentos de un diccionario pequeño de palabras sin ambigüedad.
*/

it('pasa el nombre a tipo título con las palabras cortas en minúsculas', function (string $sii, string $nexus) {
    expect(SubjectName::title($sii))->toBe($nexus);
})->with([
    'una palabra corta' => ['INTRODUCCION A LA PROGRAMACION', 'Introducción a la Programación'],
    'conjunción' => ['SEMINARIO DE INVESTIGACION Y DESARROLLO', 'Seminario de Investigación y Desarrollo'],
    'la primera palabra corta va en mayúscula' => ['DE LA TIERRA AL CIELO', 'De la Tierra al Cielo'],
    'sin palabras del diccionario' => ['DESARROLLO SUSTENTABLE', 'Desarrollo Sustentable'],
    'ecuaciones sin acento' => ['ECUACIONES DIFERENCIALES', 'Ecuaciones Diferenciales'],
]);

it('agrega los acentos del diccionario y respeta los que ya trae', function () {
    expect(SubjectName::title('CALCULO DIFERENCIAL'))->toBe('Cálculo Diferencial')
        ->and(SubjectName::title('TALLER DE ETICA'))->toBe('Taller de Ética')
        ->and(SubjectName::title('METODOS NUMERICOS'))->toBe('Métodos Numéricos')
        ->and(SubjectName::title('CÁLCULO INTEGRAL'))->toBe('Cálculo Integral')
        ->and(SubjectName::title('ELECTROMAGNETISMO Y ÓPTICA'))->toBe('Electromagnetismo y Óptica')
        ->and(SubjectName::title('ALGEBRA LINEAL'))->toBe('Álgebra Lineal');
});

it('deja los números romanos en mayúsculas', function () {
    expect(SubjectName::title('FISICA IV'))->toBe('Física IV')
        ->and(SubjectName::title('ADMINISTRACION DE LA PRODUCCION II'))->toBe('Administración de la Producción II')
        ->and(SubjectName::title('TOPICOS SELECTOS VI'))->toBe('Topicos Selectos VI');
});

it('junta los espacios repetidos y respeta la puntuación', function () {
    expect(SubjectName::title('  METODOS   NUMERICOS (OPTATIVA) '))->toBe('Métodos Numéricos (Optativa)')
        ->and(SubjectName::title('QUIMICA-FISICA'))->toBe('Química-Física');
});

it('da una llave para comparar nombres sin mayúsculas, acentos ni espacios repetidos', function () {
    expect(SubjectName::key(' Cálculo   diferencial '))->toBe('CALCULO DIFERENCIAL')
        ->and(SubjectName::key('PROBABILIDAD Y ESTADÍSTICA'))->toBe(SubjectName::key('probabilidad y estadistica'));
});
