<?php

namespace App\Modules\AcademicLoad\Enums;

/**
 * En qué va un exporte de lo publicado (PublishedExports::state): sin pedir, en la cola, listo para descargar o
 * fallido. Fallido dura un rato y después se puede volver a pedir.
 */
enum ExportState: string
{
    case Missing = 'missing';
    case Pending = 'pending';
    case Ready = 'ready';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Missing => 'Sin generar',
            self::Pending => 'Preparando',
            self::Ready => 'Listo',
            self::Failed => 'No se pudo generar',
        };
    }
}
