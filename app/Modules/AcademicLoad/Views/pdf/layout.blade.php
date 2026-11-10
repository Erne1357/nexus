{{-- Marco común de los PDF de lo publicado (ADR-0021): carta, DejaVu Sans, los colores de DESIGN.md escritos aquí porque
     DomPDF no carga app.css. Encabezado con la institución, el título y el campus y periodo; pie con la fecha de
     actualización y el número de página. --}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        @page { margin: 66px 34px 44px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 8.5pt; color: #092d48; }
        header { position: fixed; top: -50px; left: 0; right: 0; height: 38px; border-bottom: 2px solid #c2201e; }
        header .org { font-size: 7.5pt; color: #666567; }
        header .brand { font-size: 10pt; font-weight: bold; }
        footer { position: fixed; bottom: -30px; left: 0; right: 0; height: 18px; font-size: 7pt; color: #666567; }
        footer .page:after { content: "Página " counter(page); }
        h1 { font-size: 15pt; margin: 0 0 2px; }
        .meta { color: #666567; font-size: 8.5pt; margin-bottom: 10px; }
        .meta b { color: #092d48; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #f6f3f2; color: #666567; font-size: 7pt; text-transform: uppercase; letter-spacing: 0.06em; text-align: left; padding: 5px 6px; border-bottom: 1px solid #e3e0de; }
        td { vertical-align: top; padding: 4px 6px; border-bottom: 1px solid #e3e0de; }
        .mono { font-family: 'DejaVu Sans Mono', monospace; }
        .dim { color: #666567; }
        .tag { font-size: 6.5pt; color: #2b5c8a; text-transform: uppercase; letter-spacing: 0.04em; }
    </style>
</head>
<body>
    <header>
        <table><tr>
            <td style="border: 0; padding: 0;"><span class="brand">Nexus · Horarios</span><br><span class="org">Instituto Tecnológico de Ciudad Juárez</span></td>
            <td style="border: 0; padding: 0; text-align: right;" class="org">{{ $campus->name }} · {{ $term->name }}</td>
        </tr></table>
    </header>
    <footer>
        <table><tr>
            <td style="border: 0; padding: 0;">Horarios oficiales del Instituto Tecnológico de Ciudad Juárez{{ $updated ? ' · Actualizado el '.$updated : '' }}</td>
            <td style="border: 0; padding: 0; text-align: right;" class="page"></td>
        </tr></table>
    </footer>
    <main>
        <h1>{{ $title }}</h1>
        <div class="meta">{{ $subtitle }}</div>
        @yield('content')
    </main>
</body>
</html>
