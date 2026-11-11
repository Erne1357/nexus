{{-- PDF de una carrera, retícula y semestre (SemesterPdf) y, con `$staff`, el de un departamento (DepartmentPdf): tabla
     tipo SII, elegida por el usuario el 10 de noviembre de 2026. Un renglón por materia con su color y, debajo, uno por
     grupo con docente y una columna por día. Solo el del personal lleva la capacidad y la marca «préstamo» del salón de
     otro departamento. --}}
@extends('academic-load::pdf.layout')

@section('content')
    @if ($subjects === [])
        <p class="dim">No hay grupos publicados para este semestre.</p>
    @else
        <table>
            <thead>
                <tr>
                    <th style="width: 11%;">Grupo</th>
                    <th style="width: 19%;">Docente</th>
                    @if ($staff ?? false)
                        <th style="width: 6%;">Cap.</th>
                    @endif
                    @foreach ($days as $day)
                        <th>{{ $day['name'] }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($subjects as $subject)
                    <tr style="page-break-inside: avoid;">
                        <td colspan="{{ (($staff ?? false) ? 3 : 2) + count($days) }}" style="background: #f6f3f2; border-left: 4px solid {{ $subject['color'] }}; padding: 5px 6px;">
                            <span class="mono"><b>{{ $subject['code'] }}</b></span>&nbsp;&nbsp;<b>{{ $subject['name'] }}</b>
                            <span class="dim">&nbsp;·&nbsp;{{ $subject['credits'] }} créditos · {{ count($subject['groups']) }} {{ count($subject['groups']) === 1 ? 'grupo' : 'grupos' }}</span>
                        </td>
                    </tr>
                    @foreach ($subject['groups'] as $group)
                        <tr style="page-break-inside: avoid;">
                            <td style="border-left: 4px solid {{ $subject['color'] }};" class="mono"><b>{{ $subject['code'] }} · {{ $group['code'] }}</b></td>
                            <td @class(['dim' => $group['teacher'] === 'Por asignar'])>{{ $group['teacher'] }}</td>
                            @if ($staff ?? false)
                                <td class="mono">{{ $group['capacity'] }}</td>
                            @endif
                            @foreach ($days as $day)
                                <td>
                                    @forelse ($group['cells'][$day['day']] ?? [] as $cell)
                                        <div class="mono"><b>{{ $cell['hours'] }}</b></div>
                                        <div class="mono">{{ $cell['place'] }}@if ($cell['kind']) <span class="tag">{{ $cell['kind'] }}</span>@endif @if (($staff ?? false) && $cell['loan']) <span class="tag">Préstamo</span>@endif</div>
                                    @empty
                                        <span class="dim">—</span>
                                    @endforelse
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                @endforeach
            </tbody>
        </table>
    @endif
@endsection
