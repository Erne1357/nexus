{{--
    La lista de «Paralelos» (maqueta B): un renglón por materia y campus que se puede juntar, con las carreras que cuentan
    (la de parte aceptada con su palomita), esperados (desde 1200 px), pedidos, bastan, de menos y su estado: «Armada»,
    «Pendiente» con su motivo (una parte sin aceptar o un grupo con docente sin lugar) o «Por armar» con los grupos que ya
    existen. «Armar» abre el drawer; si ya está armada o el usuario solo consulta, «Ver». Desde 1024 px es tabla (debajo de
    1200 px el campus va junto a la clave y sin «Esperados»; si no cabe, se desplaza de lado dentro de su caja); debajo,
    renglones apilados, como la maqueta. Sin materias que juntar, el vacío lo explica.
--}}
@php
    $signed = fn (int $n): string => $n > 0 ? '−'.$n : ($n < 0 ? '+'.abs($n) : '0');
    $key = fn ($subject): string => $subject->subject->code.'-'.$subject->campus->code;
@endphp

@if ($figures['subjects'] === 0)
    <div class="rounded-box border border-base-300 bg-base-100 px-5 py-9 text-center" data-testid="parallels-none">
        <x-icon name="o-squares-2x2" class="mx-auto h-6 w-6 text-neutral" aria-hidden="true" />
        <h2 class="mt-2 text-lg font-bold">Nada que juntar{{ $shownCampus ? ' en '.$shownCampus->name : '' }}</h2>
        <p class="mx-auto mt-1 max-w-[56ch] text-sm text-neutral">Cada materia de {{ $parallels->department->name }} la pide una sola carrera, o las carreras que la piden ya llenan sus grupos. Sus grupos nacen por carrera al aceptar cada parte en la bandeja.</p>
    </div>
@elseif ($visible === [])
    <p class="rounded-box border border-base-300 bg-base-100 px-5 py-8 text-center font-semibold" data-testid="parallels-no-match">Ninguna materia con esos filtros.</p>
@else
    @php
        $state = function ($subject): array {
            if ($subject->arranged()) {
                return ['tone' => 'success', 'label' => 'Armada', 'icon' => null, 'note' => null];
            }

            $reason = $subject->arrangeBlockedReason();

            if ($reason !== null) {
                return ['tone' => 'neutral', 'label' => 'Pendiente', 'icon' => 'o-lock-closed', 'note' => $reason];
            }

            $existing = count($subject->groups);

            return ['tone' => 'warning', 'label' => 'Por armar', 'icon' => 'o-clock', 'note' => $existing === 0 ? null : ($existing === 1 ? '1 grupo ya creado' : "{$existing} grupos ya creados")];
        };
    @endphp

    <div class="overflow-x-auto rounded-box border border-base-300 bg-base-100 max-lg:hidden" data-testid="parallels-table">
        <table class="table [&_td]:px-3 [&_th]:px-3">
            <caption class="sr-only">Materias de {{ $parallels->department->name }} que se pueden juntar, por campus</caption>
            <thead>
                <tr class="bg-base-200/60 text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">
                    <th scope="col">Materia</th>
                    <th scope="col" class="max-desktop:hidden">Campus</th>
                    <th scope="col">Carreras que cuentan</th>
                    <th scope="col" class="text-right max-desktop:hidden">Esperados</th>
                    <th scope="col" class="text-right">Pedidos</th>
                    <th scope="col" class="text-right">Bastan</th>
                    <th scope="col" class="text-right">Menos</th>
                    <th scope="col">Estado</th>
                    <th scope="col"><span class="sr-only">Acción</span></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($visible as $subject)
                    @php
                        $rowState = $state($subject);
                        $canOpenToArm = $canArrange && ! $subject->arranged();
                    @endphp
                    <tr class="align-top" wire:key="parallels-row-{{ $key($subject) }}" data-testid="parallels-row-{{ $key($subject) }}">
                        <td class="min-w-44">
                            <span class="flex items-center gap-2"><span class="font-mono text-[15px] font-semibold leading-snug">{{ $subject->subject->code }}</span><x-core::campus-tag :campus="$subject->campus" class="desktop:hidden" /></span>
                            <span class="flex items-center gap-1">{{ $subject->subject->name }}
                                @if ($subject->subject->needs_lab)
                                    <x-icon name="o-beaker" class="h-3.5 w-3.5 text-neutral" aria-hidden="true" /><span class="sr-only">(lleva laboratorio)</span>
                                @endif
                            </span>
                        </td>
                        <td class="max-desktop:hidden"><x-core::campus-tag :campus="$subject->campus" /></td>
                        <td class="min-w-52 desktop:min-w-64">
                            <span class="flex max-w-80 flex-wrap gap-1">
                                @foreach ($subject->careers as $career)
                                    @include('scheduling::parallels.career-chip', ['code' => $career->career->code, 'name' => $career->career->name, 'accepted' => $career->isAccepted()])
                                @endforeach
                            </span>
                        </td>
                        <td class="text-right font-mono tabular-nums max-desktop:hidden">{{ $subject->expected() }}</td>
                        <td class="text-right font-mono tabular-nums">{{ $subject->requested() }}</td>
                        <td class="text-right font-mono font-semibold tabular-nums">{{ $subject->suggested() }}</td>
                        <td @class(['text-right font-mono tabular-nums', 'text-success' => $subject->saved() > 0])>{{ $signed($subject->saved()) }}</td>
                        <td class="min-w-36" data-testid="parallels-state-{{ $key($subject) }}">
                            <x-core::status-badge :tone="$rowState['tone']" :label="$rowState['label']" :icon="$rowState['icon']" />
                            @if ($rowState['note'] !== null)
                                <p class="mt-1 max-w-64 text-[12.5px] leading-snug text-neutral">{{ $rowState['note'] }}</p>
                            @endif
                        </td>
                        <td class="text-right">
                            <button type="button" wire:click="open('{{ $subject->subject->id }}', '{{ $subject->campus->id }}')" data-opens="{{ $key($subject) }}"
                                aria-label="{{ $canOpenToArm ? 'Armar' : 'Ver' }} {{ $subject->subject->code }} {{ $subject->subject->name }} en {{ $subject->campus->name }}"
                                @class(['btn btn-sm', 'btn-ghost' => ! $canOpenToArm]) data-testid="parallels-open-{{ $key($subject) }}">{{ $canOpenToArm ? 'Armar' : 'Ver' }}</button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr class="text-sm font-semibold text-base-content">
                    <th scope="row" colspan="2" class="desktop:hidden">{{ count($visible) }} {{ count($visible) === 1 ? 'materia' : 'materias' }}</th>
                    <th scope="row" colspan="3" class="max-desktop:hidden">{{ count($visible) }} {{ count($visible) === 1 ? 'materia' : 'materias' }}</th>
                    <td class="text-right font-mono tabular-nums max-desktop:hidden">{{ array_sum(array_map(fn ($subject) => $subject->expected(), $visible)) }}</td>
                    <td class="text-right font-mono tabular-nums">{{ array_sum(array_map(fn ($subject) => $subject->requested(), $visible)) }}</td>
                    <td class="text-right font-mono tabular-nums">{{ array_sum(array_map(fn ($subject) => $subject->suggested(), $visible)) }}</td>
                    <td class="text-right font-mono tabular-nums">{{ $signed(array_sum(array_map(fn ($subject) => $subject->saved(), $visible))) }}</td>
                    <td colspan="2"></td>
                </tr>
            </tfoot>
        </table>
    </div>

    {{-- Debajo de 1024 px, renglones apilados (maqueta B a 768 y 390 px): campus, clave y estado; nombre; cifras; carreras. --}}
    <ul class="divide-y divide-base-300 overflow-hidden rounded-box border border-base-300 bg-base-100 lg:hidden" data-testid="parallels-stack">
        @foreach ($visible as $subject)
            @php
                $rowState = $state($subject);
            @endphp
            <li wire:key="parallels-stack-{{ $key($subject) }}">
                <button type="button" wire:click="open('{{ $subject->subject->id }}', '{{ $subject->campus->id }}')" data-opens="stack-{{ $key($subject) }}" class="grid w-full gap-1.5 px-4 py-3 text-left hover:bg-base-200/60">
                    <span class="flex items-start justify-between gap-3">
                        <span class="flex items-center gap-2"><x-core::campus-tag :campus="$subject->campus" /><span class="font-mono text-[15px] font-semibold">{{ $subject->subject->code }}</span></span>
                        <x-core::status-badge :tone="$rowState['tone']" :label="$rowState['label']" :icon="$rowState['icon']" />
                    </span>
                    <span>{{ $subject->subject->name }}</span>
                    <span class="flex flex-wrap gap-x-3 text-sm text-neutral">
                        <span>Piden <b class="font-mono font-semibold text-base-content">{{ $subject->requested() }}</b></span>
                        <span>bastan <b class="font-mono font-semibold text-base-content">{{ $subject->suggested() }}</b></span>
                        <span @class(['font-mono', 'text-success' => $subject->saved() > 0])>{{ $signed($subject->saved()) }}</span>
                    </span>
                    @if ($rowState['note'] !== null)
                        <span class="text-[12.5px] leading-snug text-neutral">{{ $rowState['note'] }}</span>
                    @endif
                    <span class="flex flex-wrap gap-1">
                        @foreach ($subject->careers as $career)
                            @include('scheduling::parallels.career-chip', ['code' => $career->career->code, 'name' => $career->career->name, 'accepted' => $career->isAccepted()])
                        @endforeach
                    </span>
                </button>
            </li>
        @endforeach
    </ul>
@endif
