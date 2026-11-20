<?php

namespace App\Modules\CatalogValidation\Queries;

use App\Modules\Academic\Models\Department;
use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Enums\CatalogOwner;
use App\Modules\CatalogValidation\Models\CatalogReview;

/**
 * De quién son unas revisiones de catálogo (CV15, confirmada el 20 de noviembre de 2026): un departamento académico,
 * que valida sus docentes y sus salones, o Servicios Escolares, que valida las materias, los planes y las carreras de
 * todo el Tec. Lo que la pantalla de la revisión ofrece en «Departamento» y lo que el tablero de avance muestra por
 * renglón.
 *
 * `id` es el del departamento o SCHOOL_SERVICES, que es lo que lleva `?department=` en la pantalla de la revisión
 * (CatalogReview::owner_id). Solo lleva valores, así que viaja en un aviso en cola.
 */
final readonly class ReviewOwner
{
    /** El `?department=` de las revisiones de Servicios Escolares, que no tienen departamento. */
    public const string SCHOOL_SERVICES = CatalogReview::SCHOOL_SERVICES;

    private function __construct(
        public string $id,
        public string $code,
        public string $name,
        public ?string $departmentId,
    ) {}

    public static function ofDepartment(Department $department): self
    {
        return new self($department->getKey(), $department->code, $department->name, $department->getKey());
    }

    /**
     * De quién es una revisión: su departamento (con la relación `department`; uno borrado se nombra así) o, sin
     * departamento, Servicios Escolares.
     */
    public static function of(CatalogReview $review): self
    {
        return match (true) {
            $review->department_id === null => self::schoolServices(),
            $review->department !== null => self::ofDepartment($review->department),
            default => new self($review->department_id, '', 'Un departamento borrado', $review->department_id),
        };
    }

    public static function schoolServices(): self
    {
        return new self(self::SCHOOL_SERVICES, 'SE', 'Servicios Escolares', null);
    }

    public function isSchoolServices(): bool
    {
        return $this->departmentId === null;
    }

    /**
     * Los catálogos que valida, en el orden del enum (CV15).
     *
     * @return list<Catalog>
     */
    public function catalogs(): array
    {
        return Catalog::ownedBy($this->isSchoolServices() ? CatalogOwner::SchoolServices : CatalogOwner::Department);
    }

    /**
     * Cómo se nombra en una lista para elegirlo: «SC · Sistemas y Computación» o «Servicios Escolares».
     */
    public function label(): string
    {
        return $this->isSchoolServices() ? $this->name : "{$this->code} · {$this->name}";
    }
}
