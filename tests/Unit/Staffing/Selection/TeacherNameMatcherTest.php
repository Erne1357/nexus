<?php

use App\Modules\Staffing\Rules\TeacherNameMatcher;

it('SEL-06: normaliza sin acentos, en mayúsculas y con espacios colapsados', function () {
    expect(TeacherNameMatcher::normalize('  José  Pérez '))->toBe('JOSE PEREZ');
});

it('SEL-06: «José  Pérez» empata con «JOSE PEREZ»', function () {
    $teachers = collect([
        (object) ['id' => 't1', 'name' => 'JOSE PEREZ'],
        (object) ['id' => 't2', 'name' => 'Ana Ejemplo'],
    ]);

    expect(TeacherNameMatcher::match('José  Pérez', $teachers))->toBe(['teacher_id' => 't1', 'candidates' => ['t1']]);
});

it('SEL-06: dos homónimos o ninguno dejan teacher_id nulo', function () {
    $teachers = collect([
        (object) ['id' => 't1', 'name' => 'Luis Ejemplo'],
        (object) ['id' => 't2', 'name' => 'LUIS EJEMPLO'],
    ]);

    expect(TeacherNameMatcher::match('luis ejemplo', $teachers))->toBe(['teacher_id' => null, 'candidates' => ['t1', 't2']])
        ->and(TeacherNameMatcher::match('Nadie Aquí', $teachers))->toBe(['teacher_id' => null, 'candidates' => []]);
});
