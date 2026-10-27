# ADR-0009 — Modalidad como perfil de flujo del campus

**Estado:** Aceptada · **Fecha:** 2026-10-26

## Contexto
Además de Campus I y II (presencial) existen EAD (mixta), EnLínea y TecNM Virtual, con flujos distintos que aún no se han levantado. La modalidad puede cambiar la mayoría de los pasos.

## Decisión
Cada campus lleva `modality` y un perfil de flujo en `campuses.settings` (`uses_rooms`, `uses_time_blocks`, `staffing_mode`, `days`, `allow_external_teachers`, `requires_subdireccion_approval`, ventana y bloques). Pantallas, validadores y transiciones consultan el perfil, nunca el código del campus. R1 y R2 implementan solo el flujo presencial.

## Consecuencias
Las otras modalidades se activan ajustando perfiles y añadiendo únicamente las reglas que de verdad difieren (fase 10). Los docentes del ITCJ que dan clase en TecNM Virtual se modelan como horas externas desde R1.

## Alternativas descartadas
Tabla de modalidad separada con lógica por modalidad en código (duplicación). Ignorar modalidad hasta R4 (obligaría a migrar datos y reglas).
