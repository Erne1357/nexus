"""Perfilador de las exportaciones del SII para la migración a Nexus (tarea 1.16).

Lee una carpeta de exportaciones (CSV) y escribe `_perfil.md` en la misma carpeta: codificación, separador,
anotaciones al final, renglones malformados, estadística por columna, claves repetidas, cobertura entre
archivos (huérfanos) y revisiones propias de la migración. Se corre con `sii-profile.sh`, que lo ejecuta en un
contenedor sin red; en el corte oficial se vuelve a correr sobre la exportación nueva para compararla.

Privacidad (CLAUDE.md, regla dura 9): las columnas de DROP nunca se cargan; las de PRIVATE se cargan para ligar
archivos pero el informe solo da conteos y la «forma» de sus valores (letra → A, dígito → 9), nunca un valor.
"""

from __future__ import annotations

import csv
import io
import itertools
import re
import sys
import unicodedata
from dataclasses import dataclass, field
from pathlib import Path

import pandas as pd

csv.field_size_limit(sys.maxsize)

# Nunca se cargan: contraseñas, identificadores oficiales y datos personales que la migración no usa.
DROP = {
    'nip', 'curp_alumno', 'curp_empleado', 'curp', 'foto', 'firma', 'fecha_nacimiento', 'lugar_nacimiento',
    'estado_civil', 'domicilio_empleado', 'colonia_empleado', 'codigo_postal_empleado', 'telefono_empleado',
    'domicilio_escuela', 'clave_servicio_medico', 'padre', 'madre', 'conyuge', 'hijos', 'num_acta', 'num_libro',
    'num_foja', 'num_ano', 'num_cartilla_smn', 'ano_clase', 'pigmentacion', 'pelo', 'frente', 'cejas', 'ojos',
    'nariz', 'boca', 'estaturamts', 'pesokg', 'senas_visibles', 'pasaporte', 'fm', 'cedula_profesional',
    'telefono', 'domicilio', 'password', 'contrasena', 'localidad',
}

# Se cargan (sirven para ligar o se importan) pero nunca se imprime un valor.
PRIVATE = {
    'no_de_control', 'no_tarjeta', 'rfc', 'rfc2', 'apellido_paterno', 'apellido_materno', 'nombre_alumno',
    'apellidos_empleado', 'nombre_empleado', 'apellidop', 'apellidom', 'correo_electronico', 'usuario',
    'observaciones_empleado', 'clave_interna', 'nombre', 'apellidos', 'correo', 'email',
}

EMAIL = {'correo_electronico', 'correo', 'email'}

# Archivo canónico → nombres de archivo que lo representan y claves naturales a revisar.
FILES: dict[str, dict] = {
    'organigrama': {'stems': ['organigrama'], 'keys': [['clave_area']]},
    'carreras': {'stems': ['carreras'], 'keys': [['carrera', 'reticula'], ['clave_oficial']]},
    'carreras_tecnm': {'stems': ['carreras_tecnm'], 'keys': [['cv_carr']]},
    'especialidades': {'stems': ['especialidades'], 'keys': [['carrera', 'reticula', 'especialidad'], ['especialidad']]},
    'materias': {'stems': ['materias'], 'keys': [['materia']]},
    'materias_carreras': {'stems': ['materias_carreras'], 'keys': [['carrera', 'reticula', 'materia']]},
    'materias_claves_tecnm': {'stems': ['materias_claves_tecnm'], 'keys': [['carrera', 'reticula', 'materia']]},
    'aulas': {'stems': ['aulas'], 'keys': [['aula']]},
    'docentes': {'stems': ['docentes', 'personal'], 'keys': [['rfc'], ['no_tarjeta']]},
    'docentes2': {'stems': ['docentes2'], 'keys': [['rfc']]},
    'alumnos': {'stems': ['alumnos_todos', 'alumnos'], 'keys': [['no_de_control']]},
    'grupos': {'stems': ['grupos'], 'keys': [['periodo', 'materia', 'grupo']]},
    'horarios': {'stems': ['horarios'], 'keys': []},
    'kardex': {'stems': ['kardex', 'historia_alumno', 'historial_academico', 'historia_academica'],
               'keys': [['no_de_control', 'materia', 'periodo']]},
    'seriacion': {'stems': ['seriacion', 'prerrequisitos', 'prerequisitos', 'materias_prerrequisitos'], 'keys': []},
    'periodos': {'stems': ['periodos', 'periodos_escolares'], 'keys': [['periodo']]},
    'evaluacion': {'stems': ['evaluacion', 'evaluacion_docente', 'evaluaciones', 'evaluacion_docentes'], 'keys': []},
}

# Columnas que en cualquier archivo apuntan a otro: (columnas del hijo) → (archivo padre, columnas del padre).
REFERENCES: list[tuple[tuple[str, ...], str, tuple[str, ...]]] = [
    (('clave_area',), 'organigrama', ('clave_area',)),
    (('area_depende',), 'organigrama', ('clave_area',)),
    (('clave_area_academica',), 'organigrama', ('clave_area',)),
    (('id_unidad_organica',), 'organigrama', ('clave_area',)),
    (('carrera', 'reticula'), 'carreras', ('carrera', 'reticula')),
    (('exclusivo_carrera', 'exclusivo_reticula'), 'carreras', ('carrera', 'reticula')),
    (('carrera', 'reticula', 'especialidad'), 'especialidades', ('carrera', 'reticula', 'especialidad')),
    (('materia',), 'materias', ('materia',)),
    (('rfc',), 'docentes', ('rfc',)),
    (('no_de_control',), 'alumnos', ('no_de_control',)),
    (('aula',), 'aulas', ('aula',)),
    (('periodo',), 'periodos', ('periodo',)),
    (('periodo', 'materia', 'grupo'), 'grupos', ('periodo', 'materia', 'grupo')),
]

LETTERS = re.compile(r'[^\W\d_]', re.UNICODE)
DIGITS = re.compile(r'\d')
MOJIBAKE = re.compile('Ã[\u0080-\u00bf]|Â[\u0080-\u00bf]')
# Un «?» entre letras es un acento o una Ñ que el SII ya había perdido (COORDINACI?N).
LOST = re.compile(r'[^\W\d_]\?[^\W\d_]', re.UNICODE)


def base(name: str) -> str:
    """Nombre de la columna sin el sufijo que distingue un encabezado repetido (`curp__7` → `curp`)."""
    return name.split('__', 1)[0]


def slug(name: str) -> str:
    text = unicodedata.normalize('NFKD', name).encode('ascii', 'ignore').decode()
    return re.sub(r'[^a-z0-9]+', '_', text.lower()).strip('_')


def shape(value: str) -> str:
    out = DIGITS.sub('9', LETTERS.sub('A', value))
    return out if len(out) <= 32 else out[:32] + '…'


def cell(value: object, width: int = 60) -> str:
    text = str(value).replace('|', '\\|').replace('\n', ' ')
    return text if len(text) <= width else text[:width] + '…'


class EmptyExport(Exception):
    """El archivo no trae encabezados: se reporta y se sigue con los demás."""


@dataclass
class Loaded:
    key: str
    path: Path
    encoding: str
    delimiter: str
    header: list[str]
    df: pd.DataFrame
    dropped: list[str]
    blank_rows: int = 0
    malformed: list[int] = field(default_factory=list)
    tail: list[str] = field(default_factory=list)
    mojibake: int = 0
    repaired: int = 0


def canonical(path: Path) -> str:
    stem = slug(path.stem)
    for key, spec in FILES.items():
        if stem in spec['stems']:
            return key
    return f'otro:{stem}'


def decode(raw: bytes) -> tuple[str, str]:
    for encoding in ('utf-8-sig', 'cp1252'):
        try:
            return raw.decode(encoding), encoding
        except UnicodeDecodeError:
            continue
    return raw.decode('latin-1'), 'latin-1'


def tokenize(line: str, delimiter: str, quote: str) -> list[tuple[str, bool]]:
    """Parte un renglón del SII en (valor, venía entre comillas). Los textos van entre comillas (con la comilla
    doblada para escaparla) y los números sin ellas; saber cuál venía entre comillas permite reparar la coma
    decimal."""
    out: list[tuple[str, bool]] = []
    i, n = 0, len(line)
    while True:
        if i < n and line[i] == quote:
            j, buf = i + 1, []
            while j < n:
                if line[j] == quote:
                    if j + 1 < n and line[j + 1] == quote:
                        buf.append(quote)
                        j += 2
                        continue
                    break
                buf.append(line[j])
                j += 1
            out.append((''.join(buf), True))
            k = line.find(delimiter, j + 1)
            if k == -1:
                return out
            i = k + 1
        else:
            k = line.find(delimiter, i)
            if k == -1:
                out.append((line[i:], False))
                return out
            out.append((line[i:k], False))
            i = k + 1


DIGITS_ONLY = re.compile(r'^\s*-?\d+\s*$')


def is_decimal_column(name: str) -> bool:
    return name.startswith(('promedio', 'indice', 'calificacion', 'porcentaje', 'puntaje'))


def repair(tokens: list[tuple[str, bool]], header: list[str], quoted: list[bool | None]) -> list[str] | None:
    """El SII escribe los decimales con coma y sin comillas (87,50), así que un promedio se vuelve dos campos.
    Prueba qué columnas decimales venían partidas: la combinación sirve si cada campo entre comillas cae en una
    columna de texto y cada número en una numérica. Solo se repara si hay exactamente una que sirve."""
    extra = len(tokens) - len(header)
    decimals = [name for name in header if is_decimal_column(name)]
    if extra <= 0 or extra > len(decimals):
        return None
    found: list[list[str]] = []
    for split in itertools.combinations(decimals, extra):
        row, t, ok = [], 0, True
        for j, name in enumerate(header):
            if t >= len(tokens):
                ok = False
                break
            if name in split:
                if t + 1 >= len(tokens) or tokens[t][1] or tokens[t + 1][1] or not DIGITS_ONLY.match(tokens[t][0]) or not DIGITS_ONLY.match(tokens[t + 1][0]):
                    ok = False
                    break
                row.append(tokens[t][0].strip() + '.' + tokens[t + 1][0].strip())
                t += 2
                continue
            value, was_quoted = tokens[t]
            if value.strip() and quoted[j] is not None and quoted[j] != was_quoted:
                ok = False
                break
            row.append(value)
            t += 1
        if ok and t == len(tokens):
            found.append(row)
    return found[0] if len(found) == 1 else None


def load(path: Path) -> Loaded:
    text, encoding = decode(path.read_bytes())
    lines = text.split('\n')
    if lines and lines[-1].strip() == '':
        lines.pop()  # el salto de línea final no es un renglón en blanco
    sample = '\n'.join(lines[:30])
    try:
        delimiter = csv.Sniffer().sniff(sample, delimiters=',;|\t').delimiter
    except csv.Error:
        delimiter = ','

    # El SII exporta con comillas simples ('10 ') y CHAR rellenos de espacios; algunos archivos usan dobles.
    body = sample.split('\n', 1)[-1]
    quote = "'" if body.count(delimiter + "'") + body.count("\n'") > body.count(delimiter + '"') + body.count('\n"') else '"'

    parsed = [tokenize(line.rstrip('\r'), delimiter, quote) for line in lines]
    if not parsed or all(not v.strip() for v, _ in parsed[0]):
        raise EmptyExport(path.name)
    header: list[str] = []
    for name, _ in parsed[0]:
        name = slug(name) or 'sin_nombre'
        header.append(name if name not in header else f'{name}__{len(header)}')
    width = len(header)

    # Qué columnas vienen entre comillas en los renglones sanos (None: siempre vacía, no se sabe).
    votes = [[0, 0] for _ in header]
    for tokens in parsed[1:20001]:
        if len(tokens) == width:
            for j, (value, was_quoted) in enumerate(tokens):
                if value.strip():
                    votes[j][was_quoted] += 1
    quoted = [None if a + b == 0 else b > a for a, b in votes]

    keep = [i for i, name in enumerate(header) if base(name) not in DROP]
    rows: list[list[str]] = []
    blank, malformed, tail, repaired = 0, [], [], 0
    after_blank = False

    for line_no, tokens in enumerate(parsed[1:], start=2):
        values = [v for v, _ in tokens]
        if tail:
            tail.append(delimiter.join(values))
            continue
        if not tokens or all(not v.strip() for v in values):
            blank += 1
            after_blank = True
            continue
        if len(tokens) != width:
            # Primero se intenta reparar: un renglón con coma decimal tras uno en blanco sigue siendo dato.
            fixed = repair(tokens, header, quoted)
            if fixed is None:
                if after_blank:
                    tail.append(delimiter.join(values))
                else:
                    malformed.append(line_no)
                continue
            repaired += 1
            values = fixed
        after_blank = False
        rows.append([values[i].strip() for i in keep])

    df = pd.DataFrame(rows, columns=[header[i] for i in keep], dtype='string')
    dropped = [name for name in header if base(name) in DROP]

    return Loaded(
        key=canonical(path), path=path, encoding=encoding, delimiter=delimiter + ' ' + quote, header=header, df=df,
        dropped=dropped, blank_rows=blank, malformed=malformed, tail=tail, repaired=repaired,
        mojibake=len(MOJIBAKE.findall(text)) if encoding.startswith('utf-8') else 0,
    )


def column_report(name: str, s: pd.Series) -> list[str]:
    total = len(s)
    filled = s[s.fillna('') != '']
    n = len(filled)
    distinct = filled.nunique()
    pct = f'{(n / total * 100):.1f} %' if total else '—'
    lines = [f'#### `{name}` — llenos {n:,}/{total:,} ({pct}), distintos {distinct:,}']

    if n == 0:
        return lines

    shapes = filled.map(shape).value_counts().head(5)
    lines.append('- formas: ' + ', '.join(f'`{cell(k, 34)}` ×{v:,}' for k, v in shapes.items()))

    lost = filled[filled.str.contains(LOST, regex=True)]
    if len(lost):
        examples = '' if base(name) in PRIVATE else ' — ej.: ' + ', '.join(f'`{cell(v, 40)}`' for v in lost.unique()[:4])
        lines.append(f'- con caracteres perdidos en el origen («?» entre letras): {len(lost):,}{examples}')

    if base(name) in PRIVATE:
        lengths = filled.str.len()
        lines.append(f'- largo: {int(lengths.min())}–{int(lengths.max())} (privada: sin valores)')
        if base(name) in EMAIL:
            domains = filled.str.extract(r'@(.+)$')[0].str.lower().value_counts().head(8)
            if len(domains):
                lines.append('- dominios: ' + ', '.join(f'`{cell(k, 30)}` ×{v:,}' for k, v in domains.items()))
        return lines

    numbers = pd.to_numeric(filled, errors='coerce')
    if numbers.notna().mean() >= 0.95:
        lines.append(f'- numérica: mín {numbers.min():g}, máx {numbers.max():g}, media {numbers.mean():.2f}')

    if shapes.index[0] in ('99/99/9999', '9/99/9999', '99/9/9999', '9999-99-99', '9999-99-99 99:99:99'):
        dates = pd.to_datetime(filled, errors='coerce', dayfirst=not shapes.index[0].startswith('9999'))
        if dates.notna().any():
            lines.append(f'- fechas: {dates.min():%Y-%m-%d} a {dates.max():%Y-%m-%d}, inválidas {dates.isna().sum():,}')

    counts = filled.value_counts()
    top = counts if distinct <= 40 else counts.head(12)
    values = ', '.join(f'`{cell(k, 40)}` ×{v:,}' for k, v in top.items())
    lines.append(('- valores: ' if distinct <= 40 else '- más frecuentes: ') + values + ('' if distinct <= 40 else ', …'))

    return lines


def key_report(f: Loaded, cols: list[str]) -> str | None:
    if any(c not in f.df.columns for c in cols):
        return None
    keys = f.df[cols].fillna('')
    empty = (keys == '').any(axis=1)
    full = keys[~empty]
    dup_mask = full.duplicated(keep=False)
    dup_rows = int(dup_mask.sum())
    dup_keys = int(full[dup_mask].drop_duplicates().shape[0])
    text = f'- `{"+".join(cols)}`: vacía en {int(empty.sum()):,}; repetida en {dup_rows:,} filas ({dup_keys:,} claves)'
    if dup_keys and not any(base(c) in PRIVATE for c in cols):
        examples = full[dup_mask].drop_duplicates().head(5).apply(lambda r: '+'.join(r), axis=1)
        text += ' — ej.: ' + ', '.join(f'`{cell(e, 30)}`' for e in examples)
    return text


def reference_report(child: Loaded, ccols: tuple[str, ...], parent: Loaded, pcols: tuple[str, ...]) -> str:
    c = child.df[list(ccols)].fillna('')
    c = c[(c != '').all(axis=1)]
    p = parent.df[list(pcols)].fillna('')
    pkeys = set(map(tuple, p.to_numpy().tolist()))
    pkeys_ci = {tuple(v.upper() for v in k) for k in pkeys}
    ckeys = list(map(tuple, c.to_numpy().tolist()))
    missing = [k for k in ckeys if k not in pkeys]
    missing_ci = [k for k in missing if tuple(v.upper() for v in k) not in pkeys_ci]
    distinct_missing = sorted(set(missing))
    pct = f'{(1 - len(missing) / len(ckeys)) * 100:.2f} %' if ckeys else '—'
    text = (f'- `{child.key}.{"+".join(ccols)}` → `{parent.key}.{"+".join(pcols)}`: {len(ckeys):,} filas con valor, '
            f'cobertura {pct}, huérfanas {len(missing):,} ({len(distinct_missing):,} claves distintas)')
    if len(missing) != len(missing_ci):
        text += f'; {len(missing) - len(missing_ci):,} cuadran ignorando mayúsculas'
    if distinct_missing and not any(base(col) in PRIVATE for col in ccols):
        text += ' — ej.: ' + ', '.join(f'`{cell("+".join(k), 30)}`' for k in distinct_missing[:8])
    return text


def crosstab(f: Loaded, rows: str, cols: str) -> list[str]:
    if rows not in f.df.columns or cols not in f.df.columns:
        return []
    table = pd.crosstab(f.df[rows].fillna('∅').replace('', '∅'), f.df[cols].fillna('∅').replace('', '∅'))
    if table.shape[1] > 15 or table.shape[0] > 60:
        return [f'_({rows} × {cols}: {table.shape[0]}×{table.shape[1]}, demasiado grande)_']
    head = '| ' + rows + ' \\ ' + cols + ' | ' + ' | '.join(cell(c, 20) for c in table.columns) + ' |'
    sep = '|' + '---|' * (len(table.columns) + 1)
    body = ['| ' + cell(i, 30) + ' | ' + ' | '.join(f'{v:,}' for v in r) + ' |' for i, r in table.iterrows()]
    return [f'**{rows} × {cols}**', '', head, sep, *body, '']


def specific_checks(files: dict[str, Loaded]) -> list[str]:
    out: list[str] = []

    mc = files.get('materias_carreras')
    if mc is not None and {'materia', 'creditos_materia', 'horas_teoricas', 'horas_practicas'} <= set(mc.df.columns):
        combo = mc.df['creditos_materia'].fillna('') + '/' + mc.df['horas_teoricas'].fillna('') + '/' + mc.df['horas_practicas'].fillna('')
        per = pd.DataFrame({'materia': mc.df['materia'], 'combo': combo}).groupby('materia')['combo']
        plans = mc.df.groupby('materia').size()
        varying = per.nunique()
        varying = varying[varying > 1]
        out.append('### Créditos y horas por plan (materias_carreras)')
        out.append(f'- materias en más de un plan: {int((plans > 1).sum()):,}; con créditos/HT/HP distintos entre planes: {len(varying):,}')
        for materia in varying.index[:12]:
            variants = per.get_group(materia).value_counts()
            out.append(f'  - `{cell(materia, 20)}`: ' + ', '.join(f'{k} ×{v}' for k, v in variants.items()))
        out.append('')

    m = files.get('materias')
    if m is not None and {'materia', 'nombre_completo_materia'} <= set(m.df.columns):
        names = m.df.groupby(m.df['nombre_completo_materia'].str.upper())['materia'].nunique()
        out.append('### Nombres de materia con varias claves (materias)')
        out.append(f'- nombres con más de una clave: {int((names > 1).sum()):,} de {len(names):,}')
        for name, n in names[names > 1].sort_values(ascending=False).head(8).items():
            out.append(f'  - `{cell(name, 50)}`: {n} claves')
        out.append('')

    d, d2 = files.get('docentes'), files.get('docentes2')
    if d is not None and d2 is not None and 'rfc' in d.df.columns and 'rfc' in d2.df.columns:
        a = set(d.df['rfc'].fillna('').str.upper()) - {''}
        b = set(d2.df['rfc'].fillna('').str.upper()) - {''}
        out.append('### Docentes y Docentes2 por RFC')
        out.append(f'- en ambos {len(a & b):,}; solo en Docentes {len(a - b):,}; solo en Docentes2 {len(b - a):,}')
        out.append('')

    if d is not None and {'no_tarjeta', 'status_empleado'} <= set(d.df.columns):
        out.append('### no_tarjeta por estatus (Docentes)')
        for status, group in d.df.groupby(d.df['status_empleado'].fillna('∅')):
            filled = group['no_tarjeta'].fillna('')
            filled = filled[filled != '']
            out.append(f'- estatus `{cell(status, 10)}`: {len(group):,} personas; con no_tarjeta {len(filled):,}; '
                       f'no_tarjeta repetido {int(filled.duplicated(keep=False).sum()):,}')
        out.append('')

    for key, pairs in {
        'docentes': [('status_empleado', 'tipo_personal'), ('tipo_personal', 'tipo_control')],
        'alumnos': [('estatus_alumno', 'nivel_escolar'), ('campus', 'nivel_escolar'), ('estatus_alumno', 'campus')],
        'carreras': [('nivel_escolar', 'tipo_carrera')],
        'horarios': [('tipo_horario', 'dia_semana')],
        'grupos': [('estatus_grupo', 'tipo_personal')],
    }.items():
        f = files.get(key)
        if f is None:
            continue
        tables = [line for r, c in pairs for line in crosstab(f, r, c)]
        if tables:
            out.append(f'### Cruces ({key})')
            out.append('')
            out.extend(tables)

    a = files.get('alumnos')
    if a is not None and {'carrera', 'reticula', 'campus'} <= set(a.df.columns):
        offer = a.df.groupby(['carrera', 'campus']).size().reset_index(name='alumnos')
        out.append('### Oferta deducida de los alumnos (carrera × campus)')
        out.append(f'- combinaciones carrera+campus: {len(offer):,}')
        out.append('')

    for key in ('grupos', 'horarios', 'kardex'):
        f = files.get(key)
        if f is None or 'periodo' not in f.df.columns:
            continue
        counts = f.df['periodo'].fillna('∅').value_counts().sort_index()
        out.append(f'### Periodos en {key}')
        out.append(f'- {len(counts):,} periodos, de `{counts.index[0]}` a `{counts.index[-1]}`; últimos 8: '
                   + ', '.join(f'`{k}` ×{v:,}' for k, v in counts.tail(8).items()))
        out.append('')

    return out


def main(folder: Path) -> int:
    paths = sorted(p for p in folder.iterdir() if p.is_file() and p.suffix.lower() in ('.csv', '.txt'))
    if not paths:
        print(f'No hay CSV en {folder}', file=sys.stderr)
        return 1

    files: dict[str, Loaded] = {}
    report: list[str] = [f'# Perfil de las exportaciones del SII — `{folder.name}`', '']
    summary = ['| Archivo | Canónico | Filas | Columnas | Codificación | Sep. | Reparadas (coma decimal) | Malformadas | En blanco | Anotación al final |',
               '|---|---|---|---|---|---|---|---|---|---|']
    details: list[str] = []

    for path in paths:
        print(f'leyendo {path.name}…', file=sys.stderr)
        try:
            f = load(path)
        except EmptyExport:
            summary.append(f'| {cell(path.name, 40)} | — | 0 | 0 | — | — | — | — | — | archivo vacío |')
            continue
        if f.key in files:
            f.key = f'{f.key}:{slug(path.stem)}'
        files[f.key] = f
        summary.append(f'| {cell(path.name, 40)} | `{f.key}` | {len(f.df):,} | {len(f.header)} | {f.encoding} | '
                       f'`{f.delimiter}` | {f.repaired:,} | {len(f.malformed):,} | {f.blank_rows:,} | {len(f.tail):,} renglones |')

        details += [f'## {path.name} (`{f.key}`)', '']
        details.append(f'- encabezados ({len(f.header)}): ' + ', '.join(f'`{h}`' for h in f.header))
        if f.dropped:
            details.append('- descartadas sin leer: ' + ', '.join(f'`{h}`' for h in f.dropped))
        if f.malformed:
            details.append(f'- renglones malformados (otro número de columnas): {len(f.malformed):,}; líneas '
                           + ', '.join(map(str, f.malformed[:10])))
        if f.mojibake:
            details.append(f'- posible doble codificación (Ã…/Â…): {f.mojibake:,} apariciones')
        if f.tail:
            details.append('- anotación al final (primeros renglones): '
                           + ' · '.join(f'`{cell(t, 70)}`' for t in f.tail[:6]))
        spec = FILES.get(f.key.split(':')[0], {'keys': []})
        keys = [k for k in (key_report(f, cols) for cols in spec['keys']) if k]
        if keys:
            details += ['', '**Claves naturales**', *keys]
        details.append('')
        for column in f.df.columns:
            details += column_report(column, f.df[column])
        details.append('')

    report += ['## Resumen', '', *summary, '']

    refs: list[str] = []
    for ccols, parent_key, pcols in REFERENCES:
        parent = files.get(parent_key)
        if parent is None or any(c not in parent.df.columns for c in pcols):
            continue
        for child in files.values():
            if (child is parent and ccols == pcols) or any(c not in child.df.columns for c in ccols):
                continue
            refs.append(reference_report(child, ccols, parent, pcols))
    report += ['## Cobertura entre archivos', '', *(refs or ['_(sin pares que revisar)_']), '']
    report += ['## Revisiones de la migración', '', *specific_checks(files)]
    report += ['## Detalle por archivo', '', *details]

    out = folder / '_perfil.md'
    out.write_text('\n'.join(report) + '\n', encoding='utf-8')
    print(f'informe: {out}', file=sys.stderr)
    return 0


if __name__ == '__main__':
    if len(sys.argv) != 2:
        print('uso: sii-profile.py <carpeta con los CSV del SII>', file=sys.stderr)
        sys.exit(2)
    sys.exit(main(Path(sys.argv[1])))
