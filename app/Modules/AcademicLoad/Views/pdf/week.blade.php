{{-- PDF de la semana de un docente o de un salón (TeacherPdf, RoomPdf): días por bloques del perfil del campus, elegido
     por el usuario el 10 de noviembre de 2026. Cada sesión abarca sus bloques con el color de su materia. --}}
@extends('academic-load::pdf.layout')

@section('content')
    @if ($cells === [])
        <p class="dim">No hay sesiones publicadas.</p>
    @else
        <div class="meta"><b>{{ rtrim(rtrim(number_format($hours, 1), '0'), '.') }}</b> horas a la semana</div>
        <table style="table-layout: fixed;">
            <thead>
                <tr>
                    <th style="width: 7%;">Hora</th>
                    @foreach ($days as $day)
                        <th>{{ $day['name'] }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($blocks as $row => $block)
                    <tr>
                        <td class="mono dim" style="height: 26px;">{{ $block['label'] }}</td>
                        @foreach ($days as $day)
                            @php($cell = $cells[$day['day']][$row] ?? null)
                            @if ($cell === 'covered')
                                @continue
                            @elseif (is_array($cell))
                                <td rowspan="{{ $cell['span'] }}" style="padding: 2px;">
                                    @foreach ($cell['items'] as $item)
                                        <div style="border-left: 3px solid {{ $item['color'] }}; background: #f6f3f2; padding: 3px 4px; margin-bottom: 2px;">
                                            <div class="mono"><b>{{ $item['label'] }}</b></div>
                                            <div>{{ $item['subject'] }}</div>
                                            <div class="mono dim">{{ $item['hours'] }} · {{ $item['place'] }}@if ($item['kind']) <span class="tag">{{ $item['kind'] }}</span>@endif</div>
                                        </div>
                                    @endforeach
                                </td>
                            @else
                                <td></td>
                            @endif
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
@endsection
