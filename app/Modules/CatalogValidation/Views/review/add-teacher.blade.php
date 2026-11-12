{{--
    «Agregar docente» (CV-S9; opción A): busca por nombre o RFC entre todos los docentes, también los dados de baja y los
    de otros departamentos, y dice qué pasa con cada uno antes de agregarlo: se agrega como adscripción adicional, queda
    como principal, se reactiva, ya está, o no se puede (dado de baja y con un puesto: su motivo escrito, CV-S9). Al
    agregarlo queda revisado (CV7). `$candidates` es de review.php; `close()` es del x-drawer.
--}}
<div class="flex min-h-dvh flex-col" data-testid="add-teacher-panel">
    <header class="flex items-start gap-3 border-b border-base-300 py-4 ps-5 pe-3">
        <div class="min-w-0 flex-1">
            <h2 id="add-teacher-title" class="text-xl font-bold leading-tight">Agregar docente</h2>
            <p class="mt-1 text-sm text-neutral">A {{ $review->department?->name }} · {{ $term->name }}</p>
        </div>
        <x-button icon="o-x-mark" class="btn-ghost btn-sm btn-circle" @click="close()" aria-label="Cerrar el panel" />
    </header>

    <div class="flex-1 space-y-4 px-5 pt-4 pb-8">
        <x-input label="Busca por nombre o RFC" wire:model.live.debounce.300ms="teacherSearch" icon="o-magnifying-glass" clearable autocomplete="off" hint="Incluye a los docentes dados de baja y a los de otros departamentos." data-testid="teacher-search" />

        @error('candidate')
            <x-alert icon="o-exclamation-triangle" class="alert-warning alert-soft text-sm" :title="$message" data-testid="candidate-error" />
        @enderror

        @if ($candidates['tooShort'])
            <p class="text-sm text-neutral">Escribe al menos dos letras del nombre o del RFC.</p>
        @elseif ($candidates['items'] === [])
            <p class="text-sm text-neutral" data-testid="no-candidates">Ningún docente coincide con «{{ $candidates['search'] }}».</p>
        @else
            <ul class="divide-y divide-base-300 rounded-box border border-base-300" aria-label="Docentes que coinciden" data-testid="candidates">
                @foreach ($candidates['items'] as $candidate)
                    <li wire:key="candidate-{{ $candidate['id'] }}" class="space-y-2 p-3.5">
                        <div class="min-w-0">
                            <p class="font-semibold">{{ $candidate['name'] }}</p>
                            <p class="flex flex-wrap items-center gap-2"><span class="font-mono text-[12.5px]">{{ $candidate['rfc'] }}</span>
                                @unless ($candidate['active'])
                                    <x-core::status-badge tone="neutral" label="Dado de baja" />
                                @endunless
                            </p>
                            <p class="mt-1 text-[12.5px] text-neutral">{{ $candidate['note'] }}</p>
                        </div>
                        @switch ($candidate['state'])
                            @case ('here')
                                <button type="button" class="btn btn-sm" aria-disabled="true" disabled>Ya está</button>
                                @break
                            @case ('blocked')
                                <button type="button" class="btn btn-outline btn-sm" aria-disabled="true" disabled aria-describedby="candidate-why-{{ $candidate['id'] }}"><x-icon name="o-user-plus" class="h-4 w-4" aria-hidden="true" />Reactivar y agregar</button>
                                <p id="candidate-why-{{ $candidate['id'] }}" class="text-xs text-neutral">{{ $candidate['reason'] }}</p>
                                @break
                            @default
                                <x-button :label="$candidate['state'] === 'reactivate' ? 'Reactivar y agregar' : 'Agregar'" icon="o-user-plus" class="btn-outline btn-sm" wire:click="addTeacher('{{ $candidate['id'] }}')" spinner="addTeacher('{{ $candidate['id'] }}')" data-testid="add-{{ $candidate['rfc'] }}" />
                        @endswitch
                    </li>
                @endforeach
            </ul>
            @if ($candidates['more'])
                <p class="text-sm text-neutral">Hay más: escribe más del nombre o el RFC.</p>
            @endif
        @endif

        <div class="flex gap-2.5 rounded-box border border-base-300 bg-base-200 p-3 text-sm">
            <x-icon name="o-information-circle" class="h-5 w-5 shrink-0 text-neutral" aria-hidden="true" />
            <p>¿No aparece? Si es nuevo, dalo de alta en <a href="{{ route('faculty.teachers') }}" wire:navigate class="font-medium underline underline-offset-4">Docentes</a>: entra a esta revisión por revisar.</p>
        </div>
    </div>
</div>
