# ADR-0016 — Extensión `btree_gist` para restricciones de exclusión por rango de fechas

**Estado:** Aceptada · **Fecha:** 2026-10-29

## Contexto
Un departamento tiene un solo jefe vigente por campus, y la misma persona no puede quedar registrada dos veces en el mismo cargo con periodos que se traslapan (tarea 1.7, confirmado el 29 de octubre de 2026). Revisarlo solo en PHP deja pasar dos altas simultáneas y los inserts que no pasan por el modelo, como las importaciones en bloque. PostgreSQL lo garantiza con una restricción de exclusión (`EXCLUDE USING gist`) sobre el departamento, el campus y el rango de fechas, pero un índice GiST no compara `uuid` ni `text` por igualdad sin la extensión `btree_gist`.

## Decisión
La migración de `department_heads` corre `create extension if not exists btree_gist` y define dos exclusiones:
- `department_heads_one_jefe`: un solo `jefe` por departamento y campus en cada fecha;
- `department_heads_no_duplicate`: la misma persona no repite cargo, departamento y campus en fechas que se traslapan.

Las dos usan `daterange(valid_from, valid_to, '[]')`, con los dos extremos incluidos y `valid_to` nulo como abierto, y no cuentan las filas borradas.

**Cambio del 30 de octubre de 2026:** el jefe pasó a ser uno por departamento para todo el Tec. La migración `2026_10_30_000101` redefine las dos exclusiones:
- `department_heads_one_jefe` ya no lleva campus;
- `department_heads_no_duplicate` aplica solo al jefe de proyecto docente, por departamento, campus y persona.

La decisión de usar `btree_gist` no cambia.

## Consecuencias
- Toda base de Nexus (desarrollo, pruebas, CI y producción) necesita la extensión. Viene con la imagen oficial de PostgreSQL 18 y es *trusted* desde PostgreSQL 13, así que basta el dueño de la base, sin superusuario.
- La fase 3 la reutiliza para los empalmes de salón y de docente (`docs/dominio/modelo-de-datos.md`, "Scheduling").
- El mensaje para el usuario lo da la Action que escribe (tarea 1.8); la restricción es la red de seguridad.

## Alternativas descartadas
- **Revisar solo en PHP:** no cubre la carrera entre dos sesiones ni los inserts en bloque.
- **Un trigger propio:** hace lo mismo que la exclusión con más código que mantener.
