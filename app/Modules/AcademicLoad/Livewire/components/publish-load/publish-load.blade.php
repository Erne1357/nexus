@php
    $day = fn (\Carbon\CarbonInterface $at): string => str_replace('.', '', $at->timezone(config('app.timezone'))->locale('es')->translatedFormat('j M Y'));
    $clock = fn (\Carbon\CarbonInterface $at): string => $at->timezone(config('app.timezone'))->format('G:i');
    $titleId = "publish-{$loadId}-title";
    $sameDay = collect($versions)->countBy(fn (array $version): string => $day($version['publishedAt']));
@endphp

<section class="bg-base-100 border border-base-300 rounded-box p-4 md:p-5 grid gap-4 min-w-0" aria-labelledby="{{ $titleId }}" data-testid="publish-load">
    <h2 id="{{ $titleId }}" class="sr-only">Publicación de la carga</h2>

    <ol class="flex flex-col gap-3.5 md:flex-row md:gap-0 md:overflow-x-auto md:pb-1" aria-label="Versiones publicadas">
        @foreach ($versions as $version)
            @php($isCurrent = $version['version'] === $current)
            <li class="relative md:min-w-48 md:pe-4.5" wire:key="version-{{ $version['version'] }}">
                <span class="absolute bg-base-300 left-3.25 top-7 -bottom-3.5 w-0.5 md:left-3.5 md:right-0 md:top-3.25 md:bottom-auto md:w-auto md:h-0.5" aria-hidden="true"></span>
                <button type="button" wire:click="toggleVersion({{ $version['version'] }})"
                    aria-expanded="{{ $openVersion === $version['version'] ? 'true' : 'false' }}" aria-controls="{{ $titleId }}-detail"
                    class="relative grid grid-cols-[1.75rem_1fr] md:grid-cols-1 gap-x-3 gap-y-1 md:gap-y-1.5 items-center md:justify-items-start text-left cursor-pointer rounded-field focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary group"
                    data-testid="version-{{ $version['version'] }}">
                    <span @class([
                        'size-7 rounded-full grid place-items-center font-mono text-xs font-semibold border-2 border-success',
                        'bg-success text-success-content' => $isCurrent,
                        'bg-base-100 text-success' => ! $isCurrent,
                    ])>v{{ $version['version'] }}</span>
                    <span class="font-semibold group-aria-expanded:underline underline-offset-3">{{ $day($version['publishedAt']) }}@if ($sameDay[$day($version['publishedAt'])] > 1), {{ $clock($version['publishedAt']) }}@endif @if ($isCurrent) · vigente @endif</span>
                    <span class="col-start-2 md:col-start-1 text-[12.5px] text-neutral"><span class="font-mono">{{ number_format($version['groups']) }}</span> grupos</span>
                </button>
            </li>
        @endforeach

        <li class="relative md:min-w-48">
            @if ($blocked === null)
                <button type="button" wire:click="confirm" class="grid grid-cols-[1.75rem_1fr] md:grid-cols-1 gap-x-3 gap-y-1 md:gap-y-1.5 items-center md:justify-items-start text-left cursor-pointer rounded-field focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary" data-testid="publish-next">
                    <span class="size-7 rounded-full grid place-items-center font-mono text-xs font-semibold border-2 border-dashed border-primary text-primary bg-base-100">v{{ $nextVersion }}</span>
                    <span class="font-semibold text-primary">{{ $nextVersion === 1 ? 'Publicar' : "Publicar versión {$nextVersion}" }}</span>
                    <span class="col-start-2 md:col-start-1 text-[12.5px] text-neutral">{{ $nextVersion === 1 ? 'La primera versión pública' : 'Reemplaza a la v'.($nextVersion - 1).' en la consulta' }}</span>
                </button>
            @else
                <div class="grid grid-cols-[1.75rem_1fr] md:grid-cols-1 gap-x-3 gap-y-1 md:gap-y-1.5 items-center md:justify-items-start" data-testid="publish-blocked">
                    <span class="size-7 rounded-full grid place-items-center border-2 border-dashed border-base-300 bg-base-100" aria-hidden="true"><x-icon name="o-lock-closed" class="w-3.5 h-3.5 text-neutral" /></span>
                    <span class="font-semibold text-neutral">No se puede publicar</span>
                    <span class="col-start-2 md:col-start-1 text-[12.5px] text-neutral max-w-72">{{ $blocked }}</span>
                </div>
            @endif
        </li>
    </ol>

    <div id="{{ $titleId }}-detail" @if ($opened === null) hidden @endif class="border-t border-base-300 pt-3 grid gap-2" data-testid="version-detail">
        @if ($opened !== null)
            <p class="text-[12.5px] text-neutral">
                <span class="font-mono font-semibold text-base-content">v{{ $opened['version'] }}</span>
                · Publicó {{ $opened['publisher'] }} el {{ $day($opened['publishedAt']) }} a las {{ $clock($opened['publishedAt']) }}
                @if ($opened['comment'] !== null) · <span class="text-base-content">«{{ $opened['comment'] }}»</span> @endif
            </p>
            <ul class="flex flex-wrap gap-1.5" aria-label="Contenido y cambios de la versión {{ $opened['version'] }}">
                <li class="badge badge-neutral badge-soft"><span class="font-mono font-semibold">{{ number_format($opened['groups']) }}</span> grupos</li>
                <li class="badge badge-neutral badge-soft"><span class="font-mono font-semibold">{{ number_format($opened['sessions']) }}</span> sesiones</li>
                @if ($changes !== null)
                    @if ($changes['added'] > 0)<li class="badge badge-neutral badge-soft">+{{ $changes['added'] }} {{ $changes['added'] === 1 ? 'grupo' : 'grupos' }}</li>@endif
                    @if ($changes['removed'] > 0)<li class="badge badge-neutral badge-soft">−{{ $changes['removed'] }} {{ $changes['removed'] === 1 ? 'grupo' : 'grupos' }}</li>@endif
                    @if ($changes['teachers'] > 0)<li class="badge badge-neutral badge-soft">{{ $changes['teachers'] }} {{ $changes['teachers'] === 1 ? 'grupo cambió de docente' : 'grupos cambiaron de docente' }}</li>@endif
                    @if ($changes['rooms'] > 0)<li class="badge badge-neutral badge-soft">{{ $changes['rooms'] }} {{ $changes['rooms'] === 1 ? 'grupo cambió de salón' : 'grupos cambiaron de salón' }}</li>@endif
                    @if (array_sum($changes) === 0)<li class="badge badge-neutral badge-soft">Sin cambios en grupos, docentes ni salones</li>@endif
                @endif
            </ul>
        @endif
    </div>

    <x-modal wire:model="confirming" box-class="max-w-xl p-0" aria-labelledby="{{ $titleId }}-confirm" data-testid="publish-modal" x-on:keydown.escape.stop="$wire.cancel()">
        @if ($preview !== null)
            <div class="grid">
                <header class="px-5 pt-5 md:px-6">
                    <h3 id="{{ $titleId }}-confirm" class="text-xl font-bold leading-tight">Publicar la carga</h3>
                    <p class="text-neutral mt-1">{{ $load->department->name }} · {{ $load->campus->name }} · {{ $load->term->name }}</p>
                </header>

                <div class="px-5 py-4 md:px-6 grid gap-4">
                    @if ($vetoes !== [])
                        <div role="alert" class="rounded-box border border-error/30 bg-error/8 text-error px-4 py-3 grid gap-1.5" data-testid="publish-vetoes">
                            <p class="flex items-center gap-2 font-semibold"><x-icon name="o-x-circle" class="w-5 h-5" aria-hidden="true" />No se puede publicar</p>
                            <ul class="list-disc ps-7 grid gap-1">
                                @foreach ($vetoes as $veto)<li>{{ $veto }}</li>@endforeach
                            </ul>
                        </div>
                    @endif

                    @php($deltas = fn (string $key): ?int => $preview['current'] === null ? null : $preview[$key] - $preview['current'][$key])
                    <dl class="grid grid-cols-2 md:grid-cols-4 border border-base-300 rounded-box overflow-hidden" data-testid="publish-counts">
                        @foreach (['groups' => 'Grupos', 'sessions' => 'Sesiones', 'teachers' => 'Docentes', 'rooms' => 'Salones'] as $key => $label)
                            <div @class(['px-3 py-2.5 grid gap-0.5', 'border-s border-base-300' => ! $loop->first, 'max-md:border-s-0' => $loop->index === 2, 'max-md:border-t' => $loop->index >= 2])>
                                <dt class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">{{ $label }}</dt>
                                <dd class="font-mono font-semibold text-xl tabular-nums">{{ number_format($preview[$key]) }}</dd>
                                @if (($delta = $deltas($key)) !== null)
                                    <dd class="text-[12.5px] text-neutral">{{ $delta === 0 ? 'igual' : ($delta > 0 ? '+' : '−').abs($delta) }}@if ($loop->first) {{ $delta === 0 ? 'que la' : 'desde la' }} v{{ $current }}@endif</dd>
                                @endif
                            </div>
                        @endforeach
                    </dl>

                    <ul class="grid gap-2">
                        @if ($preview['withoutTeacher'] > 0)
                            <li class="flex gap-2.5 items-start"><x-icon name="o-exclamation-triangle" class="w-5 h-5 text-warning shrink-0" aria-hidden="true" />
                                <span><strong>{{ $preview['withoutTeacher'] }} {{ $preview['withoutTeacher'] === 1 ? 'grupo sin docente se publica' : 'grupos sin docente se publican' }}</strong> como «Por asignar». La justificación solo la ven Subdirección y el departamento.</span></li>
                        @endif
                        <li class="flex gap-2.5 items-start"><x-icon name="o-globe-alt" class="w-5 h-5 text-neutral shrink-0" aria-hidden="true" />
                            <span>@if ($current === 0) La consulta pública mostrará esta versión. @else La consulta pública mostrará la <strong>versión {{ $nextVersion }}</strong>. La versión {{ $current }} se conserva y deja de mostrarse. @endif</span></li>
                        <li class="flex gap-2.5 items-start"><x-icon name="o-user" class="w-5 h-5 text-neutral shrink-0" aria-hidden="true" />
                            <span>El historial docente queda como dice esta versión.</span></li>
                    </ul>

                    <x-textarea label="Comentario (opcional)" wire:model="comment" placeholder="Qué cambia en esta versión" rows="3" maxlength="500" data-testid="publish-comment" />
                </div>

                <footer class="px-5 pb-5 pt-3.5 md:px-6 border-t border-base-300 flex flex-col-reverse md:flex-row md:justify-end gap-2">
                    <x-button label="Cancelar" class="btn-ghost" wire:click="cancel" />
                    <x-button :label="'Publicar versión '.$nextVersion" icon="o-arrow-up-tray" class="btn-primary" wire:click="publish" spinner="publish" data-testid="publish-confirm" />
                </footer>
            </div>
        @endif
    </x-modal>
</section>
