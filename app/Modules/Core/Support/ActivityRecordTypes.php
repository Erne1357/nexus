<?php

namespace App\Modules\Core\Support;

/**
 * El nombre en español del «tipo de registro» de una entrada de la bitácora (`subject_type`, CF-S5): un mapa de los
 * modelos que Nexus conoce, por el nombre corto de su clase, y, para los que no, ese mismo nombre corto. Se busca por
 * el nombre corto y no por la clase completa porque Core no puede nombrar los módulos que están a su derecha
 * (arquitectura 5).
 */
final class ActivityRecordTypes
{
    /** @var array<string, string> */
    private const array LABELS = [
        'User' => 'Usuario',
        'Campus' => 'Campus',
        'Modality' => 'Modalidad',
        'OrgUnit' => 'Unidad del organigrama',
        'RoleAssignment' => 'Nombramiento',
        'Setting' => 'Parámetro',
        'Term' => 'Periodo',
        'TermOpening' => 'Apertura de periodo',
        'TermStage' => 'Etapa del periodo',
        'LoginNotice' => 'Aviso del login',
        'LoginShortcut' => 'Atajo del login',
        'Career' => 'Carrera',
        'CareerCampus' => 'Oferta de carrera por campus',
        'Department' => 'Departamento',
        'Specialty' => 'Especialidad',
        'StudyPlan' => 'Plan de estudios',
        'StudyPlanSubject' => 'Materia del plan',
        'Subject' => 'Materia',
        'SubjectAlias' => 'Alias de materia',
        'AcademicLoad' => 'Carga académica',
        'CareerProposal' => 'Propuesta de carrera',
        'InterdepartmentalAgreement' => 'Acuerdo interdepartamental',
        'CatalogReview' => 'Validación de catálogo',
        'Building' => 'Edificio',
        'Room' => 'Salón',
        'RoomBlock' => 'Bloqueo de salón',
        'Teacher' => 'Docente',
        'TeacherAvailability' => 'Disponibilidad de docente',
        'TeacherExternalLoad' => 'Carga externa de docente',
        'ImportBatch' => 'Lote de importación',
        'Group' => 'Grupo',
        'GroupSession' => 'Sesión de grupo',
        'ChangeRequest' => 'Solicitud de cambio',
        'GroupTeacherAssignment' => 'Asignación docente',
        'Student' => 'Alumno',
    ];

    /**
     * El nombre del tipo; sin mapa, el nombre corto de la clase, y sin tipo, «Sin registro».
     */
    public static function label(?string $type): string
    {
        if ($type === null || $type === '') {
            return 'Sin registro';
        }

        $short = class_basename($type);

        return self::LABELS[$short] ?? $short;
    }
}
