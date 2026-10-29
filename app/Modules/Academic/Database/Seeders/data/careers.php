<?php

/**
 * Carreras del catálogo demo, por clave TecNM: las 12 de Campus I y las 6 de Campus II del folleto "Oferta
 * Educativa" (PROJECT.md), con el departamento que las coordina (aproximado al organigrama del ITCJ), más
 * Licenciatura en Informática, que ya no se ofrece, para probar una carrera inactiva.
 *
 * - `alias`: la abreviatura de los usernames de prueba (`coord.<alias>.c2`, DemoStaffSeeder).
 * - `campuses`: dónde se ofrece; AcademicDemoSeeder solo siembra las carreras de los campus que se le piden.
 * - `active` (true por omisión): la oferta de una carrera inactiva también queda inactiva.
 *
 * @return array<string, array{name: string, alias: string, department: string, campuses: list<string>, active?: bool}>
 */
return [
    'LADM' => ['name' => 'Licenciatura en Administración', 'alias' => 'la', 'department' => 'CEA', 'campuses' => ['C1', 'C2']],
    'COPU' => ['name' => 'Contador Público', 'alias' => 'cp', 'department' => 'CEA', 'campuses' => ['C1', 'C2']],
    'IGEM' => ['name' => 'Ingeniería en Gestión Empresarial', 'alias' => 'ige', 'department' => 'CEA', 'campuses' => ['C1', 'C2']],
    'ISIC' => ['name' => 'Ingeniería en Sistemas Computacionales', 'alias' => 'isc', 'department' => 'SC', 'campuses' => ['C1', 'C2']],
    'IIND' => ['name' => 'Ingeniería Industrial', 'alias' => 'ii', 'department' => 'II', 'campuses' => ['C1', 'C2']],
    'ILOG' => ['name' => 'Ingeniería en Logística', 'alias' => 'il', 'department' => 'II', 'campuses' => ['C1', 'C2']],
    'ITIC' => ['name' => 'Ingeniería en Tecnologías de la Información y Comunicaciones', 'alias' => 'itic', 'department' => 'SC', 'campuses' => ['C1']],
    'IELE' => ['name' => 'Ingeniería Eléctrica', 'alias' => 'ie', 'department' => 'EE', 'campuses' => ['C1']],
    'IELC' => ['name' => 'Ingeniería Electrónica', 'alias' => 'iet', 'department' => 'EE', 'campuses' => ['C1']],
    'IMEC' => ['name' => 'Ingeniería Mecánica', 'alias' => 'im', 'department' => 'MM', 'campuses' => ['C1']],
    'IEME' => ['name' => 'Ingeniería Electromecánica', 'alias' => 'iem', 'department' => 'MM', 'campuses' => ['C1']],
    'IMCT' => ['name' => 'Ingeniería Mecatrónica', 'alias' => 'imt', 'department' => 'MM', 'campuses' => ['C1']],

    // Caso extremo: ya no se ofrece; su plan no tiene retícula capturada.
    'LINF' => ['name' => 'Licenciatura en Informática', 'alias' => 'li', 'department' => 'SC', 'campuses' => ['C1', 'C2'], 'active' => false],
];
