#!/usr/bin/env python3
"""Las pruebas y los archivos de prueba más lentos de un reporte JUnit, en Markdown para el resumen del job.

Sirve para ver en CI qué hace crecer el job `tests` (en paralelo, el archivo más lento marca el tiempo de su proceso).
Nunca falla el job: sin reporte o con un reporte que no se puede leer, lo dice y sale con 0.
Detalle en docs/arquitectura/testing.md, «CI».

Uso: slowest-tests.py <junit.xml> [cuántas pruebas, 20 por omisión]
"""

import sys
import xml.etree.ElementTree as ElementTree
from collections import defaultdict


def main() -> int:
    if len(sys.argv) < 2:
        print("Uso: slowest-tests.py <junit.xml> [cuántas]")
        return 0

    path = sys.argv[1]
    limit = int(sys.argv[2]) if len(sys.argv) > 2 and sys.argv[2].isdigit() else 20

    try:
        root = ElementTree.parse(path).getroot()
    except (OSError, ElementTree.ParseError) as error:
        print(f"### Pruebas más lentas\n\nSin reporte JUnit que leer ({error}).")
        return 0

    cases = []
    by_file = defaultdict(float)

    for case in root.iter("testcase"):
        seconds = float(case.get("time") or 0)
        file = case.get("file") or case.get("classname") or "?"
        file = file.split("/tests/", 1)[-1] if "/tests/" in file else file
        file = file.split("::", 1)[0]
        cases.append((seconds, file, case.get("name") or "?"))
        by_file[file] += seconds

    cases.sort(key=lambda case: case[0], reverse=True)
    total = sum(case[0] for case in cases)

    print(f"### Pruebas más lentas\n\n{len(cases)} pruebas, {total / 60:.1f} min sumadas (en paralelo se reparten entre procesos).\n")
    print("| Segundos | Archivo | Prueba |\n|---:|---|---|")
    for seconds, file, name in cases[:limit]:
        print(f"| {seconds:.2f} | `{file}` | {name.replace('|', '/')[:120]} |")

    print("\n| Segundos | Archivo más lento |\n|---:|---|")
    for file, seconds in sorted(by_file.items(), key=lambda item: item[1], reverse=True)[:10]:
        print(f"| {seconds:.2f} | `{file}` |")

    return 0


if __name__ == "__main__":
    sys.exit(main())
