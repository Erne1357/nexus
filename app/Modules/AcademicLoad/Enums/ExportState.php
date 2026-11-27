<?php

namespace App\Modules\AcademicLoad\Enums;

/**
 * En qué va un exporte de lo publicado (PublishedExports::state): sin pedir, en la cola, en la cola desde hace más de lo
 * normal (la cola no lo tomó o no lo terminó a tiempo: se puede reintentar), listo para descargar o fallido. Fallido dura
 * un rato y después se puede volver a pedir.
 */
enum ExportState: string
{
    case Missing = 'missing';
    case Pending = 'pending';
    case Stalled = 'stalled';
    case Ready = 'ready';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Missing => 'Sin generar',
            self::Pending => 'Preparando',
            self::Stalled => 'Tardando más de lo normal',
            self::Ready => 'Listo',
            self::Failed => 'No se pudo generar',
        };
    }
}
