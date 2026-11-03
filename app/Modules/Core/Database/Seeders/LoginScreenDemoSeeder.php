<?php

namespace App\Modules\Core\Database\Seeders;

use App\Modules\Core\Database\Seeders\Concerns\RefusesProduction;
use App\Modules\Core\Enums\LoginShortcutKind;
use App\Modules\Core\Models\LoginNotice;
use App\Modules\Core\Models\LoginShortcut;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Avisos y atajos de ejemplo de la pantalla de acceso (tarea 1.17): un aviso con la foto del edificio del ITCJ,
 * otro sin foto y dos enlaces. La foto se copia al disco público desde resources/images/login. Correrlo otra vez
 * no duplica: busca por título. No corre en producción.
 */
class LoginScreenDemoSeeder extends Seeder
{
    use RefusesProduction;

    public const string PHOTO = 'login/avisos/edificio-itcj.webp';

    /**
     * @throws RuntimeException en producción
     */
    public function run(): void
    {
        $this->refuseProduction();

        $public = Storage::disk('public');
        if (! $public->exists(self::PHOTO)) {
            $public->put(self::PHOTO, (string) file_get_contents(resource_path('images/login/edificio-itcj.webp')));
        }

        LoginNotice::query()->firstOrCreate(
            ['title' => 'Reinscripciones Ene–Jun 2027'],
            ['image_path' => self::PHOTO, 'sort_order' => 1],
        );
        LoginNotice::query()->firstOrCreate(
            ['title' => 'Vacaciones de invierno'],
            ['sort_order' => 2],
        );

        LoginShortcut::query()->firstOrCreate(
            ['title' => 'Sitio del TecNM'],
            ['kind' => LoginShortcutKind::Link, 'description' => 'tecnm.mx', 'url' => 'https://www.tecnm.mx', 'sort_order' => 1],
        );
        LoginShortcut::query()->firstOrCreate(
            ['title' => 'Sitio del ITCJ'],
            ['kind' => LoginShortcutKind::Link, 'description' => 'cdjuarez.tecnm.mx', 'url' => 'https://www.cdjuarez.tecnm.mx', 'sort_order' => 2],
        );
    }
}
