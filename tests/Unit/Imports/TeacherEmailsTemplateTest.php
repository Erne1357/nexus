<?php

use App\Modules\Faculty\Models\Teacher;
use App\Modules\Imports\Templates\TeacherEmailsTemplate;

/*
| La plantilla `teacher_emails` (tarea 1.21, D4): el archivo de TI con el RFC y el correo institucional de cada
| docente. Normaliza el RFC como Docentes y el correo sin espacios y en minúsculas; la clave es el RFC, un correo no
| puede venir para dos docentes y no ofrece «desactivar faltantes».
*/

it('normaliza el RFC como Docentes y el correo sin espacios y en minúsculas', function () {
    expect((new TeacherEmailsTemplate)->normalize(['rfc' => ' prue 012345 ab1 ', 'email' => '  Ana.Lopez@CDJuarez.TecNM.mx ']))
        ->toBe(['rfc' => 'PRUE012345AB1', 'email' => 'ana.lopez@cdjuarez.tecnm.mx']);
});

it('pasa a texto el RFC que un Excel lee como número, y deja vacío lo que no trae', function () {
    expect((new TeacherEmailsTemplate)->normalize(['rfc' => 98765.0]))->toBe(['rfc' => '98765', 'email' => null]);
});

it('tiene las columnas RFC y correo, el RFC como clave y escribe docentes', function () {
    $template = (new TeacherEmailsTemplate);

    expect($template->key())->toBe('teacher_emails')
        ->and($template->label())->toBe('Correos institucionales de docentes')
        ->and($template->fields())->toBe(['rfc' => 'RFC', 'email' => 'correo'])
        ->and($template->naturalKey())->toBe(['rfc'])
        ->and($template->model())->toBe(Teacher::class)
        ->and($template->requiresCampus())->toBeFalse();
});

it('no ofrece «desactivar faltantes» y un correo no puede venir para dos docentes del archivo', function () {
    $template = (new TeacherEmailsTemplate);

    expect($template->deactivatesMissing())->toBeFalse()
        ->and($template->exclusiveAcrossRows())->toBe(['value' => 'email', 'owner' => 'rfc']);
});
