"""Mide el objetivo del modelo CP-SAT (tarea 7.6) con un fixture compartido: los grupos fuera de su patrón y el puntaje
blando total y por regla, como el `SoftScorer` de PHP, después de cada fase de la resolución (`model.solve`, 7.7).
Desde la raíz del repositorio:

    docker build --target dev -t nexus-solver-dev services/solver
    docker run --rm --cpus 2 --memory 2g -e PYTHONPATH=/srv/solver \\
        -v "$PWD/services/solver:/srv/solver:ro" -v "$PWD/tests/Fixtures/solver:/fixtures:ro" \\
        nexus-solver-dev python scripts/objective.py department-multicampus --time-limit 60 --threads 2

Con `--output` escribe la salida del contrato. Así se generan los `cpsat-output.json` de `tests/Fixtures/solver/v1`,
que son fotos: las pruebas revisan que su `soft_score` sea el de `score.py` y el de PHP, no que el motor las repita.
Para escribirlas, los fixtures van sin `:ro` y con el usuario de la máquina; `small` y `medium` van con un hilo y
`department-multicampus` con 2 y `--compact` (una línea, para que pese menos):

    docker run --rm --user "$(id -u):$(id -g)" -e PYTHONPATH=/srv/solver \\
        -v "$PWD/services/solver:/srv/solver:ro" -v "$PWD/tests/Fixtures/solver:/fixtures" \\
        nexus-solver-dev python scripts/objective.py medium --threads 1 \\
        --output /fixtures/v1/medium/cpsat-output.json

Con `--hint` la entrada lleva como `hint_sessions` las sesiones de los movibles de una salida guardada, como las manda
`CpSatEngine` en PHP. Así se mide la carga real con la pista de la heurística (`heuristic-output.json`: la heurística
con 9 s, el 15 % de 60 s) y los 52 s que le quedan a CP-SAT (docs/arquitectura/solver.md, «Pista de la heurística»):

    docker run --rm --cpus 2 --memory 2g -e PYTHONPATH=/srv/solver \\
        -v "$PWD/services/solver:/srv/solver:ro" -v "$PWD/tests/Fixtures/solver:/fixtures:ro" \\
        nexus-solver-dev python scripts/objective.py department-multicampus --time-limit 52 --threads 2 \\
        --hint /fixtures/v1/department-multicampus/heuristic-output.json

Las fases: `lns` programa a todos (sin objetivo blando; con la pista completa no arma nada), `improve` busca por
vecindarios con el objetivo y `improve_rooms` (la fase B con objetivo) ajusta los salones con la reserva final.
"""

import argparse
import json
import os
import sys
from pathlib import Path

from app.contract import parse_input, parse_output, to_dict
from app.contract.input import HintSession
from app.model.phases import SearchLimits
from app.model.solve import Phase, Resolution, solve


def run(fixture: Path, time_limit: float | None, threads: int, seed: int, hint: Path | None = None) -> Resolution:
    data = parse_input(json.loads((fixture / "input.json").read_text()))
    if hint is not None:
        movable = {group.id for group in data.groups if group.movable}
        sessions = parse_output(json.loads(hint.read_text())).sessions
        hints = [HintSession(**session.model_dump(exclude={"changed"})) for session in sessions]
        data = data.model_copy(update={"hint_sessions": [entry for entry in hints if entry.group_id in movable]})

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
    parser.add_argument("--compact", action="store_true", help="la salida en una línea, sin sangría")
    parser.add_argument("--hint", help="salida guardada cuyas sesiones van como pista (`hint_sessions`)")
    args = parser.parse_args(argv)

    root = Path(os.environ.get("SOLVER_FIXTURES_DIR", "/fixtures")) / "v1" / args.fixture
    resolution = run(root, args.time_limit, args.threads, args.seed, Path(args.hint) if args.hint else None)
    if args.output:
        indent = None if args.compact else 4
        Path(args.output).write_text(json.dumps(to_dict(resolution.output), indent=indent, ensure_ascii=False) + "\n")
    result = table(resolution.phases)
    print(result)

    return result


if __name__ == "__main__":
    main(sys.argv[1:])
