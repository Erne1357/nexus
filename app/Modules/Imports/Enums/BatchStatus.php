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

    /**
     * Si la cola está trabajando el lote: la pantalla se refresca sola mientras tanto.
     */
    public function inProgress(): bool
    {
        return in_array($this, [self::Uploaded, self::Validating, self::Applying], true);
    }

    /**
     * Clase de la insignia de estado, con la semántica de colores de DESIGN.md.
     */
    public function badgeClass(): string
    {
        return match ($this) {
            self::Uploaded, self::Validating, self::Applying => 'badge-info badge-soft',
            self::Validated, self::Applied => 'badge-success badge-soft',
            self::Failed => 'badge-error badge-soft',
        };
    }
}
