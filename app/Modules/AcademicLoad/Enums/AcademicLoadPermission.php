<?php

namespace App\Modules\AcademicLoad\Enums;

use App\Modules\Core\Authorization\Dimension;
use App\Modules\Core\Authorization\OrgTree;
use App\Modules\Core\Authorization\Permission;
use App\Modules\Core\Authorization\UnitApplicability;
use App\Modules\Core\Enums\OrgUnitKind;

/**
 * Permisos del módulo AcademicLoad: la carga académica de un departamento, la propuesta de una carrera y la
 * bandeja donde el departamento decide su parte. La clave es `academic_load.<recurso>.<accion>`. Se lee a nivel de
 * modalidad y se escribe con campus (AZ-S17, MS6): la carga y la bandeja declaran modalidad y unidad (el
 * departamento); la propuesta, modalidad y carrera, y corregir una fila (de un campus, M7) también el campus; el
 * préstamo de salón o el grupo compartido, que es de un campus, modalidad, campus y unidad.
 */
enum AcademicLoadPermission: string implements Permission
{
    case AgreementView = 'academic_load.agreement.view';
    case AgreementRequest = 'academic_load.agreement.request';
    case AgreementDecide = 'academic_load.agreement.decide';
    case LoadView = 'academic_load.load.view';
    case LoadPropose = 'academic_load.load.propose';
    case LoadStartStaffing = 'academic_load.load.start_staffing';
    case LoadClose = 'academic_load.load.close';
    case LoadReopen = 'academic_load.load.reopen';
    case LoadApprove = 'academic_load.load.approve';
    case LoadExport = 'academic_load.load.export';
    case LoadPublish = 'academic_load.load.publish';
    case LoadPublishApproved = 'academic_load.load.publish_approved';
    case LoadExportSii = 'academic_load.load.export_sii';
    case ProposalCreate = 'academic_load.proposal.create';
    case ProposalUpdate = 'academic_load.proposal.update';
    case ProposalSubmit = 'academic_load.proposal.submit';
    case ProposalView = 'academic_load.proposal.view';
    case ProposalReviewView = 'academic_load.proposal_review.view';
    case ProposalReviewDecide = 'academic_load.proposal_review.decide';

    public function label(): string
    {
        return match ($this) {
            self::AgreementView => 'Ver los acuerdos entre departamentos',
            self::AgreementRequest => 'Pedir un préstamo de salón o proponer un grupo compartido',
            self::AgreementDecide => 'Aceptar o rechazar un acuerdo dirigido al departamento',
            self::LoadView => 'Ver la carga académica de un departamento',
            self::LoadPropose => 'Proponer la carga de un departamento',
            self::LoadStartStaffing => 'Pasar la carga a asignación docente',
            self::LoadClose => 'Cerrar la carga de un departamento',
            self::LoadReopen => 'Reabrir una carga cerrada, aprobada o publicada',
            self::LoadApprove => 'Dar el visto bueno a una carga cerrada',
            self::LoadExport => 'Descargar el PDF por departamento y el Excel de lo publicado',
            self::LoadPublish => 'Publicar la carga cerrada de un campus sin visto bueno',
            self::LoadPublishApproved => 'Publicar la carga que aprobó Subdirección',
            self::LoadExportSii => 'Descargar los archivos para el SII de lo publicado en un campus',
            self::ProposalCreate => 'Armar la propuesta de una carrera',
            self::ProposalUpdate => 'Corregir la propuesta de una carrera',
            self::ProposalSubmit => 'Enviar la propuesta de una carrera',
            self::ProposalView => 'Ver la propuesta de una carrera',
            self::ProposalReviewView => 'Ver la bandeja de un departamento',
            self::ProposalReviewDecide => 'Aceptar o regresar la parte del departamento en una propuesta',
        };
    }

    /**
     * Pedir un préstamo y corregir la fila de un campus declaran el campus (MS6). Decidir un acuerdo no: el jefe decide
     * los de toda la modalidad de su departamento, de cualquier campus, porque ningún jefe de departamento está limitado
     * a un campus (M12, confirmado el 12 de noviembre de 2026).
     */
    public function dimensions(): array
    {
        return match ($this) {
            self::AgreementRequest => [Dimension::Modality, Dimension::Campus, Dimension::Unit],
            self::ProposalUpdate => [Dimension::Modality, Dimension::Career, Dimension::Campus],
            self::AgreementView, self::AgreementDecide, self::LoadView, self::LoadPropose, self::LoadStartStaffing, self::LoadClose, self::LoadReopen, self::LoadApprove, self::LoadPublish, self::LoadPublishApproved, self::LoadExport, self::LoadExportSii, self::ProposalReviewView, self::ProposalReviewDecide => [Dimension::Modality, Dimension::Unit],
            self::ProposalCreate, self::ProposalSubmit, self::ProposalView => [Dimension::Modality, Dimension::Career],
        };
    }

    public function isPersonal(): bool
    {
        return false;
    }

    /**
     * Lo académico (AZ-02, AZ-S16): a los departamentos académicos y a la Subdirección Académica. Los exportes del
     * personal de lo publicado (`LoadExport`) también al Departamento de Servicios Escolares, que captura la carga en
     * el SII (supuesto por confirmar). Los archivos para el SII (`LoadExportSii`) solo a Servicios Escolares y a la
     * Subdirección Académica, no a los departamentos académicos (supuesto por confirmar).
     */
    public function appliesTo(): ?UnitApplicability
    {
        return match ($this) {
            self::LoadExport => UnitApplicability::of([OrgUnitKind::DepartamentoAcademico], [OrgTree::ACADEMIC, OrgTree::SCHOOL_SERVICES]),
            self::LoadExportSii => UnitApplicability::of([], [OrgTree::ACADEMIC, OrgTree::SCHOOL_SERVICES]),
            default => UnitApplicability::academic(),
        };
    }
}
