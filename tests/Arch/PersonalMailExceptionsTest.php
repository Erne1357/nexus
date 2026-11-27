<?php

use App\Modules\Core\Notifications\AccessInvitation;
use App\Modules\Core\Notifications\AllowsPersonalMail;
use App\Modules\Core\Notifications\PersonalMailExceptions;
use App\Modules\Staffing\Notifications\SelectionTeacherNotice;
use Symfony\Component\Finder\Finder;

/*
| CE-01 (confirmado el 26 de noviembre de 2026): solo las excepciones declaradas mandan correo a una dirección personal.
| La lista de Core (PersonalMailExceptions) nombra cada clase con la regla que la permite, y ninguna otra clase de los
| módulos implementa AllowsPersonalMail, salvo las que heredan de una clase de la lista (los avisos al docente de la
| orden de selección heredan de SelectionTeacherNotice). Una excepción nueva es una regla nueva: se declara en
| reglas-de-negocio.md, en la lista y aquí.
*/

/**
 * Las clases de app/Modules que implementan AllowsPersonalMail, directamente o por herencia.
 *
 * @return list<class-string>
 */
function classesAllowingPersonalMail(): array
{
    $root = dirname(__DIR__, 2);
    $classes = [];

    foreach (Finder::create()->files()->name('*.php')->in("{$root}/app/Modules") as $file) {
        if (preg_match('/^namespace\s+([^;]+);.*?^(?:final\s+|abstract\s+|readonly\s+)*(?:class|interface)\s+(\w+)/sm', $file->getContents(), $match) !== 1) {
            continue;
        }

        $class = "{$match[1]}\\{$match[2]}";

        if (class_exists($class) && is_subclass_of($class, AllowsPersonalMail::class)) {
            $classes[] = $class;
        }
    }

    sort($classes);

    return $classes;
}

it('CE-01: la lista de excepciones declara solo SEL-13 (la invitación) y SEL-15 (los avisos al docente de la selección)', function () {
    expect(PersonalMailExceptions::DECLARED)->toBe([
        'AccessInvitation' => 'SEL-13',
        'SelectionTeacherNotice' => 'SEL-15',
    ]);
});

it('CE-01: solo las clases de la lista, y las que heredan de ellas, implementan AllowsPersonalMail', function () {
    $declared = [AccessInvitation::class, SelectionTeacherNotice::class];
    $offenders = array_values(array_filter(
        classesAllowingPersonalMail(),
        fn (string $class): bool => ! in_array($class, $declared, true) && array_filter($declared, fn (string $parent): bool => is_subclass_of($class, $parent)) === [],
    ));

    expect($offenders)->toBe([])
        ->and(classesAllowingPersonalMail())->toContain(...$declared);
});

it('CE-01: cada clase de la lista implementa AllowsPersonalMail y su regla existe en reglas-de-negocio.md', function () {
    $rules = file_get_contents(dirname(__DIR__, 2).'/docs/dominio/reglas-de-negocio.md');

    foreach ([AccessInvitation::class, SelectionTeacherNotice::class] as $class) {
        $rule = PersonalMailExceptions::DECLARED[class_basename($class)];

        expect(is_subclass_of($class, AllowsPersonalMail::class))->toBeTrue()
            ->and($rules)->toContain("**{$rule}.");
    }
});
