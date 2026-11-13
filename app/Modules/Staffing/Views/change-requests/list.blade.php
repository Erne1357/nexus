{{--
    La lista de la pantalla de solicitudes de cambio (staffing::pages.change-requests): en tres grupos, en ese orden,
    pendientes, aprobadas por aplicar y resueltas (rechazadas y aplicadas), las más recientes primero. Cada renglón dice
    quién la pide, el grupo (con la etiqueta de su campus de hoy si la modalidad tiene más de uno), el estado, el tipo y lo
    que propone; elegirla la abre a la derecha. La que se abre sola (la
    primera, sin elegirla) solo se marca desde lg, donde se ve su detalle; la elegida va en `bg-secondary`, como la lista de
    la asignación.
--}}
@php
    $tz = config('app.timezone');
    $sections = [
        'Pendientes' => $visible->filter(fn ($request) => $request->status === \App\Modules\Staffing\Enums\ChangeRequestStatus::Pending),
        'Por aplicar' => $visible->filter(fn ($request) => $request->status === \App\Modules\Staffing\Enums\ChangeRequestStatus::Approved),
        'Resueltas' => $visible->filter(fn ($request) => ! $request->status->isOpen()),
    ];
@endphp
@if ($requests->isEmpty())
    <p class="px-4 py-10 text-center text-neutral">No hay solicitudes de cambio en este departamento.</p>
@elseif ($visible->isEmpty())
    <div class="space-y-3 px-4 py-10 text-center text-neutral">
        <p>Ninguna solicitud coincide con los filtros.</p>
        <button type="button" class="btn btn-ghost max-md:h-11" wire:click="$set('status', ''); $set('kind', ''); $set('search', '')">Quitar filtros</button>
    </div>
@else
    @foreach ($sections as $title => $rows)
        @continue($rows->isEmpty())
        <h2 class="flex items-center justify-between border-b border-base-300 bg-secondary/40 px-4 py-2 text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral [&:not(:first-child)]:border-t">
            {{ $title }} <span class="font-mono text-xs normal-case tracking-normal">{{ $rows->count() }}</span>
        </h2>
        <ul class="divide-y divide-base-300">
            @foreach ($rows as $request)
                @php($isSelected = $selected !== null && $selected->id === $request->id)
                <li wire:key="request-{{ $request->id }}">
                    <button type="button" wire:click="pick('{{ $request->id }}')" aria-current="{{ $isSelected ? 'true' : 'false' }}" data-request="{{ $request->id }}"
                        class="group grid w-full min-w-0 cursor-pointer grid-cols-[minmax(0,1fr)_auto] gap-x-3 gap-y-1 px-4 py-3 text-left hover:bg-base-200 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary {{ $requestId !== '' ? 'aria-[current=true]:bg-secondary aria-[current=true]:text-secondary-content' : 'lg:aria-[current=true]:bg-secondary lg:aria-[current=true]:text-secondary-content' }}">
                        <span class="truncate font-semibold">{{ $request->teacher?->name ?? $request->requestedBy?->name }}</span>
                        <span class="text-sm text-neutral tabular-nums group-aria-[current=true]:text-secondary-muted">{{ $request->created_at->timezone($tz)->translatedFormat('j M') }}</span>
                        <span class="col-span-2 truncate text-sm text-neutral group-aria-[current=true]:text-secondary-muted">
                            {{ $request->group->subject->name }} · <span class="font-mono font-semibold text-base-content group-aria-[current=true]:text-secondary-content">{{ $request->group->code }}</span> {{ $request->group->ownerCareer?->code }}
                        </span>
                        <span class="col-span-2 flex flex-wrap items-center gap-1.5">
                            @if ($multiCampus && $request->group->campus !== null)
                                <x-core::campus-tag :campus="$request->group->campus" data-testid="request-campus-{{ $request->id }}" />
                            @endif
                            <x-core::status-badge :tone="$request->status->tone()" :label="$request->status->label()" :icon="$request->status->icon()" />
                            <span class="badge badge-neutral badge-soft h-6.5 gap-1.5 font-medium">
                                <x-icon :name="$request->kind === \App\Modules\Staffing\Enums\ChangeRequestKind::Schedule ? 'o-calendar-days' : 'o-map-pin'" class="h-3.5 w-3.5" aria-hidden="true" />{{ $request->kind->label() }}
                            </span>
                        </span>
                        <span class="col-span-2 line-clamp-2 text-sm">{{ $request->proposed['description'] ?? '' }}</span>
                    </button>
                </li>
            @endforeach
        </ul>
    @endforeach
@endif
