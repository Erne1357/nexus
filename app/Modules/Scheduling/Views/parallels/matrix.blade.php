{{--
    La matriz carrera × grupo del drawer de «Paralelos» (maqueta B), desde 1024 px. Un renglón por carrera que cuenta: su
    clave y el estado de su parte, sus esperados, sus completos (con − y + si se edita; debajo, los esperados que llevan) y
    un campo por mixto con cuántos de sus esperados van ahí; al final, si le quedan esperados sin lugar o de más. Cada mixto
    dice en su encabezado si es un grupo que ya existe («es 2 de ISIC») o nuevo, y se quita con su ×. Al pie, por grupo,
    los esperados contra la capacidad con su barra y los cupos repartidos; si pasan la capacidad van en `error` con HC-07,
    su icono y su texto. Sin permiso o con la carga cerrada, las cifras sin campos.
--}}
@php
    $slotOf = array_flip($draft->columns);
    $fullSlots = array_values(array_filter($draft->slots, fn ($slot): bool => $slot->full));
    $placedLeft = array_sum(array_filter($draft->left, fn (int $left): bool => $left > 0));
@endphp
<div class="overflow-x-auto rounded-box border border-base-300 max-lg:hidden" data-testid="parallels-matrix">
    <table class="w-full border-collapse text-sm">
        <caption class="sr-only">Esperados de cada carrera en cada grupo de {{ $row->subject->name }} en {{ $row->campus->name }}</caption>
        <thead>
            <tr class="border-b border-base-300 bg-base-200/60 text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">
                <th scope="col" class="px-3 py-2 text-left">Carrera</th>
                <th scope="col" class="px-3 py-2 text-right">Esperados</th>
                <th scope="col" class="px-3 py-2 text-right">Completos<span class="block normal-case tracking-normal">de {{ $row->size() }}</span></th>
                @foreach ($draft->mixed as $column => $shares)
                    @php
                        $slot = isset($slotOf[$column]) ? $draft->slots[$slotOf[$column]] : null;
                    @endphp
                    <th scope="col" class="min-w-28 border-s border-base-300 bg-info/5 px-3 py-2 text-right" wire:key="mixed-head-{{ $column }}" data-testid="parallels-column-{{ $column }}">
                        <span class="flex items-start justify-end gap-1">
                            <span>
                                <span class="block text-sm font-semibold normal-case tracking-normal text-base-content">Mixto {{ $column + 1 }}</span>
                                <span class="block text-[11.5px] normal-case tracking-normal">{{ $slot === null ? 'vacío: no se crea' : ($slot->keeps ? 'es '.$slot->keeps->code.' de '.$draft->code($slot->keeps->ownerCareerId) : 'nuevo') }}</span>
                            </span>
                            @if ($editable)
                                <button type="button" wire:click="removeMixed({{ $column }})" class="btn btn-ghost btn-xs btn-square -me-1.5 text-neutral" aria-label="Quitar Mixto {{ $column + 1 }}"><x-icon name="o-x-mark" class="h-3.5 w-3.5" aria-hidden="true" /></button>
                            @endif
                        </span>
                    </th>
                @endforeach
                <th scope="col" class="px-3 py-2 text-left">
                    @if ($editable)
                        <button type="button" wire:click="addMixed" class="btn btn-sm btn-ghost border border-dashed border-base-content/30 normal-case tracking-normal" data-testid="parallels-add-mixed"><x-icon name="o-plus" class="h-3.5 w-3.5" aria-hidden="true" />Mixto</button>
                    @else
                        <span class="sr-only">Sin lugar</span>
                    @endif
                </th>
            </tr>
        </thead>
        <tbody>
            @foreach ($row->careers as $career)
                @php
                    $id = $career->career->getKey();
                    $left = $draft->left[$id];
                @endphp
                <tr class="border-b border-base-300 last:border-b-0" wire:key="matrix-{{ $id }}" data-testid="parallels-career-{{ $career->career->code }}">
                    <th scope="row" class="px-3 py-1.5 text-left font-normal">
                        <span class="flex items-center gap-2">
                            @include('scheduling::parallels.career-chip', ['code' => $career->career->code, 'name' => $career->career->name, 'accepted' => $career->isAccepted()])
                            <span class="text-[12.5px] text-neutral">{{ $career->isAccepted() ? 'aceptada' : 'pendiente' }}</span>
                        </span>
                    </th>
                    <td class="px-3 py-1.5 text-right font-mono tabular-nums">{{ $career->expected }}</td>
                    <td class="px-3 py-1.5 text-right">
                        <span class="inline-flex items-center gap-1">
                            @if ($editable)
                                <button type="button" wire:click="step('{{ $id }}', -1)" class="btn btn-ghost btn-xs btn-square" aria-label="Un completo menos de {{ $career->career->code }}" @disabled($draft->full[$id] === 0)><x-icon name="o-minus" class="h-3.5 w-3.5" aria-hidden="true" /></button>
                            @endif
                            <b class="min-w-5 text-center font-mono tabular-nums" data-testid="parallels-full-{{ $career->career->code }}">{{ $draft->full[$id] }}</b>
                            @if ($editable)
                                <button type="button" wire:click="step('{{ $id }}', 1)" class="btn btn-ghost btn-xs btn-square" aria-label="Un completo más de {{ $career->career->code }}"><x-icon name="o-plus" class="h-3.5 w-3.5" aria-hidden="true" /></button>
                            @endif
                            <span class="inline-block min-w-8 text-right font-mono text-[12.5px] text-neutral tabular-nums">{{ $draft->full[$id] > 0 ? $draft->full[$id] * $row->size() : '' }}</span>
                        </span>
                    </td>
                    @foreach ($draft->mixed as $column => $shares)
                        <td class="border-s border-base-300 bg-info/5 px-3 py-1.5 text-right" wire:key="cell-{{ $column }}-{{ $id }}">
                            @if ($editable)
                                <input type="number" min="0" max="999" inputmode="numeric" wire:model.live.debounce.400ms="mixed.{{ $column }}.{{ $id }}"
                                    class="input input-sm w-16 text-right font-mono tabular-nums" aria-label="Esperados de {{ $career->career->code }} en Mixto {{ $column + 1 }}" />
                            @elseif (($shares[$id] ?? 0) > 0)
                                <span class="font-mono tabular-nums">{{ $shares[$id] }}</span>
                            @else
                                <span class="text-neutral" aria-label="ninguno">·</span>
                            @endif
                        </td>
                    @endforeach
                    <td class="px-3 py-1.5" data-testid="parallels-left-{{ $career->career->code }}">
                        @if ($left > 0)
                            <x-core::status-badge tone="warning" icon="o-exclamation-triangle" :label="$left.' sin lugar'" />
                        @elseif ($left < 0)
                            <x-core::status-badge tone="warning" icon="o-exclamation-triangle" :label="abs($left).' de más'" />
                        @else
                            <x-icon name="o-check" class="h-4 w-4 text-success" aria-hidden="true" /><span class="sr-only">Todos sus esperados tienen grupo</span>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
        <tfoot class="border-t border-base-300 bg-base-200/40">
            <tr>
                <th scope="row" class="px-3 pt-2.5 pb-1 text-left font-semibold">Esperados en el grupo</th>
                <td class="px-3 pt-2.5 pb-1 text-right font-mono tabular-nums">{{ $row->expected() }}</td>
                <td class="px-3 pt-2.5 pb-1 text-right font-mono tabular-nums">{{ count($fullSlots) }} {{ count($fullSlots) === 1 ? 'grupo' : 'grupos' }}</td>
                @foreach ($draft->mixed as $column => $shares)
                    @php
                        $slot = isset($slotOf[$column]) ? $draft->slots[$slotOf[$column]] : null;
                        $sum = array_sum($shares);
                    @endphp
                    <td class="border-s border-base-300 px-3 pt-2.5 pb-1 text-right">
                        @if ($slot === null)
                            <span class="text-neutral">—</span>
                        @else
                            <span @class(['font-mono tabular-nums', 'font-semibold text-error' => $sum > $slot->capacity])>
                                @if ($sum > $slot->capacity)
                                    <x-icon name="o-exclamation-triangle" class="inline h-3.5 w-3.5 align-[-2px]" aria-hidden="true" />
                                @endif
                                <b>{{ $sum }}</b> / {{ $slot->capacity }}
                            </span>
                            <span class="mt-1 block h-1.5 overflow-hidden rounded-full border border-base-300 bg-[image:repeating-linear-gradient(135deg,color-mix(in_oklab,var(--color-neutral)_14%,var(--color-base-100))_0_3px,var(--color-base-100)_3px_6px)]" aria-hidden="true">
                                <span @class(['block h-full', 'bg-error/70' => $sum > $slot->capacity, 'bg-info/45' => $sum <= $slot->capacity]) style="width: {{ min(100, $slot->capacity > 0 ? $sum / $slot->capacity * 100 : 0) }}%"></span>
                            </span>
                        @endif
                    </td>
                @endforeach
                <td class="px-3 pt-2.5 pb-1">
                    @if ($placedLeft > 0)
                        <span class="font-mono text-[12.5px] font-semibold text-warning">{{ $placedLeft }} sin lugar</span>
                    @endif
                </td>
            </tr>
            <tr>
                <th scope="row" class="px-3 pt-1 pb-2.5 text-left font-semibold">Cupos repartidos</th>
                <td class="px-3 pt-1 pb-2.5"></td>
                <td class="px-3 pt-1 pb-2.5 text-right font-mono tabular-nums">{{ array_sum(array_map(fn ($slot) => array_sum($slot->quotas), $fullSlots)) }}</td>
                @foreach ($draft->mixed as $column => $shares)
                    @php
                        $slot = isset($slotOf[$column]) ? $draft->slots[$slotOf[$column]] : null;
                    @endphp
                    <td class="border-s border-base-300 px-3 pt-1 pb-2.5 text-right">
                        @if ($slot === null)
                            <span class="text-neutral">—</span>
                        @elseif ($slot->over() > 0)
                            <span class="inline-flex flex-wrap items-center justify-end gap-1 font-mono font-semibold text-error tabular-nums" data-testid="parallels-over-{{ $column }}">
                                <x-icon name="o-x-circle" class="h-3.5 w-3.5" aria-hidden="true" />{{ array_sum($slot->quotas) }} / {{ $slot->capacity }}
                                <span class="rounded-[4px] border border-error/60 px-1 text-[11px] leading-4">HC-07</span>
                            </span>
                        @else
                            <span class="font-mono tabular-nums">{{ array_sum($slot->quotas) }} / {{ $slot->capacity }}</span>
                        @endif
                    </td>
                @endforeach
                <td class="px-3 pt-1 pb-2.5"></td>
            </tr>
        </tfoot>
    </table>
</div>
