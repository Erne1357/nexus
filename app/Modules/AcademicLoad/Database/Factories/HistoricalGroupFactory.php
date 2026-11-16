<?php

namespace App\Modules\AcademicLoad\Database\Factories;

use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Enums\HistoricalGroupSource;
use App\Modules\AcademicLoad\Models\HistoricalGroup;
use App\Modules\AcademicLoad\Models\LoadSnapshot;
use App\Modules\Core\Models\Modality;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Un grupo histórico del SII de Ene-Jun 2026 (20261), de 35 de cupo y 30 inscritos, sin campus ni carrera exclusiva.
 * `fromSii()` y `fromNexus()` fijan la fuente; este último pide una versión del snapshot y deja los inscritos en nulo,
 * como en la fuente real (en R1 la inscripción sigue en el SII).
 *
 * @extends Factory<HistoricalGroup>
 */
#[UseModel(HistoricalGroup::class)]
class HistoricalGroupFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'term_code' => '20261',
            'modality_id' => fn (): string => Modality::query()->where('code', 'PRES')->value('id') ?? Modality::factory()->create()->getKey(),
            'campus_id' => null,
            'subject_id' => Subject::factory(),
            'group_code' => fake()->unique()->bothify('?#'),
            'sii_subject_code' => null,
            'capacity' => 35,
            'enrolled' => 30,
            'career_id' => null,
            'parallel_of' => null,
            'source' => HistoricalGroupSource::Sii,
            'load_snapshot_id' => null,
            'recorded_at' => now(),
        ];
    }

    public function fromSii(): static
    {
        return $this->state(['source' => HistoricalGroupSource::Sii, 'load_snapshot_id' => null]);
    }

    public function fromNexus(?LoadSnapshot $snapshot = null): static
    {
        return $this->state(['source' => HistoricalGroupSource::Nexus, 'enrolled' => null, 'load_snapshot_id' => $snapshot?->getKey() ?? LoadSnapshot::factory()]);
    }
}
