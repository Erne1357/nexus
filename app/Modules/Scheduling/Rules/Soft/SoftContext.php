<?php

namespace App\Modules\Scheduling\Rules\Soft;

/**
 * Lo que las reglas blandas leen y la foto del validador no trae (tarea 6.5): las cohortes de cada grupo (SC-01; las de
 * Rules/Cohorts, o las que trae el contrato del motor), los bloques preferidos de cada docente (SC-04, SC-08), el
 * edificio de cada salón (SC-06) y los salones por defecto o sugeridos de cada materia en cada campus (SC-03). Las
 * horas van en minutos desde la medianoche, como en la foto.
 */
final readonly class SoftContext
{
    /**
     * @param  array<string, list<string>>  $cohorts  claves de cohorte, por grupo
     * @param  array<string, list<array{int, int, int}>>  $preferred  [día, inicio, fin] preferidos, por docente
     * @param  array<string, string>  $buildings  edificio, por salón
     * @param  array<string, array<string, int>>  $roomDefaults  por «materia|campus», la prioridad de cada salón
     */
    public function __construct(
        public array $cohorts = [],
        public array $preferred = [],
        public array $buildings = [],
        public array $roomDefaults = [],
    ) {}

    /**
     * @return array<string, int> prioridad, por salón
     */
    public function roomDefaultsOf(string $subjectId, string $campusId): array
    {
        return $this->roomDefaults["{$subjectId}|{$campusId}"] ?? [];
    }
}
