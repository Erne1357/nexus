<?php

namespace App\Modules\CatalogValidation\Enums;

/**
 * Catálogo que se valida cada periodo (docs/dominio/modelo-de-datos.md, `catalog_reviews`). Docentes y salones los
 * valida el jefe de cada departamento, una vez por periodo para todo el Tec, también los salones de todos los campus
 * (decisión 6, confirmada el 12 de noviembre de 2026); materias, planes y carreras, Servicios Escolares, una sola
 * revisión del Tec (CV15, confirmada el 20 de noviembre de 2026; owner()). Los salones solo se exigen donde el campus
 * usa salones (HC-14).
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
     * Quién lo valida (CV15, confirmada el 20 de noviembre de 2026): docentes y salones, cada departamento; materias,
     * planes y carreras, Servicios Escolares para todo el Tec.
     */
    public function owner(): CatalogOwner
    {
        return match ($this) {
            self::Teachers, self::Rooms => CatalogOwner::Department,
            self::Subjects, self::StudyPlans, self::Careers => CatalogOwner::SchoolServices,
        };
    }

    /**
     * Los catálogos que valida `$owner`, en el orden del enum (CV15).
     *
     * @return list<self>
     */
    public static function ownedBy(CatalogOwner $owner): array
    {
        return array_values(array_filter(self::cases(), fn (self $catalog): bool => $catalog->owner() === $owner));
    }

    /**
     * Los que no son salones, en el orden del enum: los exige la carga de cualquier campus (HC-14).
     *
     * @return list<self>
     */
    public static function general(): array
    {
        return array_values(array_filter(self::cases(), fn (self $catalog): bool => $catalog !== self::Rooms));
    }

    /**
     * Los catálogos que exige la carga de un campus para salir de borrador (HC-14), en el orden del enum: todos, y los
     * salones solo si su perfil usa salones.
     *
     * @return list<self>
     */
    public static function requiredFor(bool $usesRooms): array
    {
        return $usesRooms ? self::cases() : self::general();
    }
}
