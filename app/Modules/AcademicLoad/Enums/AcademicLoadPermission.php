<?php

namespace App\Modules\AcademicLoad\Enums;

use App\Modules\Core\Authorization\Dimension;
use App\Modules\Core\Authorization\Permission;
use App\Modules\Core\Authorization\UnitApplicability;

/**
 * Permisos del módulo AcademicLoad: la carga académica de un departamento, la propuesta de una carrera y la
 * bandeja donde el departamento decide su parte. La clave es `academic_load.<recurso>.<accion>`. La carga y la
 * bandeja declaran campus y unidad (el departamento); la propuesta, campus y carrera.
 */
enum AcademicLoadPermission: string implements Permission
{
    case LoadView = 'academic_load.load.view';
    case LoadPropose = 'academic_load.load.propose';
    case ProposalCreate = 'academic_load.proposal.create';
    case ProposalUpdate = 'academic_load.proposal.update';
    case ProposalSubmit = 'academic_load.proposal.submit';
    case ProposalView = 'academic_load.proposal.view';
    case ProposalReviewView = 'academic_load.proposal_review.view';
    case ProposalReviewDecide = 'academic_load.proposal_review.decide';

    public function label(): string
    {
        return match ($this) {
            self::LoadView => 'Ver la carga académica de un departamento',
            self::LoadPropose => 'Proponer la carga de un departamento',
            self::ProposalCreate => 'Armar la propuesta de una carrera',
            self::ProposalUpdate => 'Corregir la propuesta de una carrera',
            self::ProposalSubmit => 'Enviar la propuesta de una carrera',
            self::ProposalView => 'Ver la propuesta de una carrera',
            self::ProposalReviewView => 'Ver la bandeja de un departamento',
            self::ProposalReviewDecide => 'Aceptar o regresar la parte del departamento en una propuesta',
        };
    }

    public function dimensions(): array
    {
        return match ($this) {
            self::LoadView, self::LoadPropose, self::ProposalReviewView, self::ProposalReviewDecide => [Dimension::Campus, Dimension::Unit],
            default => [Dimension::Campus, Dimension::Career],
        };
    }

    public function isPersonal(): bool
    {
        return false;
    }

    /**
     * Lo académico (AZ-02, AZ-S16): a los departamentos académicos y a la Subdirección Académica.
     */
    public function appliesTo(): ?UnitApplicability
    {
        return UnitApplicability::academic();
    }
}
