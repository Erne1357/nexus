<?php

namespace App\Modules\Core\Database\Seeders;

use App\Modules\Core\Enums\Modality;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\CampusProfile;
use Illuminate\Database\Seeder;

/**
 * Los cinco campus con su modalidad y su perfil de flujo completo en `settings`. Cada perfil
 * declara solo lo que los documentos dicen distinto al de CampusProfile::DEFAULTS (presencial de
 * PROJECT.md); lo demás es supuesto por confirmar y se implementa en la fase 10:
 *
 * - EAD: virtual de lunes a jueves y presencial viernes y sábado, con docentes propios (glosario,
 *   PROJECT.md).
 * - TNMV: docentes del TecNM, es decir, externos al ITCJ (glosario, PROJECT.md).
 * - ENL: nada documentado más allá de su modalidad.
 *
 * Solo crea los campus que faltan (también entre los borrados): volver a sembrar no pisa un
 * perfil ya editado.
 */
class CampusSeeder extends Seeder
{
    /**
     * @var list<array{code: string, name: string, modality: Modality, profile: array<string, mixed>}>
     */
    private const array CAMPUSES = [
        ['code' => 'C1', 'name' => 'Campus I', 'modality' => Modality::Presencial, 'profile' => []],
        ['code' => 'C2', 'name' => 'Campus II', 'modality' => Modality::Presencial, 'profile' => []],
        ['code' => 'EAD', 'name' => 'EAD', 'modality' => Modality::Mixta, 'profile' => ['days' => [1, 2, 3, 4, 5, 6]]],
        ['code' => 'ENL', 'name' => 'EnLínea', 'modality' => Modality::EnLinea, 'profile' => []],
        ['code' => 'TNMV', 'name' => 'TecNM Virtual', 'modality' => Modality::VirtualTecnm, 'profile' => ['allow_external_teachers' => true]],
    ];

    public function run(): void
    {
        foreach (self::CAMPUSES as $campus) {
            Campus::withTrashed()->firstOrCreate(['code' => $campus['code']], [
                'name' => $campus['name'],
                'modality' => $campus['modality'],
                'settings' => CampusProfile::fromArray($campus['profile'])->toArray(),
                'is_active' => true,
            ]);
        }
    }
}
