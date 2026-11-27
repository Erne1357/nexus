"""Logs estructurados en JSON a stdout (docs/arquitectura/solver.md, «Observabilidad»)."""

import json
import logging
import sys
from datetime import UTC, datetime

LOGGER_NAME = "nexus.solver"

#: Atributos estándar de LogRecord: lo demás que traiga un registro (`extra=`) va al JSON.
_STANDARD = set(vars(logging.LogRecord("", 0, "", 0, "", (), None))) | {"message", "asctime", "taskName"}


class JsonFormatter(logging.Formatter):
    def format(self, record: logging.LogRecord) -> str:
        payload: dict[str, object] = {
            "ts": datetime.fromtimestamp(record.created, UTC).isoformat(timespec="milliseconds"),
            "level": record.levelname.lower(),
            "event": record.getMessage(),
        }
        for key, value in vars(record).items():
            if key not in _STANDARD:
                payload[key] = value
        if record.exc_info:
            payload["exception"] = self.formatException(record.exc_info)

        return json.dumps(payload, ensure_ascii=False, default=str)


def configure_logging() -> logging.Logger:
    """Deja el logger del servicio escribiendo JSON a stdout; se puede llamar más de una vez."""
    logger = logging.getLogger(LOGGER_NAME)
    if not any(getattr(h, "_nexus_json", False) for h in logger.handlers):
        handler = logging.StreamHandler(sys.stdout)
        handler.setFormatter(JsonFormatter())
        handler._nexus_json = True  # type: ignore[attr-defined]
        logger.addHandler(handler)
    logger.setLevel(logging.INFO)
    logger.propagate = False

    return logger
