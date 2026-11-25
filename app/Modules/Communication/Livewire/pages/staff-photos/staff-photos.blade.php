{{--
    «Fotos del personal» (communication::pages.staff-photos, CO-03): la anatomía de una pantalla de catálogo (DESIGN.md):
    barra de herramientas común (búsqueda, «Sin foto / Con foto» y «Subir ZIP»), tabla en una superficie con filete,
    «Subir foto» o «Cambiar foto» visible y lo demás en «Acciones», y debajo de 768 px renglones apilados. La foto de
    una persona va en un drawer (la actual, la subida, la vista previa del recorte 3:4 y «Quitar foto») y el lote en
    ZIP en otro, con el reporte por archivo (ADR-0023). Maqueta A de .impeccable/mocks/decision/comunicacion.
--}}
@php($initials = fn (string $name): string => collect(preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY))->take(2)->map(fn (string $word): string => mb_strtoupper(mb_substr($word, 0, 1)))->implode(''))
<div>
    <x-header title="Fotos del personal" size="text-[1.625rem] md:text-3xl" weight="font-bold" class="mb-6!" use-h1 separator progress-indicator>
        <x-slot:subtitle class="max-w-prose text-sm text-neutral!">
            Las fotos del personal: salen en la pantalla de acceso, en la credencial de la banda y en su ficha. Una foto reemplazada se conserva.
        </x-slot:subtitle>
    </x-header>

    <x-core::catalog-toolbar>
        <x-slot:search>
            <x-input wire:model.live.debounce.300ms="search" placeholder="Nombre o usuario" aria-label="Buscar personal" icon="o-magnifying-glass" clearable />
        </x-slot:search>

        <x-select wire:model.live="photoFilter" aria-label="Foto" :options="$photoFilters" placeholder="Con y sin foto" />

        <x-slot:action>
            <x-button label="Subir ZIP" icon="o-archive-box" class="btn-primary" wire:click="openZip" spinner="openZip" data-testid="zip-open" />
        </x-slot:action>
    </x-core::catalog-toolbar>

    <div class="overflow-hidden rounded-box border border-base-300 bg-base-100">
        @if ($people->isEmpty())
            <p class="px-4 py-8 text-center text-sm text-neutral" data-testid="empty">Nadie coincide con la búsqueda.</p>
        @else
            <table class="table max-md:hidden [&_td]:px-3 [&_td]:py-2 [&_th]:px-3 [&_th]:text-[11.5px] [&_th]:font-medium [&_th]:uppercase [&_th]:tracking-[0.09em] [&_th]:text-secondary-muted [&_thead]:bg-secondary/40" data-testid="photos-table">
                <thead>
                    <tr>
                        <th>Persona</th>
                        <th class="max-[1023px]:hidden">Unidad</th>
                        <th>Foto</th>
                        <th class="max-[1023px]:hidden">Actualizada</th>
                        <th class="w-px"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($people as $row)
                        @php($url = $photoUrls[$row->id] ?? null)
                        <tr wire:key="row-{{ $row->id }}" data-testid="photo-row">
                            <td>
                                <div class="flex items-center gap-3">
                                    @include('communication::staff-photos.thumb', ['url' => $url, 'initials' => $initials($row->name), 'size' => 'w-11'])
                                    <div class="min-w-0">
                                        <p class="font-semibold">{{ $row->name }}</p>
                                        <p class="font-mono text-sm tabular-nums text-neutral">{{ $row->username }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="text-sm text-neutral max-[1023px]:hidden">{{ $row->units === [] ? '—' : implode(' · ', $row->units) }}</td>
                            <td>
                                @if ($url !== null)
                                    <x-core::status-badge tone="success" label="Con foto" />
                                @else
                                    <x-core::status-badge tone="draft" label="Sin foto" icon="o-camera" />
                                @endif
                            </td>
                            <td class="whitespace-nowrap text-sm text-neutral max-[1023px]:hidden">{{ $row->photo_updated_at?->format('d/m/Y') ?? '—' }}</td>
                            <td>@include('communication::staff-photos.row-actions', ['person' => $row, 'photo' => $url, 'inTable' => true])</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <ul class="divide-y divide-base-300 md:hidden" data-testid="photos-cards">
                @foreach ($people as $row)
                    @php($url = $photoUrls[$row->id] ?? null)
                    <li wire:key="card-{{ $row->id }}" class="space-y-1 px-4 py-3">
                        <div class="flex items-center gap-3">
                            @include('communication::staff-photos.thumb', ['url' => $url, 'initials' => $initials($row->name), 'size' => 'w-11'])
                            <div class="min-w-0 flex-1">
                                <p class="font-semibold">{{ $row->name }}</p>
                                <p class="font-mono text-sm tabular-nums text-neutral">{{ $row->username }}</p>
                            </div>
                            @if ($url !== null)
                                <x-core::status-badge tone="success" label="Con foto" />
                            @else
                                <x-core::status-badge tone="draft" label="Sin foto" icon="o-camera" />
                            @endif
                        </div>
                        @include('communication::staff-photos.row-actions', ['person' => $row, 'photo' => $url, 'inTable' => false])
                    </li>
                @endforeach
            </ul>

            <div class="flex flex-wrap items-center justify-between gap-2 border-t border-base-300 px-4 py-2.5 text-sm text-neutral">
                <span>{{ $people->total() }} {{ $people->total() === 1 ? 'persona' : 'personas' }}</span>
                {{ $people->onEachSide(1)->links(data: ['scrollTo' => false]) }}
            </div>
        @endif
    </div>

    <x-drawer wire:model.live="showPhoto" right close-on-escape class="w-full max-w-md p-0!" role="dialog" aria-modal="true" aria-labelledby="photo-drawer-title" data-testid="photo-drawer">
        @if ($showPhoto && $person !== null)
            <form wire:submit="savePhoto" class="flex h-dvh flex-col">
                <header class="flex items-start gap-3 border-b border-base-300 px-5 pt-5 pb-4">
                    @include('communication::staff-photos.thumb', ['url' => $personPhoto, 'initials' => $initials($person->name), 'size' => 'w-14'])
                    <div class="min-w-0">
                        <h2 id="photo-drawer-title" class="text-xl font-bold">{{ $personPhoto === null ? 'Subir foto' : 'Cambiar foto' }}</h2>
                        <p class="text-sm text-neutral">{{ $person->name }} · <span class="font-mono tabular-nums">{{ $person->username }}</span></p>
                    </div>
                    <button type="button" class="btn btn-ghost btn-sm btn-square ms-auto max-md:size-11" wire:click="closePhoto" aria-label="Cerrar"><x-icon name="o-x-mark" class="h-5 w-5" aria-hidden="true" /></button>
                </header>

                <div class="flex-1 space-y-5 overflow-y-auto px-5 py-4">
                    @if ($confirmRemove)
                        <div class="rounded-box border border-error/40 bg-error/5 p-4" data-testid="photo-remove-confirm">
                            <p class="font-semibold">¿Quitar la foto de {{ $person->name }}?</p>
                            <p class="mt-1 text-sm text-neutral">Vuelve a verse con sus iniciales. El archivo se conserva.</p>
                            <div class="mt-3 flex flex-wrap gap-2">
                                <x-button label="Quitar foto" icon="o-trash" class="btn-error max-md:h-11" wire:click="removePhoto" spinner="removePhoto" data-testid="photo-remove-confirm-yes" />
                                <x-button label="No quitarla" class="max-md:h-11" wire:click="$set('confirmRemove', false)" />
                            </div>
                        </div>
                    @endif

                    <x-file label="Foto nueva" name="photo" wire:model="photo" accept="image/jpeg,image/png,image/webp" hint="jpg, png o webp de hasta 10 MB." data-testid="photo-input" />
                    @error('photo')
                        <p class="text-sm text-error" data-testid="photo-error">{{ $message }}</p>
                    @enderror

                    <div class="grid grid-cols-2 gap-4">
                        @if ($personPhoto !== null)
                            <figure class="space-y-1.5">
                                <img src="{{ $personPhoto }}" alt="Foto actual de {{ $person->name }}" class="aspect-3/4 w-full rounded-box border border-base-300 bg-base-200 object-cover object-top" />
                                <figcaption class="text-xs text-neutral">Actual</figcaption>
                            </figure>
                        @endif
                        @if ($preview !== null)
                            <figure class="space-y-1.5" data-testid="photo-preview">
                                <img src="{{ $preview }}" alt="Vista previa del recorte" class="aspect-3/4 w-full rounded-box border border-base-300 bg-base-200 object-cover object-top" />
                                <figcaption class="text-xs text-neutral">Así se verá: recorte 3:4, centrado y pegado arriba.</figcaption>
                            </figure>
                        @endif
                    </div>
                    @if ($personPhoto !== null && $preview !== null)
                        <p class="text-sm text-neutral">La foto anterior se conserva.</p>
                    @endif
                </div>

                <footer class="flex flex-wrap justify-end gap-2 border-t border-base-300 px-5 py-3">
                    @if ($personPhoto !== null && ! $confirmRemove)
                        <x-button label="Quitar foto" icon="o-trash" class="btn-ghost text-error me-auto" wire:click="askRemove" data-testid="photo-remove" />
                    @endif
                    <x-button label="Cancelar" wire:click="closePhoto" />
                    <x-button label="Guardar" type="submit" icon="o-check" class="btn-primary" spinner="savePhoto" data-testid="photo-save" />
                </footer>
            </form>
        @endif
    </x-drawer>

    <x-drawer wire:model.live="showZip" right close-on-escape class="w-full max-w-xl p-0!" role="dialog" aria-modal="true" aria-labelledby="zip-drawer-title" data-testid="zip-drawer">
        @if ($showZip)
            <div class="flex h-dvh flex-col" {!! $batch !== null && $batch->status->isPending() ? 'wire:poll.2s' : '' !!}>
                <header class="flex items-start gap-3 border-b border-base-300 px-5 pt-5 pb-4">
                    <div>
                        <h2 id="zip-drawer-title" class="text-xl font-bold">Subir lote en ZIP</h2>
                        <p class="text-sm text-neutral">Cada archivo se llama como el usuario: <span class="font-mono">RFC.jpg</span>. Las carpetas dentro del ZIP se ignoran.</p>
                    </div>
                    <button type="button" class="btn btn-ghost btn-sm btn-square ms-auto max-md:size-11" wire:click="closeZip" aria-label="Cerrar"><x-icon name="o-x-mark" class="h-5 w-5" aria-hidden="true" /></button>
                </header>

                <div class="flex-1 space-y-5 overflow-y-auto px-5 py-4">
                    @if ($batch === null)
                        <form wire:submit="uploadZip" class="space-y-3">
                            <x-file label="Archivo ZIP" name="zip" wire:model="zip" accept=".zip,application/zip" hint="Hasta 19 MB; para más fotos, varios lotes." data-testid="zip-input" />
                            @error('zip')
                                <p class="text-sm text-error" data-testid="zip-error">{{ $message }}</p>
                            @enderror
                            <x-button label="Subir lote" type="submit" icon="o-arrow-up-tray" class="btn-primary" spinner="uploadZip" data-testid="zip-submit" />
                        </form>

                        @if ($recentBatches->isNotEmpty())
                            <section class="space-y-2" aria-labelledby="zip-recent-title">
                                <h3 id="zip-recent-title" class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-secondary-muted">Lotes anteriores</h3>
                                <ul class="divide-y divide-base-300 rounded-box border border-base-300">
                                    @foreach ($recentBatches as $recent)
                                        <li wire:key="recent-{{ $recent->id }}" class="flex flex-wrap items-center gap-2 px-3 py-2">
                                            <span class="min-w-0 flex-1 truncate font-mono text-sm">{{ $recent->original_name }}</span>
                                            <span class="text-sm text-neutral">{{ $recent->created_at->format('d/m/Y H:i') }}</span>
                                            <x-core::status-badge :tone="$recent->status->tone()" :label="$recent->status->label()" />
                                            <x-button label="Ver" class="btn-ghost btn-sm max-md:h-11" wire:click="showBatch('{{ $recent->id }}')" data-testid="zip-recent-{{ $recent->id }}" />
                                        </li>
                                    @endforeach
                                </ul>
                            </section>
                        @endif
                    @else
                        @php($rows = $batch->report ?? [])
                        @php($counts = collect($rows)->countBy('outcome'))
                        <section class="space-y-3" data-testid="zip-batch">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-mono text-sm">{{ $batch->original_name }}</span>
                                <x-core::status-badge :tone="$batch->status->tone()" :label="$batch->status->label()" data-testid="zip-status" />
                            </div>

                            @if ($batch->status->isPending())
                                <p class="text-sm text-neutral" data-testid="zip-progress">
                                    {{ $batch->status === \App\Modules\Communication\Enums\PhotoBatchStatus::EnCola ? 'Esperando su turno en la cola.' : "Procesando: {$batch->processed} de {$batch->total} archivos." }}
                                </p>
                                @if ($batch->total > 0)
                                    <progress class="progress progress-primary w-full" value="{{ $batch->processed }}" max="{{ $batch->total }}" aria-label="Avance del lote"></progress>
                                @endif
                            @elseif ($batch->status === \App\Modules\Communication\Enums\PhotoBatchStatus::Fallido)
                                <p class="text-sm text-error" data-testid="zip-failed">El lote falló{{ $batch->error ? ": {$batch->error}" : '.' }} Revisa el ZIP y súbelo de nuevo.</p>
                            @else
                                <div class="flex flex-wrap gap-2" data-testid="zip-summary">
                                    @foreach (\App\Modules\Communication\Enums\PhotoOutcome::cases() as $outcome)
                                        @if (($counts[$outcome->value] ?? 0) > 0)
                                            <x-core::status-badge :tone="$outcome->tone()" :label="$counts[$outcome->value].' '.mb_strtolower($outcome->label())" />
                                        @endif
                                    @endforeach
                                </div>
                            @endif

                            @if ($rows !== [])
                                <div class="overflow-hidden rounded-box border border-base-300" data-testid="zip-report">
                                    <ul class="divide-y divide-base-300">
                                        @foreach ($rows as $row)
                                            @php($outcome = \App\Modules\Communication\Enums\PhotoOutcome::from($row['outcome']))
                                            <li class="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2" data-testid="zip-report-row">
                                                <span class="min-w-0 flex-1 break-all font-mono text-sm">{{ $row['file'] }}</span>
                                                <x-core::status-badge :tone="$outcome->tone()" :label="$outcome->label()" />
                                                @if ($row['message'] !== null)
                                                    <span class="w-full text-sm text-neutral">{{ $row['message'] }}</span>
                                                @endif
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif
                        </section>
                    @endif
                </div>

                <footer class="flex flex-wrap justify-end gap-2 border-t border-base-300 px-5 py-3">
                    @if ($batch !== null && ! $batch->status->isPending())
                        <x-button label="Subir otro" wire:click="anotherZip" data-testid="zip-another" />
                    @endif
                    <x-button :label="$batch === null ? 'Cancelar' : 'Listo'" :class="$batch === null ? '' : 'btn-primary'" wire:click="closeZip" />
                </footer>
            </div>
        @endif
    </x-drawer>
</div>
