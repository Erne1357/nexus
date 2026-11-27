# ADR-0019 — pandas para perfilar las exportaciones del SII

**Estado:** Aceptada · **Fecha:** 2026-11-05

## Contexto
La tarea 1.16 migra los datos reales del SII y debe poder repetirse en el corte oficial con una exportación más reciente. Antes de escribir los adaptadores hay que conocer cada archivo: codificación, comillas, anotaciones al final, vacíos, formatos, claves repetidas y huérfanas entre archivos. Las exportaciones pesan hasta 42 MB y traen datos personales (alumnos, docentes), así que el análisis no puede imprimir valores ni sacar los archivos de la máquina. El usuario pidió hacerlo con pandas (5 de noviembre de 2026).

Del primer perfilado salieron rasgos que el lector de la migración también debe resolver: comillas simples, campos CHAR rellenos de espacios, Windows-1252 y promedios con coma decimal sin comillas, que parten 35 521 renglones de alumnos en dos campos.

## Decisión
1. `.github/scripts/sii-profile.py` perfila una carpeta de exportaciones y deja `_perfil.md` en ella (fuera de git, porque vive en `storage/app/imports/`). Lo corre `.github/scripts/sii-profile.sh` en un contenedor `python:3.13-slim` con `pandas==2.3.3`, **sin red** (`--network none`) y con el usuario local.
2. pandas es solo herramienta de análisis: no es dependencia de la app ni corre en CI. La migración misma está en PHP, dentro de `Imports`, y pasa por el motor de importación.
3. Privacidad: las columnas que no se usan (NIP, CURP, domicilios, media filiación…) no se cargan; las personales que sí se usan (nombres, RFC, número de control, correo) solo dan conteos y la forma de sus valores (letra → A, dígito → 9).
4. El perfilador repara la coma decimal con la misma regla que usará el lector PHP: prueba qué columnas de promedio venían partidas y solo acepta la combinación única que deja cada texto entre comillas en su columna; si hay dos posibles, el renglón queda como malformado.

## Consecuencias
- En el corte oficial se vuelve a perfilar la exportación nueva y se compara con la anterior antes de migrar.
- La imagen local `nexus-sii-profile:pandas-2.3.3` se construye la primera vez; cambiar de versión es cambiar la etiqueta en el script.
