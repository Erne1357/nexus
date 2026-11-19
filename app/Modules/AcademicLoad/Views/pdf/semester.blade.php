{{-- PDF de una carrera, retícula y semestre (SemesterPdf) y, con `$staff`, el de un departamento (DepartmentPdf): tabla
     tipo SII, elegida por el usuario el 10 de noviembre de 2026. Por materia, su renglón con su color y los nombres de las
     columnas y, debajo, un renglón por grupo con docente y una columna por día. Solo el del personal lleva la capacidad y
     la marca «préstamo» del salón de otro departamento. Tarea 8.5: bajo «CLAVE · grupo», la marca de lo que le cambió en
     la última publicación; un grupo cancelado en ella va con la clave tachada, «Cancelado» y sin horario.

     Memoria (E-05 del ensayo 8.11): DomPDF arma un objeto por cada nodo del documento y una tabla partida entre páginas no
     suelta las que ya imprimió, así que el PDF de un departamento con cientos de grupos en una sola tabla se acababa la
     memoria. Cada materia es su propia tabla, con anchos fijos para que las columnas cuadren entre materias y su
     encabezado en `thead`, que DomPDF repite si la materia sigue en la página siguiente; cada celda de día lleva sus
     sesiones como texto con `<br>`, sin un bloque por renglón. --}}
@extends('academic-load::pdf.layout')

@section('content')
    @if ($subjects === [])
        <p class="dim">No hay grupos publicados para este semestre.</p>
    @else
        @php
            $staff ??= false;
            $columns = ($staff ? 3 : 2) + count($days);
            $dayWidth = round((100 - ($staff ? 36 : 30)) / max(count($days), 1), 2);
        @endphp
        @foreach ($subjects as $subject)
            <table class="subject">
                <thead>
                    <tr>
                        <td colspan="{{ $columns }}" class="band" style="border-left: 4px solid {{ $subject['color'] }};"><span class="mono"><b>{{ $subject['code'] }}</b></span>&nbsp;&nbsp;<b>{{ $subject['name'] }}</b><span class="dim">&nbsp;·&nbsp;{{ $subject['credits'] }} créditos · {{ count($subject['groups']) }} {{ count($subject['groups']) === 1 ? 'grupo' : 'grupos' }}</span></td>
                    </tr>
                    <tr>
                        <th style="width: 11%;">Grupo</th>
                        <th style="width: 19%;">Docente</th>
                        @if ($staff)
                            <th style="width: 6%;">Cap.</th>
                        @endif
                        @foreach ($days as $day)
                            <th style="width: {{ $dayWidth }}%;">{{ $day['name'] }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($subject['groups'] as $group)
                        <tr>
                            <td class="mono" style="border-left: 4px solid {{ $subject['color'] }};"><b @class(['gone' => $group['cancelled']])>{{ $subject['code'] }} · {{ $group['code'] }}</b>@if (($group['campus'] ?? null) !== null)<br><span class="dim">{{ $group['campus'] }}</span>@endif @if ($group['change'] !== null)<br><span @class(['tag change', 'change-added' => $group['change']['added'], 'change-cancelled' => $group['cancelled']])>{{ $group['change']['label'] }}</span>@endif</td>
                            <td @class(['dim' => $group['teacher'] === 'Por asignar'])>{{ $group['teacher'] }}</td>
                            @if ($staff)
                                <td class="mono">{{ $group['cancelled'] ? '' : $group['capacity'] }}</td>
                            @endif
                            @if ($group['cancelled'])
                                <td colspan="{{ count($days) }}" class="dim">Se canceló este grupo.</td>
                            @else
                                @foreach ($days as $day)
                                    @php($sessions = $group['cells'][$day['day']] ?? [])
                                    @if ($sessions === [])
                                        <td class="dim">—</td>
                                    @else
                                        <td class="mono">@foreach ($sessions as $cell)<b>{{ $cell['hours'] }}</b><br>{{ $cell['place'] }}@foreach (array_filter([$cell['kind'], $staff && $cell['loan'] ? 'Préstamo' : null]) as $tag) <span class="tag">{{ $tag }}</span>@endforeach{!! $loop->last ? '' : '<br>' !!}@endforeach</td>
                                    @endif
                                @endforeach
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endforeach
    @endif
@endsection
