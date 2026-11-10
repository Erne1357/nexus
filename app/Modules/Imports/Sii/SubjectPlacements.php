<?php

namespace App\Modules\Imports\Sii;

/**
 * La retícula de Nexus que sale de los lugares del SII (D8, D15, D18; reglas-de-negocio.md "Reglas de datos"). No lee
 * archivos ni la base: recibe los lugares ya llevados a su plan de Nexus y devuelve uno por plan y materia de Nexus,
 * con lo que corrigió en incidents(). Los renglones se ordenan primero por retícula y clave del SII, así que el
 * resultado no depende del orden del archivo.
 *
 * 1. Clave (D8): la clave TecNM del lugar si la tiene; si no, su clave del SII. Los lugares de una clave TecNM votan
 *    por su combinación de créditos y horas, un voto por plan: la más votada se queda la clave TecNM y los lugares
 *    con otra conservan su clave del SII, con advertencia. En un empate gana la combinación del plan de versión más
 *    reciente, con advertencia (supuestos por confirmar). Dos materias distintas de una misma retícula del SII nunca
 *    quedan con la misma clave: si el SII les da la misma clave TecNM, o una es la clave del SII de la otra, la
 *    conserva la que es esa clave del SII o, si no, la única que se llama como la clave en las demás retículas
 *    (hasta dos letras de diferencia); las otras se quedan con su clave del SII y se avisa (supuesto por confirmar).
 * 2. Variantes (D15, R14): dentro de un plan, las materias de retículas distintas del SII con el mismo nombre
 *    normalizado (sin importar singular o plural), los mismos créditos y la misma especialidad (o ninguna) son una
 *    sola, aunque repartan distinto las horas de teoría y práctica. Si las horas coinciden, se queda la clave TecNM si
 *    alguna la tiene; si no, la clave del SII de la variante presencial, y si no hay, la menor. Si difieren, se queda
 *    la materia de la variante presencial, con sus horas, y se avisa (decidido el 9 de noviembre de 2026 como
 *    supuesto por confirmar con Servicios Escolares). Dos claves TecNM distintas no se funden (D8). Las materias con
 *    el mismo nombre dentro de una misma retícula no se funden y se avisa; si además hay otra variante, cada una se
 *    empareja con la primera libre, en orden de clave (supuestos por confirmar).
 * 3. Lugar: los renglones que caen en el mismo plan y clave son un solo lugar. El semestre es el de la variante
 *    presencial, o el de la primera, entre las que traen semestre; si traen semestres distintos, avisa.
 * 4. Horas: una materia de Nexus tiene unas solas horas y créditos. Si una clave queda en planes con combinaciones
 *    distintas (una clave del SII que el SII repite en retículas con otras horas, o igual a una clave TecNM), todos
 *    sus lugares toman la de más planes; en empate, la del plan de versión más reciente. Avisa (supuesto por confirmar).
 * 5. Nombre: el de la clave de Nexus es el más frecuente entre sus renglones del SII (SubjectName::key()); en empate,
 *    el del plan de versión más reciente y luego el primero en orden alfabético. Si el SII trae otros, avisa.
 * 6. Sin semestre (D18, R10): un lugar con semestre 0 toma el semestre más frecuente (en empate, el menor) de los
 *    lugares con semestre con el mismo nombre normalizado (de cualquiera de sus claves del SII), primero en planes de
 *    la misma carrera y si no hay, de cualquiera, con advertencia que nombra de dónde lo tomó. Si nada empata, no
 *    entra a la retícula: queda en pending() y avisa que va a la lista de pendientes de Servicios Escolares, que le
 *    asigna semestre (D19; detalles del empate, supuestos por confirmar).
 *
 * @phpstan-type Placement array{plan: string, career: string, modality: string, key: string, materia: string, tecnm: string|null, credits: string, theory: string, practice: string, semester: int, specialty: string, position: int}
 * @phpstan-type CodedPlacement array{plan: string, career: string, modality: string, key: string, materia: string, tecnm: string|null, code: string, origin: string, unified: bool, credits: string, theory: string, practice: string, semester: int, specialty: string, position: int}
 * @phpstan-type GridPlacement array{plan: string, career: string, modality: string, key: string, materia: string, tecnm: string|null, code: string, origin: string, unified: bool, name: string, credits: string, theory: string, practice: string, semester: int, specialty: string, position: int, materias: list<string>, sources: list<string>}
 */
final class SubjectPlacements
{
    private const int SOURCE_CAP = 5;

    /** @var list<array{level: 'warning', ref: string, message: string}> */
    private array $incidents = [];

    /** @var array<string, GridPlacement> */
    private array $pending = [];

    /**
     * @param  array<string, array{name: string, type: string, area: string}>  $catalog  materias del SII por clave
     * @param  array<string, string>  $versions  versión de cada plan de Nexus por clave
     */
    public function __construct(private readonly array $catalog, private readonly array $versions) {}

    /**
     * Un lugar por plan y clave de Nexus, por `"{plan}/{clave}"` en orden; `materia` es la clave del SII de la variante
     * elegida, `materias`, todas las claves del SII que se juntaron en el lugar, y `name`, el nombre de la materia.
     *
     * @param  list<Placement>  $placements
     * @return array<string, GridPlacement>
     */
    public function grid(array $placements): array
    {
        usort($placements, fn (array $a, array $b): int => strcmp($a['key'], $b['key']) ?: strcmp($a['materia'], $b['materia']));
        $this->warnRepeatedNames($placements);

        return $this->withSemesters($this->named($this->sharedHours($this->merged($this->fused($this->coded($placements))))));
    }

    /**
     * Los lugares sin semestre que no empataron con ninguna materia (D18 y D19), por `"{plan}/{clave}"`, con la forma
     * de grid(): no entran a la retícula y pasan a la lista de pendientes. Vacío hasta llamar a grid().
     *
     * @return array<string, GridPlacement>
     */
    public function pending(): array
    {
        return $this->pending;
    }

    /**
     * Los alias (R11): cada clave del SII de un lugar emitido, o pendiente (`$pending`), apunta a su materia de Nexus,
     * con el primer plan donde se vio. Si una clave del SII quedó en varias materias de Nexus (en un plan se unió por la clave TecNM y en otro
     * conservó la suya), el alias apunta a la que está en más planes, en empate a la menor, y se avisa. El cuarto
     * elemento es el mapa por plan (plan → clave del SII → materia de Nexus), que no depende de esa elección: el
     * kárdex lo usa con el plan del alumno.
     *
     * @param  array<string, GridPlacement>  $grid
     * @param  array<string, GridPlacement>  $pending
     * @return array{array<string, string>, list<array{code: string, subject: string, plan: string}>, list<array{level: 'warning', ref: string, message: string}>, array<string, array<string, string>>}
     */
    public function aliases(array $grid, array $pending = []): array
    {
        $targets = [];
        $byPlan = [];
        foreach ([...$grid, ...$pending] as $placement) {
            foreach ($placement['materias'] as $materia) {
                $targets[$materia][$placement['code']][] = $placement['plan'];
                $byPlan[$placement['plan']][$materia] ??= $placement['code'];
            }
        }
        ksort($targets, SORT_STRING);
        ksort($byPlan, SORT_STRING);
        foreach ($byPlan as $plan => $codes) {
            ksort($codes, SORT_STRING);
            $byPlan[$plan] = $codes;
        }

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

        return [$subjectFor, $rows, $incidents, $byPlan];
    }

    /**
     * @return list<array{level: 'warning', ref: string, message: string}>
     */
    public function incidents(): array
    {
        return $this->incidents;
    }

    /**
     * Una advertencia por nombre que una retícula del SII trae con varias claves: no se funden (R14).
     *
     * @param  list<Placement>  $placements  ya ordenados
     */
    private function warnRepeatedNames(array $placements): void
    {
        $byName = [];
        foreach ($placements as $placement) {
            $byName[$placement['key']][$this->nameKey($placement['materia'])][$placement['materia']] = true;
        }

        foreach ($byName as $key => $names) {
            foreach ($names as $codes) {
                if (count($codes) > 1) {
                    $codes = array_map('strval', array_keys($codes));
                    $name = SubjectName::title($this->catalog[$codes[0]]['name']);
                    $this->warn((string) $key, "El SII trae la materia «{$name}» ".count($codes)." veces en la retícula {$key} con las claves ".implode(', ', $codes).': revisar.');
                }
            }
        }
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
            $placements[$index]['origin'] = $code;
            $placements[$index]['unified'] = ! $kept;
            $ref = "{$placement['plan']}/{$code}";

            if ($tecnm !== null && $kept && ! isset($warned[$ref])) {
                $warned[$ref] = true;
                $this->warn($ref, "La materia {$placement['materia']} del plan {$placement['plan']} trae créditos/teoría/práctica {$combination}, distintos de los de la clave TecNM {$tecnm} ({$winners[$tecnm]}): conserva su clave del SII {$code} (supuesto por confirmar).");
            }
        }

        return $this->distinctWithinGrid($placements);
    }

    /**
     * Regla 1: dos materias distintas de una misma retícula del SII no quedan con la misma clave. La conserva la que
     * es esa clave del SII o, si no, la única que se llama como ella en las demás retículas; las otras vuelven a su
     * clave del SII.
     *
     * @param  list<CodedPlacement>  $placements
     * @return list<CodedPlacement>
     */
    private function distinctWithinGrid(array $placements): array
    {
        $claims = [];
        foreach ($placements as $index => $placement) {
            $claims[$placement['key']][$placement['code']][$placement['materia']][] = $index;
        }
        ksort($claims, SORT_STRING);

        foreach ($claims as $key => $codes) {
            foreach ($codes as $code => $materias) {
                if (count($materias) < 2) {
                    continue;
                }

                $code = (string) $code;
                $names = array_map('strval', array_keys($materias));
                sort($names, SORT_STRING);
                $own = array_find($names, fn (string $materia): bool => mb_strtoupper($materia) === $code);
                $winner = $own ?? $this->namedAfter($code, $names, (string) $key, $placements);

                foreach ($materias as $materia => $indexes) {
                    if ((string) $materia === $winner) {
                        continue;
                    }

                    foreach ($indexes as $index) {
                        $placements[$index]['code'] = $placements[$index]['origin'] = mb_strtoupper((string) $materia);
                        $placements[$index]['unified'] = false;
                    }
                }

                $listed = implode(', ', array_map(fn (string $materia): string => "{$materia} ({$this->catalog[$materia]['name']})", $names));
                $outcome = $winner === null
                    ? 'ninguna la conserva y cada una se queda con su clave del SII'
                    : "la conserva {$winner} y las demás se quedan con su clave del SII";
                $this->warn((string) $key, "En la retícula {$key} del SII, {$listed} quedaban con la misma clave {$code} y son materias distintas: {$outcome} (supuesto por confirmar).");
            }
        }

        return $placements;
    }

    /**
     * De las materias de una retícula que se disputan una clave TecNM, la única cuyo nombre es el que esa clave
     * tiene en las demás retículas, con hasta dos letras de diferencia; null si no hay una sola.
     *
     * @param  list<string>  $materias
     * @param  list<CodedPlacement>  $placements
     */
    private function namedAfter(string $code, array $materias, string $key, array $placements): ?string
    {
        $elsewhere = [];
        foreach ($placements as $placement) {
            if ($placement['tecnm'] === $code && $placement['key'] !== $key) {
                $elsewhere[] = $this->nameKey($placement['materia']);
            }
        }

        if ($elsewhere === []) {
            return null;
        }

        $name = $this->majorityKey($elsewhere);
        $close = array_values(array_filter($materias, fn (string $materia): bool => levenshtein($this->nameKey($materia), $name) <= 2));

        return count($close) === 1 ? $close[0] : null;
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
     * R14: dentro de cada plan, junta bajo una sola clave las materias de variantes distintas que son la misma.
     *
     * @param  list<CodedPlacement>  $placements
     * @return list<CodedPlacement>
     */
    private function fused(array $placements): array
    {
        $byPlan = [];
        foreach ($placements as $index => $placement) {
            $byPlan[$placement['plan']][$placement['code']][] = $index;
        }

        foreach ($byPlan as $plan => $codes) {
            $alike = [];

            foreach ($codes as $code => $indexes) {
                $rows = array_map(fn (int $index): array => $placements[$index], $indexes);
                $signature = implode("\n", [
                    $this->majorityKey(array_map($this->fusionName(...), array_column($rows, 'materia'))),
                    implode('|', array_unique(array_column($rows, 'credits'))),
                    implode('|', array_unique(array_column($rows, 'specialty'))),
                ]);

                $alike[$signature][] = [
                    'code' => (string) $code,
                    'indexes' => $indexes,
                    'keys' => array_values(array_unique(array_column($rows, 'key'))),
                    'tecnm' => array_any($rows, fn (array $row): bool => $row['unified']) ? (string) $code : null,
                    'presencial' => array_any($rows, fn (array $row): bool => $row['modality'] === 'presencial'),
                    'combinations' => array_values(array_unique(array_map($this->combination(...), $rows))),
                    'materias' => array_values(array_unique(array_column($rows, 'materia'))),
                ];
            }

            foreach ($alike as $units) {
                foreach (count($units) > 1 ? $this->clusters($units) : [] as $cluster) {
                    $kept = $this->keptCode((string) $plan, $cluster);

                    foreach ($cluster as $unit) {
                        foreach ($unit['indexes'] as $index) {
                            $placements[$index]['code'] = $kept;
                        }
                    }
                }
            }
        }

        return $placements;
    }

    /**
     * Agrupa las materias iguales de un plan sin juntar dos de la misma retícula ni dos claves TecNM distintas: las de
     * clave TecNM primero y luego por clave, cada una entra al primer grupo libre. Devuelve los grupos de más de una.
     *
     * @param  list<array{code: string, indexes: list<int>, keys: list<string>, tecnm: string|null, presencial: bool, combinations: list<string>, materias: list<string>}>  $units
     * @return list<list<array{code: string, indexes: list<int>, keys: list<string>, tecnm: string|null, presencial: bool, combinations: list<string>, materias: list<string>}>>
     */
    private function clusters(array $units): array
    {
        usort($units, fn (array $a, array $b): int => ($b['tecnm'] !== null) <=> ($a['tecnm'] !== null) ?: strcmp($a['code'], $b['code']));
        $clusters = [];

        foreach ($units as $unit) {
            $slot = array_find_key($clusters, fn (array $cluster): bool => array_intersect($cluster['keys'], $unit['keys']) === []
                && ($cluster['tecnm'] === null || $unit['tecnm'] === null || $cluster['tecnm'] === $unit['tecnm']));

            if ($slot === null) {
                $clusters[] = ['keys' => $unit['keys'], 'tecnm' => $unit['tecnm'], 'units' => [$unit]];

                continue;
            }

            $clusters[$slot]['keys'] = [...$clusters[$slot]['keys'], ...$unit['keys']];
            $clusters[$slot]['tecnm'] ??= $unit['tecnm'];
            $clusters[$slot]['units'][] = $unit;
        }

        return array_values(array_filter(array_column($clusters, 'units'), fn (array $members): bool => count($members) > 1));
    }

    /**
     * La clave que se queda un grupo de R14. Si sus horas difieren, la de la variante presencial, con sus horas, y
     * avisa (supuesto por confirmar); si no hay presencial o las horas coinciden, la TecNM si alguna la tiene; si no,
     * la de la variante presencial; si no hay, la menor.
     *
     * @param  list<array{code: string, tecnm: string|null, presencial: bool, combinations: list<string>, materias: list<string>}>  $cluster
     */
    private function keptCode(string $plan, array $cluster): string
    {
        $combinations = array_values(array_unique(array_merge(...array_column($cluster, 'combinations'))));
        $presencial = array_find($cluster, fn (array $unit): bool => $unit['presencial']);

        if (count($combinations) > 1) {
            sort($combinations);
            $kept = $presencial['code'] ?? $this->tecnmOrSmallest($cluster);
            $codes = array_map(fn (array $unit): string => $unit['materias'] === [$unit['code']] ? $unit['code'] : $unit['code'].' (del SII '.implode(', ', $unit['materias']).')', $cluster);
            sort($codes, SORT_STRING);
            $from = $presencial === null ? 'no hay variante presencial: queda con sus horas' : 'con las horas de la variante presencial';
            $this->warn("{$plan}/{$kept}", "En el plan {$plan}, ".implode(', ', $codes).' son la misma materia con horas distintas (créditos/teoría/práctica '.implode(', ', $combinations)."): queda {$kept}, {$from} (supuesto por confirmar).");

            return $kept;
        }

        return $this->tecnmOrSmallest($cluster);
    }

    /**
     * La TecNM si alguna la tiene; si no, la de la variante presencial; si no hay, la menor.
     *
     * @param  list<array{code: string, tecnm: string|null, presencial: bool, combinations: list<string>, materias: list<string>}>  $cluster
     */
    private function tecnmOrSmallest(array $cluster): string
    {
        $tecnm = array_find($cluster, fn (array $unit): bool => $unit['tecnm'] !== null);

        if ($tecnm !== null) {
            return $tecnm['code'];
        }

        $presencial = array_values(array_filter($cluster, fn (array $unit): bool => $unit['presencial']));
        $codes = array_column($presencial === [] ? $cluster : $presencial, 'code');
        sort($codes, SORT_STRING);

        return $codes[0];
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
            $hours = $chosen['origin'] === $chosen['code'] ? $chosen : (array_find($variants, fn (array $variant): bool => $variant['origin'] === $variant['code']) ?? $chosen);

            $grid[(string) $ref] = [
                ...$chosen,
                'credits' => $hours['credits'],
                'theory' => $hours['theory'],
                'practice' => $hours['practice'],
                'name' => '',
                'specialty' => $specialty,
                'materias' => array_values(array_unique([$chosen['materia'], ...array_column($variants, 'materia')])),
                'sources' => array_column($variants, 'materia'),
            ];
        }

        return $grid;
    }

    /**
     * Regla 4: una materia de Nexus tiene unas solas horas y créditos. Si una clave quedó en planes con combinaciones
     * distintas (una clave del SII que el SII repite en retículas con otras horas, o que es igual a una clave TecNM),
     * todos sus lugares toman la de más planes; en empate, la del plan de versión más reciente, y luego la menor.
     *
     * @param  array<string, GridPlacement>  $grid
     * @return array<string, GridPlacement>
     */
    private function sharedHours(array $grid): array
    {
        $byCode = [];
        foreach ($grid as $ref => $placement) {
            $byCode[$placement['code']][$this->combination($placement)][$placement['plan']] = (string) $ref;
        }
        ksort($byCode, SORT_STRING);

        foreach ($byCode as $code => $combinations) {
            if (count($combinations) === 1) {
                continue;
            }

            $ranked = array_map('strval', array_keys($combinations));
            usort($ranked, fn (string $a, string $b): int => count($combinations[$b]) <=> count($combinations[$a])
                ?: $this->newest($combinations[$b]) <=> $this->newest($combinations[$a])
                ?: strcmp($a, $b));
            $winner = $ranked[0];
            [$credits, $theory, $practice] = explode('/', $winner);
            $changed = [];

            foreach (array_slice($ranked, 1) as $combination) {
                foreach ($combinations[$combination] as $plan => $ref) {
                    $grid[$ref] = [...$grid[$ref], 'credits' => $credits, 'theory' => $theory, 'practice' => $practice];
                    $changed[] = "{$plan} ({$combination})";
                }
            }

            sort($changed, SORT_STRING);
            $count = count($combinations[$winner]);
            $this->warn((string) $code, "La materia {$code} trae créditos/teoría/práctica distintos según el plan: {$winner} en {$count} ".($count === 1 ? 'plan' : 'planes').' y otros en '.implode(', ', $changed).". En Nexus una materia tiene unas solas horas: queda {$winner} en todos sus planes; revisar (supuesto por confirmar).");
        }

        return $grid;
    }

    /**
     * Le pone a cada lugar el nombre de su materia de Nexus: el más frecuente entre todos sus renglones del SII.
     *
     * @param  array<string, GridPlacement>  $grid
     * @return array<string, GridPlacement>
     */
    private function named(array $grid): array
    {
        $sources = [];
        foreach ($grid as $placement) {
            foreach ($placement['sources'] as $materia) {
                $sources[$placement['code']][] = ['materia' => $materia, 'plan' => $placement['plan']];
            }
        }

        $names = [];
        foreach ($sources as $code => $rows) {
            $names[$code] = $this->representativeName((string) $code, $rows);
        }

        foreach ($grid as $ref => $placement) {
            $grid[$ref]['name'] = $names[$placement['code']];
        }

        return $grid;
    }

    /**
     * @param  list<array{materia: string, plan: string}>  $rows
     */
    private function representativeName(string $code, array $rows): string
    {
        $byKey = [];
        foreach ($rows as $row) {
            $name = $this->catalog[$row['materia']]['name'];
            $key = SubjectName::key($name);
            $byKey[$key]['count'] = ($byKey[$key]['count'] ?? 0) + 1;
            $byKey[$key]['version'] = max($byKey[$key]['version'] ?? '', $this->versions[$row['plan']] ?? '');
            $byKey[$key]['name'] ??= $name;
        }

        $keys = array_map('strval', array_keys($byKey));
        usort($keys, fn (string $a, string $b): int => $byKey[$b]['count'] <=> $byKey[$a]['count'] ?: strcmp($byKey[$b]['version'], $byKey[$a]['version']) ?: strcmp($a, $b));
        $titles = array_map(fn (string $key): string => SubjectName::title($byKey[$key]['name']), $keys);

        if (count($titles) > 1) {
            $this->warn($code, "La materia {$code} trae nombres distintos en el SII (".implode(', ', array_slice($titles, 1))."): queda «{$titles[0]}».");
        }

        return $titles[0];
    }

    /**
     * @param  array<string, GridPlacement>  $grid
     * @return array<string, GridPlacement>
     */
    private function withSemesters(array $grid): array
    {
        $this->pending = [];
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

            $matches = $byName[SubjectName::key($placement['name'])] ?? [];
            $sameCareer = array_values(array_filter($matches, fn (array $match): bool => $match['career'] === $placement['career']));
            $sources = $sameCareer === [] ? $matches : $sameCareer;

            if ($sources === []) {
                $this->pending[(string) $ref] = $placement;
                unset($grid[$ref]);
                $this->warn((string) $ref, "Lugar {$ref} sin semestre en el SII y sin materia con el mismo nombre que lo tenga: no entra a la retícula; pasa a la lista de pendientes para que Servicios Escolares le asigne semestre (D18 y D19).");

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
     * La llave de nombre más frecuente; en empate, la primera en orden alfabético.
     *
     * @param  list<string>  $keys
     */
    private function majorityKey(array $keys): string
    {
        $counts = array_count_values($keys);
        $keys = array_map('strval', array_keys($counts, max($counts), true));
        sort($keys, SORT_STRING);

        return $keys[0];
    }

    /**
     * La llave del nombre de una materia del SII (SubjectName::key()).
     */
    private function nameKey(string $materia): string
    {
        return SubjectName::key($this->catalog[$materia]['name']);
    }

    /**
     * La llave del nombre para fundir variantes: la de nameKey() con cada palabra de más de tres letras en singular
     * (`TUTORIAS` y `TUTORIA` son la misma; `REDES`, `RED`). Basta con que las dos formas den lo mismo.
     */
    private function fusionName(string $materia): string
    {
        return implode(' ', array_map(
            fn (string $word): string => strlen($word) > 3 ? (preg_replace('/(?<=[^AEIOU])ES$|(?<=[AEIOU])S$/', '', $word) ?? $word) : $word,
            explode(' ', $this->nameKey($materia)),
        ));
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
