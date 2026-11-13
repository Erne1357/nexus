<?php

use App\Modules\Core\Models\ModalityProfile;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo de modalidades con su perfil de flujo (ADR-0025; MS1 y MS2, supuestos por confirmar). Es el primer paso
 * (ampliar) de la carga por modalidad: crea y siembra `modalities` y agrega el puente `campuses.modality_id`, que se
 * quita al contraer. Ningún lector cambia todavía.
 *
 * Siembra (las claves y los nombres quedan congelados aquí):
 * - `PRES` Presencial, activa, con el perfil de los campus presenciales activos sin `heads_edit_rooms`, que es de la
 *   validación de salones y no de la modalidad. Sin ninguno, el perfil por omisión. Si dos difieren en otra clave del
 *   perfil, se detiene con un RuntimeException que lista cada clave con el valor de cada campus.
 * - `EAD`, `ENL` En línea y `TNMV` TecNM Virtual, inactivas y solo con lo documentado (fase 10): las tres con
 *   asignación central; EAD de lunes a sábado con salones; En línea sin salones; TecNM Virtual sin salones, sin
 *   bloques de tiempo (cursos a ritmo propio) y con docentes externos. El resto se define en la fase 10.
 *
 * `campuses.modality_id` (FK nullable) se llena por `campuses.modality` de cada campus, también los borrados, con la
 * equivalencia congelada aquí (la migración no lee el enum, que sale al contraer).
 *
 * Todo up() va en una transacción: si se detiene, no deja la tabla ni la columna.
 *
 * down() quita la columna y la tabla.
 */
return new class extends Migration
{
    /**
     * @var array<string, array{name: string, is_active: bool, profile: array<string, mixed>|null}>
     */
    private const array MODALITIES = [
        'PRES' => ['name' => 'Presencial', 'is_active' => true, 'profile' => null],
        'EAD' => ['name' => 'EAD', 'is_active' => false, 'profile' => [
            'uses_rooms' => true,
            'staffing_mode' => 'central',
            'days' => [1, 2, 3, 4, 5, 6],
        ]],
        'ENL' => ['name' => 'En línea', 'is_active' => false, 'profile' => [
            'uses_rooms' => false,
            'staffing_mode' => 'central',
        ]],
        'TNMV' => ['name' => 'TecNM Virtual', 'is_active' => false, 'profile' => [
            'uses_rooms' => false,
            'uses_time_blocks' => false,
            'staffing_mode' => 'central',
            'allow_external_teachers' => true,
        ]],
    ];

    /**
     * `campuses.modality` → `modalities.code`.
     *
     * @var array<string, string>
     */
    private const array CAMPUS_MODALITIES = [
        'presencial' => 'PRES',
        'mixta' => 'EAD',
        'en_linea' => 'ENL',
        'virtual_tecnm' => 'TNMV',
    ];

    public function up(): void
    {
        DB::transaction(function (): void {
            $presencial = $this->presencialProfile();

            Schema::create('modalities', function (Blueprint $table) {
                $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
                $table->string('code')->unique();
                $table->string('name');
                $table->jsonb('settings')->default('{}');
                $table->boolean('is_active')->default(true);
                $table->timestampsTz();
                $table->softDeletesTz();
            });

            $now = now();

            foreach (self::MODALITIES as $code => $modality) {
                $profile = $modality['profile'] === null ? $presencial : ModalityProfile::fromArray($modality['profile'])->toArray();

                DB::table('modalities')->insert([
                    'code' => $code,
                    'name' => $modality['name'],
                    'settings' => json_encode($profile, JSON_THROW_ON_ERROR),
                    'is_active' => $modality['is_active'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            Schema::table('campuses', function (Blueprint $table) {
                $table->foreignUuid('modality_id')->nullable()->constrained('modalities');
            });

            foreach (self::CAMPUS_MODALITIES as $campusModality => $code) {
                DB::table('campuses')
                    ->where('modality', $campusModality)
                    ->update(['modality_id' => DB::table('modalities')->where('code', $code)->value('id')]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('campuses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('modality_id');
        });

        Schema::dropIfExists('modalities');
    }

    /**
     * El perfil de Presencial: el de los campus presenciales activos y sin borrar, normalizado por ModalityProfile
     * (que completa lo que falta y deja fuera `heads_edit_rooms`). Sin ninguno, el perfil por omisión.
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException si un perfil no es válido o si dos campus difieren en una clave
     */
    private function presencialProfile(): array
    {
        /** @var Collection<string, array<string, mixed>> $profiles */
        $profiles = DB::table('campuses')
            ->where('modality', 'presencial')
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->orderBy('code')
            ->pluck('settings', 'code')
            ->map(fn (?string $settings, string $code): array => $this->normalizedProfile($code, $settings));

        if ($profiles->isEmpty()) {
            return ModalityProfile::fromArray([])->toArray();
        }

        $differences = collect(array_keys(ModalityProfile::DEFAULTS))
            ->filter(fn (string $key): bool => $profiles->map(fn (array $profile): string => $this->encode($profile[$key]))->unique()->count() > 1)
            ->map(fn (string $key): string => $key.' ('.$profiles->map(fn (array $profile, string $code): string => "{$code}: {$this->encode($profile[$key])}")->implode(', ').')');

        if ($differences->isNotEmpty()) {
            throw new RuntimeException(sprintf(
                'Los campus presenciales activos no tienen el mismo perfil de flujo y no se puede sembrar el de Presencial: %s. Iguala esas claves en sus perfiles antes de migrar.',
                $differences->implode('; '),
            ));
        }

        return $profiles->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizedProfile(string $code, ?string $settings): array
    {
        try {
            return ModalityProfile::fromArray(json_decode($settings ?? '{}', true, flags: JSON_THROW_ON_ERROR))->toArray();
        } catch (InvalidArgumentException $exception) {
            throw new RuntimeException("El perfil de flujo del campus {$code} no es válido y no se puede copiar a Presencial: {$exception->getMessage()}", previous: $exception);
        }
    }

    private function encode(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
};
