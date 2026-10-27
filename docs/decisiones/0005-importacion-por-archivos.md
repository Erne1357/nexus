# ADR-0005 — Carga inicial por importación de archivos del SII

**Estado:** Aceptada · **Fecha:** 2026-10-26

## Contexto
No hay acceso directo a la base de datos del SII; el equipo recibirá exportaciones en Excel, CSV o SQL.

## Decisión
Módulo `Imports` con plantillas por catálogo, mapeo de columnas guardado, staging fila por fila, validación con errores descargables y aplicación por upsert sobre claves naturales. Nunca borra; desactiva solo bajo opción explícita.

## Consecuencias
Cualquier catálogo se puede recargar en cualquier momento (base del re-análisis). Si algún día hay acceso a la BD del SII, se agrega un origen alternativo que alimente el mismo staging.

## Alternativas descartadas
ETL directo (sin acceso). Captura manual (volumen inviable).
