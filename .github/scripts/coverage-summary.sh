#!/usr/bin/env bash
# Cobertura de líneas por módulo de app/Modules/ para el resumen del job, con mínimo bloqueante.
# Los totales salen del reporte Clover, que incluye las clases sin ninguna línea ejecutada
# (el reporte de texto las omite); el reporte de texto va completo al final, solo para leerlo.
# Sale con 1 si algún módulo queda debajo del mínimo o si el Clover no trae ningún módulo.
# Detalle en docs/arquitectura/testing.md.
# Uso: coverage-summary.sh <clover.xml> <coverage.txt> <mínimo>
set -euo pipefail

clover="$1"
text="$2"
min="$3"

rows="$(mktemp)"
trap 'rm -f "$rows"' EXIT

# PHPUnit escribe por archivo un <file name="..."> y, al cerrarlo, sus <metrics loc=... statements=...
# coveredstatements=...>; las <metrics> de cada <class> no traen loc y no se cuentan. El porcentaje
# se trunca a dos decimales antes de comparar, para que la tabla nunca muestre 80.00 % junto a ❌.
awk -v min="$min" '
  /<file name="/ {
    mod = "app (fuera de Modules, informativo)"
    if (match($0, /\/app\/Modules\/[^\/]+\//)) {
      mod = substr($0, RSTART + 13, RLENGTH - 14)
    }
    next
  }
  /<metrics loc="/ && mod != "" {
    match($0, / statements="[0-9]+"/);        tot[mod] += substr($0, RSTART + 13, RLENGTH - 14)
    match($0, / coveredstatements="[0-9]+"/); cov[mod] += substr($0, RSTART + 20, RLENGTH - 21)
    mod = ""
  }
  END {
    for (m in tot) {
      pct = tot[m] ? int(10000 * cov[m] / tot[m]) / 100 : 100
      mark = (m ~ /^app /) ? "—" : (pct < min ? "❌" : "✅")
      printf "| %s | %.2f %% | %d/%d | %s |\n", m, pct, cov[m], tot[m], mark
    }
  }
' "$clover" | sort > "$rows"

cat <<MD
### Cobertura de líneas por módulo

Mínimo bloqueante: ${min} % por módulo de \`app/Modules/\`.

| Módulo | Líneas | Cubiertas | Mínimo |
|---|---|---|---|
MD
cat "$rows"

if [ -f "$text" ]; then
  printf '\n<details><summary>Reporte completo</summary>\n\n```\n'
  sed 's/\x1b\[[0-9;]*m//g' "$text"
  printf '```\n\n</details>\n'
fi

# Sin ninguna fila de módulo el mínimo no revisó nada: un cambio de formato del Clover o de rutas
# no debe pasar como cobertura suficiente.
if ! grep -q -E '(✅|❌) \|$' "$rows"; then
  echo "El reporte Clover no trae ningún módulo de app/Modules/: no se pudo revisar el mínimo." >&2
  exit 1
fi

if grep -q '❌' "$rows"; then
  echo "Módulos debajo de ${min} %: $(grep '❌' "$rows" | cut -d'|' -f2 | xargs)" >&2
  exit 1
fi
