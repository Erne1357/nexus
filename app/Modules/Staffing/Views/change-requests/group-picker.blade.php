{{--
    «Grupo» en los modales de solicitud de cambio (Mi carga y Solicitudes de cambio): una lista de radios ligada a
    `requestGroupId`, cada grupo con su clave en Mono, su materia y un renglón de datos (horario, campus o departamento).
    El que no se puede pedir va deshabilitado con su motivo escrito. Con más de 8 grupos, arriba una búsqueda que filtra
    en el navegador, sin importar mayúsculas ni acentos. Espera $groupOptions: list<array{id, code, subject, detail,
    reason: ?string}>.
--}}
@php($searchable = count($groupOptions) > 8)
<fieldset class="fieldset py-0" data-testid="request-group"
    x-data="{ q: '', fold: (text) => (text ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim(), matches(el) { return this.fold(el.dataset.search).includes(this.fold(this.q)) } }">
    <legend class="fieldset-legend mb-1">Grupo</legend>
    @if ($searchable)
        <x-input x-model="q" icon="o-magnifying-glass" placeholder="Clave, materia o departamento" aria-label="Buscar grupo" class="mb-2" data-testid="request-group-search" />
    @endif
    @if ($groupOptions === [])
        <p class="rounded-field border border-base-300 px-3 py-3 text-sm text-neutral">No hay grupos en que puedas pedir un cambio.</p>
    @else
        <div role="radiogroup" aria-label="Grupo" class="relative max-h-64 text-sm divide-y divide-base-300 overflow-y-auto overscroll-contain rounded-field border border-base-300" x-ref="groups">
            @foreach ($groupOptions as $option)
                <label wire:key="request-group-{{ $option['id'] }}" data-group-option="{{ $option['id'] }}"
                    data-search="{{ $option['code'].' '.$option['subject'].' '.$option['detail'] }}" x-show="matches($el)"
                    @class([
                        'flex items-start gap-3 px-3 py-2.5 has-focus-visible:outline-2 has-focus-visible:-outline-offset-2 has-focus-visible:outline-primary',
                        'cursor-pointer hover:bg-base-200 has-checked:bg-secondary has-checked:text-secondary-content' => $option['reason'] === null,
                        'cursor-not-allowed text-neutral' => $option['reason'] !== null,
                    ])>
                    <input type="radio" name="request-group" value="{{ $option['id'] }}" wire:model="requestGroupId" class="radio radio-sm mt-0.5 shrink-0" @disabled($option['reason'] !== null) />
                    <span class="min-w-0">
                        <span class="block font-medium"><span class="font-mono font-semibold">{{ $option['code'] }}</span> · {{ $option['subject'] }}</span>
                        <span class="block text-neutral">{{ $option['detail'] }}@if ($option['reason']) · <span class="font-medium">{{ $option['reason'] }}</span>@endif</span>
                    </span>
                </label>
            @endforeach
            @if ($searchable)
                <p class="px-3 py-3 text-sm text-neutral" x-show="q !== '' && ! [...$refs.groups.querySelectorAll('[data-group-option]')].some((el) => matches(el))" x-cloak>Ningún grupo coincide con la búsqueda.</p>
            @endif
        </div>
    @endif
    @error('requestGroupId')
        <p class="mt-1.5 text-sm text-error">{{ $message }}</p>
    @enderror
</fieldset>
