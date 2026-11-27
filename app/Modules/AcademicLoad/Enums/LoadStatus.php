<?php

namespace App\Modules\AcademicLoad\Enums;

use App\Modules\Core\Enums\Stage;
use App\Modules\Core\Enums\StatusTone;
use App\Modules\Core\Models\ModalityProfile;

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
     * El estado dentro de una frase, después de su sujeto («La carga de Ciencias Básicas en Presencial …»), con la
     * etiqueta en minúsculas: los estados que son un participio van con «ya está» («ya está publicada», «ya está
     * cerrada», «ya está aprobada») y los que son una etapa, con «está en» («está en ajustes», «está en re-análisis»).
     * Ensayo 8.11, E-07: «está en publicada» no se lee.
     */
    public function predicate(): string
    {
        $label = mb_strtolower($this->label());

        return match ($this) {
            self::Closed, self::Approved, self::Published => "ya está {$label}",
            default => "está en {$label}",
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
     * carga académica" de docs/proceso/proceso-propuesto.md y el perfil de la modalidad de la carga (M1). Es la única
     * fuente: TransitionLoad la consulta. Desde Cerrada, una modalidad que exige el visto bueno de Subdirección
     * (`requires_subdireccion_approval`) pasa a Aprobada y de ahí a Publicada; una que no lo exige publica
     * directo. Aprobada solo existe en las modalidades que lo exigen. Cerrada, Aprobada y Publicada se reabren a Ajustes,
     * con motivo (ReopenLoad; P3, confirmado el 10 de noviembre de 2026).
     *
     * El re-análisis (fase 8, RA-01 a RA-08) es otro ciclo que sale de Publicada y vuelve a ella por el cierre: Publicada
     * pasa a Re-análisis (StartReanalysis), de ahí a Cerrada (CloseLoad) y sigue como la primera vez, con el visto bueno
     * si la modalidad lo exige (RA-03) y la publicación de la versión n + 1. Cerrada y Aprobada también tienen a
     * Re-análisis como destino: es como Subdirección devuelve una carga re-analizada (RA-08), que ReopenLoad elige en
     * lugar de Ajustes cuando el último cierre vino de Re-análisis. Desde Re-análisis no se publica: la única flecha a Publicada es
     * la de cancelar el re-análisis sin cambios (CancelReanalysis, RA-10), que vuelve a la versión vigente sin una nueva; PublishLoad la
     * rechaza (publicar exige Cerrada o Aprobada) y solo CancelReanalysis la toma.
     *
     * @return list<self>
     */
    public function transitions(ModalityProfile $profile): array
    {
        return match ($this) {
            self::Draft => [self::Proposed],
            self::Proposed => [self::Staffing],
            self::Staffing => [self::Adjusting, self::Closed],
            self::Adjusting => [self::Staffing],
            self::Closed => [$profile->requiresSubdireccionApproval ? self::Approved : self::Published, self::Adjusting, self::Reanalysis],
            self::Approved => [self::Published, self::Adjusting, self::Reanalysis],
            self::Published => [self::Reanalysis, self::Adjusting],
            self::Reanalysis => [self::Closed, self::Published],
        };
    }

    /**
     * Las etapas del proceso en que está una carga en este estado (MN-06): las pantallas de esas etapas son las que ven y
     * abren el jefe de departamento y el JPD (AcademicLoadVisibility::ROUTE_STAGES). Supuesto por confirmar, sobre los
     * ejemplos del usuario (26 de noviembre de 2026): en borrador ya corre la orden de selección, así que el jefe asigna y ve
     * cómo va la asignación; Salones y acuerdos acompañan a Horarios desde la propuesta. La propuesta sigue abierta en
     * propuesta: la carga pasa a propuesta al aceptar la primera parte (AcceptProposalReview), y el jefe sigue aceptando las
     * de las demás carreras y armando paralelos en la bandeja.
     *
     * @return list<Stage>
     */
    public function stages(): array
    {
        return match ($this) {
            self::Draft => [Stage::Proposal, Stage::Scheduling, Stage::Staffing],
            self::Proposed => [Stage::Proposal, Stage::Scheduling, Stage::Rooms, Stage::Staffing],
            self::Staffing, self::Adjusting => [Stage::Scheduling, Stage::Rooms, Stage::Staffing, Stage::Closing],
            self::Closed, self::Approved => [Stage::Closing, Stage::Publication],
            self::Published => [Stage::Publication, Stage::Reanalysis],
            self::Reanalysis => [Stage::Scheduling, Stage::Rooms, Stage::Staffing, Stage::Reanalysis, Stage::Publication],
        };
    }

    /**
     * Si en este estado se asignan y quitan docentes: Asignación, Ajustes y Re-análisis (el jefe ajusta la carga re-analizada
     * y RA-05 avisa a los docentes afectados al republicar). Las solicitudes de cambio siguen limitadas a Asignación y Ajustes.
     */
    public function acceptsTeacherAssignment(): bool
    {
        return in_array($this, [self::Staffing, self::Adjusting, self::Reanalysis], true);
    }

    /**
     * Si la carga todavía puede abrirse a la selección por turnos de los docentes (SEL-11): Borrador, Propuesta y
     * Ajustes. Asignación ya está abierta; de Cerrada en adelante la carga ya no se asigna.
     */
    public function opensForSelection(): bool
    {
        return in_array($this, [self::Draft, self::Proposed, self::Adjusting], true);
    }

    public function canTransitionTo(self $to, ModalityProfile $profile): bool
    {
        return in_array($to, $this->transitions($profile), true);
    }
}
