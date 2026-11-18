"""Mide el objetivo del modelo CP-SAT (tarea 7.6) con un fixture compartido: los grupos fuera de su patrón y el puntaje
blando total y por regla, como el `SoftScorer` de PHP, después de cada fase de la resolución (`model.solve`, 7.7). Con
`--output` escribe la salida del contrato (así se generan los `cpsat-output.json` de `tests/Fixtures/solver/v1`).
Desde la raíz del repositorio:

    docker build --target dev -t nexus-solver-dev services/solver
    docker run --rm --cpus 2 --memory 2g -e PYTHONPATH=/srv/solver \\
        -v "$PWD/services/solver:/srv/solver:ro" -v "$PWD/tests/Fixtures/solver:/fixtures:ro" \\
        nexus-solver-dev python scripts/objective.py department-multicampus --time-limit 60 --threads 2

Las fases: `lns` programa a todos (sin objetivo blando), `improve` busca por vecindarios con el objetivo y
`improve_rooms` (la fase B con objetivo) ajusta los salones con la reserva final.
"""

import argparse
import json
import os
import sys
from pathlib import Path

from app.contract import parse_input, to_dict
from app.model.phases import SearchLimits
from app.model.solve import Phase, Resolution, solve


def run(fixture: Path, time_limit: float | None, threads: int, seed: int) -> Resolution:
    data = parse_input(json.loads((fixture / "input.json").read_text()))

    return solve(
        data,
        time_limit=time_limit or data.time_limit_seconds,
        limits=SearchLimits(workers=threads),
        seed=seed,
        version="script",
    )


def table(phases: list[Phase]) -> str:
    rules = list(phases[0].score.by_rule)
    lines = [
        "| Fase | Segundos | Fuera del patrón | Total | " + " | ".join(rules) + " |",
        "|---|---|---|---|" + "---|" * len(rules),
    ]
    for phase in phases:
        values = " | ".join(f"{phase.score.by_rule[rule]:,}" for rule in rules)
        lines.append(f"| {phase.name} | {phase.seconds} | {phase.off_pattern} | {phase.score.total:,} | {values} |")

    return "\n".join(lines)


def main(argv: list[str] | None = None) -> str:
    parser = argparse.ArgumentParser(description=__doc__.split("\n\n")[0])
    parser.add_argument("fixture", help="nombre del fixture en tests/Fixtures/solver/v1")
    parser.add_argument("--time-limit", type=float, help="segundos (los de la entrada, si no se dan)")
    parser.add_argument("--threads", type=int, default=2, help="hilos de CP-SAT (2, los del servicio en compose)")
    parser.add_argument("--seed", type=int, default=7)
    parser.add_argument("--output", help="archivo donde escribir la salida del contrato")
    args = parser.parse_args(argv)

    root = Path(os.environ.get("SOLVER_FIXTURES_DIR", "/fixtures")) / "v1" / args.fixture
    resolution = run(root, args.time_limit, args.threads, args.seed)
    if args.output:
        Path(args.output).write_text(json.dumps(to_dict(resolution.output), indent=4, ensure_ascii=False) + "\n")
    result = table(resolution.phases)
    print(result)

    return result


if __name__ == "__main__":
    main(sys.argv[1:])
