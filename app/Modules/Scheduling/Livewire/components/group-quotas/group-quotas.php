<?php

use App\Modules\Core\Queries\KeyLookup;
use App\Modules\Scheduling\Actions\Quotas\SetGroupQuotas;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Queries\GroupQuotasQuery;
use App\Modules\Scheduling\Rules\QuotaRules;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Mary\Traits\Toast;

/**
 * La sección «Cupos por carrera» del cajón del grupo en el editor de horarios (tarea 3.6, HC-07; maqueta `cajones`,
 * estado `#grupo`): un renglón por carrera con su clave en Mono, su nombre, sus lugares y su estado («Dueña», que no
 * se quita; «Aceptado»; «Propuesto · espera al jefe de <depto>», que todavía no cuenta), la barra «N de M lugares
 * repartidos» con los propuestos aparte, el error HC-07 si se pasa, «Agregar carrera» (con «Proponer a <depto>» si la
 * carrera es de otro departamento, que crea el acuerdo al instante) y «Guardar cupos» (SetGroupQuotas).
 *
 * Lo edita quien reparte cupos (GroupPolicy::manageQuotas: el jefe del departamento que imparte); quien solo ve el
 * grupo lo ve igual, de solo lectura, con «Guardar cupos» deshabilitado y su motivo escrito (DESIGN.md, «Nada oculto
 * por permiso»). Cada método vuelve a autorizar. `quotas` es el borrador `[carrera => lugares]` que se guarda junto:
 * quitar un renglón o agregar una carrera del mismo departamento no escribe hasta «Guardar cupos». Tras guardar o
 * proponer despacha `quotas-saved` para que el editor se refresque.
 */
new class extends Component
{
    use Toast;

    /** El grupo del panel. El navegador no lo puede cambiar. */
    #[Locked]
    public string $groupId = '';

    /** @var array<string, int|string|null> Los lugares por carrera, tal como van en los campos. */
    public array $quotas = [];

    public string $newCareer = '';

    public int|string|null $newSeats = null;

    public function mount(string $groupId, GroupQuotasQuery $query): void
    {
        $this->groupId = $groupId;
        $this->authorize('view', $this->group());

        $this->quotas = $this->persisted($query);
    }

    /**
     * @return array<string, mixed>
     */
    public function with(GroupQuotasQuery $query): array
    {
        $group = $this->group();
        $this->authorize('view', $group);

        $data = $query->for($group);
        $rows = [];
        $counted = 0;
        $proposed = 0;

        foreach ($data['rows'] as $row) {
            if (! array_key_exists($row['career_id'], $this->quotas)) {
                continue;
            }

            $rows[] = $row;
        }

        $present = array_column($rows, 'career_id');

        foreach ($data['addable'] as $career) {
            if (array_key_exists($career['id'], $this->quotas) && ! in_array($career['id'], $present, true)) {
                $rows[] = ['career_id' => $career['id'], 'code' => $career['code'], 'name' => $career['name'], 'seats' => 0, 'state' => 'new', 'decides' => null, 'agreement_id' => null];
            }
        }

        foreach ($rows as $row) {
            $seats = $this->seatsOf($row['career_id']);

            if ($row['state'] === 'proposed') {
                $proposed += $seats;
            } else {
                $counted += $seats;
            }
        }

        $addable = array_values(array_filter($data['addable'], fn (array $career): bool => ! array_key_exists($career['id'], $this->quotas)));
        $selected = collect($addable)->firstWhere('id', $this->newCareer);
        $over = $counted - $data['capacity'];
        $canManage = $this->can('manageQuotas', $group);

        return [
            'rows' => $rows,
            'capacity' => $data['capacity'],
            'counted' => $counted,
            'proposed' => $proposed,
            'over' => $over,
            'department' => $data['department'],
            'options' => array_map(fn (array $career): array => ['id' => $career['id'], 'label' => $career['code'].' · '.$career['name']], $addable),
            'hasAddable' => $addable !== [],
            'selected' => $selected,
            'canManage' => $canManage,
            'reason' => $canManage ? null : "Los cupos los reparte el jefe de {$data['department']['name']}.",
            'maxSeats' => QuotaRules::MAX_SEATS,
        ];
    }

    public function removeCareer(string $careerId): void
    {
        $group = $this->group();
        $this->authorize('manageQuotas', $group);

        if ($careerId === $group->owner_career_id) {
            $this->addError('quotas', 'La carrera dueña no se quita.');

            return;
        }

        unset($this->quotas[$careerId]);
        $this->resetErrorBag();
    }

    /**
     * Una carrera del mismo departamento entra al borrador; una de otro departamento se propone al instante (con lo
     * ya guardado, sin los cambios sin guardar).
     */
    public function addCareer(GroupQuotasQuery $query, SetGroupQuotas $set): void
    {
        $group = $this->group();
        $this->authorize('manageQuotas', $group);

        $data = $query->for($group);
        $addable = collect($data['addable'])->keyBy('id');

        $this->validate([
            'newCareer' => ['required', Rule::in($addable->keys()->all())],
            'newSeats' => ['required', 'integer', 'min:1', 'max:'.QuotaRules::MAX_SEATS],
        ], [
            'newCareer.*' => 'Elige una carrera.',
            'newSeats.*' => 'Los lugares van de 1 a '.QuotaRules::MAX_SEATS.'.',
        ]);

        $seats = (int) $this->newSeats;
        $career = $addable[$this->newCareer];

        if (! $career['needs_agreement']) {
            $this->quotas[$career['id']] = $seats;
            $this->reset('newCareer', 'newSeats');

            return;
        }

        $saved = $this->persisted($query);
        $set->handle($group, array_map('intval', $saved) + [$career['id'] => $seats], auth()->user());

        $this->reset('newCareer', 'newSeats');
        $this->quotas[$career['id']] = $seats;
        $this->success('Propuesta enviada', e("Espera al jefe de {$career['department']['name']}."));
        $this->dispatch('quotas-saved', groupId: $group->id);
    }

    public function save(SetGroupQuotas $set, GroupQuotasQuery $query): void
    {
        $group = $this->group();
        $this->authorize('manageQuotas', $group);

        $this->validate(['quotas.*' => ['required', 'integer', 'min:1', 'max:'.QuotaRules::MAX_SEATS]], [
            'quotas.*' => 'Los lugares van de 1 a '.QuotaRules::MAX_SEATS.'.',
        ]);

        $set->handle($group, array_map('intval', $this->quotas), auth()->user());

        $this->quotas = $this->persisted($query);
        $this->success('Cupos guardados', e("Grupo {$group->code}"));
        $this->dispatch('quotas-saved', groupId: $group->id);
    }

    private function group(): Group
    {
        return KeyLookup::findOrFail(Group::query(), $this->groupId);
    }

    private function can(string $ability, Group $group): bool
    {
        return auth()->user()?->can($ability, $group) ?? false;
    }

    private function seatsOf(string $careerId): int
    {
        $value = $this->quotas[$careerId] ?? 0;

        return is_numeric($value) && (int) $value == $value ? max(0, (int) $value) : 0;
    }

    /**
     * Lo guardado: los lugares de la dueña, los aceptados y los propuestos.
     *
     * @return array<string, int>
     */
    private function persisted(GroupQuotasQuery $query): array
    {
        $seats = [];

        foreach ($query->for($this->group())['rows'] as $row) {
            $seats[$row['career_id']] = $row['seats'];
        }

        return $seats;
    }
};
