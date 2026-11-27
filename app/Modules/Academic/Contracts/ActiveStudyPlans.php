<?php

namespace App\Modules\Academic\Contracts;

/**
 * Las retículas activas, para la lista de Materias del jefe de departamento (SEL-19): planes de estudio con al menos un
 * alumno activo. Academic no conoce a los alumnos, que están a su derecha en el mapa de módulos: liga
 * AllStudyPlansActive (sin Students todas las retículas cuentan, como si el filtro no existiera) y Students lo
 * reemplaza en su register().
 */
interface ActiveStudyPlans
{
    /**
     * @return list<string>|null ids de los planes activos; null si no se puede decidir y no se restringe nada
     */
    public function ids(): ?array;
}
