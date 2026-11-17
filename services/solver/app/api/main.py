"""Punto de entrada de uvicorn: `uvicorn app.api.main:app` (falla al arrancar si falta `SOLVER_TOKEN`)."""

from .factory import create_app

app = create_app()
