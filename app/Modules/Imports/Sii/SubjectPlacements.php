<?php

namespace App\Modules\Imports\Sii;

/**
 * La retícula de Nexus que sale de los lugares del SII (D8, D15, D18; reglas-de-negocio.md "Reglas de datos"). No lee
 * archivos ni la base: recibe los lugares ya llevados a su plan de Nexus y devuelve uno por plan y materia de Nexus,
 * con lo que corrigió en incidents().
 *
 * 1. Clave (D8): la clave TecNM del lugar si la tiene; si no, su clave del SII. Los lugares de una clave TecNM votan
 *    por su combinación de créditos y horas, un voto por plan: la más votada se queda la clave TecNM y los lugares
 *    con otra conservan su clave del SII, con advertencia. En un empate gana la combinación del plan de versión más
 *    reciente, con advertencia (supuestos por confirmar).
 * 2. Variantes (D15): los renglones del SII que caen en el mismo plan y clave son un solo lugar. El semestre es el de
 *    la variante presencial, o el de la primera, entre las que traen semestre; si traen semestres distintos, avisa.
 * 3. Sin semestre (D18, R10): un lugar con semestre 0 toma el semestre más frecuente (en empate, el menor) de los
 *    lugares con semestre con el mismo nombre normalizado (SubjectName::key(), de cualquiera de sus claves del SII), primero en planes de la misma
 *    carrera y si no hay, de cualquiera, con advertencia que nombra de dónde lo tomó. Si nada empata, no entra a la
 *    retícula y avisa que queda para Servicios Escolares (detalles del empate, supuestos por confirmar).
 *
 * @phpstan-type Placement array{plan: string, career: string, modality: string, key: string, materia: string, tecnm: string|null, credits: string, theory: string, practice: string, semester: int, specialty: string}
 * @phpstan-type CodedPlacement array{plan: string, career: string, modality: string, key: string, materia: string, tecnm: string|null, code: string, credits: string, theory: string, practice: string, semester: int, specialty: string}
 * @phpstan-type GridPlacement array{plan: string, career: string, modality: string, key: string, materia: string, tecnm: string|null, code: string, credits: string, theory: string, practice: string, semester: int, specialty: string, materias: list<string>}
 */
final class SubjectPlacements
{
    private const int SOURCE_CAP = 5;

    /** @var list<array{level: 'warning', ref: string, message: string}> */
    private array $incidents = [];

    /**
     * @param  array<string, array{name: string, type: string, area: string}>  $catalog  materias del SII por clave
     * @param  array<string, string>  $versions  versión de cada plan de Nexus por clave
     */
    public function __construct(private readonly array $catalog, private readonly array $versions) {}

    /**
     * Un lugar por plan y clave de Nexus, por `"{plan}/{clave}"` en orden; `materia` es la clave del SII que da el
     * nombre, el tipo y el área, y `materias`, todas las claves del SII que se juntaron en el lugar.
     *
     * @param  list<Placement>  $placements
     * @return array<string, GridPlacement>
     */
    public function grid(array $placements): array
    {
        return $this->withSemesters($this->merged($this->coded($placements)));
    }

    /**
     * Los alias (R11): cada clave del SII de un lugar emitido apunta a su materia de Nexus, con el primer plan donde
     * se vio. Si una clave del SII quedó en varias materias de Nexus (en un plan se unió por la clave TecNM y en otro
     * conservó la suya), el alias apunta a la que está en más planes, en empate a la menor, y se avisa.
     *
     * @param  array<string, GridPlacement>  $grid
     * @return array{array<string, string>, list<array{code: string, subject: string, plan: string}>, list<array{level: 'warning', ref: string, message: string}>}
     */
    public function aliases(array $grid): array
    {
        $targets = [];
        foreach ($grid as $placement) {
            foreach ($placement['materias'] as $materia) {
                $targets[$materia][$placement['code']][] = $placement['plan'];
            }
        }
        ksort($targets, SORT_STRING);

        $subjectFor = [];
        $rows = [];
        $incidents = [];

        foreach ($targets as $materia => $codes) {
            ksort($codes, SORT_STRING);
            $counts = array_map('count', $codes);
            $subject = (string) array_search(max($counts), $counts, true);

            if (count($codes) > 1) {
                $incidents[] = ['level' => 'warning', 'ref' => (string) $materia, 'message' => "La clave del SII {$materia} quedó en varias materias de Nexus (".implode(', ', array_map('strval', array_keys($codes)))."): su alias apunta a {$subject} (supuesto por confirmar)."];
            }

            $subjectFor[$materia] = $subject;
            $rows[] = ['code' => (string) $materia, 'subject' => $subject, 'plan' => $codes[$subject][0]];
        }

        return [$subjectFor, $rows, $incidents];
    }

    /**
     * @return list<array{level: 'warning', ref: string, message: string}>
     */
    public function incidents(): array
    {
        return $this->incidents;
    }

    /**
     * @param  list<Placement>  $placements
     * @return list<CodedPlacement>
     */
    private function coded(array $placements): array
    {
        $votes = [];
        foreach ($placements as $placement) {
            if ($placement['tecnm'] !== null) {
                $votes[$placement['tecnm']][$this->combination($placement)][$placement['plan']] = true;
            }
        }
        ksort($votes, SORT_STRING);

        $winners = [];
        foreach ($votes as $tecnm => $combinations) {
            $winners[$tecnm] = $this->winner((string) $tecnm, $combinations);
        }

        $warned = [];
        foreach ($placements as $index => $placement) {
            $combination = $this->combination($placement);
            $tecnm = $placement['tecnm'];
            $kept = $tecnm === null || $combination !== $winners[$tecnm];
            $code = mb_strtoupper($kept ? $placement['materia'] : $tecnm);
            $placements[$index]['code'] = $code;
            $ref = "{$placement['plan']}/{$code}";

            if ($tecnm !== null && $kept && ! isset($warned[$ref])) {
                $warned[$ref] = true;
                $this->warn($ref, "La materia {$placement['materia']} del plan {$placement['plan']} trae créditos/teoría/práctica {$combination}, distintos de los de la clave TecNM {$tecnm} ({$winners[$tecnm]}): conserva su clave del SII {$code} (supuesto por confirmar).");
            }
        }

        return $placements;
    }

    /**
     * La combinación de créditos y horas que se queda la clave TecNM.
     *
     * @param  array<string, array<string, true>>  $combinations  combinación → planes que la traen
     */
    private function winner(string $tecnm, array $combinations): string
    {
        $votes = array_map('count', $combinations);
        $tied = array_map('strval', array_keys($votes, max($votes), true));

        if (count($tied) === 1) {
            return $tied[0];
        }

        usort($tied, fn (string $a, string $b): int => $this->newest($combinations[$b]) <=> $this->newest($combinations[$a]) ?: strcmp($a, $b));
        [$version, $plan] = $this->newest($combinations[$tied[0]]);
        $this->warn($tecnm, "Clave TecNM {$tecnm}: empate de créditos/teoría/práctica entre ".implode(', ', $tied)."; gana {$tied[0]}, la del plan más reciente {$plan} ({$version}) (supuesto por confirmar).");

        return $tied[0];
    }

    /**
     * La versión y la clave del plan más reciente de una lista de planes.
     *
     * @param  array<string, true>  $plans
     * @return array{string, string}
     */
    private function newest(array $plans): array
    {
        return max(array_map(fn (string|int $plan): array => [$this->versions[$plan] ?? '', (string) $plan], array_keys($plans)));
    }

    /**
     * @param  list<CodedPlacement>  $placements
     * @return array<string, GridPlacement>
     */
    private function merged(array $placements): array
    {
        $groups = [];
        foreach ($placements as $placement) {
            $groups["{$placement['plan']}/{$placement['code']}"][] = $placement;
        }
        ksort($groups, SORT_STRING);

        $grid = [];
        foreach ($groups as $ref => $variants) {
            $dated = array_values(array_filter($variants, fn (array $variant): bool => $variant['semester'] > 0));
            $pool = $dated === [] ? $variants : $dated;
            $chosen = array_find($pool, fn (array $variant): bool => $variant['modality'] === 'presencial') ?? $pool[0];
            $semesters = array_values(array_unique(array_column($dated, 'semester')));

            if (count($semesters) > 1) {
                sort($semesters);
                $from = $chosen['modality'] === 'presencial' ? 'la variante presencial' : "la retícula {$chosen['key']}";
                $this->warn((string) $ref, "Lugar {$ref}: sus renglones del SII traen semestres distintos (".implode(', ', $semesters)."); queda el {$chosen['semester']}, de {$from}.");
            }

            $specialty = $chosen['specialty'] !== '' ? $chosen['specialty'] : (array_find($variants, fn (array $variant): bool => $variant['specialty'] !== '')['specialty'] ?? '');

            $grid[(string) $ref] = [
                ...$chosen,
                'specialty' => $specialty,
                'materias' => array_values(array_unique([$chosen['materia'], ...array_column($variants, 'materia')])),
            ];
        }

        return $grid;
    }

    /**
     * @param  array<string, GridPlacement>  $grid
     * @return array<string, GridPlacement>
     */
    private function withSemesters(array $grid): array
    {
        $byName = [];
        foreach ($grid as $ref => $placement) {
            if ($placement['semester'] > 0) {
                foreach (array_unique(array_map($this->nameKey(...), $placement['materias'])) as $name) {
                    $byName[$name][] = ['ref' => (string) $ref, 'career' => $placement['career'], 'semester' => $placement['semester']];
                }
            }
        }

        foreach ($grid as $ref => $placement) {
            if ($placement['semester'] > 0) {
                continue;
            }

            $matches = $byName[$this->nameKey($placement['materia'])] ?? [];
            $sameCareer = array_values(array_filter($matches, fn (array $match): bool => $match['career'] === $placement['career']));
            $sources = $sameCareer === [] ? $matches : $sameCareer;

            if ($sources === []) {
                unset($grid[$ref]);
                $this->warn((string) $ref, "Lugar {$ref} sin semestre en el SII y sin materia con el mismo nombre que lo tenga: no entra a la retícula; queda para que Servicios Escolares le asigne semestre (D18).");

                continue;
            }

            $counts = array_count_values(array_column($sources, 'semester'));
            $semester = min(array_keys($counts, max($counts), true));
            $grid[$ref]['semester'] = $semester;
            $where = $sameCareer === [] ? 'de otra carrera' : 'de la misma carrera';
            $this->warn((string) $ref, "Lugar {$ref} sin semestre en el SII: toma el semestre {$semester} de la materia con el mismo nombre {$where} en {$this->listed(array_column($sources, 'ref'))} (D18; supuesto por confirmar).");
        }

        return $grid;
    }

    /**
     * La llave del nombre de una materia del SII, con que se empata un lugar sin semestre.
     */
    private function nameKey(string $materia): string
    {
        return SubjectName::key($this->catalog[$materia]['name']);
    }

    /**
     * @param  array{credits: string, theory: string, practice: string}  $placement
     */
    private function combination(array $placement): string
    {
        return "{$placement['credits']}/{$placement['theory']}/{$placement['practice']}";
    }

    /**
     * @param  list<string>  $refs
     */
    private function listed(array $refs): string
    {
        $shown = array_slice($refs, 0, self::SOURCE_CAP);
        $rest = count($refs) - count($shown);

        return implode(', ', $shown).($rest > 0 ? " y {$rest} más" : '');
    }

    private function warn(string $ref, string $message): void
    {
        $this->incidents[] = ['level' => 'warning', 'ref' => $ref, 'message' => $message];
    }
}
