<?php

namespace App\Modules\Faculty\Enums;

/**
 * `teacher_subject_history.source`: importado del SII o registrado por Nexus al publicar cargas.
 */
enum HistorySource: string
{
    case Import = 'import';
    case Nexus = 'nexus';

    public function label(): string
    {
        return match ($this) {
            self::Import => 'Importado',
            self::Nexus => 'Nexus',
        };
    }
}
