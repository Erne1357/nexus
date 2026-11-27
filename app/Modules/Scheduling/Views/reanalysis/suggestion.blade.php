{{--
    Una sugerencia del re-análisis (tarea 8.3a) en la lista del drawer: el icono de su tipo y su título; debajo el tipo,
    a quién toca y, si pide un salón de otro departamento, la insignia «Pide préstamo» (HC-10, la del préstamo en el
    panel del salón); «Por qué» (la evidencia, en Mono) y «Qué cambia» (el efecto); la advertencia, si la hay (el docente que
    deja de impartir un grupo, en `warning` con su icono); y las acciones. «Editar» abre aquí los lugares que suma, de 1 al
    máximo. Cerrar un grupo pide confirmar con el diálogo de Nexus (ADR-0023). `$reason` es por qué no se puede aplicar (la
    carga o el grupo), o null. En celular, todo apilado y las acciones a lo ancho.

    Abrir un grupo (tarea 8.3b) suma «Horario» (días, horas y salón): antes de buscar, «Buscar horario y salón» (el motor, con lo demás
    fijo; «Buscando lugar…» mientras tanto); con lugar (`$placement`), los días y horas en Mono, cada salón con su
    capacidad y la evidencia del motor en «Por qué», con «Aplicar», «Editar» (otro salón libre de la lista, solo si todas
    sus sesiones van en uno) y «Descartar»; sin lugar, por qué, con la regla al frente, y «Buscar otra vez».

    Quien ajusta (`$choice`, null si no) ve a la izquierda la casilla para elegirla y aplicarla con las demás («Aplicar las
    N elegidas», al pie del drawer), en un blanco de 44 × 40 px centrado con el icono del tipo; la elegida lleva
    `bg-primary/5`. La que no se puede elegir lleva la casilla deshabilitada con su motivo en `title` y para lectores de
    pantalla (el de una bloqueada es el que ya está escrito bajo sus botones); la que choca con una elegida lo escribe
    además bajo sus botones, con `o-no-symbol`; y la que se desmarcó porque cambió desde que se eligió lo dice ahí, en
    `text-warning` con `o-arrow-path`. Un «Cerrar grupo» bloqueado porque el grupo tiene docente lleva, bajo su motivo y solo
    para quien ajusta, «Ir a Asignación docente» (`$assignUrl`; G-05 del recorrido 8.11).
--}}
@php($slug = 'suggestion-'.substr(md5($suggestion->key), 0, 10))
@php($editingThis = $editing === $suggestion->key)
@php($opens = $suggestion->kind === \App\Modules\Scheduling\Enums\ReanalysisSuggestionKind::OpenGroup && $suggestion->newGroup !== null)
@php($placement ??= null)
@php($findTarget = "findPlacement('{$suggestion->key}')")
@php($careerCode = $suggestion->careerId === null ? null : ($advice->careerCodes[$suggestion->careerId] ?? $suggestion->newGroup?->careerCode))
@php($describedBy = $reason === null ? null : $slug.'-why')
@php($choice ??= null)
@php($chosen = in_array($suggestion->key, $choice['chosen'] ?? [], true))
@php($clash = $choice['clashes'][$suggestion->key] ?? null)
@php($notChoosable = $choice['reasons'][$suggestion->key] ?? $clash)
@php($changedNote = $choice['changed'][$suggestion->key] ?? null)
<li wire:key="{{ $slug }}" @class(['relative grid gap-2.5 border-t border-base-300 py-3.5 pe-4 first:border-t-0', 'ps-12' => $choice !== null, 'ps-4' => $choice === null, 'bg-primary/5' => $chosen]) aria-labelledby="{{ $slug }}-title" data-testid="suggestion" data-kind="{{ $suggestion->kind->value }}" data-key="{{ $suggestion->key }}" @if ($chosen) data-chosen @endif>
    @if ($choice !== null)
        <label @class(['absolute start-1 top-2.5 flex h-11 w-10 items-center justify-center', 'cursor-pointer' => $notChoosable === null, 'cursor-not-allowed' => $notChoosable !== null]) @if ($notChoosable !== null) title="{{ $notChoosable }}" @endif>
            <input
                type="checkbox"
                class="checkbox checkbox-sm"
                value="{{ $suggestion->key }}"
                wire:model.live="chosen"
                aria-label="Elegir: {{ $suggestion->title }}"
                @disabled($notChoosable !== null)
                @if ($notChoosable !== null) title="{{ $notChoosable }}" aria-describedby="{{ $clash !== null ? $slug.'-clash' : ($reason !== null ? $slug.'-why' : $slug.'-unchoosable') }}" @endif
                data-testid="suggestion-choose"
            />
            @if ($notChoosable !== null && $clash === null && $reason === null)
                <span id="{{ $slug }}-unchoosable" class="sr-only">{{ $notChoosable }}</span>
            @endif
        </label>
    @endif
    <div class="flex items-start gap-3">
        <span class="mt-0.5 grid h-8 w-8 shrink-0 place-items-center rounded-[6px] border border-base-300 bg-base-200 text-base-content" aria-hidden="true">
            <x-icon :name="$suggestion->kind->icon()" class="h-4 w-4" />
        </span>
        <div class="grid min-w-0 gap-0.5">
            <h4 id="{{ $slug }}-title" class="font-bold leading-snug">{{ $suggestion->title }}</h4>
            <p class="flex flex-wrap items-center gap-x-2 gap-y-1 text-[12.5px] text-neutral">
                <span>{{ $suggestion->kind->label() }}@if ($careerCode) · <span class="font-mono font-semibold">{{ $careerCode }}</span>@endif</span>
                @if ($suggestion->asksLoan())
                    <x-core::status-badge tone="warning" icon="o-arrows-right-left" label="Pide préstamo" data-testid="suggestion-loan" />
                @endif
            </p>
        </div>
    </div>

    <dl class="grid gap-x-4 gap-y-1.5 md:grid-cols-[6.5rem_minmax(0,1fr)] md:ps-11">
        <dt class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral md:pt-0.5">Por qué</dt>
        <dd>
            <ul class="grid gap-0.5 font-mono text-[12.5px] tabular-nums" data-testid="suggestion-evidence">
                @foreach ([...$suggestion->evidence, ...($placement?->evidence ?? [])] as $line)
                    <li class="break-words">{{ $line }}</li>
                @endforeach
            </ul>
        </dd>
        <dt class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral md:pt-0.5">Qué cambia</dt>
        <dd>
            <ul class="grid gap-0.5 text-sm" data-testid="suggestion-effect">
                @foreach ($suggestion->effect as $line)
                    <li>{{ $line }}</li>
                @endforeach
            </ul>
        </dd>
        @if ($opens)
            <dt class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral md:pt-0.5">Horario</dt>
            <dd data-testid="suggestion-placement" aria-live="polite">
                @if ($placement?->isPlaced())
                    <p class="font-mono text-[13px] font-semibold tabular-nums" data-testid="suggestion-schedule">{{ $placement->scheduleLabel() }}</p>
                    <ul class="mt-0.5 flex flex-wrap gap-x-3 gap-y-0.5 text-sm">
                        @foreach ($placement->roomIds() as $roomId)
                            <li class="inline-flex items-center gap-1" data-testid="suggestion-room"><x-icon name="o-map-pin" class="h-3.5 w-3.5 text-neutral" aria-hidden="true" /><span class="font-mono font-semibold">{{ $placement->roomCode($roomId) }}</span><span class="text-neutral" aria-hidden="true">·</span><span class="text-neutral"><span class="font-mono tabular-nums">{{ $placement->rooms[$roomId]['capacity'] ?? '' }}</span> lugares</span></li>
                        @endforeach
                    </ul>
                @elseif ($placement !== null)
                    <p class="flex items-start gap-1.5 text-sm text-warning" data-testid="suggestion-unplaced">
                        <x-icon name="o-exclamation-triangle" class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
                        <span>El motor no le encontró lugar con los demás grupos fijos.</span>
                    </p>
                    <ul class="mt-0.5 grid gap-0.5 font-mono text-[12.5px]">
                        @foreach ($placement->reasons as $line)
                            <li class="break-words">{{ $line }}</li>
                        @endforeach
                    </ul>
                @else
                    <p class="text-sm text-neutral" wire:loading.remove wire:target="{{ $findTarget }}">Lo busca el motor con los demás grupos de la carga fijos.</p>
                    <p class="items-center gap-1.5 text-sm" wire:loading.flex wire:target="{{ $findTarget }}" role="status">
                        <span class="loading loading-spinner loading-xs" aria-hidden="true"></span>Buscando lugar…
                    </p>
                @endif
            </dd>
        @endif
    </dl>

    @if ($suggestion->warning)
        <p class="flex items-start gap-1.5 text-[13px] text-warning md:ps-11" data-testid="suggestion-warning">
            <x-icon name="o-exclamation-triangle" class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
            <span>{{ $suggestion->warning }}</span>
        </p>
    @endif

    @if ($editingThis && $opens)
        <div class="flex flex-wrap items-end gap-2 md:ps-11" data-testid="suggestion-edit">
            <div class="w-full md:w-56">
                <x-select
                    label="Salón"
                    wire:model="editRoomId"
                    :options="array_map(fn (string $id, array $room): array => ['id' => $id, 'name' => $room['code'].' · '.$room['capacity'].' lugares'], array_keys($roomOptions), $roomOptions)"
                    hint="Libres a todas sus horas"
                    class="font-mono"
                    data-testid="suggestion-room-select"
                />
            </div>
            <x-button label="Aplicar" class="btn-primary btn-sm max-md:h-11" wire:click="apply('{{ $suggestion->key }}')" spinner="apply('{{ $suggestion->key }}')" id="{{ $slug }}-apply-edited" data-testid="suggestion-apply-edited" />
            <x-button label="Cancelar" class="btn-ghost btn-sm max-md:h-11" wire:click="cancelEdit" id="{{ $slug }}-cancel-edit" />
        </div>
    @elseif ($editingThis)
        <div class="flex flex-wrap items-end gap-2 md:ps-11" data-testid="suggestion-edit">
            <div class="w-40">
                <x-input
                    :label="'Lugares para '.($careerCode ?? 'la carrera')"
                    type="number"
                    min="1"
                    :max="$suggestion->maxSeats"
                    wire:model="editSeats"
                    :hint="'De 1 a '.$suggestion->maxSeats"
                    class="font-mono"
                    data-testid="suggestion-seats"
                />
            </div>
            <x-button label="Aplicar" class="btn-primary btn-sm max-md:h-11" wire:click="apply('{{ $suggestion->key }}')" spinner="apply('{{ $suggestion->key }}')" id="{{ $slug }}-apply-edited" data-testid="suggestion-apply-edited" />
            <x-button label="Cancelar" class="btn-ghost btn-sm max-md:h-11" wire:click="cancelEdit" id="{{ $slug }}-cancel-edit" />
        </div>
    @else
        <div class="grid grid-cols-3 gap-2 md:flex md:flex-wrap md:ps-11">
            @if ($opens && ! $placement?->isPlaced())
                <x-button
                    :label="$placement === null ? 'Buscar horario y salón' : 'Buscar otra vez'"
                    icon="o-cpu-chip"
                    class="btn-sm col-span-2 max-md:h-11 {{ $placement === null ? 'btn-outline' : 'btn-ghost' }}"
                    wire:click="findPlacement('{{ $suggestion->key }}')"
                    spinner="findPlacement('{{ $suggestion->key }}')"
                    :disabled="$reason !== null"
                    :title="$reason"
                    :aria-describedby="$describedBy"
                    id="{{ $slug }}-find"
                    data-testid="suggestion-find"
                />
            @elseif ($suggestion->isApplicable())
                @if ($suggestion->kind === \App\Modules\Scheduling\Enums\ReanalysisSuggestionKind::CloseGroup)
                    <x-button
                        label="Aplicar"
                        class="btn-outline btn-sm max-md:h-11"
                        wire:click="apply('{{ $suggestion->key }}')"
                        spinner="apply('{{ $suggestion->key }}')"
                        x-confirm="{{ implode(' ', array_filter([...array_slice($suggestion->effect, 0, -1), $suggestion->warning])) }}"
                        data-confirm-title="¿{{ $suggestion->title }}?"
                        data-confirm-button="Cerrar el grupo"
                        data-confirm-tone="error"
                        :disabled="$reason !== null"
                        :title="$reason"
                        :aria-describedby="$describedBy"
                        id="{{ $slug }}-apply"
                        data-testid="suggestion-apply"
                    />
                @else
                    <x-button
                        label="Aplicar"
                        class="btn-outline btn-sm max-md:h-11"
                        wire:click="apply('{{ $suggestion->key }}')"
                        spinner="apply('{{ $suggestion->key }}')"
                        :disabled="$reason !== null"
                        :title="$reason"
                        :aria-describedby="$describedBy"
                        id="{{ $slug }}-apply"
                        data-testid="suggestion-apply"
                    />
                @endif
                @if ($suggestion->isEditable() || ($opens && $placement?->isEditable()))
                    <x-button
                        label="Editar"
                        icon="o-pencil-square"
                        class="btn-ghost btn-sm max-md:h-11"
                        wire:click="edit('{{ $suggestion->key }}')"
                        :disabled="$reason !== null"
                        :title="$reason"
                        id="{{ $slug }}-edit"
                        data-testid="suggestion-edit-open"
                    />
                @endif
            @endif
            <x-button
                label="Descartar"
                class="btn-ghost btn-sm max-md:h-11"
                wire:click="discard('{{ $suggestion->key }}')"
                :disabled="$adjustReason !== null"
                :title="$adjustReason"
                id="{{ $slug }}-discard"
                data-testid="suggestion-discard"
            />
        </div>
        @if ($reason !== null && $adjustReason === null)
            <p id="{{ $slug }}-why" class="flex items-start gap-1.5 text-[13px] text-neutral md:ps-11"><x-icon name="o-lock-closed" class="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />{{ $reason }}</p>
            @if (($assignUrl ?? null) !== null)
                <a href="{{ $assignUrl }}" wire:navigate class="link -mt-1 inline-flex min-h-11 items-center gap-1 justify-self-start text-[13px] font-semibold no-underline hover:underline md:ms-11 md:min-h-0" data-testid="suggestion-assign">Ir a Asignación docente<x-icon name="o-arrow-right" class="h-3.5 w-3.5" aria-hidden="true" /></a>
            @endif
        @elseif ($reason !== null)
            <span id="{{ $slug }}-why" class="sr-only">{{ $reason }}</span>
        @endif
        @if ($clash !== null)
            <p id="{{ $slug }}-clash" class="flex items-start gap-1.5 text-[13px] text-neutral md:ps-11" data-testid="suggestion-clash"><x-icon name="o-no-symbol" class="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />{{ $clash }}</p>
        @endif
        @if ($changedNote !== null)
            <p class="flex items-start gap-1.5 text-[13px] text-warning md:ps-11" data-testid="suggestion-changed"><x-icon name="o-arrow-path" class="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />{{ $changedNote }}</p>
        @endif
    @endif
</li>
