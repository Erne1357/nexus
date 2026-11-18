"""Autenticación por token compartido: `Authorization: Bearer <SOLVER_TOKEN>`."""

import secrets

from fastapi import HTTPException, Request, status


def require_token(request: Request) -> None:
    expected: str = request.app.state.settings.token
    scheme, _, token = request.headers.get("authorization", "").partition(" ")

    if scheme.lower() != "bearer" or not secrets.compare_digest(token.strip().encode(), expected.encode()):
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="Token ausente o inválido.",
            headers={"WWW-Authenticate": "Bearer"},
        )
