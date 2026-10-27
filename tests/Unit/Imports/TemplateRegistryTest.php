<?php

use App\Modules\Core\Models\Campus;
use App\Modules\Imports\Templates\TemplateRegistry;
use Tests\Fixtures\ImportTemplates\CampusesTemplate;

/**
 * Plantillas de prueba con una clave y una clave natural a elección, para armar registros inválidos.
 */
class OtherCampusesTemplate extends CampusesTemplate
{
    public function key(): string
    {
        return 'test-other-campuses';
    }
}

class DuplicatedKeyTemplate extends CampusesTemplate {}

class KeyOutsideFieldsTemplate extends CampusesTemplate
{
    public function key(): string
    {
        return 'test-key-outside';
    }

    public function naturalKey(): array
    {
        return ['employee_number'];
    }
}

class EmptyKeyTemplate extends CampusesTemplate
{
    public function key(): string
    {
        return 'test-empty-key';
    }

    public function naturalKey(): array
    {
        return [];
    }
}

class ReservedFieldTemplate extends CampusesTemplate
{
    public function key(): string
    {
        return 'test-reserved';
    }

    public function fields(): array
    {
        return [...parent::fields(), 'deleted_at' => 'baja'];
    }
}

function templateRegistryWith(string ...$templates): TemplateRegistry
{
    return new TemplateRegistry($templates);
}

it('entrega una instancia de la plantilla por su clave', function () {
    $registry = templateRegistryWith(CampusesTemplate::class, OtherCampusesTemplate::class);

    expect($registry->has('test-campuses'))->toBeTrue()
        ->and($registry->get('test-campuses'))->toBeInstanceOf(CampusesTemplate::class)
        ->and($registry->get('test-other-campuses'))->toBeInstanceOf(OtherCampusesTemplate::class);
});

it('lista las plantillas por clave en el orden en que se registraron', function () {
    $registry = templateRegistryWith(OtherCampusesTemplate::class, CampusesTemplate::class);

    expect(array_keys($registry->all()))->toBe(['test-other-campuses', 'test-campuses']);
});

it('una clave que no está registrada es un error', function () {
    expect(templateRegistryWith(CampusesTemplate::class)->has('kardex'))->toBeFalse();

    templateRegistryWith(CampusesTemplate::class)->get('kardex');
})->throws(InvalidArgumentException::class, "No hay plantilla de importación 'kardex'");

it('rechaza dos plantillas con la misma clave', function () {
    templateRegistryWith(CampusesTemplate::class, DuplicatedKeyTemplate::class);
})->throws(InvalidArgumentException::class, "Dos plantillas usan la clave 'test-campuses'");

it('rechaza una clase que no es plantilla', function () {
    templateRegistryWith(Campus::class);
})->throws(InvalidArgumentException::class, 'no extiende ImportTemplate');

it('rechaza una plantilla cuya clave natural no sale de sus campos', function (string $template) {
    templateRegistryWith($template);
})->with([
    'campo que no existe' => KeyOutsideFieldsTemplate::class,
    'clave vacía' => EmptyKeyTemplate::class,
])->throws(InvalidArgumentException::class, 'clave natural');

it('rechaza una plantilla que importa columnas que solo maneja el sistema', function () {
    templateRegistryWith(ReservedFieldTemplate::class);
})->throws(InvalidArgumentException::class, 'deleted_at');
