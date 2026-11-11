#!/usr/bin/env python3
"""Junta los tiempos por clase de dos o más `tests/.pest/shards.json` en uno.

`--update-shards` de Pest reescribe el archivo solo con las clases de la corrida que lo generó, y Unit/Feature/Arch y
Browser corren por separado; aquí se unen sus `timings` (si una clase sale en varios, queda el último). Pest solo lee
`timings`; `checksum` y `updated_at` se escriben como los escribe Pest. Detalle en docs/arquitectura/testing.md, «Shards».

Uso: merge-shard-timings.py <salida.json> <shards-1.json> <shards-2.json> [...]
"""

import hashlib
import json
import sys
from datetime import datetime, timezone


def main() -> int:
    if len(sys.argv) < 4:
        print("Uso: merge-shard-timings.py <salida.json> <shards-1.json> <shards-2.json> [...]", file=sys.stderr)
        return 2

    output, inputs = sys.argv[1], sys.argv[2:]
    timings = {}
    for path in inputs:
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
