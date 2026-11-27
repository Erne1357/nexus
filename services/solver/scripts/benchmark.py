"""Reproduce la tabla de «Tamaño de la instancia real» (docs/arquitectura/solver.md) con un fixture compartido.

Cada forma corre en un proceso aparte, para que la memoria máxima sea solo la suya. Desde la raíz del repositorio:

    docker build --target dev -t nexus-solver-dev services/solver
    docker run --rm --cpus 2 --memory 2g -e PYTHONPATH=/srv/solver \\
        -v "$PWD/services/solver:/srv/solver:ro" -v "$PWD/tests/Fixtures/solver:/fixtures:ro" \\
        nexus-solver-dev python scripts/benchmark.py department-multicampus --threads 2

Formas: `lns` (la elegida), `two-phase` (dos fases con niveles por necesidad y, si es infactible, todos los niveles)
y `exact` (el modelo de 7.4 y 7.5: con `department-multicampus` necesita más de 5 GB y no cabe en 2).
"""

import argparse
import json
import multiprocessing
import os
import resource
import sys
import time
from pathlib import Path
from typing import Any

from app.contract import parse_input
from app.model.data import ModelData
from app.model.phases import SearchLimits, solve_hard
from app.model.search import lns
from app.model.verify import hard_violations

FORMS = ("lns", "two-phase", "exact")


def measure(form: str, fixture: Path, time_limit: float, threads: int, seed: int, max_variables: int) -> dict[str, Any]:
    """Corre una forma y da sus números; la memoria es la máxima del proceso."""
    source = parse_input(json.loads((fixture / "input.json").read_text()))
    data = ModelData.of(source)
    limits = SearchLimits(workers=threads, max_variables=max_variables)
    started = time.perf_counter()
    if form == "lns":
        result = lns(data, time_limit=time_limit, limits=limits, seed=seed)
        steps = result.steps
        sessions = result.all_sessions
        placed = len(result.sessions)
        first_feasible = result.first_feasible_seconds
    else:
        run = solve_hard(
            data,
            time_limit=time_limit,
            limits=limits,
            seed=seed,
            pool_rooms=form != "exact",
            tiers_by_need=form != "exact",
        )
        steps = run.rounds
        sessions = run.sessions
        placed = len({session.group_id for session in sessions})
        first_feasible = time.perf_counter() - started if run.feasible else None
    largest = max(steps, key=lambda step: step.variables, default=None)

    return {
        "form": form,
        "variables": largest.variables if largest else 0,
        "constraints": largest.constraints if largest else 0,
        "build_seconds": steps[0].build_seconds if steps else None,
        "memory_mb": resource.getrusage(resource.RUSAGE_SELF).ru_maxrss // 1024,
        "first_feasible_seconds": round(first_feasible, 1) if first_feasible is not None else None,
        "placed": placed,
        "groups": len(data.movable),
        "hard_violations": len(hard_violations(source, sessions)),
        "seconds": round(time.perf_counter() - started, 1),
    }


def _child(queue: Any, *args: Any) -> None:
    try:
        queue.put(measure(*args))
    except BaseException as error:
        queue.put({"form": args[0], "error": f"{type(error).__name__}: {error}"})


def run_form(
    form: str, fixture: Path, time_limit: float, threads: int, seed: int, max_variables: int
) -> dict[str, Any]:
    context = multiprocessing.get_context("fork")
    queue = context.Queue()
    process = context.Process(target=_child, args=(queue, form, fixture, time_limit, threads, seed, max_variables))
    process.start()
    process.join()
    if not queue.empty():
        return queue.get()

    return {"form": form, "error": f"el proceso terminó con código {process.exitcode} (¿memoria?)"}


def table(rows: list[dict[str, Any]]) -> str:
    lines = [
        "| Forma | Variables (vuelta mayor) | Restricciones | Armado | Memoria máx. "
        "| Todos sin duras | Programados | Duras |",
        "|---|---|---|---|---|---|---|---|",
    ]
    for row in rows:
        if "error" in row:
            lines.append(f"| {row['form']} | {row['error']} | | | | | | |")
            continue
        feasible = f"{row['first_feasible_seconds']} s" if row["first_feasible_seconds"] is not None else "no llega"
        lines.append(
            f"| {row['form']} | {row['variables']:,} | {row['constraints']:,} | {row['build_seconds']} s | "
            f"{row['memory_mb']} MB | {feasible} | {row['placed']} de {row['groups']} | {row['hard_violations']} |"
        )

    return "\n".join(lines)


def main(argv: list[str] | None = None) -> str:
    parser = argparse.ArgumentParser(description=__doc__.split("\n\n")[0])
    parser.add_argument("fixture", help="nombre del fixture en tests/Fixtures/solver/v1 (department-multicampus…)")
    parser.add_argument("--time-limit", type=float, default=60, help="segundos por forma (60, el del servicio)")
    parser.add_argument("--threads", type=int, default=2, help="hilos de CP-SAT (2, los del servicio en compose)")
    parser.add_argument("--seed", type=int, default=7)
    parser.add_argument(
        "--max-variables",
        type=int,
        default=1_500_000,
        help="tope de variables por modelo (1 500 000, el SOLVER_MAX_MODEL_VARIABLES por omisión)",
    )
    parser.add_argument("--forms", default="lns,two-phase", help=f"de {', '.join(FORMS)}, separadas por coma")
    args = parser.parse_args(argv)

    root = Path(os.environ.get("SOLVER_FIXTURES_DIR", "/fixtures")) / "v1" / args.fixture
    forms = [form for form in args.forms.split(",") if form]
    unknown = [form for form in forms if form not in FORMS]
    if unknown:
        parser.error(f"formas desconocidas: {', '.join(unknown)}")
    rows = [run_form(form, root, args.time_limit, args.threads, args.seed, args.max_variables) for form in forms]
    output = table(rows)
    print(output)

    return output


if __name__ == "__main__":
    main(sys.argv[1:])
