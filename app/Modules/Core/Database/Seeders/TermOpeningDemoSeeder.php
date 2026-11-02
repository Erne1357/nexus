<?php

namespace App\Modules\Core\Database\Seeders;

use App\Modules\Core\Actions\OpenTerm;
use App\Modules\Core\Database\Seeders\Concerns\RefusesProduction;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Aperturas de periodo del demo: abre cada periodo en los campus que se piden con la Action real, así los
 * módulos de la derecha crean sus cargas en borrador y sus revisiones de catálogo. La apertura queda a nombre
 * del usuario `admin`. Se salta el periodo que no existe y el campus que no existe o está inactivo o borrado.
 * Abrir otra vez solo completa, no duplica. Requiere el catálogo (para tener qué crear) y AdminUserSeeder. No
 * corre en producción.
 */
class TermOpeningDemoSeeder extends Seeder
{
    use RefusesProduction;

    /**
     * @param  array<string, list<string>>  $openings  código del periodo => claves de los campus
     *
     * @throws RuntimeException en producción o si falta el usuario admin
     */
    public function run(array $openings = ['20271' => ['C2']]): void
    {
        $this->refuseProduction();

        $admin = User::query()->where('username', 'admin')->first()
            ?? throw new RuntimeException('Falta el usuario admin (AdminUserSeeder).');

        $guard = Auth::guard();
        $previous = $guard->user();
        $guard->setUser($admin);

        try {
            foreach ($openings as $code => $campusCodes) {
                $term = Term::query()->where('code', $code)->first();

                if ($term === null) {
                    continue;
                }

                foreach ($campusCodes as $campusCode) {
                    $campus = Campus::query()->where('code', $campusCode)->where('is_active', true)->first();

                    if ($campus !== null) {
                        app(OpenTerm::class)->handle($term, $campus);
                    }
                }
            }
        } finally {
            if ($previous !== null) {
                $guard->setUser($previous);
            } elseif (method_exists($guard, 'forgetUser')) {
                $guard->forgetUser();
            }
        }
    }
}
