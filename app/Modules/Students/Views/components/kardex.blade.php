{{-- El kárdex del alumno por periodo, el mismo en la ficha del personal y en la pestaña «Kárdex» del alumno. `$record` es el StudentRecord. --}}
@props(['record', 'audience' => 'personal'])
    <p class="text-neutral">{{ $audience === 'alumno' ? 'Cada materia que has cursado' : 'Cada materia que ha cursado' }}, por periodo y desde el primero, como viene en la última importación de kárdex.</p>
    <div class="overflow-hidden rounded-box border border-base-300 bg-base-100" data-testid="student-kardex" wire:key="student-kardex">
        @forelse ($record->kardex as $term)
            <div class="flex flex-wrap items-center justify-between gap-2 border-base-300 bg-base-200/60 px-5 py-2.5 font-bold [&:not(:first-child)]:border-t">
                <span>@if ($term['term'] !== null)<span class="font-mono">{{ $term['term'] }}</span> · @endif{{ $term['label'] }}</span>
                <span class="text-sm font-normal text-neutral"><span class="font-mono">{{ count($term['records']) }}</span> materias</span>
            </div>
            @foreach ($term['records'] as $row)
                <div class="grid grid-cols-[6.5rem_minmax(0,1fr)_auto] items-center gap-x-3 gap-y-1 border-t border-base-300 px-5 py-2.5 md:grid-cols-[6.5rem_minmax(0,1fr)_4rem_9rem_9rem]">
                    @php([$primary, $secondary] = $record->codesFor($row->subject_id, $row->subject->code))
                    <span class="font-mono font-semibold">{{ $primary }}@if ($secondary !== null)<span class="block text-xs font-normal text-neutral" title="Clave del TecNM" data-secondary-code>{{ $secondary }}</span>@endif</span>
                    <span>{{ $row->subject->name }}</span>
                    <span class="text-right font-mono tabular-nums max-md:hidden">{{ $row->grade_label }}</span>
                    <x-core::status-badge :tone="$row->result->tone()" :label="$row->result->label()" />
                    <span class="text-neutral max-md:col-span-3">{{ $row->attempt_label }}</span>
                </div>
            @endforeach
        @empty
            <p class="px-5 py-6 text-center text-neutral">{{ $audience === 'alumno' ? 'Tu kárdex todavía no se importa.' : 'El kárdex de este alumno todavía no se importa.' }}</p>
        @endforelse
    </div>
