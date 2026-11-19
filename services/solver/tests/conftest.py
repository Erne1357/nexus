"""Fixtures de pytest.

Los fixtures compartidos con PHP (`tests/Fixtures/solver/v1/*`) no se copian al servicio: en la imagen `dev` se montan
en `/fixtures` (`SOLVER_FIXTURES_DIR`) y, fuera de Docker, se leen de `<repositorio>/tests/Fixtures/solver`. Si no se
encuentran, la recolección falla con un mensaje claro: ninguna prueba se omite en silencio.
"""

import copy
import json
import os
import threading
import time
from collections.abc import Callable, Iterator
from pathlib import Path
from typing import Any

import pytest
from fastapi.testclient import TestClient

os.environ.setdefault("SOLVER_TOKEN", "test-token")

from app.api.factory import create_app
from app.config import Settings
from app.contract import SolverInput, SolverOutput
from app.engine import RunContext, TrivialEngine

TOKEN = "test-token"


def fixtures_dir() -> Path:
    configured = os.environ.get("SOLVER_FIXTURES_DIR")
    path = Path(configured) if configured else Path(__file__).resolve().parents[3] / "tests" / "Fixtures" / "solver"
    if not (path / "v1").is_dir():
        raise RuntimeError(
            f"No se encuentran los fixtures compartidos en {path}/v1. Monta tests/Fixtures/solver del repositorio "
            'en /fixtures (docker run -v "$PWD/tests/Fixtures/solver:/fixtures:ro" ...) o define SOLVER_FIXTURES_DIR.'
        )

    return path


def fixture_files(name: str) -> list[Path]:
    """Los `input.json` o `output.json` de cada instancia de la versión 1."""
    return sorted((fixtures_dir() / "v1").glob(f"*/{name}.json"))


def load(path: Path) -> dict[str, Any]:
    return json.loads(path.read_text(encoding="utf-8"))


@pytest.fixture
def tiny_input() -> dict[str, Any]:
    """Una copia fresca de la entrada `tiny`, lista para romperla en cada prueba."""
    return copy.deepcopy(load(fixtures_dir() / "v1" / "tiny" / "input.json"))


@pytest.fixture
def tiny_output() -> dict[str, Any]:
    return copy.deepcopy(load(fixtures_dir() / "v1" / "tiny" / "output.json"))


@pytest.fixture
def auth() -> dict[str, str]:
    return {"Authorization": f"Bearer {TOKEN}"}


def make_settings(**overrides: Any) -> Settings:
    return Settings(token=TOKEN, **overrides)


@pytest.fixture
def client() -> Iterator[TestClient]:
    """La API con el motor de prueba, pedido explícitamente (el del servicio por omisión es `cpsat` desde la 7.10): las
    pruebas de la API miden el ciclo, no el modelo."""
    with TestClient(create_app(make_settings(engine="trivial"))) as test_client:
        yield test_client


class BlockingEngine:
    """Un motor que no termina hasta que la prueba lo suelte o cancele la corrida."""

    key = "blocking"
    version = "test"

    def __init__(self) -> None:
        self.release = threading.Event()

    def solve(self, data: SolverInput, context: RunContext) -> SolverOutput:
        while not self.release.wait(0.01):
            context.check_cancelled()

        return TrivialEngine().solve(data, context)


@pytest.fixture
def blocking_engine() -> BlockingEngine:
    return BlockingEngine()


def wait_until(condition: Callable[[], bool], timeout: float = 5.0) -> None:
    deadline = time.monotonic() + timeout
    while not condition():
        if time.monotonic() > deadline:
            raise AssertionError("La condición no se cumplió a tiempo.")
        time.sleep(0.01)
