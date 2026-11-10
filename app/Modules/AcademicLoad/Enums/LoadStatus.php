<?php

namespace App\Modules\AcademicLoad\Enums;

use App\Modules\Core\Enums\StatusTone;
use App\Modules\Core\Models\CampusProfile;

/**
 * Estado de una carga académica (docs/dominio/modelo-de-datos.md, `academic_loads`; el diagrama de
 * transiciones está en docs/proceso/proceso-propuesto.md y son `transitions()`).
 */
enum LoadStatus: string
{
    case Draft = 'draft';
    case Proposed = 'proposed';
    case Staffing = 'staffing';
    case Adjusting = 'adjusting';
    case Closed = 'closed';
    case Approved = 'approved';
    case Published = 'published';
    case Reanalysis = 'reanalysis';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Borrador',
            self::Proposed => 'Propuesta',
            self::Staffing => 'Asignación',
            self::Adjusting => 'Ajustes',
            self::Closed => 'Cerrada',
            self::Approved => 'Aprobada',
            self::Published => 'Publicada',
            self::Reanalysis => 'Re-análisis',
        };
    }

    /**
     * Tono de la insignia de estado (DESIGN.md, "Insignias de estado"): el borrador en su tono; lo que se
     * está trabajando (propuesta, asignación, ajustes) en info; lo cerrado y lo aprobado en éxito suave; lo
     * publicado en éxito sólido, y el re-análisis en advertencia, porque reabre una carga ya publicada.
     */
    public function tone(): StatusTone
    {
        return match ($this) {
            self::Draft => StatusTone::Draft,
            self::Proposed, self::Staffing, self::Adjusting => StatusTone::Info,
            self::Closed, self::Approved => StatusTone::Success,
            self::Published => StatusTone::SuccessSolid,
            self::Reanalysis => StatusTone::Warning,
        };
    }

    /**
     * Los estados a los que puede pasar la carga desde este, según el diagrama de "Máquina de estados de la
     * carga académica" de docs/proceso/proceso-propuesto.md y el perfil del campus. Es la única fuente:
     * TransitionLoad la consulta. Desde Cerrada, un campus que exige el visto bueno de Subdirección
     * (`requires_subdireccion_approval`) pasa a Aprobada y de ahí a Publicada; uno que no lo exige publica
     * directo. Aprobada solo existe en los campus que lo exigen. Cerrada, Aprobada y Publicada se reabren a Ajustes,
     * con motivo (ReopenLoad; P3, confirmado el 10 de noviembre de 2026).
     *
     * @return list<self>
     */
    public function transitions(CampusProfile $profile): array
    {
        return match ($this) {
            self::Draft => [self::Proposed],
            self::Proposed => [self::Staffing],
            self::Staffing => [self::Adjusting, self::Closed],
            self::Adjusting => [self::Staffing],
            self::Closed => [$profile->requiresSubdireccionApproval ? self::Approved : self::Published, self::Adjusting],
            self::Approved => [self::Published, self::Adjusting],
            self::Published => [self::Reanalysis, self::Adjusting],
            self::Reanalysis => [self::Published],
        };
    }

    public function canTransitionTo(self $to, CampusProfile $profile): bool
    {
        return in_array($to, $this->transitions($profile), true);
    }
}
