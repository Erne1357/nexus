"""Mide el objetivo del modelo CP-SAT (tarea 7.6) con un fixture compartido: el puntaje blando total y por regla,
como el `SoftScorer` de PHP, después de cada fase. Con `--output` escribe la salida del contrato (así se generan los
`cpsat-output.json` de `tests/Fixtures/solver/v1`). Desde la raíz del repositorio:

    docker build --target dev -t nexus-solver-dev services/solver
    docker run --rm --cpus 2 --memory 2g -e PYTHONPATH=/srv/solver \\
        -v "$PWD/services/solver:/srv/solver:ro" -v "$PWD/tests/Fixtures/solver:/fixtures:ro" \\
        nexus-solver-dev python scripts/objective.py department-multicampus --time-limit 60 --threads 2

Las fases: `lns` programa a todos (sin objetivo), `improve` busca por vecindarios con el objetivo hasta el plazo menos
la reserva de los salones, y `improve_rooms` (la fase B con objetivo) ajusta los salones con lo que queda.
"""

import argparse
import json
import os
import sys
import time
from pathlib import Path
from typing import Any

from app.contract import RunStatus, SolverOutput, parse_input, to_dict
from app.contract.output import RunMetrics, UnscheduledGroup
from app.model.constraints import input_problems
from app.model.data import ModelData
from app.model.phases import SearchLimits
from app.model.room_model import improve_rooms
from app.model.score import SoftScorer
from app.model.search import improve, lns
from app.model.verify import hard_violations


def room_reserve(time_limit: float) -> float:
    """Lo que se guarda para la fase B con objetivo: la décima parte del plazo, hasta 10 s."""
    return min(10.0, time_limit / 10)


def first_share(time_limit: float) -> float:
    """La primera vuelta de `lns`: la cuarta parte del plazo, hasta 15 s. Con 2 hilos programa casi lo mismo en 15 s
    que en 75 (de 1 122 a 1 124 de 1 139 en `department-multicampus`), y lo demás sirve más para mejorar."""
    return min(0.25, 15 / time_limit)


def run(fixture: Path, time_limit: float | None, threads: int, seed: int) -> tuple[SolverOutput, list[dict[str, Any]]]:
    source = parse_input(json.loads((fixture / "input.json").read_text()))
    time_limit = time_limit or source.time_limit_seconds
    data = ModelData.of(source)
    scorer = SoftScorer(source)
    limits = SearchLimits(workers=threads)
    started = time.perf_counter()
    deadline = started + time_limit
    phases: list[dict[str, Any]] = []

    def mark(phase: str, sessions: list) -> None:
        phases.append(
            {"phase": phase, "seconds": round(time.perf_counter() - started, 1), "score": scorer.score(sessions)}
        )

    search = lns(data, time_limit=time_limit, limits=limits, seed=seed, first_share=first_share(time_limit))
    mark("lns", search.all_sessions)
    improve(data, search, deadline - room_reserve(time_limit), limits=limits, seed=seed)
    mark("improve", search.all_sessions)
    sessions = improve_rooms(data, search.all_sessions, deadline, limits=limits)
    mark("improve_rooms", sessions)
    violations = hard_violations(source, sessions) + input_problems(data)
    unscheduled = [
        UnscheduledGroup(group_id=group_id, reasons=["Sin lugar en el plazo."]) for group_id in search.unplaced
    ]
    output = SolverOutput(
        version=source.version,
        run_id=source.run_id,
        status=RunStatus.PARTIAL if violations or unscheduled else RunStatus.FEASIBLE,
        sessions=sessions,
        unscheduled=unscheduled,
        hard_violations=violations,
        soft_score=phases[-1]["score"].to_contract(),
        metrics=RunMetrics(
            elapsed_seconds=round(time.perf_counter() - started, 3), engine="cpsat", engine_version="7.6"
        ),
    )

    return output, phases


def table(phases: list[dict[str, Any]]) -> str:
    rules = list(phases[0]["score"].by_rule)
    lines = [
        "| Fase | Segundos | Total | " + " | ".join(rules) + " |",
        "|---|---|---|" + "---|" * len(rules),
    ]
    for row in phases:
        score = row["score"]
        values = " | ".join(f"{score.by_rule[rule]:,}" for rule in rules)
        lines.append(f"| {row['phase']} | {row['seconds']} | {score.total:,} | {values} |")

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
    output, phases = run(root, args.time_limit, args.threads, args.seed)
    if args.output:
        Path(args.output).write_text(json.dumps(to_dict(output), indent=4, ensure_ascii=False) + "\n")
    result = table(phases)
    print(result)

    return result


if __name__ == "__main__":
    main(sys.argv[1:])
