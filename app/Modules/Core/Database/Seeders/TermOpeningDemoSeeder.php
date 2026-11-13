<?php

namespace App\Modules\Core\Database\Seeders;

use App\Modules\Core\Actions\OpenTerm;
use App\Modules\Core\Database\Seeders\Concerns\RefusesProduction;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Aperturas de periodo del demo: abre cada periodo en las modalidades que se piden (M4) con la Action real, así
 * AcademicLoad crea sus cargas en borrador (las revisiones de catálogo las abre el admin, CV9). La apertura queda a nombre
 * del usuario `admin`. Se salta el periodo que no existe y la modalidad que no existe o está inactiva o borrada.
 * Abrir otra vez solo completa, no duplica. Requiere el catálogo (para tener qué crear) y AdminUserSeeder. No
 * corre en producción.
 */
class TermOpeningDemoSeeder extends Seeder
{
    use RefusesProduction;

    /**
     * @param  array<string, list<string>>  $openings  código del periodo => claves de las modalidades
     *
     * @throws RuntimeException en producción o si falta el usuario admin
     */
    public function run(array $openings = ['20271' => ['PRES']]): void
    {
        $this->refuseProduction();

        $admin = User::query()->where('username', 'admin')->first()
            ?? throw new RuntimeException('Falta el usuario admin (AdminUserSeeder).');

        $guard = Auth::guard();
        $previous = $guard->user();
        $guard->setUser($admin);

        try {
            foreach ($openings as $code => $modalityCodes) {
                $term = Term::query()->where('code', $code)->first();

                if ($term === null) {
                    continue;
                }

                foreach ($modalityCodes as $modalityCode) {
                    $modality = Modality::query()->where('code', $modalityCode)->where('is_active', true)->first();

                    if ($modality !== null) {
                        app(OpenTerm::class)->handle($term, $modality);
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
