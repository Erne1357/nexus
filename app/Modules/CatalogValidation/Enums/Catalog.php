<?php

namespace App\Modules\CatalogValidation\Enums;

/**
 * Catálogo que el jefe de departamento valida cada periodo (docs/dominio/modelo-de-datos.md,
 * `catalog_reviews`). Docentes, materias, planes y carreras son generales: se revisan una vez por periodo
 * para todo el Tec. Los salones se revisan en cada campus (D2, confirmado el 1 de noviembre de 2026).
 */
enum Catalog: string
{
    case Teachers = 'teachers';
    case Rooms = 'rooms';
    case Subjects = 'subjects';
    case StudyPlans = 'study_plans';
    case Careers = 'careers';

    public function label(): string
    {
        return match ($this) {
            self::Teachers => 'Docentes',
            self::Rooms => 'Salones',
            self::Subjects => 'Materias',
            self::StudyPlans => 'Planes de estudio',
            self::Careers => 'Carreras',
        };
    }

    /**
     * Si se revisa una vez por periodo para todo el Tec (sin campus); si no, se revisa en cada campus.
     */
    public function isGeneral(): bool
    {
        return $this !== self::Rooms;
    }

    /**
     * Los catálogos generales, en el orden del enum.
     *
     * @return list<self>
     */
    public static function general(): array
    {
        return array_values(array_filter(self::cases(), fn (self $catalog): bool => $catalog->isGeneral()));
    }
}
