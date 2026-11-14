<?php

namespace App\Modules\Core\Support;

/**
 * El nombre en español del «tipo de registro» de una entrada de la bitácora (`subject_type`, CF-S5): un mapa de los
 * modelos que Nexus conoce y, para los que no, el nombre corto de la clase. Las claves son texto y no `::class`, porque
 * Core no depende de los módulos que están a su derecha (arquitectura 5).
 */
final class ActivityRecordTypes
{
    /** @var array<string, string> */
    private const array LABELS = [
        'App\Modules\Core\Models\User' => 'Usuario',
        'App\Modules\Core\Models\Campus' => 'Campus',
        'App\Modules\Core\Models\Modality' => 'Modalidad',
        'App\Modules\Core\Models\OrgUnit' => 'Unidad del organigrama',
        'App\Modules\Core\Models\RoleAssignment' => 'Nombramiento',
        'App\Modules\Core\Models\Setting' => 'Parámetro',
        'App\Modules\Core\Models\Term' => 'Periodo',
        'App\Modules\Core\Models\TermOpening' => 'Apertura de periodo',
        'App\Modules\Core\Models\TermStage' => 'Etapa del periodo',
        'App\Modules\Core\Models\LoginNotice' => 'Aviso del login',
        'App\Modules\Core\Models\LoginShortcut' => 'Atajo del login',
        'App\Modules\Academic\Models\Career' => 'Carrera',
        'App\Modules\Academic\Models\CareerCampus' => 'Oferta de carrera por campus',
        'App\Modules\Academic\Models\Department' => 'Departamento',
        'App\Modules\Academic\Models\Specialty' => 'Especialidad',
        'App\Modules\Academic\Models\StudyPlan' => 'Plan de estudios',
        'App\Modules\Academic\Models\StudyPlanSubject' => 'Materia del plan',
        'App\Modules\Academic\Models\Subject' => 'Materia',
        'App\Modules\Academic\Models\SubjectAlias' => 'Alias de materia',
        'App\Modules\AcademicLoad\Models\AcademicLoad' => 'Carga académica',
        'App\Modules\AcademicLoad\Models\CareerProposal' => 'Propuesta de carrera',
        'App\Modules\AcademicLoad\Models\InterdepartmentalAgreement' => 'Acuerdo interdepartamental',
        'App\Modules\CatalogValidation\Models\CatalogReview' => 'Validación de catálogo',
        'App\Modules\Facilities\Models\Building' => 'Edificio',
        'App\Modules\Facilities\Models\Room' => 'Salón',
        'App\Modules\Facilities\Models\RoomBlock' => 'Bloqueo de salón',
        'App\Modules\Faculty\Models\Teacher' => 'Docente',
        'App\Modules\Faculty\Models\TeacherAvailability' => 'Disponibilidad de docente',
        'App\Modules\Faculty\Models\TeacherExternalLoad' => 'Carga externa de docente',
        'App\Modules\Imports\Models\ImportBatch' => 'Lote de importación',
        'App\Modules\Scheduling\Models\Group' => 'Grupo',
        'App\Modules\Scheduling\Models\GroupSession' => 'Sesión de grupo',
        'App\Modules\Staffing\Models\ChangeRequest' => 'Solicitud de cambio',
        'App\Modules\Staffing\Models\GroupTeacherAssignment' => 'Asignación docente',
        'App\Modules\Students\Models\Student' => 'Alumno',
    ];

    /**
     * El nombre del tipo; sin mapa, el nombre corto de la clase, y sin tipo, «Sin registro».
     */
    public static function label(?string $type): string
    {
        if ($type === null || $type === '') {
            return 'Sin registro';
        }

        return self::LABELS[$type] ?? class_basename($type);
    }
}
