{{--
    «Orden de selección» en un drawer de ancho `max-w-xl` (maqueta B, «b-orden»), abierto desde la barra del turno: la regla del orden
    en un párrafo y la tabla por escalón (SEL-06 y SEL-07): lugar, docente («tú»), turno (con «empate» si comparte la
    media hora), escalón («TC → ¾» y cuántos criterios, o las horas del CSV), los cuatro criterios con su marca (y su
    texto para lectores de pantalla), antigüedad y estado. Quien no viene en el CSV va al final, sin datos (SEL-10). Quien
    ya trae sus grupos (elección previa, SEL-16) va arriba y aparte, sin lugar ni turno y con sus horas. Todos
    los de la cola lo ven completo (decisión 16). Un solo diseño de renglones compactos (sin tabla ancha, 1.52): lugar, nombre, estado, turno, escalón y criterios, con un lugar para etiquetas cortas por fila. Espera $order, $selection, $teacher.
--}}
@use('App\Modules\Scheduling\Validation\Minutes')
@use('Carbon\CarbonImmutable')
@php
    $criteria = [
        'auto_eval' => ['Autoev.', 'Autoevaluación'],
        'prior_compliance' => ['Cumpl.', 'Cumplimiento de asignación previa'],
        'partials_1' => ['Parc. 1', 'Parcial 1'],
        'partials_2' => ['Parc. 2', 'Parcial 2'],
    ];
    $extraRound = $selection->status === \App\Modules\Staffing\Enums\SelectionStatus::ExtraRound;
    $slot = function (?string $iso): ?string {
        if ($iso === null) {
            return null;
        }

        $at = CarbonImmutable::parse($iso)->setTimezone(config('app.timezone'));

        return Minutes::dayLabel($at->dayOfWeekIso, true).' '.$at->format('G:i');
    };
    $tiers = collect($order)->groupBy(fn ($row) => $row['prior'] ? 'prior' : ($row['source'] === 'appended' ? 'nd' : (string) $row['tier']), preserveKeys: false);
    $extraStarts = $selection->extra_round_starts_at?->setTimezone(config('app.timezone'));
@endphp
<x-drawer wire:model.live="showOrder" right close-on-escape class="w-full max-w-xl p-0!" role="dialog" aria-modal="true" aria-labelledby="selection-order-title" data-testid="selection-order-drawer">
    @if ($showOrder)
        <div class="flex min-h-dvh flex-col" data-testid="selection-order">
            <header class="flex items-start gap-3 border-b border-base-300 py-4 ps-5 pe-3">
                <div class="min-w-0 flex-1">
                    <h2 id="selection-order-title" class="text-xl font-bold leading-tight">Orden de selección</h2>
                    <p class="mt-1 text-sm text-neutral">
                        <span class="font-mono font-semibold">{{ $selection->department?->code }}</span> {{ $selection->department?->name }} · {{ $selection->term?->name }} ·
                        {{ count($order) }} {{ count($order) === 1 ? 'docente' : 'docentes' }} · turnos de {{ $selection->slot_minutes }} min
                    </p>
                </div>
                <x-button icon="o-x-mark" class="btn-ghost btn-sm btn-circle max-md:h-11 max-md:w-11" @click="close()" aria-label="Cerrar el orden" />
            </header>

            <div class="flex-1 space-y-4 px-5 pt-4 pb-6">
                <p class="flex gap-2 text-sm text-neutral">
                    <x-icon name="o-information-circle" class="mt-0.5 h-4 w-4 flex-none" aria-hidden="true" />
                    <span>Va primero la plaza (TC, ¾, ½, Asignatura) y cada criterio que no se cumple baja un escalón. Dentro del escalón va primero quien cumple más criterios y luego la antigüedad; con todo igual se comparte el turno y el primero que elige se queda el grupo. Quien no viene en el CSV va al final, por su antigüedad en Nexus. Quien ya trae sus grupos escritos (elección previa) va arriba, sin turno. Los turnos no se recorren si alguien termina antes.</span>
                </p>

                <div class="space-y-4">
                    @foreach ($tiers as $key => $rows)
                        <section wire:key="tier-{{ $key }}" aria-labelledby="tier-title-{{ $key }}" class="min-w-0">
                            <h3 id="tier-title-{{ $key }}" class="mb-1.5 flex items-baseline gap-2 text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">
                                {{ match ($key) { 'prior' => 'Elección previa · ya traen sus grupos, sin turno', 'nd' => 'Sin datos · al final, por antigüedad en Nexus', default => $rows->first()['tier_label'] } }}
                                <span class="font-mono">{{ $rows->count() }}</span>
                            </h3>
                            <ol class="divide-y divide-base-300 overflow-hidden rounded-box border border-base-300">
                                @foreach ($rows as $row)
                                    @php($me = $teacher !== null && $row['teacher_id'] === $teacher->id)
                                    <li wire:key="order-{{ $row['position'] }}-{{ $row['teacher_id'] }}" data-testid="{{ $row['prior'] ? 'order-row-prior-'.$row['teacher_id'] : 'order-row-'.$row['position'] }}" @class(['grid grid-cols-[1.75rem_minmax(0,1fr)] gap-x-2 gap-y-1 px-3 py-2.5', 'bg-info/10' => $me])>
                                        <span @class(['pt-0.5 text-end font-mono', 'font-semibold text-info' => $row['in_turn'], 'text-neutral' => ! $row['in_turn']])>{{ $row['prior'] ? '—' : $row['position'] }}</span>
                                        <div class="min-w-0 space-y-1">
                                            <div class="flex flex-wrap items-start justify-between gap-x-2 gap-y-1">
                                                <p class="flex min-w-[10rem] flex-1 flex-wrap items-center gap-x-2 gap-y-1">
                                                    <b class="min-w-0 break-words">{{ $row['name'] }}</b>
                                                    @if ($me)<span class="badge badge-info badge-sm">tú</span>@endif
                                                    {{-- Lugar de las etiquetas cortas por fila (p. ej. «Puesto administrativo», 1.54). --}}
                                                    <span class="contents" data-slot="order-row-tags"></span>
                                                </p>
                                                @include('staffing::offer.selection.status', ['status' => $row['status'], 'inTurn' => $row['in_turn'], 'confirmation' => $row['confirmation']])
                                            </div>
                                            <p class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5 text-[13px] text-neutral">
                                                @if ($row['prior'])
                                                    <span>Sin turno</span>
                                                    <span><b class="font-mono text-base-content">{{ $row['hours'] }} h</b> de sus grupos escritos en la relación</span>
                                                @else
                                                    <span class="whitespace-nowrap">
                                                        <span class="font-mono text-base-content">{{ $extraRound && $row['extra_slot'] ? $slot($row['extra_slot']) : $slot($row['slot']) }}</span>@if ($extraRound && $row['extra_slot']) <span class="text-[12px]">ronda extra</span>@endif
                                                        @if ($row['tie'])<span class="ms-1 inline-flex items-center gap-0.5 text-[12px] font-semibold text-info" title="Mismo escalón, criterios y antigüedad: comparten el turno"><x-icon name="o-link" class="h-3 w-3" aria-hidden="true" />empate</span>@endif
                                                    </span>
                                                    @if ($row['source'] === 'appended')
                                                        <span>Sin datos · no viene en el CSV{{ $row['hired_on'] ? ' · ingresó en '.substr($row['hired_on'], 0, 4) : '' }}</span>
                                                    @else
                                                        <span class="whitespace-nowrap"><b class="text-base-content">{{ $row['plaza_tier_label'] }}</b>@if ($row['tier_label'] !== $row['plaza_tier_label']) → <b class="text-base-content">{{ $row['tier_label'] }}</b>@endif · {{ $row['failed_criteria'] > 0 ? $row['failed_criteria'].' '.($row['failed_criteria'] === 1 ? 'criterio' : 'criterios') : $row['base_hours'].' h en el CSV' }}</span>
                                                        @if ($row['seniority'] !== null)
                                                            <span class="whitespace-nowrap"><span class="font-mono text-base-content">{{ $row['seniority'] }}</span> años</span>
                                                        @elseif ($row['hired_on'])
                                                            <span class="whitespace-nowrap">ingresó {{ substr($row['hired_on'], 0, 4) }}</span>
                                                        @endif
                                                    @endif
                                                @endif
                                            </p>
                                            @if ($row['criteria'] !== null)
                                                <p class="flex flex-wrap gap-x-3 gap-y-1 text-[12.5px]">
                                                    @foreach ($criteria as $field => [$short, $long])
                                                        <span class="inline-flex items-center gap-1">@include('staffing::offer.selection.criterion', ['value' => $row['criteria'][$field] ?? null, 'label' => $long, 'noData' => false]){{ $short }}</span>
                                                    @endforeach
                                                </p>
                                            @endif
                                        </div>
                                    </li>
                                @endforeach
                            </ol>
                        </section>
                    @endforeach
                </div>

                <p class="text-sm text-neutral">
                    @if ($extraStarts)
                        La ronda extra empieza el {{ $extraStarts->isoFormat('dddd D [de] MMMM') }} a las {{ $extraStarts->format('G:i') }}, en el mismo orden, para quien no terminó o terminó con menos horas.
                    @else
                        Quien no termina en su turno, o termina con menos horas, vuelve a elegir en la ronda extra, en el mismo orden.
                    @endif
                </p>
            </div>

            <footer class="sticky bottom-0 flex justify-end border-t border-base-300 bg-base-100 px-5 py-3">
                <x-button label="Cerrar" class="btn-ghost max-md:h-11" @click="close()" />
            </footer>
        </div>
    @endif
</x-drawer>
