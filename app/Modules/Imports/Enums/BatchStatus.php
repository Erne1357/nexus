<?php

namespace App\Modules\Imports\Enums;

/**
 * Estado de un lote de importación (docs/dominio/modelo-de-datos.md, `import_batches`):
 * uploaded → validating → validated → applying → applied. `failed` es terminal: el archivo no se pudo
 * leer o la aplicación se cortó, y el admin vuelve a subirlo.
 */
enum BatchStatus: string
{
    case Uploaded = 'uploaded';
    case Validating = 'validating';
    case Validated = 'validated';
    case Failed = 'failed';
    case Applying = 'applying';
    case Applied = 'applied';

    public function label(): string
    {
        return match ($this) {
            self::Uploaded => 'Subido',
            self::Validating => 'Validando',
            self::Validated => 'Validado',
            self::Failed => 'Falló',
            self::Applying => 'Aplicando',
            self::Applied => 'Aplicado',
        };
    }
}
