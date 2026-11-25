<?php

use App\Modules\Communication\Queries\UnitStaffQuery;
use App\Modules\Core\Actions\AppointRole;
use App\Modules\Core\Actions\EndRoleAssignment;
use App\Modules\Core\Enums\AssignmentEnding;
use App\Modules\Core\Models\OrgUnit;
use App\Modules\Core\Models\RoleAssignment;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Mary\Traits\Toast;

/**
 * «Personal» de Comunicación y Difusión (CO-02, `/comunicacion/personal`): la jefa ve a su personal de área (los
 * nombramientos de «Personal de área» vigentes o por empezar sobre su unidad), nombra a una persona del personal en ese
 * puesto («Nombrar», en un x-drawer: la busca por nombre o usuario, sin alumnos) y termina un nombramiento («Terminar…»,
 * en un x-modal). El puesto y la unidad no llegan del navegador: siempre `personal` sobre la unidad COMUNICACION.
 *
 * Escriben AppointRole y EndRoleAssignment, que hacen cumplir RoleAssignmentRules. Autoriza en mount() y en cada
 * método con RoleAssignmentPolicy::appointUnitStaff sobre la unidad (`core.unit_staff.appoint`): la jefa de esa unidad
 * y el admin; nadie más, ni el personal de área. «Personas y puestos» y el Organigrama siguen siendo solo del admin.
 */
new #[Title('Personal')] class extends Component
{
    use Toast;

    /** `showAppoint` no puede ser #[Locked]: el x-drawer lo cierra desde el navegador. */
    public bool $showAppoint = false;

    /** Búsqueda de la persona por nombre o usuario. */
    public string $findPerson = '';

    /** La persona elegida; solo la cambia choosePerson(). */
    #[Locked]
    public ?string $personId = null;

    public string $from = '';

    public string $to = '';

    public bool $showEnd = false;

    /** El nombramiento que se está terminando. El navegador no lo puede cambiar. */
    #[Locked]
    public ?string $endingId = null;

    public string $lastDay = '';

    public function mount(UnitStaffQuery $query): void
    {
        $this->authorizeOn($query);
    }

    /**
     * @return array<string, mixed>
     */
    public function with(UnitStaffQuery $query): array
    {
        $unit = $this->authorizeOn($query);

        return [
            'unit' => $unit,
            'title' => $query->title($unit),
            'appointments' => $query->appointments($unit),
            'person' => $this->showAppoint && $this->personId !== null ? $query->findStaffOrFail($this->personId) : null,
            'found' => $this->showAppoint && $this->personId === null ? $query->search($this->findPerson) : collect(),
            'ending' => $this->showEnd && $this->endingId !== null ? $query->assignmentOf($unit, $this->endingId) : null,
        ];
    }

    /**
     * Cerrar el drawer o el modal con la X, el velo o Esc olvida lo que tenía.
     */
    public function updated(string $property, UnitStaffQuery $query): void
    {
        $this->authorizeOn($query);

        if ($property === 'showAppoint' && ! $this->showAppoint) {
            $this->forgetAppoint();
        } elseif ($property === 'showEnd' && ! $this->showEnd) {
            $this->forgetEnd();
        }
    }

    public function openAppoint(UnitStaffQuery $query): void
    {
        $this->authorizeOn($query);

        $this->resetValidation();
        $this->reset('findPerson', 'personId', 'to');
        $this->from = today()->toDateString();
        $this->showAppoint = true;
    }

    public function closeAppoint(UnitStaffQuery $query): void
    {
        $this->authorizeOn($query);

        $this->forgetAppoint();
    }

    public function choosePerson(string $id, UnitStaffQuery $query): void
    {
        $this->authorizeOn($query);

        $this->personId = $query->findStaffOrFail($id)->id;
        $this->findPerson = '';
        $this->resetValidation('personId');
    }

    public function clearPerson(UnitStaffQuery $query): void
    {
        $this->authorizeOn($query);

        $this->personId = null;
    }

    public function appoint(AppointRole $appointRole, UnitStaffQuery $query): void
    {
        $unit = $this->authorizeOn($query);

        $this->validate([
            'from' => ['required', 'date'],
            'to' => ['nullable', 'date'],
        ], [
            'from.required' => 'Escribe desde cuándo.',
            'from.date' => 'La fecha de inicio no es válida.',
            'to.date' => 'La fecha de fin no es válida.',
        ]);

        if ($this->personId === null) {
            $this->addError('personId', 'Elige a la persona.');

            return;
        }

        $person = $query->findStaffOrFail($this->personId);

        try {
            $appointRole->handle($person, $query->role(), null, $unit, null, CarbonImmutable::parse($this->from), $this->to === '' ? null : CarbonImmutable::parse($this->to));
        } catch (ValidationException $exception) {
            // La fecha de fin va bajo «Hasta»; todo lo demás que diga AppointRole (la persona, el puesto, la unidad o lo que
            // agregue después), bajo la persona, para que ningún error se quede sin mostrar.
            $errors = $exception->errors();
            $toPerson = array_merge(...array_values(array_diff_key($errors, ['to' => true])));

            if ($toPerson === [] && ! isset($errors['to'])) {
                $toPerson = [$exception->getMessage()];
            }

            throw ValidationException::withMessages(array_filter([
                'personId' => $toPerson === [] ? null : $toPerson,
                'to' => $errors['to'] ?? null,
            ]));
        }

        $this->success('Nombramiento creado', e("{$person->name} · ".$query->title($unit)));
        $this->forgetAppoint();
    }

    /**
     * Abre «Terminar…» con hoy como último día.
     */
    public function confirmEnd(string $id, UnitStaffQuery $query): void
    {
        $unit = $this->authorizeOn($query);

        $this->resetValidation();
        $this->endingId = $query->assignmentOf($unit, $id)->id;
        $this->lastDay = today()->toDateString();
        $this->showEnd = true;
    }

    public function cancelEnd(UnitStaffQuery $query): void
    {
        $this->authorizeOn($query);

        $this->forgetEnd();
    }

    public function end(EndRoleAssignment $endRoleAssignment, UnitStaffQuery $query): void
    {
        $unit = $this->authorizeOn($query);

        if ($this->endingId === null) {
            return;
        }

        $assignment = $query->assignmentOf($unit, $this->endingId);

        $this->validate(['lastDay' => ['required', 'date']], [
            'lastDay.required' => 'Escribe el último día.',
            'lastDay.date' => 'El último día no es válido.',
        ]);

        $day = CarbonImmutable::parse($this->lastDay);

        try {
            $ending = $endRoleAssignment->handle($assignment, $day);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(['lastDay' => $exception->errors()['lastDay'] ?? $exception->errors()['assignment'] ?? [$exception->getMessage()]]);
        }

        $name = $assignment->user->name;

        match ($ending) {
            AssignmentEnding::Ended => $this->success('Nombramiento terminado', e("{$name} · último día: ".$day->format('d/m/Y'))),
            AssignmentEnding::Removed => $this->success('Nombramiento quitado', e("{$name} · ".($assignment->valid_from->isToday() ? 'empezaba hoy.' : 'todavía no empezaba.'))),
            AssignmentEnding::Unchanged => $this->info('Sin cambios', e("{$name} ya termina el ".$assignment->valid_to->format('d/m/Y').'.')),
        };

        $this->forgetEnd();
    }

    private function forgetAppoint(): void
    {
        $this->resetValidation();
        $this->reset('showAppoint', 'findPerson', 'personId', 'from', 'to');
    }

    private function forgetEnd(): void
    {
        $this->resetValidation();
        $this->reset('showEnd', 'endingId', 'lastDay');
    }

    /**
     * CO-02: la unidad de Comunicación y Difusión, si quien mira nombra a su personal de área; 403 si no.
     */
    private function authorizeOn(UnitStaffQuery $query): OrgUnit
    {
        $unit = $query->unit();

        $this->authorize('appointUnitStaff', [RoleAssignment::class, $unit]);

        return $unit;
    }
};
