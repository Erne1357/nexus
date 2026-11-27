<?php

/**
 * Departamentos del catálogo demo, por clave: los académicos del organigrama del ITCJ (aproximado), más un
 * departamento inactivo y uno borrado para probar las pantallas. Opciones: `active` (true por omisión) y
 * `deleted` (false por omisión). Los usa AcademicDemoSeeder.
 *
 * @return array<string, array{name: string, active?: bool, deleted?: bool}>
 */
return [
    'CB' => ['name' => 'Ciencias Básicas'],
    'CEA' => ['name' => 'Ciencias Económico-Administrativas'],
    'II' => ['name' => 'Ingeniería Industrial'],
    'SC' => ['name' => 'Sistemas y Computación'],
    'EE' => ['name' => 'Ingeniería Eléctrica y Electrónica'],
    'MM' => ['name' => 'Metal-Mecánica'],

    // Casos extremos: sin carreras ni materias.
    'CT' => ['name' => 'Ciencias de la Tierra', 'active' => false],
    'QB' => ['name' => 'Química y Bioquímica', 'deleted' => true],
];
