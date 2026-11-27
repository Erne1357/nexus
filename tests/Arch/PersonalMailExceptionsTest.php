<?php

use App\Modules\Core\Actions\SendAccessInvitation;
use App\Modules\Core\ModuleServiceProvider;
use App\Modules\Core\Notifications\AccessInvitation;
use App\Modules\Core\Notifications\AllowsPersonalMail;
use App\Modules\Faculty\Actions\InviteTeacherAccess;
use App\Modules\Staffing\Notifications\SelectionTeacherNotice;
use Illuminate\Mail\Mailable;
use Illuminate\Notifications\Messages\MailMessage;
use Symfony\Component\Finder\Finder;

/*
| CE-01 (confirmado el 26 de noviembre de 2026): solo las excepciones declaradas mandan correo a una dirección personal.
| Cada módulo declara las suyas por el nombre completo de la clase (personalMailExceptions() de su provider) y Core las
| junta (PersonalMailExceptions). Aquí se fija la lista completa, que ninguna otra clase implemente AllowsPersonalMail
| (salvo las hijas de una declarada), que solo el flujo de la orden de selección pida la excepción de la invitación y
| que ningún aviso se salga de la guardia con copias o un mailable. Una excepción nueva es una regla nueva: se declara
| en reglas-de-negocio.md, en su provider y aquí.
*/

/**
 * Las excepciones que declaran los providers de bootstrap/providers.php, en su orden.
 *
 * @return array<class-string, string>
 */
function declaredPersonalMailExceptions(): array
{
    $declared = [];

    foreach (require dirname(__DIR__, 2).'/bootstrap/providers.php' as $provider) {
        if (is_subclass_of($provider, ModuleServiceProvider::class)) {
            $declared += (new ReflectionClass($provider))->newInstanceWithoutConstructor()->personalMailExceptions();
        }
    }

    return $declared;
}

/**
 * Las clases de app/Modules (con su archivo) que cumplen `$matches`.
 *
 * @param  Closure(class-string): bool  $matches
 * @return array<class-string, string>
 */
function moduleClassesWhere(Closure $matches, string $folder = 'app/Modules'): array
{
    $root = dirname(__DIR__, 2);
    $classes = [];

    foreach (Finder::create()->files()->name('*.php')->notName('*.blade.php')->in("{$root}/{$folder}") as $file) {
        if (preg_match('/^namespace\s+([^;]+);.*?^(?:final\s+|abstract\s+|readonly\s+)*class\s+(\w+)/sm', $file->getContents(), $match) !== 1) {
            continue;
        }

        $class = "{$match[1]}\\{$match[2]}";

        if (class_exists($class) && $matches($class)) {
            $classes[$class] = $file->getPathname();
        }
    }

    ksort($classes);

    return $classes;
}

it('CE-01: las excepciones declaradas son solo SEL-13 (la invitación) y SEL-15 (los avisos al docente de la selección), por su clase completa', function () {
    expect(declaredPersonalMailExceptions())->toBe([
        AccessInvitation::class => 'SEL-13',
        SelectionTeacherNotice::class => 'SEL-15',
    ]);
});

it('CE-01: solo las clases declaradas, y las que heredan de ellas, implementan AllowsPersonalMail', function () {
    $declared = array_keys(declaredPersonalMailExceptions());
    $offenders = array_keys(moduleClassesWhere(fn (string $class): bool => is_subclass_of($class, AllowsPersonalMail::class)
        && ! in_array($class, $declared, true)
        && array_filter($declared, fn (string $parent): bool => is_subclass_of($class, $parent)) === []));

    expect($offenders)->toBe([]);
});

it('CE-01: cada excepción declarada implementa AllowsPersonalMail y su regla existe en reglas-de-negocio.md', function () {
    $rules = file_get_contents(dirname(__DIR__, 2).'/docs/dominio/reglas-de-negocio.md');

    foreach (declaredPersonalMailExceptions() as $class => $rule) {
        expect(is_subclass_of($class, AllowsPersonalMail::class))->toBeTrue()
            ->and($rules)->toContain("**{$rule}.");
    }
});

it('SEL-13: solo InviteTeacherAccess, en el flujo de la orden de selección y su reenvío, pide la excepción de la invitación', function () {
    $root = dirname(__DIR__, 2);
    $allowed = [
        realpath("{$root}/app/Modules/Core/Actions/SendAccessInvitation.php"),
        realpath("{$root}/app/Modules/Core/Notifications/AccessInvitation.php"),
        realpath("{$root}/app/Modules/Faculty/Actions/InviteTeacherAccess.php"),
    ];
    $offenders = [];

    foreach (Finder::create()->files()->name('*.php')->in(["{$root}/app", "{$root}/database", "{$root}/routes"]) as $file) {
        $source = $file->getContents();

        if (! in_array($file->getRealPath(), $allowed, true) && (preg_match('/\bpersonalMailRule\s*:/', $source) === 1 || str_contains($source, 'new AccessInvitation('))) {
            $offenders[] = substr($file->getPathname(), strlen($root) + 1);
        }
    }

    $flow = new ReflectionMethod(InviteTeacherAccess::class, 'inviteForSelection');
    $lines = file($flow->getFileName());
    $uses = array_keys(array_filter($lines, fn (string $line): bool => str_contains($line, "personalMailRule: 'SEL-13'")));

    expect($offenders)->toBe([])
        ->and($uses)->toHaveCount(1)
        ->and($uses[0] + 1)->toBeGreaterThanOrEqual($flow->getStartLine())->toBeLessThanOrEqual($flow->getEndLine())
        ->and(substr_count(file_get_contents((new ReflectionClass(SendAccessInvitation::class))->getFileName()), 'new AccessInvitation('))->toBe(1);
});

it('CE-01: ningún aviso de los módulos manda copias (cc, bcc) ni arma un mailable que se salte la guardia', function () {
    $mailing = moduleClassesWhere(fn (string $class): bool => method_exists($class, 'toMail'));
    $offenders = [];

    foreach ($mailing as $class => $path) {
        $source = file_get_contents($path);
        $type = (new ReflectionMethod($class, 'toMail'))->getReturnType();

        if (preg_match('/->\s*(cc|bcc)\s*\(/', $source) === 1) {
            $offenders[] = "{$class}: manda copias";
        }

        if (! $type instanceof ReflectionNamedType || $type->getName() !== MailMessage::class || is_a($type->getName(), Mailable::class, true)) {
            $offenders[] = "{$class}: toMail() no devuelve un MailMessage";
        }
    }

    expect($mailing)->not->toBeEmpty()
        ->and($offenders)->toBe([]);
});
