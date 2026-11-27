<?php

use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Imports\Models\ImportRow;
use App\Modules\Imports\Templates\ImportContext;
use Tests\Fixtures\ImportTemplates\CampusesTemplate;

/**
 * Una variante de la plantilla de prueba cuya clave natural es por campus, como `rooms.code`.
 */
function campusScopedImportTemplate(): CampusesTemplate
{
    return new class extends CampusesTemplate
    {
        public function requiresCampus(): bool
        {
            return true;
        }
    };
}

function importContextFor(?Campus $campus = null): ImportContext
{
    return new ImportContext(new User, $campus);
}

describe('normalize', function () {
    it('recorta, junta los espacios repetidos y convierte el texto vacío en null', function () {
        $row = (new CampusesTemplate)->normalize([
            'code' => '  c2 ',
            'name' => "Campus   Dos\t ",
            'modality' => '   ',
        ]);

        expect($row)->toBe(['code' => 'C2', 'name' => 'Campus Dos', 'modality' => null]);
    });

    it('deja solo los campos de la plantilla, en su orden, y completa con null los que faltan', function () {
        $row = (new CampusesTemplate)->normalize(['name' => 'Campus Dos', 'extra' => 'x', 'code' => 'C2']);

        expect($row)->toBe(['code' => 'C2', 'name' => 'Campus Dos', 'modality' => null]);
    });

    it('deja los números como vienen y pasa las fechas a texto ISO, como se guardan en la fila', function () {
        $row = (new CampusesTemplate)->normalize([
            'code' => new DateTimeImmutable('2027-01-15 00:00:00'),
            'name' => 12,
            'modality' => new DateTimeImmutable('2027-01-15 08:30:00'),
        ]);

        expect($row)->toBe(['code' => '2027-01-15', 'name' => 12, 'modality' => '2027-01-15 08:30:00']);
    });
});

describe('keyFor', function () {
    it('arma la clave natural con los campos de la fila', function () {
        $template = new CampusesTemplate;

        expect($template->keyFor(['code' => 'C2', 'name' => 'Campus Dos'], importContextFor()))
            ->toBe(['code' => 'C2']);
    });

    it('agrega el campus del contexto cuando la plantilla lo requiere', function () {
        $campus = (new Campus)->forceFill(['id' => fakeKey(2)]);

        expect(campusScopedImportTemplate()->keyFor(['code' => 'LC1'], importContextFor($campus)))
            ->toBe(['code' => 'LC1', 'campus_id' => fakeKey(2)]);
    });

    it('sin campus en el contexto, una plantilla que lo requiere es un error de programación', function () {
        campusScopedImportTemplate()->keyFor(['code' => 'LC1'], importContextFor());
    })->throws(LogicException::class, 'necesita un campus');
});

describe('deactivatesMissing', function () {
    it('un catálogo con is_active ofrece desactivar faltantes', function () {
        expect((new CampusesTemplate)->deactivatesMissing())->toBeTrue();
    });

    it('un modelo sin is_active, como el kardex, no lo ofrece', function () {
        $template = new class extends CampusesTemplate
        {
            public function model(): string
            {
                return ImportRow::class;
            }
        };

        expect($template->deactivatesMissing())->toBeFalse();
    });
});
