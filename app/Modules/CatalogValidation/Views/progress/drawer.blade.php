{{--
    El drawer de un departamento en el tablero de avance (variante A): sus catálogos con quién y cuándo validó, sus
    observaciones abiertas con «Marcar como resuelta» (CatalogReviewPolicy::resolveFlag) y el último recordatorio con
    «Recordar». `$row` es la fila del tablero, `$flags` sus observaciones abiertas y `$columns` las columnas; `close()`
    es del x-drawer.
--}}
@php($department = $row['department'])
<div class="flex min-h-dvh flex-col" data-testid="progress-panel">
    <header class="flex items-start gap-3 border-b border-base-300 py-4 ps-5 pe-3">
        <div class="min-w-0 flex-1">
            <p class="font-mono text-[15px] font-semibold">{{ $department->code }}</p>
            <h2 id="progress-drawer-title" class="mt-0.5 text-xl font-bold leading-tight">{{ $department->name }}</h2>
            <p class="mt-1 text-sm text-neutral">{{ $row['heads']->isEmpty() ? 'Sin jefe vigente' : $row['heads']->pluck('name')->implode(', ') }}</p>
        </div>
        <x-button icon="o-x-mark" class="btn-ghost btn-sm btn-circle" @click="close()" aria-label="Cerrar el panel" />
    </header>

    <div class="flex-1 space-y-6 px-5 pt-4 pb-8">
        <section class="space-y-1" aria-labelledby="drawer-catalogs">
            <h3 id="drawer-catalogs" class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Catálogos · {{ $row['validated'] }} de {{ $row['total'] }} validados</h3>
            <ul class="divide-y divide-base-300">
                @foreach ($columns as $column)
                    @php($review = $row['cells'][$column['key']])
                    @php($status = $review?->status ?? \App\Modules\CatalogValidation\Enums\ReviewStatus::Pending)
                    <li class="flex items-start justify-between gap-3 py-2.5" data-testid="drawer-catalog-{{ $column['key'] }}">
                        <div class="min-w-0">
                            <p class="font-semibold">{{ $column['catalog']->label() }}@if ($column['campus']) <span class="font-normal text-neutral">· {{ $column['campus']->name }}</span>@endif</p>
                            <p class="text-sm text-neutral">
                                @if ($status === \App\Modules\CatalogValidation\Enums\ReviewStatus::Validated)
                                    Validó {{ $review->validator?->name ?? 'un usuario ya dado de baja' }} · <span class="tabular-nums">{{ $review->validated_at->timezone(config('app.timezone'))->translatedFormat('j M Y, H:i') }}</span>
                                @elseif ($status === \App\Modules\CatalogValidation\Enums\ReviewStatus::InReview)
                                    En revisión desde <span class="tabular-nums">{{ $review->updated_at->timezone(config('app.timezone'))->translatedFormat('j M, H:i') }}</span>
                                @else
                                    Pendiente
                                @endif
                            </p>
                        </div>
                        <x-core::status-badge :tone="$status->tone()" :label="$status->label()" />
                    </li>
                @endforeach
            </ul>
        </section>

        <section class="space-y-3" aria-labelledby="drawer-flags">
            <h3 id="drawer-flags" class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Observaciones abiertas · {{ $flags->count() }}</h3>

            @forelse ($flags->groupBy(fn ($flag) => $flag->review->catalog->value) as $catalogFlags)
                @php($review = $catalogFlags->first()->review)
                <div class="space-y-2" wire:key="flag-group-{{ $review->id }}" data-testid="drawer-catalog-flags-{{ $review->catalog->value }}">
                    <p class="text-sm font-semibold">{{ $review->catalog->label() }}@if ($review->campus) <span class="font-normal text-neutral">· {{ $review->campus->name }}</span>@endif <span class="font-mono font-normal text-neutral tabular-nums">{{ $catalogFlags->count() }}</span></p>
                    @foreach ($catalogFlags->groupBy(fn ($flag) => $flag->flaggable_type.'|'.$flag->flaggable_id) as $recordFlags)
                        @php($record = $recordFlags->first()->flaggable)
                        <article class="space-y-2 rounded-box border border-base-300 bg-base-100 p-3.5" data-testid="drawer-record-{{ $recordFlags->first()->flaggable_id }}">
                            <p class="font-semibold"><span class="font-mono">{{ $record?->code ?? $record?->employee_number }}</span>@if ($record === null) · Registro ya no disponible @elseif (filled($record->name)) · {{ $record->name }}@endif</p>
                            <ul class="divide-y divide-base-300">
                                @foreach ($recordFlags as $flag)
                                    <li wire:key="flag-{{ $flag->id }}" class="space-y-1.5 py-2 first:pt-0 last:pb-0" data-testid="drawer-flag-{{ $flag->id }}">
                                        <x-core::status-badge tone="warning" icon="o-chat-bubble-left-ellipsis" :label="$flag->flag->label()" />
                                        @if ($flag->comment)
                                            <p class="text-sm">{{ $flag->comment }}</p>
                                        @endif
                                        <p class="text-xs text-neutral">{{ $flag->flagger?->name ?? 'Un usuario ya dado de baja' }} · <span class="tabular-nums">{{ $flag->created_at->timezone(config('app.timezone'))->translatedFormat('j M Y, H:i') }}</span></p>
                                        @can('resolveFlag', $flag->review)
                                            <div class="flex justify-end">
                                                <x-button label="Marcar como resuelta" icon="o-check" class="btn-sm" wire:click="resolveFlag('{{ $flag->id }}')" spinner="resolveFlag('{{ $flag->id }}')" data-testid="resolve-flag-{{ $flag->id }}" />
                                            </div>
                                        @endcan
                                    </li>
                                @endforeach
                            </ul>
                        </article>
                    @endforeach
                </div>
            @empty
                <p class="text-sm text-neutral" data-testid="drawer-no-flags">Sin observaciones abiertas.</p>
            @endforelse
        </section>

        <section class="space-y-2" aria-labelledby="drawer-reminder">
            <h3 id="drawer-reminder" class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Recordatorio</h3>
            <p class="text-sm" data-testid="drawer-last-reminder">
                Último recordatorio:
                @if ($row['last_reminder'])
                    <span class="font-semibold tabular-nums">{{ $row['last_reminder']->timezone(config('app.timezone'))->isToday() ? 'hoy, '.$row['last_reminder']->timezone(config('app.timezone'))->format('H:i') : $row['last_reminder']->timezone(config('app.timezone'))->translatedFormat('j M Y, H:i') }}</span>
                @else
                    <span class="text-neutral">nunca</span>
                @endif
            </p>
        </section>
    </div>

    <footer class="sticky bottom-0 flex flex-wrap items-center justify-end gap-2 border-t border-base-300 bg-base-100 px-5 py-3">
        @php($reason = $row['remind_blocked_reason'])
        <x-button label="Recordar" icon="o-bell" wire:click="remind('{{ $department->id }}')" spinner="remind('{{ $department->id }}')" :disabled="$reason !== null" :title="$reason" data-testid="drawer-remind" />
        <x-button label="Ver validación" icon="o-eye" :link="route('catalog-validation.review', ['department' => $department->id])" class="btn-primary" data-testid="drawer-review" />
    </footer>
</div>
