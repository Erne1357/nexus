{{--
    Vista «Por materia» (maqueta C): una superficie por materia con su encabezado pegado arriba (semestre, nombre,
    clave y cuántos de sus grupos tienen docente, con un punto por grupo cuando son pocos) y un renglón por grupo
    (staffing::assign.group-block).
--}}
<div class="space-y-3" data-testid="assign-by-subject">
    @forelse ($sections as $section)
        @php($subject = $section['subject'])
        <section class="rounded-box border border-base-300 bg-base-100" wire:key="subject-{{ $subject->id }}" aria-labelledby="subject-{{ $subject->id }}" data-subject="{{ $subject->code }}">
            <header class="z-10 rounded-t-box border-b border-base-300 bg-base-100 md:sticky md:top-(--shell-top)">
                <div class="flex flex-wrap items-center gap-x-3.5 gap-y-1.5 rounded-t-box bg-secondary/40 px-4 py-2.5">
                    <span class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-secondary-muted">Sem. {{ $section['semester'] }}</span>
                    <h2 id="subject-{{ $subject->id }}" class="text-base font-bold leading-tight">{{ $subject->name }}</h2>
                    <span class="whitespace-nowrap font-mono text-[12.5px] font-semibold text-secondary-muted">{{ $subject->code }}</span>
                    <span class="flex items-center gap-2 text-sm sm:ms-auto">
                        @if ($section['total'] <= 12)
                            <span class="inline-flex gap-[3px]" aria-hidden="true">
                                @foreach ($section['dots'] as $withTeacher)
                                    <span @class(['h-2 w-3 rounded-[2px] border', 'border-primary bg-primary' => $withTeacher, 'border-secondary-muted/60 bg-base-100' => ! $withTeacher])></span>
                                @endforeach
                            </span>
                        @endif
                        <span><span class="font-mono font-semibold tabular-nums">{{ $section['assigned'] }}</span> de <span class="font-mono tabular-nums">{{ $section['total'] }}</span> con docente</span>
                    </span>
                </div>
            </header>
            <div class="divide-y divide-base-300">
                @foreach ($section['rows'] as $row)
                    @include('staffing::assign.group-block', ['row' => $row])
                @endforeach
            </div>
        </section>
    @empty
        <div class="rounded-box border border-base-300 bg-base-100 px-5 py-8 text-center text-neutral" data-testid="assign-no-groups">
            {{ $board->total() === 0 ? 'El departamento no tiene grupos en este periodo y campus.' : 'Ningún grupo cumple los filtros.' }}
        </div>
    @endforelse
</div>
