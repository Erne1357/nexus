<?php

namespace App\Modules\Scheduling\Enums;

/**
 * Qué propone una sugerencia del re-análisis (tarea 8.3a, ReanalysisAdvisor): ampliar el cupo de una carrera en un grupo
 * (faltante), cambiar el salón de un grupo para que quepan más (faltante que no cabe en su salón), cerrar un grupo
 * (sobrante) o abrir uno nuevo, que llega con la sugerencia del motor (tarea 8.3b) y aquí solo se avisa.
 */
enum ReanalysisSuggestionKind: string
{
    case ExpandQuota = 'expand_quota';
    case ChangeRoom = 'change_room';
    case OpenGroup = 'open_group';
    case CloseGroup = 'close_group';

    public function label(): string
    {
        return match ($this) {
            self::ExpandQuota => 'Ampliar cupo',
            self::ChangeRoom => 'Cambiar salón',
            self::OpenGroup => 'Abrir grupo',
            self::CloseGroup => 'Cerrar grupo',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::ExpandQuota => 'o-arrows-pointing-out',
            self::ChangeRoom => 'o-map-pin',
            self::OpenGroup => 'o-plus-circle',
            self::CloseGroup => 'o-archive-box-x-mark',
        };
    }

    /**
     * Si suma lugares a una carrera: lo que el jefe puede editar antes de aplicar.
     */
    public function addsSeats(): bool
    {
        return $this === self::ExpandQuota || $this === self::ChangeRoom;
    }
}
