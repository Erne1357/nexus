#!/usr/bin/env bash
# Convierte el reporte de texto de PHPUnit (--coverage-text) en Markdown para el
# resumen del job: cobertura de líneas por módulo de app/Modules/ y el reporte completo.
# Uso: coverage-summary.sh <coverage.txt>
set -euo pipefail

report="$(mktemp)"
trap 'rm -f "$report"' EXIT
sed 's/\x1b\[[0-9;]*m//g' "$1" > "$report"

cat <<'MD'
### Cobertura de líneas por módulo

Informativa en fase 0 (meta: 80 % en `app/Modules/Core`); bloqueante desde la fase 1.

| Módulo | Líneas | Cubiertas |
|---|---|---|
MD

# Cada clase ocupa dos líneas: su nombre completo y, debajo, "Methods: … Lines: … ( cubiertas/total)".
awk '
  /^App\\/ {
    n = split($0, p, /\\/)
    mod = (p[2] == "Modules" && n >= 3) ? p[3] : "app (fuera de Modules)"
    next
  }
  /Lines:/ && mod != "" {
    s = $0
    sub(/.*Lines: +[0-9.]+% \( */, "", s)
    split(s, c, /[\/ )]+/)
    cov[mod] += c[1]
    tot[mod] += c[2]
    mod = ""
  }
  END {
    for (m in tot) {
      printf "| %s | %.2f %% | %d/%d |\n", m, (tot[m] ? 100 * cov[m] / tot[m] : 100), cov[m], tot[m]
    }
  }
' "$report" | sort

printf '\n<details><summary>Reporte completo</summary>\n\n```\n'
cat "$report"
printf '```\n\n</details>\n'
