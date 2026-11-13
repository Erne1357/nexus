<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Events\ModalityDeactivating;
use App\Modules\Core\Events\ModalityUpdated;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Rules\ModalityRules;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Cambia los datos, el estado (activar o desactivar) o el perfil de flujo de una modalidad: solo las claves que recibe.
 * Quién puede cambiar qué lo decide ModalityPolicy antes de llamarla. Hace cumplir las mismas reglas que CreateModality
 * (ModalityRules); conservar la clave propia vale.
 *
 * En una transacción vuelve a leer la modalidad con bloqueo (`lockForUpdate`), así el valor anterior de la bitácora es
 * el confirmado aunque `$modality` llegue vieja, y la deja en `$modality`. Las claves del perfil que no llegan (las
 * que el formulario no lleva, como la tolerancia de HC-12, MS17, o una que el perfil no conoce) se conservan de lo
 * guardado. El perfil se compara normalizado (PostgreSQL reordena el jsonb): uno igual no cuenta como cambio. Un perfil guardado inválido no estorba: los demás cambios
 * funcionan y cualquier perfil válido lo repara. Si nada cambió, no escribe, no registra ni avisa; si algo cambió,
 * guarda, deja en la bitácora el valor anterior y el nuevo de cada campo y, después del commit, avisa con
 * ModalityUpdated.
 *
 * La clave no cambia una vez creada (supuesto por confirmar): los campus se ligan a su modalidad por ella mientras exista
 * el puente; una clave distinta es una ValidationException sobre `code` (la misma, con otro formato, se acepta). Desactivar
 * una modalidad (M11, confirmado el 12 de noviembre de 2026) se rechaza, bajo bloqueo de las activas, si es la única
 * activa o si tiene un periodo abierto con cargas sin publicar (se pregunta a los módulos de la derecha con
 * ModalityDeactivating); si no, procede. Activar no tiene guarda.
 *
 * Temporal, hasta el paso de contracción del plan de carga por modalidad: Scheduling todavía lee el perfil de
 * `campuses.settings`, así que cuando cambia el perfil se escribe igual en cada campus liga a esta modalidad por el
 * puente `campuses.modality_id` (con UpdateCampus: bitácora y CampusUpdated de cada campus), en la misma transacción:
 * si un campus lo rechaza no queda nada guardado y nada diverge en silencio.
 */
final class UpdateModality
{
    public function __construct(
        private readonly ModalityRules $rules,
        private readonly UpdateCampus $updateCampus,
    ) {}

    /**
     * @param  array{code?: string, name?: string, settings?: array<string, mixed>, is_active?: bool}  $data
     *
     * @throws ValidationException
     */
    public function handle(Modality $modality, array $data): Modality
    {
        $data = Arr::only($data, ['code', 'name', 'settings', 'is_active']);

        if (array_key_exists('code', $data)) {
            $data['code'] = $this->rules->normalizeCode($data['code']);
        }

        return DB::transaction(function () use ($modality, $data): Modality {
            $activeOthers = null;

            if (($data['is_active'] ?? true) === false) {
                // Al desactivar se bloquea todo de una vez y en un orden fijo (la propia y las activas, por id): dos
                // desactivaciones a la vez se forman una tras otra en lugar de bloquearse entre sí.
                $locked = Modality::query()->whereKey($modality->getKey())->orWhere('is_active', true)->orderBy('id')->lockForUpdate()->get();
                $activeOthers = $locked->where('is_active', true)->reject(fn (Modality $row): bool => $row->getKey() === $modality->getKey())->count();
            }

            $modality->setRawAttributes($modality->newQuery()->lockForUpdate()->findOrFail($modality->getKey())->getAttributes(), sync: true);
            $old = [...$modality->only(['code', 'name', 'is_active']), 'settings' => $this->storedProfile($modality)];
            $unchangedProfile = false;

            if (array_key_exists('settings', $data)) {
                // Lo que el formulario no lleva (la tolerancia de HC-12, MS17, o una clave que el perfil no conoce) se
                // conserva de lo guardado; lo que sí lleva manda.
                $settings = [...($modality->settings ?? []), ...$data['settings']];
                $profile = $this->rules->profile($settings);
                $unchangedProfile = $profile === $old['settings'];
                $data['settings'] = [...$settings, ...$profile];
            }

            if (array_key_exists('code', $data)) {
                if ($data['code'] !== $modality->code) {
                    throw ValidationException::withMessages(['code' => 'La clave de una modalidad no cambia una vez creada.']);
                }

                unset($data['code']);
            }

            if (($data['is_active'] ?? true) === false && $modality->is_active) {
                $this->ensureCanDeactivate($modality, $activeOthers ?? 0);
            }

            if (array_key_exists('settings', $data) && $unchangedProfile) {
                unset($data['settings']);
            }

            $modality->fill($data);
            $changed = array_keys($modality->getDirty());

            if ($changed === []) {
                return $modality;
            }

            try {
                $modality->save();
            } catch (UniqueConstraintViolationException) {
                throw $this->rules->codeTaken($modality->code);
            }

            activity()
                ->performedOn($modality)
                ->event(ActivityEvent::Updated)
                ->withChanges([
                    'attributes' => $modality->only($changed),
                    'old' => Arr::only($old, $changed),
                ])
                ->log("Actualizó la modalidad {$modality->code}");

            if (in_array('settings', $changed, true)) {
                $this->copyProfileToCampuses($modality);
            }

            ModalityUpdated::dispatch($modality, $changed);

            return $modality;
        });
    }

    /**
     * M11: no se desactiva la única modalidad activa ni una con un periodo abierto y cargas sin publicar.
     *
     * @throws ValidationException
     */
    private function ensureCanDeactivate(Modality $modality, int $others): void
    {
        if ($others === 0) {
            throw ValidationException::withMessages(['is_active' => "No se puede desactivar {$modality->name}: es la única modalidad activa. Activa otra primero."]);
        }

        $event = new ModalityDeactivating($modality);
        event($event);

        if ($event->unpublishedByTerm() === []) {
            return;
        }

        $terms = Term::query()->whereIn('id', array_keys($event->unpublishedByTerm()))->orderBy('code')->get();
        $lines = $terms->map(function (Term $term) use ($event): string {
            $count = $event->unpublishedByTerm()[$term->id];

            return "{$term->code} ({$count} ".($count === 1 ? 'carga sin publicar' : 'cargas sin publicar').')';
        })->implode(', ');

        throw ValidationException::withMessages(['is_active' => "No se puede desactivar {$modality->name}: tiene periodos abiertos con cargas sin publicar: {$lines}. Publícalas primero."]);
    }

    /**
     * El perfil nuevo en cada campus de la modalidad (puente temporal; ver la documentación de la clase).
     *
     * @throws ValidationException
     */
    private function copyProfileToCampuses(Modality $modality): void
    {
        Campus::query()
            ->where('modality_id', $modality->getKey())
            ->orderBy('code')
            ->get()
            ->each(fn (Campus $campus) => $this->updateCampus->handle($campus, ['settings' => $modality->settings]));
    }

    /**
     * El perfil guardado, normalizado; si ModalityProfile no lo acepta, tal como está (así queda en la bitácora como
     * valor anterior).
     *
     * @return array<string, mixed>
     */
    private function storedProfile(Modality $modality): array
    {
        try {
            return $modality->profile->toArray();
        } catch (InvalidArgumentException) {
            return $modality->settings ?? [];
        }
    }
}
