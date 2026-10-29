<?php

namespace App\Modules\Imports\Enums;

use App\Modules\Core\Enums\StatusTone;

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
     * Tono de la insignia de estado (DESIGN.md, "Insignias de estado"): lo que la cola trabaja va en
     * info, lo validado en éxito suave, lo aplicado en éxito sólido (ya está en el catálogo, como lo
     * publicado) y lo que falló en error.
     */
    public function tone(): StatusTone
    {
        return match ($this) {
            self::Uploaded, self::Validating, self::Applying => StatusTone::Info,
            self::Validated => StatusTone::Success,
            self::Applied => StatusTone::SuccessSolid,
            self::Failed => StatusTone::Error,
        };
    }

    /**
     * Icono de la insignia cuando no es el de su tono: lo que espera en la cola o se aplica lleva el
     * reloj de «en cola»; null deja el del tono.
     */
    public function icon(): ?string
    {
        return in_array($this, [self::Uploaded, self::Applying], true) ? 'o-clock' : null;
    }
}
