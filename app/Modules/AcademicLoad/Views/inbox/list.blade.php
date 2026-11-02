{{--
    La lista de carreras de la bandeja (academic-load::pages.inbox): pendientes primero, cada una con su estado y
    sus totales para el departamento. Elegir una la abre a la derecha.
--}}
<ul class="divide-y divide-base-300" data-testid="inbox-careers">
    @forelse ($visible as $row)
        @php($career = $row['career'])
        @php($isSelected = $selected !== null && $selected['career']->getKey() === $career->getKey())
        <li wire:key="career-{{ $career->getKey() }}">
            <button type="button" wire:click="pick('{{ $career->getKey() }}')" aria-current="{{ $isSelected ? 'true' : 'false' }}" data-career="{{ $career->code }}"
                @class([
                    'flex w-full min-w-0 cursor-pointer flex-col gap-1 border-l-4 px-4 py-3 text-left hover:bg-base-200 aria-[current=true]:bg-base-200',
                    'border-warning' => $row['status'] === \App\Modules\AcademicLoad\Enums\InboxStatus::Pending,
                    'border-error' => $row['status'] === \App\Modules\AcademicLoad\Enums\InboxStatus::Returned,
                    'border-success' => $row['status'] === \App\Modules\AcademicLoad\Enums\InboxStatus::Accepted,
                    'border-base-300' => $row['status'] === \App\Modules\AcademicLoad\Enums\InboxStatus::NotSubmitted,
                ])>
                <span class="flex items-center justify-between gap-2">
                    <span class="font-mono text-base font-semibold">{{ $career->code }}</span>
                    <x-core::status-badge :tone="$row['status']->tone()" :label="$row['status']->label()" />
                </span>
                <span class="truncate text-sm" title="{{ $career->name }}">{{ $career->name }}</span>
                <span class="text-[12.5px] text-neutral">
                    @if ($row['review'] === null)
                        {{ $row['proposal'] === null || $row['proposal']->status === \App\Modules\AcademicLoad\Enums\ProposalStatus::Draft ? 'Sin propuesta' : 'Sin materias de este departamento' }}
                    @else
                        {{ $row['groups'] }} grupos · {{ $row['expected'] }} esperados · {{ $row['subjects'] }} {{ $row['subjects'] === 1 ? 'materia' : 'materias' }}
                    @endif
                </span>
            </button>
        </li>
    @empty
        <li class="px-4 py-6 text-center text-sm text-neutral">Ninguna carrera pendiente.</li>
    @endforelse
</ul>
