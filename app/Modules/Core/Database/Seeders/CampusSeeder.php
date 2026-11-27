<?php

namespace App\Modules\Core\Database\Seeders;

use App\Modules\Core\Models\Campus;
use Illuminate\Database\Seeder;

/**
 * Los campus del Tec, los lugares físicos donde se dan los grupos: Campus I y Campus II (M2; MS3, supuesto por
 * confirmar). EAD, En línea y TecNM Virtual son modalidades del catálogo `modalities`, no campus (M2, M3).
 *
 * Crea los que faltan (también entre los borrados); a uno existente no lo toca.
 */
class CampusSeeder extends Seeder
{
    /**
     * @var list<array{code: string, name: string}>
     */
    private const array CAMPUSES = [
        ['code' => 'C1', 'name' => 'Campus I'],
        ['code' => 'C2', 'name' => 'Campus II'],
    ];

    public function run(): void
    {
        foreach (self::CAMPUSES as $campus) {
            Campus::withTrashed()->firstOrCreate(['code' => $campus['code']], ['name' => $campus['name'], 'is_active' => true]);
        }
    }
}
