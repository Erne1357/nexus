<?php

use App\Modules\Academic\Models\Career;
use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use App\Modules\Core\Queries\KeyLookup;
use App\Modules\Scheduling\Actions\Quotas\ProposeGroupQuota;
use App\Modules\Scheduling\Actions\Quotas\SetGroupQuotas;
use App\Modules\Scheduling\Actions\Quotas\WithdrawGroupQuotaProposal;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Queries\GroupQuotasQuery;
use App\Modules\Scheduling\Rules\QuotaRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Mary\Traits\Toast;

/**
 * La sección «Cupos por carrera» del cajón del grupo en el editor de horarios (tarea 3.6, HC-07; maqueta `cajones`,
 * estado `#grupo`): un renglón por carrera con su clave en Mono, su nombre, sus lugares y su estado («Dueña», que no
 * se quita; «Aceptado»; «Propuesto · aparta N lugares», que espera al jefe de <depto> y ya aparta sus lugares), la
 * barra «N de M lugares repartidos», que suma los propuestos (aparte, en rayas), el error HC-07 si la suma con lo
 * apartado se pasa (confirmado el 8 de noviembre de 2026; lo apartado son los lugares guardados de cada propuesta, como
 * los cuenta SetGroupQuotas: un cambio pendiente cuenta hasta «Cambiar lugares»), «Agregar carrera» (con «Proponer a <depto>» si la
 * carrera es de otro departamento, que propone al instante con ProposeGroupQuota; quitar un «Propuesto» lo retira al
 * instante y «Cambiar lugares» lo retira y lo propone de nuevo, solo cuando se pide en su renglón) y «Guardar cupos»
 * (SetGroupQuotas, que no toca las propuestas). Los errores de cualquier llave de una Action salen en un aviso arriba.
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

    /** El grupo y lo que dice la Query, leídos una vez por petición; se sueltan después de escribir. */
    protected ?Group $loadedGroup = null;

    /** @var array<string, mixed>|null */
    protected ?array $loaded = null;

    public function mount(string $groupId): void
    {
        $this->groupId = $groupId;
        $this->authorize('view', $this->group());

        $this->quotas = $this->persisted();
    }

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        $group = $this->group();
        $this->authorize('view', $group);

        $data = $this->data();
        $rows = [];
        $counted = 0;
        $proposed = 0;

        foreach ($data['rows'] as $row) {
            if (array_key_exists($row['career_id'], $this->quotas)) {
                $rows[] = $row;
            }
        }

        $present = array_column($rows, 'career_id');

        foreach ($data['addable'] as $career) {
            if (array_key_exists($career['id'], $this->quotas) && ! in_array($career['id'], $present, true)) {
                $rows[] = ['career_id' => $career['id'], 'code' => $career['code'], 'name' => $career['name'], 'seats' => 0, 'state' => 'new', 'decides' => null, 'agreement_id' => null];
            }
        }

        foreach ($rows as $index => $row) {
            $seats = $this->seatsOf($row['career_id']);
            $rows[$index]['edited'] = $row['state'] === 'proposed' && $seats !== $row['seats'] && $seats > 0;

            if ($row['state'] === 'proposed') {
                $proposed += $row['seats'];
            } else {
                $counted += $seats;
            }
        }

        $addable = array_values(array_filter($data['addable'], fn (array $career): bool => ! array_key_exists($career['id'], $this->quotas)));
        $canManage = $this->can('manageQuotas', $group);

        return [
            'rows' => $rows,
            'capacity' => $data['capacity'],
            'counted' => $counted,
            'proposed' => $proposed,
            'over' => $counted + $proposed - $data['capacity'],
            'options' => array_map(fn (array $career): array => ['id' => $career['id'], 'label' => $career['code'].' · '.$career['name']], $addable),
            'hasAddable' => $addable !== [],
            'selected' => collect($addable)->firstWhere('id', $this->newCareer),
            'canManage' => $canManage,
            'reason' => $canManage ? null : "Los cupos los reparte el jefe de {$data['department']['name']}.",
            'maxSeats' => QuotaRules::MAX_SEATS,
        ];
    }

    /**
     * Quita un renglón del borrador; si es una propuesta, la retira al instante (WithdrawGroupQuotaProposal).
     */
    public function removeCareer(string $careerId, WithdrawGroupQuotaProposal $withdraw): void
    {
        $group = $this->group();
        $this->authorize('manageQuotas', $group);

        if ($careerId === $group->owner_career_id) {
            $this->addError('quotas', 'La carrera dueña no se quita.');

            return;
        }

        $row = $this->rowOf($careerId);

        if ($row !== null && $row['state'] === 'proposed') {
            $withdraw->handle($this->agreementOf($row), auth()->user());
            $this->written();
            $this->success('Propuesta retirada', e("{$row['code']} · {$row['name']}"));
            $this->dispatch('quotas-saved', groupId: $group->id);
        }

        unset($this->quotas[$careerId]);
        $this->resetErrorBag();
    }

    /**
     * Cambiar los lugares de una propuesta es retirarla y proponerla de nuevo, y solo se hace cuando se pide en su
     * renglón.
     */
    public function changeProposal(string $careerId, WithdrawGroupQuotaProposal $withdraw, ProposeGroupQuota $propose): void
    {
        $group = $this->group();
        $this->authorize('manageQuotas', $group);

        $row = $this->rowOf($careerId);

        if ($row === null || $row['state'] !== 'proposed') {
            return;
        }

        $this->validate(["quotas.{$careerId}" => $this->seatRules()], ["quotas.{$careerId}.*" => $this->seatMessage()]);

        $seats = (int) $this->quotas[$careerId];
        $career = KeyLookup::findOrFail(Career::query(), $careerId);

        DB::transaction(function () use ($withdraw, $propose, $row, $group, $career, $seats): void {
            $withdraw->handle($this->agreementOf($row), auth()->user());
            $propose->handle($group, $career, $seats, auth()->user());
        });

        $this->written();
        $this->success('Propuesta cambiada', e("{$row['code']}: {$seats} lugares"));
        $this->dispatch('quotas-saved', groupId: $group->id);
    }

    /**
     * Una carrera del mismo departamento entra al borrador; una de otro departamento se propone al instante
     * (ProposeGroupQuota), sin tocar el borrador.
     */
    public function addCareer(ProposeGroupQuota $propose): void
    {
        $group = $this->group();
        $this->authorize('manageQuotas', $group);

        $addable = collect($this->data()['addable'])->keyBy('id');

        $this->validate([
            'newCareer' => ['required', Rule::in($addable->keys()->all())],
            'newSeats' => $this->seatRules(),
        ], [
            'newCareer.*' => 'Elige una carrera.',
            'newSeats.*' => $this->seatMessage(),
        ]);

        $seats = (int) $this->newSeats;
        $career = $addable[$this->newCareer];

        if (! $career['needs_agreement']) {
            $this->quotas[$career['id']] = $seats;
            $this->reset('newCareer', 'newSeats');

            return;
        }

        $propose->handle($group, KeyLookup::findOrFail(Career::query(), $career['id']), $seats, auth()->user());

        $this->reset('newCareer', 'newSeats');
        $this->written();
        $this->quotas[$career['id']] = $seats;
        $this->success('Propuesta enviada', e("Espera al jefe de {$career['department']['name']}."));
        $this->dispatch('quotas-saved', groupId: $group->id);
    }

    public function save(SetGroupQuotas $set): void
    {
        $group = $this->group();
        $this->authorize('manageQuotas', $group);

        $this->validate(['quotas.*' => $this->seatRules()], ['quotas.*' => $this->seatMessage()]);

        $proposed = collect($this->data()['rows'])->where('state', 'proposed')->pluck('career_id')->all();
        $set->handle($group, array_map('intval', array_diff_key($this->quotas, array_flip($proposed))), auth()->user());

        $this->written();
        $this->quotas = array_replace($this->persisted(), array_intersect_key($this->quotas, array_flip($proposed)));
        $this->success('Cupos guardados', e("Grupo {$group->code}"));
        $this->dispatch('quotas-saved', groupId: $group->id);
    }

    private function seatsOf(string $careerId): int
    {
        $value = $this->quotas[$careerId] ?? 0;

        return is_numeric($value) && (int) $value == $value ? max(0, (int) $value) : 0;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function rowOf(string $careerId): ?array
    {
        return collect($this->data()['rows'])->firstWhere('career_id', $careerId);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function agreementOf(array $row): InterdepartmentalAgreement
    {
        return KeyLookup::findOrFail(InterdepartmentalAgreement::query(), $row['agreement_id']);
    }

    /**
     * Lo guardado: los lugares de la dueña, los aceptados y los propuestos.
     *
     * @return array<string, int>
     */
    private function persisted(): array
    {
        return collect($this->data()['rows'])->pluck('seats', 'career_id')->all();
    }

    private function group(): Group
    {
        return $this->loadedGroup ??= KeyLookup::findOrFail(Group::query(), $this->groupId);
    }

    /**
     * @return array<string, mixed>
     */
    private function data(): array
    {
        return $this->loaded ??= app(GroupQuotasQuery::class)->for($this->group());
    }

    private function can(string $ability, Group $group): bool
    {
        return auth()->user()?->can($ability, $group) ?? false;
    }

    /** Después de escribir, lo leído ya no vale. */
    private function written(): void
    {
        $this->loadedGroup = null;
        $this->loaded = null;
    }

    /**
     * @return list<string>
     */
    private function seatRules(): array
    {
        return ['required', 'integer', 'min:1', 'max:'.QuotaRules::MAX_SEATS];
    }

    private function seatMessage(): string
    {
        return 'Los lugares van de 1 a '.QuotaRules::MAX_SEATS.'.';
    }
};
