{{--
    Una sugerencia del re-análisis (tarea 8.3a) en la lista del drawer: el icono de su tipo y su título; debajo el tipo y
    a quién toca; «Por qué» (la evidencia, en Mono) y «Qué cambia» (el efecto); la advertencia, si la hay (el docente que
    deja de impartir un grupo, en `warning` con su icono); y las acciones. «Editar» abre aquí los lugares que suma, de 1 al
    máximo. Cerrar un grupo pide confirmar con el diálogo de Nexus (ADR-0023). Abrir un grupo no tiene «Aplicar»: llega con
    el motor. `$reason` es por qué no se puede aplicar (la carga o el grupo), o null. En celular, todo apilado y las
    acciones a lo ancho.
--}}
@php($slug = 'suggestion-'.substr(md5($suggestion->key), 0, 10))
@php($editingThis = $editing === $suggestion->key)
@php($careerCode = $suggestion->careerId === null ? null : ($advice->careerCodes[$suggestion->careerId] ?? null))
@php($describedBy = $reason === null ? null : $slug.'-why')
<li class="grid gap-2.5 border-t border-base-300 px-4 py-3.5 first:border-t-0" aria-labelledby="{{ $slug }}-title" data-testid="suggestion" data-kind="{{ $suggestion->kind->value }}" data-key="{{ $suggestion->key }}">
    <div class="flex items-start gap-3">
        <span class="mt-0.5 grid h-8 w-8 shrink-0 place-items-center rounded-[6px] border border-base-300 bg-base-200 text-base-content" aria-hidden="true">
            <x-icon :name="$suggestion->kind->icon()" class="h-4 w-4" />
        </span>
        <div class="grid min-w-0 gap-0.5">
            <h4 id="{{ $slug }}-title" class="font-bold leading-snug">{{ $suggestion->title }}</h4>
            <p class="text-[12.5px] text-neutral">{{ $suggestion->kind->label() }}@if ($careerCode) · <span class="font-mono font-semibold">{{ $careerCode }}</span>@endif</p>
        </div>
    </div>

    <dl class="grid gap-x-4 gap-y-1.5 md:grid-cols-[6.5rem_minmax(0,1fr)] md:ps-11">
        <dt class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral md:pt-0.5">Por qué</dt>
        <dd>
            <ul class="grid gap-0.5 font-mono text-[12.5px] tabular-nums" data-testid="suggestion-evidence">
                @foreach ($suggestion->evidence as $line)
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
    </dl>

    @if ($suggestion->warning)
        <p class="flex items-start gap-1.5 text-[13px] text-warning md:ps-11" data-testid="suggestion-warning">
            <x-icon name="o-exclamation-triangle" class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
            <span>{{ $suggestion->warning }}</span>
        </p>
    @endif

    @if ($editingThis)
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
            <x-button label="Aplicar" class="btn-primary btn-sm max-md:h-11" wire:click="apply('{{ $suggestion->key }}')" spinner="apply('{{ $suggestion->key }}')" data-testid="suggestion-apply-edited" />
            <x-button label="Cancelar" class="btn-ghost btn-sm max-md:h-11" wire:click="cancelEdit" />
        </div>
    @else
        <div class="grid grid-cols-3 gap-2 md:flex md:flex-wrap md:ps-11">
            @if ($suggestion->isApplicable())
                @if ($suggestion->kind === \App\Modules\Scheduling\Enums\ReanalysisSuggestionKind::CloseGroup)
                    <x-button
                        label="Aplicar"
                        class="btn-outline btn-sm max-md:h-11"
                        wire:click="apply('{{ $suggestion->key }}')"
                        spinner="apply('{{ $suggestion->key }}')"
                        x-confirm="{{ implode(' ', array_filter([...$suggestion->effect, $suggestion->warning])) }}"
                        data-confirm-title="¿{{ $suggestion->title }}?"
                        data-confirm-button="Cerrar el grupo"
                        data-confirm-tone="error"
                        :disabled="$reason !== null"
                        :title="$reason"
                        :aria-describedby="$describedBy"
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
                        data-testid="suggestion-apply"
                    />
                @endif
                @if ($suggestion->isEditable())
                    <x-button
                        label="Editar"
                        icon="o-pencil-square"
                        class="btn-ghost btn-sm max-md:h-11"
                        wire:click="edit('{{ $suggestion->key }}')"
                        :disabled="$reason !== null"
                        :title="$reason"
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
                data-testid="suggestion-discard"
            />
        </div>
        @if ($reason !== null && $adjustReason === null)
            <p id="{{ $slug }}-why" class="flex items-start gap-1.5 text-[13px] text-neutral md:ps-11"><x-icon name="o-lock-closed" class="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />{{ $reason }}</p>
        @elseif ($reason !== null)
            <span id="{{ $slug }}-why" class="sr-only">{{ $reason }}</span>
        @endif
    @endif
</li>
