"""Las respuestas de la API (el contrato de entrada y de salida vive en `app.contract`)."""

from typing import Any

from pydantic import BaseModel


class HealthResponse(BaseModel):
    status: str
    version: str
    contract_versions: list[int]
    runs_in_progress: int


class RunRef(BaseModel):
    """La referencia a una corrida: su identificador y su estado."""

    run_id: str
    status: str


class RunResponse(BaseModel):
    run_id: str
    #: queued, running, done, failed o cancelled.
    status: str
    #: De 0 a 100.
    progress: int
    #: Mejor puntaje blando hasta el momento (menor es mejor).
    best_score: int | None = None
    #: La salida completa del contrato, solo cuando `status` es `done`.
    output: dict[str, Any] | None = None
    #: El motivo del fallo, solo cuando `status` es `failed`.
    error: str | None = None
