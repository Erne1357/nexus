<?php

namespace App\Modules\Imports\Sii;

/**
 * La retícula de Nexus que sale de los lugares del SII (D8, D15, D18, R-V2-1; reglas-de-negocio.md "Reglas de datos").
 * No lee archivos ni la base: recibe los lugares ya llevados a su plan de Nexus, con su grupo, y devuelve uno por plan y
 * materia de Nexus, con lo que corrigió en incidents(). Cada retícula del SII es su propio plan (R-V2-1, confirmado el
 * 19 de noviembre de 2026), pero el catálogo de materias se arma como cuando las variantes por modalidad se fundían en un
 * plan: por grupo, la clave oficial que juntaba esas retículas (`grupo` de `planes.csv`). Así la clave, las horas y el
 * nombre de cada materia de Nexus no cambian por separarlas; los lugares, los pendientes y el mapa por plan sí van al plan
 * de cada retícula. Los renglones se ordenan primero por retícula y clave del SII, así que el resultado no depende del
 * orden del archivo.
 *
 * 1. Clave (D8): la clave TecNM del lugar si la tiene; si no, su clave del SII. Los lugares de una clave TecNM votan
 *    por su combinación de créditos y horas, un voto por grupo: la más votada se queda la clave TecNM y los lugares
 *    con otra conservan su clave del SII, con advertencia. En un empate gana la combinación del grupo de versión más
 *    reciente, con advertencia (supuestos por confirmar). Dos materias distintas de una misma retícula del SII nunca
 *    quedan con la misma clave (supuesto por confirmar, con aviso): si el SII les da la misma clave TecNM, antes del
 *    voto la conserva la única que se llama como esa clave en las retículas donde nadie se la disputa (hasta dos
 *    letras de diferencia) y las otras se quedan sin ella; si la clave TecNM de una es la clave del SII de otra, la
 *    conserva esta y la primera vuelve a su clave del SII.
 * 2. Variantes (D15, R14): dentro de un grupo, las materias de retículas distintas del SII con el mismo nombre
 *    normalizado (sin importar singular o plural), los mismos créditos y la misma especialidad (o ninguna) son una
 *    sola materia de Nexus, aunque repartan distinto las horas de teoría y práctica. Si las horas coinciden, se queda la
 *    clave TecNM si alguna la tiene; si no, la clave del SII de la variante presencial, y si no hay, la menor. Si
 *    difieren, se queda la materia de la variante presencial, con sus horas, y se avisa (decidido el 9 de noviembre de
 *    2026 como supuesto por confirmar con Servicios Escolares). Entre varias presenciales va primero la de la retícula
 *    que conserva la clave oficial del grupo (supuesto por confirmar: hasta el 19 de noviembre de 2026 era la única
 *    presencial de cada grupo). Dos claves TecNM distintas no se funden (D8). Las materias con el mismo nombre dentro de
 *    una misma retícula no se funden y se avisa; si además hay otra variante, cada una se empareja con la primera libre,
 *    en orden de clave (supuestos por confirmar).
 * 3. Lugar: los renglones de una retícula que caen en el mismo plan y clave son un solo lugar, con el semestre, el
 *    renglón y la especialidad de su propia retícula: los de la variante presencial, o de la primera, entre las que
 *    traen semestre; si traen semestres distintos, avisa. Sus horas y su nombre son los de su materia de Nexus.
 * 4. Horas: una materia de Nexus tiene unas solas horas y créditos. Si una clave queda en grupos con combinaciones
 *    distintas (una clave del SII que el SII repite en retículas con otras horas, o igual a una clave TecNM), todos
 *    sus lugares toman la de más grupos; en empate, la del grupo de versión más reciente. Avisa (supuesto por
 *    confirmar). La versión de un grupo es la mayor de sus planes (en el curado, todos llevan el año de la clave oficial).
 * 5. Nombre: el de la clave de Nexus es el más frecuente entre sus renglones del SII (SubjectName::key()); en empate,
 *    el del grupo de versión más reciente y luego el primero en orden alfabético. Si el SII trae otros, avisa.
 * 6. Estatus N: un lugar cuyas variantes del SII traen todas estatus `N` (ya no se ofrece) no entra a la retícula de su
 *    plan: pasa a pending() con aviso, para que Servicios Escolares decida, y lo aprobado en él cuenta (supuesto por
 *    confirmar, 10 de noviembre de 2026; antes no se migraba y se perdía el kárdex de quien lo aprobó). Sigue siendo la
 *    misma materia de Nexus que su variante vigente de otra retícula del grupo. En una retícula viva sugiere su semestre
 *    del SII si lo trae (es dato del SII, no una decisión de Nexus) y si no, el de su misma carrera, como en el punto 7.
 *    En una retícula histórica pasa sin sugerencia ni aviso propio: cuenta aparte, como «ya no se ofrece», en el aviso
 *    del plan.
 * 7. Sin semestre (D18, R10; confirmado, 10 de noviembre de 2026): Nexus nunca decide un semestre; ningún lugar entra a
 *    la retícula con un semestre que no venga del SII. Un lugar con semestre 0 no entra a la retícula: queda en
 *    pending(). En una retícula viva lleva un semestre sugerido (`suggested`): el más frecuente, y en empate el menor,
 *    entre los lugares con semestre de la misma carrera con el mismo nombre normalizado (de cualquiera de sus claves
 *    del SII), nunca de otra carrera; sin ninguno, sin sugerencia. Avisa por lugar que va a «Por validar» con qué
 *    sugerencia y de dónde, y que lo aprobado cuenta (D19). En una retícula histórica (`$historical`: ningún alumno
 *    inscrito en el periodo actual y no es vigente) queda como en el SII, sin sugerencia, y avisa una sola vez por
 *    plan cuántas materias quedaron sin semestre.
 *
 * @phpstan-type Placement array{plan: string, group: string, career: string, modality: string, key: string, materia: string, tecnm: string|null, credits: string, theory: string, practice: string, semester: int, specialty: string, position: int, retired: bool}
 * @phpstan-type CodedPlacement array{plan: string, group: string, career: string, modality: string, key: string, materia: string, tecnm: string|null, code: string, origin: string, unified: bool, credits: string, theory: string, practice: string, semester: int, specialty: string, position: int, retired: bool}
 * @phpstan-type GroupSubject array{group: string, code: string, career: string, credits: string, theory: string, practice: string, name: string, materias: list<string>, sources: list<string>, listed: bool}
 * @phpstan-type GridPlacement array{plan: string, group: string, career: string, modality: string, key: string, materia: string, tecnm: string|null, code: string, origin: string, unified: bool, name: string, credits: string, theory: string, practice: string, semester: int, specialty: string, position: int, retired: bool, materias: list<string>, sources: list<string>}
 * @phpstan-type PendingPlacement array{plan: string, group: string, career: string, modality: string, key: string, materia: string, tecnm: string|null, code: string, origin: string, unified: bool, name: string, credits: string, theory: string, practice: string, semester: int, specialty: string, position: int, retired: bool, materias: list<string>, sources: list<string>, suggested: int|null}
 */
final class SubjectPlacements
{
    private const int SOURCE_CAP = 5;

    /** @var list<array{level: 'warning', ref: string, message: string}> */
    private array $incidents = [];

    /** @var array<string, PendingPlacement> */
    private array $pending = [];

    /** @var array<string, string> la versión de cada grupo: la mayor de sus planes */
    private array $groupVersions = [];

    /** @var array<string, list<array{career: string, materias: list<string>}>> */
    private array $subjects = [];

    /**
     * @param  array<string, array{name: string, type: string, area: string}>  $catalog  materias del SII por clave
     * @param  array<string, string>  $versions  versión de cada plan de Nexus por clave
     * @param  array<string, true>  $historical  las retículas históricas, por clave del plan de Nexus
     */
    public function __construct(private readonly array $catalog, private readonly array $versions, private readonly array $historical = []) {}

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
        $this->groupVersions = [];
        foreach ($placements as $placement) {
            $this->groupVersions[$placement['group']] = max($this->groupVersions[$placement['group']] ?? '', $this->versions[$placement['plan']] ?? '');
        }

        $coded = $this->fused($this->coded($placements));
        $subjects = $this->named($this->sharedHours($this->subjectsByGroup($coded)));
        $this->subjects = $this->sourcesByCode($subjects);

        return $this->withSemesters($this->placed($coded, $subjects));
    }

    /**
     * Las claves del SII de cada materia de Nexus, para decidir su departamento y su tipo (SubjectsStep): una entrada por
     * grupo donde está, con la carrera de su variante elegida y sus claves del SII, la de esa variante primero. Van
     * primero los grupos donde la materia entra a la retícula y luego los demás, cada parte en orden de grupo: el orden
     * que tenían los lugares cuando las retículas de un grupo eran un plan, así que el primer departamento y el primer
     * tipo no cambian por separarlas. Vacío hasta llamar a grid().
     *
     * @return array<string, list<array{career: string, materias: list<string>}>>
     */
    public function subjects(): array
    {
        return $this->subjects;
    }

    /**
     * Los lugares que no entran a la retícula (D18 y D19): los sin semestre y los de estatus `N`, por
     * `"{plan}/{clave}"`, con la forma de grid() y su semestre sugerido en `suggested` (null sin sugerencia). Pasan a
     * «Por validar». Vacío hasta llamar a grid().
     *
     * @return array<string, PendingPlacement>
     */
    public function pending(): array
    {
        return $this->pending;
    }

    /**
     * Los alias (R11): cada clave del SII de un lugar emitido, o pendiente (`$pending`), apunta a su materia de Nexus,
     * con el plan donde se vio: del primer grupo donde se vio, el plan que conserva la clave oficial si la trae, y si no,
     * el primero. Si una clave del SII quedó en varias materias de Nexus (en un grupo se unió por la clave TecNM y en
     * otro conservó la suya), el alias apunta a la que está en más grupos, en empate a la menor, y se avisa. El cuarto
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
                $targets[$materia][$placement['code']][$placement['group']][] = $placement['plan'];
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

            $group = (string) array_key_first($codes[$subject]);
            $plans = $codes[$subject][$group];
            $subjectFor[$materia] = $subject;
            $rows[] = ['code' => (string) $materia, 'subject' => $subject, 'plan' => in_array($group, $plans, true) ? $group : $plans[0]];
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
        $placements = $this->withoutSharedTecnm($placements);
        $votes = [];
        foreach ($placements as $placement) {
            if ($placement['tecnm'] !== null) {
                $votes[$placement['tecnm']][$this->combination($placement)][$placement['group']] = true;
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
     * Regla 1, antes del voto: si el SII da la misma clave TecNM a dos materias distintas de una retícula, la conserva
     * la única que se llama como esa clave en las retículas donde nadie se la disputa (hasta dos letras de diferencia);
     * las otras se quedan sin clave TecNM, así que no votan por sus horas.
     *
     * @param  list<Placement>  $placements
     * @return list<Placement>
     */
    private function withoutSharedTecnm(array $placements): array
    {
        $claims = [];
        foreach ($placements as $index => $placement) {
            if ($placement['tecnm'] !== null) {
                $claims[$placement['key']][$placement['tecnm']][$placement['materia']][] = $index;
            }
        }

        $disputed = [];
        foreach ($claims as $key => $byTecnm) {
            foreach ($byTecnm as $tecnm => $materias) {
                if (count($materias) > 1) {
                    $disputed["{$key}|{$tecnm}"] = true;
                }
            }
        }

        ksort($disputed, SORT_STRING);

        foreach (array_keys($disputed) as $dispute) {
            [$key, $tecnm] = explode('|', (string) $dispute, 2);
            $materias = $claims[$key][$tecnm];
            $names = array_map('strval', array_keys($materias));
            sort($names, SORT_STRING);
            $winner = $this->namedAfter($tecnm, $names, $key, $placements, $disputed);

            foreach ($materias as $materia => $indexes) {
                if ((string) $materia !== $winner) {
                    foreach ($indexes as $index) {
                        $placements[$index]['tecnm'] = null;
                    }
                }
            }

            $outcome = $winner === null
                ? 'ninguna la conserva y cada una se queda con su clave del SII'
                : "la conserva {$winner} y las demás se quedan con su clave del SII";
            $this->warn($key, "En la retícula {$key} del SII, {$this->withNames($names)} traen la misma clave TecNM {$tecnm} y son materias distintas: {$outcome} (supuesto por confirmar).");
        }

        return $placements;
    }

    /**
     * Regla 1, después del voto: dos materias distintas de una retícula no quedan con la misma clave. Pasa cuando la
     * clave TecNM de una es la clave del SII de otra: la conserva la que es esa clave del SII y las demás vuelven a la
     * suya. Se repite hasta que no cambia nada, porque la que vuelve a su clave puede chocar con otra.
     *
     * @param  list<CodedPlacement>  $placements
     * @return list<CodedPlacement>
     */
    private function distinctWithinGrid(array $placements): array
    {
        do {
            $changed = false;
            $claims = [];
            foreach ($placements as $index => $placement) {
                $claims[$placement['key']][$placement['code']][$placement['materia']][] = $index;
            }
            ksort($claims, SORT_STRING);

            foreach ($claims as $key => $codes) {
                ksort($codes, SORT_STRING);

                foreach ($codes as $code => $materias) {
                    if (count($materias) < 2) {
                        continue;
                    }

                    $code = (string) $code;
                    $names = array_map('strval', array_keys($materias));
                    sort($names, SORT_STRING);
                    $own = array_find($names, fn (string $materia): bool => mb_strtoupper($materia) === $code);

                    foreach ($materias as $materia => $indexes) {
                        foreach ((string) $materia === $own ? [] : $indexes as $index) {
                            $placements[$index]['code'] = $placements[$index]['origin'] = mb_strtoupper((string) $materia);
                            $placements[$index]['unified'] = false;
                            $changed = $changed || mb_strtoupper((string) $materia) !== $code;
                        }
                    }

                    $outcome = $own === null
                        ? 'ninguna la conserva y cada una se queda con su clave del SII'
                        : "la conserva {$own}, que es su clave del SII, y las demás se quedan con la suya";
                    $this->warn((string) $key, "En la retícula {$key} del SII, {$this->withNames($names)} quedaban con la misma clave {$code} y son materias distintas: {$outcome} (supuesto por confirmar).");
                }
            }
        } while ($changed);

        return $placements;
    }

    /**
     * De las materias de una retícula que se disputan una clave TecNM, la única cuyo nombre es el que esa clave tiene
     * en las retículas donde nadie se la disputa, con hasta dos letras de diferencia; null si no hay una sola.
     *
     * @param  list<string>  $materias
     * @param  list<Placement>  $placements
     * @param  array<string, true>  $disputed  `"{retícula}|{clave TecNM}"` de las disputas
     */
    private function namedAfter(string $tecnm, array $materias, string $key, array $placements, array $disputed): ?string
    {
        $elsewhere = [];
        foreach ($placements as $placement) {
            if ($placement['tecnm'] === $tecnm && $placement['key'] !== $key && ! isset($disputed["{$placement['key']}|{$tecnm}"])) {
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
     * @param  list<string>  $materias
     */
    private function withNames(array $materias): string
    {
        return implode(', ', array_map(fn (string $materia): string => "{$materia} ({$this->catalog[$materia]['name']})", $materias));
    }

    /**
     * La combinación de créditos y horas que se queda la clave TecNM.
     *
     * @param  array<string, array<string, true>>  $combinations  combinación → grupos que la traen
     */
    private function winner(string $tecnm, array $combinations): string
    {
        $votes = array_map('count', $combinations);
        $tied = array_map('strval', array_keys($votes, max($votes), true));

        if (count($tied) === 1) {
            return $tied[0];
        }

        usort($tied, fn (string $a, string $b): int => $this->newest($combinations[$b]) <=> $this->newest($combinations[$a]) ?: strcmp($a, $b));
        [$version, $group] = $this->newest($combinations[$tied[0]]);
        $this->warn($tecnm, "Clave TecNM {$tecnm}: empate de créditos/teoría/práctica entre ".implode(', ', $tied)."; gana {$tied[0]}, la del plan más reciente {$group} ({$version}) (supuesto por confirmar).");

        return $tied[0];
    }

    /**
     * La versión y la clave del grupo más reciente de una lista de grupos.
     *
     * @param  array<string, mixed>  $groups  por clave del grupo
     * @return array{string, string}
     */
    private function newest(array $groups): array
    {
        return max(array_map(fn (string|int $group): array => [$this->groupVersions[$group] ?? '', (string) $group], array_keys($groups)));
    }

    /**
     * R14: dentro de cada grupo, junta bajo una sola clave las materias de variantes distintas que son la misma.
     *
     * @param  list<CodedPlacement>  $placements
     * @return list<CodedPlacement>
     */
    private function fused(array $placements): array
    {
        $byGroup = [];
        foreach ($placements as $index => $placement) {
            $byGroup[$placement['group']][$placement['code']][] = $index;
        }

        foreach ($byGroup as $group => $codes) {
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
                    'official' => array_any($rows, fn (array $row): bool => $row['modality'] === 'presencial' && $row['plan'] === $row['group']),
                    'combinations' => array_values(array_unique(array_map($this->combination(...), $rows))),
                    'materias' => array_values(array_unique(array_column($rows, 'materia'))),
                ];
            }

            foreach ($alike as $units) {
                foreach (count($units) > 1 ? $this->clusters($units) : [] as $cluster) {
                    $kept = $this->keptCode((string) $group, $cluster);

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
     * Agrupa las materias iguales de un grupo sin juntar dos de la misma retícula ni dos claves TecNM distintas: las de
     * clave TecNM primero y luego por clave, cada una entra al primer grupo libre. Devuelve los grupos de más de una.
     *
     * @param  list<array{code: string, indexes: list<int>, keys: list<string>, tecnm: string|null, presencial: bool, official: bool, combinations: list<string>, materias: list<string>}>  $units
     * @return list<list<array{code: string, indexes: list<int>, keys: list<string>, tecnm: string|null, presencial: bool, official: bool, combinations: list<string>, materias: list<string>}>>
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
     * La clave que se queda un grupo de R14. Si sus horas difieren, la de la variante presencial (la de la retícula con
     * la clave oficial, si es una), con sus horas, y avisa (supuesto por confirmar); si no hay presencial o las horas
     * coinciden, la TecNM si alguna la tiene; si no, la de la variante presencial; si no hay, la menor.
     *
     * @param  list<array{code: string, tecnm: string|null, presencial: bool, official: bool, combinations: list<string>, materias: list<string>}>  $cluster
     */
    private function keptCode(string $group, array $cluster): string
    {
        $combinations = array_values(array_unique(array_merge(...array_column($cluster, 'combinations'))));
        $presencial = array_find($cluster, fn (array $unit): bool => $unit['official']) ?? array_find($cluster, fn (array $unit): bool => $unit['presencial']);

        if (count($combinations) > 1) {
            sort($combinations);
            $kept = $presencial['code'] ?? $this->tecnmOrSmallest($cluster);
            $codes = array_map(fn (array $unit): string => $unit['materias'] === [$unit['code']] ? $unit['code'] : $unit['code'].' (del SII '.implode(', ', $unit['materias']).')', $cluster);
            sort($codes, SORT_STRING);
            $from = $presencial === null ? 'no hay variante presencial: queda con sus horas' : 'con las horas de la variante presencial';
            $this->warn("{$group}/{$kept}", "En el grupo de planes {$group}, ".implode(', ', $codes).' son la misma materia con horas distintas (créditos/teoría/práctica '.implode(', ', $combinations)."): queda {$kept}, {$from} (supuesto por confirmar).");

            return $kept;
        }

        return $this->tecnmOrSmallest($cluster);
    }

    /**
     * La TecNM si alguna la tiene; si no, la de la variante presencial (las de la retícula con la clave oficial
     * primero); si no hay, la menor.
     *
     * @param  list<array{code: string, tecnm: string|null, presencial: bool, official: bool, combinations: list<string>, materias: list<string>}>  $cluster
     */
    private function tecnmOrSmallest(array $cluster): string
    {
        $tecnm = array_find($cluster, fn (array $unit): bool => $unit['tecnm'] !== null);

        if ($tecnm !== null) {
            return $tecnm['code'];
        }

        $official = array_values(array_filter($cluster, fn (array $unit): bool => $unit['official']));
        $presencial = $official !== [] ? $official : array_values(array_filter($cluster, fn (array $unit): bool => $unit['presencial']));
        $codes = array_column($presencial === [] ? $cluster : $presencial, 'code');
        sort($codes, SORT_STRING);

        return $codes[0];
    }

    /**
     * Las materias de Nexus de cada grupo, por `"{grupo}/{clave}"` en orden, como eran los lugares cuando las retículas
     * de un grupo eran un plan: las horas de la variante que se quedó con la clave (la elegida si lo es), `materias` con
     * todas las claves del SII que se juntaron, la de la elegida primero, y `listed` si ese lugar habría entrado a la
     * retícula (trae semestre y no todas sus variantes tienen estatus `N`).
     *
     * @param  list<CodedPlacement>  $placements
     * @return array<string, GroupSubject>
     */
    private function subjectsByGroup(array $placements): array
    {
        $groups = [];
        foreach ($placements as $placement) {
            $groups["{$placement['group']}/{$placement['code']}"][] = $placement;
        }
        ksort($groups, SORT_STRING);

        $subjects = [];
        foreach ($groups as $ref => $variants) {
            $chosen = $this->chosen($variants);
            $hours = $chosen['origin'] === $chosen['code'] ? $chosen : (array_find($variants, fn (array $variant): bool => $variant['origin'] === $variant['code']) ?? $chosen);

            $subjects[(string) $ref] = [
                'group' => $chosen['group'],
                'code' => $chosen['code'],
                'career' => $chosen['career'],
                'credits' => $hours['credits'],
                'theory' => $hours['theory'],
                'practice' => $hours['practice'],
                'name' => '',
                'materias' => array_values(array_unique([$chosen['materia'], ...array_column($variants, 'materia')])),
                'sources' => array_column($variants, 'materia'),
                'listed' => $chosen['semester'] > 0 && ! array_all($variants, fn (array $variant): bool => $variant['retired']),
            ];
        }

        return $subjects;
    }

    /**
     * Las claves del SII de cada materia de Nexus para subjects(): primero los grupos que entran a la retícula y luego
     * los demás, cada parte en orden de grupo.
     *
     * @param  array<string, GroupSubject>  $subjects
     * @return array<string, list<array{career: string, materias: list<string>}>>
     */
    private function sourcesByCode(array $subjects): array
    {
        $listed = array_filter($subjects, fn (array $subject): bool => $subject['listed']);
        $byCode = [];

        foreach ([...$listed, ...array_diff_key($subjects, $listed)] as $subject) {
            $byCode[$subject['code']][] = ['career' => $subject['career'], 'materias' => $subject['materias']];
        }

        return $byCode;
    }

    /**
     * Regla 3: un lugar por plan y clave de Nexus, por `"{plan}/{clave}"` en orden, con el semestre, el renglón, la
     * especialidad y el estatus de su propia retícula, y las horas y el nombre de su materia de Nexus.
     *
     * @param  list<CodedPlacement>  $placements
     * @param  array<string, GroupSubject>  $subjects
     * @return array<string, GridPlacement>
     */
    private function placed(array $placements, array $subjects): array
    {
        $places = [];
        foreach ($placements as $placement) {
            $places["{$placement['plan']}/{$placement['code']}"][] = $placement;
        }
        ksort($places, SORT_STRING);

        $grid = [];
        foreach ($places as $ref => $variants) {
            $chosen = $this->chosen($variants);
            $semesters = array_values(array_unique(array_column(array_filter($variants, fn (array $variant): bool => $variant['semester'] > 0), 'semester')));

            if (count($semesters) > 1) {
                sort($semesters);
                $from = $chosen['modality'] === 'presencial' ? 'la variante presencial' : "la retícula {$chosen['key']}";
                $this->warn((string) $ref, "Lugar {$ref}: sus renglones del SII traen semestres distintos (".implode(', ', $semesters)."); queda el {$chosen['semester']}, de {$from}.");
            }

            $subject = $subjects["{$chosen['group']}/{$chosen['code']}"];
            $specialty = $chosen['specialty'] !== '' ? $chosen['specialty'] : (array_find($variants, fn (array $variant): bool => $variant['specialty'] !== '')['specialty'] ?? '');

            $grid[(string) $ref] = [
                ...$chosen,
                'credits' => $subject['credits'],
                'theory' => $subject['theory'],
                'practice' => $subject['practice'],
                'name' => $subject['name'],
                'specialty' => $specialty,
                'retired' => array_all($variants, fn (array $variant): bool => $variant['retired']),
                'materias' => array_values(array_unique([$chosen['materia'], ...array_column($variants, 'materia')])),
                'sources' => array_column($variants, 'materia'),
            ];
        }

        return $grid;
    }

    /**
     * La variante que da el semestre, el renglón y la carrera: entre las que traen semestre (o todas, si ninguna), la
     * presencial de la retícula con la clave oficial del grupo; si no, la primera presencial; si no, la primera.
     *
     * @param  non-empty-list<CodedPlacement>  $variants
     * @return CodedPlacement
     */
    private function chosen(array $variants): array
    {
        $dated = array_values(array_filter($variants, fn (array $variant): bool => $variant['semester'] > 0));
        $pool = $dated === [] ? $variants : $dated;

        return array_find($pool, fn (array $variant): bool => $variant['modality'] === 'presencial' && $variant['plan'] === $variant['group'])
            ?? array_find($pool, fn (array $variant): bool => $variant['modality'] === 'presencial')
            ?? $pool[0];
    }

    /**
     * Regla 4: una materia de Nexus tiene unas solas horas y créditos. Si una clave quedó en grupos con combinaciones
     * distintas (una clave del SII que el SII repite en retículas con otras horas, o que es igual a una clave TecNM),
     * todas sus materias de grupo toman la de más grupos; en empate, la del grupo de versión más reciente, y luego la
     * menor.
     *
     * @param  array<string, GroupSubject>  $subjects
     * @return array<string, GroupSubject>
     */
    private function sharedHours(array $subjects): array
    {
        $byCode = [];
        foreach ($subjects as $ref => $subject) {
            $byCode[$subject['code']][$this->combination($subject)][$subject['group']] = (string) $ref;
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
                foreach ($combinations[$combination] as $group => $ref) {
                    $subjects[$ref] = [...$subjects[$ref], 'credits' => $credits, 'theory' => $theory, 'practice' => $practice];
                    $changed[] = "{$group} ({$combination})";
                }
            }

            sort($changed, SORT_STRING);
            $count = count($combinations[$winner]);
            $this->warn((string) $code, "La materia {$code} trae créditos/teoría/práctica distintos según el plan: {$winner} en {$count} ".($count === 1 ? 'plan' : 'planes').' y otros en '.implode(', ', $changed).". En Nexus una materia tiene unas solas horas: queda {$winner} en todos sus planes; revisar (supuesto por confirmar).");
        }

        return $subjects;
    }

    /**
     * Le pone a cada materia de grupo el nombre de su materia de Nexus: el más frecuente entre todos sus renglones del SII.
     *
     * @param  array<string, GroupSubject>  $subjects
     * @return array<string, GroupSubject>
     */
    private function named(array $subjects): array
    {
        $sources = [];
        foreach ($subjects as $subject) {
            foreach ($subject['sources'] as $materia) {
                $sources[$subject['code']][] = ['materia' => $materia, 'group' => $subject['group']];
            }
        }

        $names = [];
        foreach ($sources as $code => $rows) {
            $names[$code] = $this->representativeName((string) $code, $rows);
        }

        foreach ($subjects as $ref => $subject) {
            $subjects[$ref]['name'] = $names[$subject['code']];
        }

        return $subjects;
    }

    /**
     * @param  list<array{materia: string, group: string}>  $rows
     */
    private function representativeName(string $code, array $rows): string
    {
        $byKey = [];
        foreach ($rows as $row) {
            $name = $this->catalog[$row['materia']]['name'];
            $key = SubjectName::key($name);
            $byKey[$key]['count'] = ($byKey[$key]['count'] ?? 0) + 1;
            $byKey[$key]['version'] = max($byKey[$key]['version'] ?? '', $this->groupVersions[$row['group']] ?? '');
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
     * Reglas 6 y 7: saca de la retícula lo que no trae semestre o tiene estatus `N` y, en una retícula viva, le pone a
     * lo que no trae semestre el sugerido de su misma carrera.
     *
     * @param  array<string, GridPlacement>  $grid
     * @return array<string, GridPlacement>
     */
    private function withSemesters(array $grid): array
    {
        $this->pending = [];
        $byName = [];
        foreach ($grid as $ref => $placement) {
            if ($placement['semester'] > 0 && ! $placement['retired']) {
                foreach (array_unique(array_map($this->nameKey(...), $placement['materias'])) as $name) {
                    $byName[$placement['career']][$name][] = ['ref' => (string) $ref, 'semester' => $placement['semester']];
                }
            }
        }

        $shelved = [];

        foreach ($grid as $ref => $placement) {
            if ($placement['semester'] > 0 && ! $placement['retired']) {
                continue;
            }

            unset($grid[$ref]);
            $ref = (string) $ref;

            if (isset($this->historical[$placement['plan']])) {
                $this->pending[$ref] = [...$placement, 'suggested' => null];
                $kind = $placement['retired'] ? 'retired' : 'undated';
                $shelved[$placement['plan']][$kind] = ($shelved[$placement['plan']][$kind] ?? 0) + 1;

                continue;
            }

            if ($placement['retired'] && $placement['semester'] > 0) {
                $this->pending[$ref] = [...$placement, 'suggested' => $placement['semester']];
                $this->warn($ref, "Lugar {$ref}: el SII lo trae con estatus N (ya no se ofrece): no entra a la retícula; pasa a «Por validar» para que Servicios Escolares decida, con el {$placement['semester']} sugerido, su semestre en el SII. Lo aprobado en él cuenta (supuesto por confirmar).");

                continue;
            }

            $sources = $byName[$placement['career']][SubjectName::key($placement['name'])] ?? [];
            $counts = array_count_values(array_column($sources, 'semester'));
            $suggested = $sources === [] ? null : min(array_keys($counts, max($counts), true));
            $this->pending[$ref] = [...$placement, 'suggested' => $suggested];
            $suggestion = $suggested === null
                ? 'sin sugerencia: ninguna materia con el mismo nombre de la misma carrera tiene semestre'
                : "con el {$suggested} sugerido por la materia con el mismo nombre de la misma carrera en {$this->listed(array_column($sources, 'ref'))}";

            $this->warn($ref, $placement['retired']
                ? "Lugar {$ref}: el SII lo trae con estatus N (ya no se ofrece) y sin semestre: no entra a la retícula; pasa a «Por validar» para que Servicios Escolares decida, {$suggestion}. Lo aprobado en él cuenta (supuesto por confirmar)."
                : "Lugar {$ref} sin semestre en el SII: no entra a la retícula; pasa a «Por validar», donde Servicios Escolares le asigna semestre, {$suggestion}. Lo aprobado en ella cuenta (D18 y D19; confirmado, 10 de noviembre de 2026).");
        }

        ksort($shelved, SORT_STRING);

        foreach ($shelved as $plan => $counts) {
            $this->warn((string) $plan, "Retícula histórica {$plan} (ningún alumno inscrito en el periodo actual): {$this->shelvedSummary($counts['undated'] ?? 0, $counts['retired'] ?? 0)}; esperan en «Por validar» y Servicios Escolares solo les asigna semestre si vuelve un alumno (confirmado, 10 de noviembre de 2026).");
        }

        return $grid;
    }

    /**
     * Cuántas materias de una retícula histórica quedan sin semestre, como en el SII, y cuántas ya no se ofrecen
     * (estatus `N`, traigan o no semestre).
     */
    private function shelvedSummary(int $undated, int $retired): string
    {
        $parts = [];

        if ($undated > 0) {
            $parts[] = $undated.($undated === 1 ? ' materia queda' : ' materias quedan').' sin semestre, como en el SII';
        }

        if ($retired > 0) {
            $parts[] = ($undated > 0 ? (string) $retired : $retired.($retired === 1 ? ' materia' : ' materias')).($retired === 1 ? ' ya no se ofrece' : ' ya no se ofrecen').' (estatus N)';
        }

        return implode(', y ', $parts);
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
