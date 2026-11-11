#!/usr/bin/env python3
"""Junta los tiempos por clase de varios `tests/.pest/shards.json` o reportes JUnit en un `shards.json`.

`--update-shards` de Pest reescribe el archivo solo con las clases de la corrida que lo generó, y Unit/Feature/Arch y
Browser corren por separado; aquí se unen sus `timings`. Un `.xml` es el JUnit de un shard del CI (artefacto
`tests-shard-<n>`): su tiempo por clase es la suma de sus pruebas, medido en el runner y con cobertura, que es lo que
reparte el CI. Si una clase sale en varias entradas, queda la última. Pest solo lee `timings`; `checksum` y `updated_at`
se escriben como los escribe Pest. Detalle en docs/arquitectura/testing.md, «Shards».

Uso: merge-shard-timings.py <salida.json> <entrada-1> [<entrada-2> ...]   (cada entrada, un .json o un .xml)
"""

import hashlib
import json
import sys
import xml.etree.ElementTree as ElementTree
from collections import defaultdict
from datetime import datetime, timezone


def junit_timings(path: str) -> dict:
    """Segundos por clase de un JUnit de Pest: la suma de sus `testcase`."""
    timings = defaultdict(float)
    for case in ElementTree.parse(path).getroot().iter("testcase"):
        if case.get("class"):
            timings[case.get("class")] += float(case.get("time") or 0)

    return {name: round(seconds, 4) for name, seconds in timings.items()}


def main() -> int:
    if len(sys.argv) < 3:
        print("Uso: merge-shard-timings.py <salida.json> <entrada-1> [<entrada-2> ...]", file=sys.stderr)
        return 2

    output, inputs = sys.argv[1], sys.argv[2:]
    timings = {}
    for path in inputs:
        if path.endswith(".xml"):
            timings.update(junit_timings(path))
            continue
        with open(path, encoding="utf-8") as handle:
            data = json.load(handle)
        if not isinstance(data.get("timings"), dict):
            print(f"{path} no trae `timings`.", file=sys.stderr)
            return 1
        timings.update(data["timings"])

    timings = dict(sorted(timings.items()))
    merged = {
        "timings": timings,
        "checksum": hashlib.md5("\n".join(sorted(timings)).encode()).hexdigest(),
        "updated_at": datetime.now(timezone.utc).astimezone().isoformat(timespec="seconds"),
    }

    with open(output, "w", encoding="utf-8") as handle:
        handle.write(json.dumps(merged, indent=4, ensure_ascii=False) + "\n")

    print(f"{len(timings)} clases con tiempo en {output}.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
