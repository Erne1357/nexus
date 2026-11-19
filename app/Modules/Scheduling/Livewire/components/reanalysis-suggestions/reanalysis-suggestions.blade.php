{{--
    El drawer de sugerencias de una materia en un campus (x-drawer a la derecha, hasta 860 px; ADR-0023): la materia, el
    campus y en cuántos grupos se ofrece; «Demanda por carrera» (esperados con las calificaciones del semestre actual
    contra los cupos de la versión vigente y los de ahora, con la diferencia y la clase de RA-04 contra la versión vigente
    y, si con los lugares de ahora es otra, cómo quedó: «Faltante → ajustada», en `info` con `o-pencil-square` como
    «Ahora», la clase de ahora que usa el asesor (ensayo 8.11, F-08); primer semestre sin clasificar, RA-09); y las
    sugerencias de la tarea 8.3a con los grupos de ahora (scheduling::reanalysis.suggestions).
    Al pie fijo, a quien no ajusta, por qué; a quien ajusta y tiene algo que elegir, cuántas eligió y «Aplicar las N
    elegidas» (`btn-primary`, deshabilitado sin ninguna y mientras viaja el cambio de una casilla), que confirma con el diálogo de Nexus (tono `error` si cierra un grupo) lo que pasa: cuántas
    de cada tipo, qué grupos se cierran y qué horario pierden. En celular, la cuenta arriba y debajo «Cerrar» y «Aplicar»
    (el resto del ancho), de 44 px. `close()` es del x-drawer.
--}}
<div>
    <x-drawer wire:model.live="show" right close-on-escape class="w-full max-w-[860px] p-0!" role="dialog" aria-modal="true" aria-labelledby="suggestions-title" data-testid="suggestions-drawer">
        @if ($block)
            <div class="flex min-h-dvh flex-col">
                <header class="flex items-start gap-3 border-b border-base-300 py-4 ps-5 pe-3">
                    <div class="min-w-0 flex-1">
                        <h2 id="suggestions-title" class="text-xl font-bold leading-tight"><span class="font-mono">{{ $block->subjectCode }}</span> {{ $block->subjectName }}</h2>
                        <p class="mt-1 flex flex-wrap items-center gap-x-1.5 gap-y-0.5 text-sm text-neutral">
                            @if ($block->campus)
                                <x-core::campus-tag :campus="$block->campus" decorative />
                                <span>{{ $block->campus->name }} ·</span>
                            @endif
                            <span>{{ $block->groups }} {{ $block->groups === 1 ? 'grupo publicado' : 'grupos publicados' }} en la versión {{ $load->current_version }}</span>
                        </p>
                    </div>
                    <x-button icon="o-x-mark" class="btn-ghost btn-sm btn-circle max-md:h-11 max-md:w-11" @click="close()" aria-label="Cerrar el panel" />
                </header>

                <div class="flex-1 space-y-6 px-5 pt-4 pb-6">
                    <section class="space-y-2" aria-labelledby="suggestions-demand">
                        <h3 id="suggestions-demand" class="text-base font-bold">Demanda por carrera</h3>
                        <p class="text-[13px] text-neutral">Esperados con las calificaciones del semestre actual contra los cupos de la versión {{ $load->current_version }} y los de ahora, con lo que ya ajustaste.</p>
                        {{-- Desde 768 px, la tabla. En celular (F-07 del ensayo 8.11), sin desplazarse de lado: cada carrera apilada en dos
                             columnas, la clave y su clase arriba y debajo sus cifras en dos líneas, cada una con su etiqueta (como el
                             encabezado de la tabla): Esperados y Cupos, Ahora y Dif.; un filete entre carreras. La clase va arriba a la
                             derecha y, si no cabe con lo que cambió, baja alineada a la derecha; la acomoda el renglón
                             (`[&>td:last-child]`). --}}
                        <div class="overflow-x-auto rounded-box border border-base-300">
                            <table class="table table-sm max-md:block" data-testid="suggestions-evidence">
                                <thead class="max-md:hidden">
                                    <tr class="bg-base-200 text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">
                                        <th>Carrera</th>
                                        <th class="text-end">Esperados</th>
                                        <th class="text-end">Cupos v{{ $load->current_version }}</th>
                                        <th class="text-end">Ahora</th>
                                        <th class="text-end">Dif.</th>
                                        <th>Clase</th>
                                    </tr>
                                </thead>
                                <tbody class="max-md:block">
                                    @foreach ($block->rows as $row)
                                        <tr class="max-md:grid max-md:grid-cols-2 max-md:items-center max-md:gap-x-5 max-md:gap-y-1.5 max-md:border-t max-md:border-base-300 max-md:px-3 max-md:py-3 max-md:first:border-t-0 max-md:*:border-0 max-md:*:p-0 max-md:[&>td:last-child]:col-start-2 max-md:[&>td:last-child]:row-start-1 max-md:[&>td:last-child]:text-end max-md:[&>td:last-child>span]:justify-end" data-testid="suggestions-career" data-career="{{ $row->careerCode }}">
                                            <td class="max-md:col-start-1 max-md:row-start-1">
                                                <span class="font-mono font-semibold" title="{{ $row->careerName }}">{{ $row->careerCode }}</span>
                                                @if ($row->balance === null)
                                                    <span class="ms-1 text-[12.5px] text-neutral md:hidden">Nuevo ingreso</span>
                                                @endif
                                            </td>
                                            <td class="text-end max-md:col-start-1 max-md:row-start-2 max-md:flex max-md:items-baseline max-md:justify-between max-md:gap-2">
                                                <span class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral md:hidden">Esperados</span>
                                                <span>
                                                    @if ($row->balance === null)
                                                        <span class="text-[12.5px] text-neutral max-md:hidden">Nuevo ingreso</span>
                                                    @endif
                                                    <span class="font-mono font-semibold tabular-nums">{{ number_format(\App\Modules\Scheduling\Queries\ReanalysisBoard::shownStudents($row)) }}</span>
                                                </span>
                                            </td>
                                            <td class="text-end max-md:col-start-2 max-md:row-start-2 max-md:flex max-md:items-baseline max-md:justify-between max-md:gap-2">
                                                <span class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral md:hidden">Cupos v{{ $load->current_version }}</span>
                                                <span class="whitespace-nowrap"><span class="font-mono tabular-nums">{{ number_format($row->offeredSeats) }}</span> <span class="text-[12.5px] text-neutral">en {{ $row->offeredGroups }}</span></span>
                                            </td>
                                            @php($now = $advice?->seats[$row->careerId] ?? 0)
                                            <td class="text-end max-md:col-start-1 max-md:row-start-3 max-md:flex max-md:items-baseline max-md:justify-between max-md:gap-2">
                                                <span class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral md:hidden">Ahora</span>
                                                <span data-testid="suggestions-now" data-career="{{ $row->careerCode }}">
                                                    @if ($now !== $row->offeredSeats)
                                                        <span class="inline-flex items-center gap-1 font-mono font-semibold tabular-nums text-info"><x-icon name="o-pencil-square" class="h-3.5 w-3.5" aria-hidden="true" />{{ number_format($now) }}<span class="sr-only">, ya ajustado</span></span>
                                                    @else
                                                        <span class="font-mono tabular-nums">{{ number_format($now) }}</span>
                                                    @endif
                                                </span>
                                            </td>
                                            <td class="text-end font-mono font-semibold tabular-nums max-md:col-start-2 max-md:row-start-3 max-md:flex max-md:items-baseline max-md:justify-between max-md:gap-2"><span class="font-sans text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral md:hidden">Dif.</span><span>{{ $row->balance === null ? '—' : ($row->gap() > 0 ? '+' : ($row->gap() < 0 ? '−' : '')).number_format(abs($row->gap())) }}</span></td>
                                            <td data-testid="suggestions-class" data-career="{{ $row->careerCode }}">
                                                @if ($row->balance === null)
                                                    <span class="inline-flex items-center gap-1.5 whitespace-nowrap text-[13px] text-neutral"><x-icon name="o-minus-circle" class="h-3.5 w-3.5" aria-hidden="true" />Sin clasificar</span>
                                                @else
                                                    @php($balanceNow = $advice?->balances[$row->careerId] ?? null)
                                                    <span class="inline-flex flex-wrap items-center gap-x-1.5 gap-y-1">
                                                        <x-core::status-badge :tone="$row->balance->tone()" :label="$row->balance->label()" :icon="$row->balance->icon()" />
                                                        @if ($balanceNow !== null && $balanceNow !== $row->balance)
                                                            <span class="inline-flex items-center gap-1 whitespace-nowrap text-[13px] font-semibold text-info" title="Con los lugares de ahora: {{ mb_strtolower($balanceNow->label()) }}" data-testid="suggestions-class-now">
                                                                <span aria-hidden="true">→</span>
                                                                <x-icon name="o-pencil-square" class="h-3.5 w-3.5" aria-hidden="true" />
                                                                <span><span class="sr-only">con los lugares de ahora, </span>{{ mb_strtolower($balanceNow->label()) }}</span>
                                                            </span>
                                                        @endif
                                                    </span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </section>

                    @include('scheduling::reanalysis.suggestions', ['block' => $block, 'load' => $load, 'suggestions' => $suggestions, 'advice' => $advice, 'adjustReason' => $adjustReason, 'blocked' => $blocked, 'discardedCount' => $discardedCount, 'editing' => $editing, 'placements' => $placements, 'roomOptions' => $roomOptions, 'choice' => $choice, 'assignUrls' => $assignUrls])
                </div>

                @php($batch = ($choice['choosable'] ?? 0) > 0)
                @php($count = count($choice['chosen'] ?? []))
                @php($confirm = $choice['confirm'] ?? null)
                <footer class="sticky bottom-0 flex flex-wrap items-center justify-end gap-x-4 gap-y-2 border-t border-base-300 bg-base-100 px-5 py-3">
                    @if ($adjustReason !== null)
                        <p class="me-auto text-[13px] text-neutral">{{ $adjustReason }}</p>
                    @elseif ($batch)
                        <p id="suggestions-chosen-count" class="me-auto text-[13px] text-neutral max-md:w-full" aria-live="polite" data-testid="suggestions-chosen-count">
                            @if ($count === 0)
                                Marca las que quieras aplicar juntas.
                            @else
                                <span class="font-mono font-semibold tabular-nums text-base-content">{{ $count }}</span> {{ $count === 1 ? 'elegida' : 'elegidas' }} · cada una se aplica por separado.
                            @endif
                        </p>
                    @endif
                    @if ($batch)
                        <div class="grid grid-cols-[auto_minmax(0,1fr)] gap-2 max-md:w-full md:flex md:items-center">
                            <button type="button" class="btn btn-ghost max-md:h-11" @click="close()">Cerrar</button>
                            <button
                                type="button"
                                class="btn btn-primary max-md:h-11"
                                wire:click="applyChosen"
                                wire:loading.attr="disabled"
                                wire:target="applyChosen,chosen"
                                @disabled($confirm === null)
                                @if ($confirm !== null)
                                    x-confirm="{{ $confirm['message'] }}" data-confirm-title="{{ $confirm['title'] }}" data-confirm-button="{{ $confirm['button'] }}" data-confirm-tone="{{ $confirm['tone'] }}"
                                @else
                                    aria-describedby="suggestions-chosen-count"
                                @endif
                                data-testid="suggestions-apply-chosen"
                            ><span class="loading loading-spinner loading-sm" wire:loading wire:target="applyChosen" aria-hidden="true"></span>{{ match ($count) { 0 => 'Aplicar las elegidas', 1 => 'Aplicar la elegida', default => "Aplicar las {$count} elegidas" } }}</button>
                        </div>
                    @else
                        <x-button label="Cerrar" class="max-md:h-11 max-md:w-full" @click="close()" />
                    @endif
                </footer>
            </div>
        @elseif ($show)
            <div class="px-5 py-8 text-center text-neutral">
                <p>Esta materia ya no está en el diff del re-análisis.</p>
                <x-button label="Cerrar" class="mt-3" @click="close()" />
            </div>
        @endif
    </x-drawer>
</div>
