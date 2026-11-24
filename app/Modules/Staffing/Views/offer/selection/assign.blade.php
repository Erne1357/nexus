{{--
    «Asignar a…» de la jefatura en el drawer del grupo (maqueta B, «b-asignar»): el docente entre los de la cola en el
    orden de selección, con su escalón, lo que lleva contra su base, con cuál de sus grupos le choca (no se puede
    elegir) y su estado; para quien asigna salones, el salón sin el filtro de la selección (diseño 6.14): primero los
    recomendados (los elegibles) y luego los demás del campus, del departamento o sin dueño, con su motivo; el motivo
    opcional con la selección viva y, ya terminada, el de HC-16 si no es el primer lugar del ranking.
    Espera $card, $candidates, $managerRooms (SelectionRoomOptionsQuery::forManager), $canAssignRoom y $teacherId (el
    elegido; al abrir, el de ahora).
--}}
@use('App\Modules\Facilities\Enums\RoomType')
<section class="space-y-2" aria-labelledby="assign-teacher-title" data-testid="selection-assign-panel">
    <div class="flex flex-wrap items-baseline justify-between gap-2">
        <h3 id="assign-teacher-title" class="text-base font-bold">Docente</h3>
        <span class="text-[12.5px] text-neutral">En el orden de selección</span>
    </div>
    <x-input wire:model.live.debounce.250ms="teacherSearch" icon="o-magnifying-glass" placeholder="Nombre del docente" aria-label="Buscar docente" clearable />
    <ul class="max-h-80 divide-y divide-base-300 overflow-y-auto overscroll-contain rounded-field border border-base-300" role="radiogroup" aria-labelledby="assign-teacher-title">
        @forelse ($candidates as $row)
            <li wire:key="candidate-{{ $row['teacher_id'] }}">
                <label @class([
                    'flex items-start gap-3 px-3 py-2.5',
                    'cursor-pointer hover:bg-base-200' => $row['clash_with'] === null,
                    'text-neutral' => $row['clash_with'] !== null,
                    'bg-secondary/60' => $row['teacher_id'] === $teacherId,
                ])>
                    <input type="radio" class="radio radio-sm mt-0.5" name="assign-teacher" value="{{ $row['teacher_id'] }}" wire:model.live="teacherId" @disabled($row['clash_with'] !== null) />
                    <span class="w-6 flex-none text-end font-mono text-neutral">{{ $row['position'] }}</span>
                    <span class="min-w-0 flex-1">
                        <b class="block">{{ $row['name'] }}</b>
                        <span class="block text-[12.5px] text-neutral">{{ $row['tier_label'] ?? 'Sin datos' }} · lleva <span class="font-mono">{{ $row['hours'] }}</span> de <span class="font-mono">{{ $row['base_hours'] }}</span> h</span>
                        @if ($row['clash_with'] !== null)
                            <span class="mt-0.5 flex items-center gap-1 text-[12.5px] font-semibold text-error"><x-icon name="o-x-circle" class="h-3.5 w-3.5" aria-hidden="true" />Choca con su {{ $row['clash_with'] }}</span>
                        @endif
                    </span>
                    @include('staffing::offer.selection.status', ['status' => $row['status'], 'inTurn' => $row['in_turn']])
                </label>
            </li>
        @empty
            <li class="px-3 py-4 text-sm text-neutral">Ningún docente de la cola coincide.</li>
        @endforelse
    </ul>
    @foreach (['teacherId', 'teacher', 'group', 'reason'] as $field)
        @error($field)
            <p class="text-sm font-semibold text-error" role="alert">{{ $message }}</p>
        @enderror
    @endforeach
</section>

<section class="space-y-3" aria-labelledby="assign-room-title">
    <h3 id="assign-room-title" class="text-base font-bold">Salón</h3>
    @if ($canAssignRoom)
        @php
            $roomLabel = fn (array $room): string => $room['code'].' · '.(RoomType::tryFrom($room['type'])?->label() ?? $room['type']).' · '.$room['capacity'].' lugares'
                .($room['capacity'] === $card['capacity'] ? ' · justo' : '')
                .(($room['reason'] ?? null) !== null ? ' — '.$room['reason'] : '');
            $groups = array_filter([
                'Recomendado' => array_map(fn ($room) => ['id' => $room['id'], 'name' => $roomLabel($room)], $managerRooms['recommended']),
                'Otros salones (con aviso o rechazo al guardar)' => array_map(fn ($room) => ['id' => $room['id'], 'name' => $roomLabel($room)], $managerRooms['others']),
            ]);
        @endphp
        <x-select-group label="Salón" wire:model.live="roomId" placeholder="Sin cambiar el salón" placeholder-value="" :options="$groups"
            hint="Recomendado: los que se pueden elegir en la selección. Los demás se guardan con su aviso (cupo, tipo o bloqueo); uno ocupado a esa hora no se guarda." data-testid="selection-assign-room" />
    @else
        <p class="text-sm text-neutral">El salón lo asigna el jefe del departamento.</p>
    @endif
    @if ($selection->status->isLive())
        <x-textarea label="Motivo (opcional)" wire:model="reason" rows="2" placeholder="Por qué lo asignas a mano" />
    @else
        <x-textarea label="Motivo" wire:model="reason" rows="2" placeholder="Por qué lo asignas a mano"
            hint="La selección terminó: si no es el primer lugar del ranking del grupo, el motivo es obligatorio (HC-16)." />
    @endif
</section>
