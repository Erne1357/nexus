#!/usr/bin/env python3
"""Junta los reportes Clover de los shards del job `tests` en uno solo, como si la suite hubiera corrido entera.

Cada shard corre una parte de la suite con cobertura y deja su Clover con todos los archivos de app/ (los que no
cargó, con conteo 0). Por archivo, aquí se unen sus líneas sumando los conteos y se vuelven a calcular sus métricas.
coverage-summary.sh solo lee, por archivo, `statements` y `coveredstatements`; dos detalles de PHPUnit los hacen
salir de las métricas y no solo de las líneas:

- Una instrucción en la misma línea que la firma de un método (un constructor de una línea) cuenta en `statements`,
  pero el Clover solo escribe la línea del método. Las cubiertas así salen de comparar `coveredstatements` con las
  líneas `stmt` cubiertas del shard, y queda la mayor de los shards (nunca cuenta de más).
- Un archivo que el shard no cargó se analiza sin ejecutarlo y puede traer una línea ejecutable más que uno que sí
  se cargó. Las métricas y las líneas salen de los shards que lo cargaron; si ninguno, de todos.

Con esto, la unión da los mismos totales por archivo que una corrida de toda la suite (comprobado con dos archivos de
prueba contra su corrida junta). Un método cuenta como cubierto si se ejecutó su línea y todas sus líneas `stmt` hasta
el siguiente método (aproximado: sirve para leer, el mínimo no lo usa). El resultado no trae `<package>` ni
`<class>`: solo `<file>`, `<line>` y `<metrics>`. Detalle en docs/arquitectura/testing.md, «CI».

Uso: merge-clover.py <salida.xml> <clover-1.xml> [<clover-2.xml> ...]
"""

import sys
import time
import xml.etree.ElementTree as ElementTree
from xml.sax.saxutils import quoteattr


def read(path: str) -> dict:
    """Los archivos de un Clover: por nombre, sus métricas y sus líneas por (número, tipo)."""
    files = {}
    for node in ElementTree.parse(path).getroot().iter("file"):
        metrics = node.find("metrics")
        lines = {}
        for line in node.findall("line"):
            attributes = dict(line.attrib)
            attributes["count"] = int(attributes.get("count") or 0)
            lines[(int(attributes["num"]), attributes.get("type"))] = attributes

        files[node.get("name")] = {
            "loc": int(metrics.get("loc") or 0) if metrics is not None else 0,
            "ncloc": int(metrics.get("ncloc") or 0) if metrics is not None else 0,
            "statements": int(metrics.get("statements") or 0) if metrics is not None else 0,
            "covered": int(metrics.get("coveredstatements") or 0) if metrics is not None else 0,
            "lines": lines,
        }

    return files


def covered_stmt_lines(lines: dict) -> int:
    return sum(1 for (_, kind), line in lines.items() if kind == "stmt" and line["count"] > 0)


def merge_file(reports: list) -> tuple:
    """Las líneas unidas y las métricas de un archivo, de sus renglones en cada shard."""
    loaded = [report for report in reports if report["covered"] > 0 or any(line["count"] > 0 for line in report["lines"].values())]
    base = loaded or reports

    lines = {}
    for report in base:
        for key, line in report["lines"].items():
            lines.setdefault(key, {**line, "count": 0})
    for report in reports:
        for key, line in report["lines"].items():
            if key in lines:
                lines[key]["count"] += line["count"]

    statements = max(report["statements"] for report in base)
    hidden = max(max(report["covered"] - covered_stmt_lines(report["lines"]), 0) for report in base)
    covered = min(covered_stmt_lines(lines) + hidden, statements)

    ordered = sorted(lines.values(), key=lambda line: (int(line["num"]), line.get("type") != "method"))
    methods = 0
    covered_methods = 0
    method_open = None
    for line in ordered + [None]:
        if line is None or line.get("type") == "method":
            if method_open is not None:
                methods += 1
                covered_methods += 1 if method_open else 0
            method_open = None if line is None else line["count"] > 0
        elif line.get("type") == "stmt" and method_open is not None and line["count"] == 0:
            method_open = False

    metrics = {
        "loc": max(report["loc"] for report in reports),
        "ncloc": max(report["ncloc"] for report in reports),
        "methods": methods,
        "coveredmethods": covered_methods,
        "conditionals": 0,
        "coveredconditionals": 0,
        "statements": statements,
        "coveredstatements": covered,
        "elements": methods + statements,
        "coveredelements": covered_methods + covered,
    }

    return ordered, metrics


def attributes(values: dict) -> str:
    return " ".join(f"{key}={quoteattr(str(value))}" for key, value in values.items())


def main() -> int:
    if len(sys.argv) < 3:
        print("Uso: merge-clover.py <salida.xml> <clover-1.xml> [<clover-2.xml> ...]", file=sys.stderr)
        return 2

    output, inputs = sys.argv[1], sys.argv[2:]
    shards = [read(path) for path in inputs]
    names = sorted({name for shard in shards for name in shard})

    totals = {"files": 0}
    now = int(time.time())
    out = ['<?xml version="1.0" encoding="UTF-8"?>', f'<coverage generated="{now}">', f'  <project timestamp="{now}" name="Clover Coverage">']

    for name in names:
        lines, metrics = merge_file([shard[name] for shard in shards if name in shard])
        totals["files"] += 1
        for key, value in metrics.items():
            totals[key] = totals.get(key, 0) + value

        out.append(f"    <file name={quoteattr(name)}>")
        out.extend(f"      <line {attributes(line)}/>" for line in lines)
        out.append(f"      <metrics {attributes(metrics)}/>")
        out.append("    </file>")

    out += [f"    <metrics {attributes(totals)}/>", "  </project>", "</coverage>"]

    with open(output, "w", encoding="utf-8") as handle:
        handle.write("\n".join(out) + "\n")

    print(f"Clover unido: {len(inputs)} reportes, {totals['files']} archivos, {totals.get('coveredstatements', 0)}/{totals.get('statements', 0)} líneas.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
