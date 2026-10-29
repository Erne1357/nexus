<?php

use App\Modules\Imports\Templates\KardexTemplate;
use App\Modules\Imports\Templates\StudentsTemplate;

/*
| Lo que normalizan las plantillas `students` y `kardex` (tarea 1.4) antes de validar. Todavía no hay una
| exportación real del SII: los valores son los del modelo de datos y las variantes comunes, y se ajustan en
| la integración 1.16 (confirmado el 29 de octubre de 2026).
*/

describe('students', function () {
    it('deja el número de control como texto y las claves en mayúsculas', function () {
        $row = (new StudentsTemplate)->normalize([
            'control_number' => 19111234,
            'name' => ' Ana  López ',
            'career' => ' isic ',
            'study_plan' => 'isic-2010-224',
            'campus' => 'c2',
            'semester' => '3',
            'status' => 'Activo',
        ]);

        expect($row)->toBe([
            'control_number' => '19111234',
            'name' => 'Ana López',
            'career' => 'ISIC',
            'study_plan' => 'ISIC-2010-224',
            'campus' => 'C2',
            'semester' => 3,
            'status' => 'activo',
        ]);
    });

    it('pasa a texto las claves que un Excel lee como número', function () {
        $row = (new StudentsTemplate)->normalize(['control_number' => 20111234.0, 'career' => 123, 'study_plan' => 456, 'campus' => 2]);

        expect(array_intersect_key($row, array_flip(['control_number', 'career', 'study_plan', 'campus'])))
            ->toBe(['control_number' => '20111234', 'career' => '123', 'study_plan' => '456', 'campus' => '2']);
    });

    it('reconoce el estatus sin importar mayúsculas, acentos ni espacios, y deja tal cual el que no conoce', function (string $raw, string $status) {
        expect((new StudentsTemplate)->normalize(['status' => $raw])['status'])->toBe($status);
    })->with([
        ['BAJA TEMPORAL', 'baja_temporal'],
        ['Baja definitiva', 'baja_definitiva'],
        ['EGRESADO', 'egresado'],
        ['SUSPENDIDO', 'SUSPENDIDO'],
    ]);
});

describe('kardex', function () {
    it('deja el número de control como texto, la clave de materia en mayúsculas y los números como números', function () {
        $row = (new KardexTemplate)->normalize([
            'control_number' => 19111234,
            'subject_code' => 'acf-0901',
            'term_code' => 20253,
            'grade' => '85.5',
            'result' => 'Aprobada',
            'attempt' => '2',
        ]);

        expect($row)->toBe([
            'control_number' => '19111234',
            'subject_code' => 'ACF-0901',
            'term_code' => '20253',
            'grade' => 85.5,
            'result' => 'aprobada',
            'attempt' => 2,
        ]);
    });

    it('pasa a texto la clave de materia y el número de control que un Excel lee como número', function () {
        expect(array_intersect_key((new KardexTemplate)->normalize(['control_number' => 20111234.0, 'subject_code' => 901]), array_flip(['control_number', 'subject_code'])))
            ->toBe(['control_number' => '20111234', 'subject_code' => '901']);
    });

    it('lleva el periodo al código del SII: año y 1, 2 o 3, sin guión', function (string|int $raw, string $term) {
        expect((new KardexTemplate)->normalize(['term_code' => $raw])['term_code'])->toBe($term);
    })->with([
        ['2025-1', '20251'],
        ['2025/3', '20253'],
        ['20252', '20252'],
        [20251, '20251'],
        ['ENE-JUN 2025', 'ENE-JUN 2025'],
    ]);

    it('reconoce el resultado y sus variantes comunes, y deja tal cual el que no conoce', function (string $raw, string $result) {
        expect((new KardexTemplate)->normalize(['result' => $raw])['result'])->toBe($result);
    })->with([
        ['APROBADO', 'aprobada'],
        ['AC', 'aprobada'],
        ['Acreditada', 'aprobada'],
        ['NA', 'reprobada'],
        ['No acreditada', 'reprobada'],
        ['Reprobado', 'reprobada'],
        ['En curso', 'cursando'],
        ['BAJA', 'baja'],
        ['X', 'X'],
    ]);
});
