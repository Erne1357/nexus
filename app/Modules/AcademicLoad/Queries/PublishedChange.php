<?php

namespace App\Modules\AcademicLoad\Queries;

use App\Modules\AcademicLoad\Rules\SnapshotDiffText;
use App\Modules\Core\Enums\StatusTone;
use Illuminate\Support\Str;

/**
 * Lo que el público ve de lo que le cambió a un grupo en la última publicación de su carga (tarea 8.5; decisión del
 * usuario del 17 de noviembre de 2026): la marca «Cambió» con qué cambió (horario, salón o docente), o «Grupo nuevo» o
 * «Cancelado», y la línea de SnapshotDiffText::publicEntries() que lo dice en palabras (la de los avisos, RA-05, sin lo
 * interno). Sale del diff guardado de la versión vigente contra la anterior (SnapshotDiff): en la versión 1 no hay
 * marcas. Nunca dice cupos, lugares por carrera ni el número de versión; un grupo al que solo le cambió el cupo no lleva
 * marca. Es puro y viaja a la caché como arreglo (toArray(), fromArray()): la caché no deserializa clases.
 */
final readonly class PublishedChange
{
    public const string ADDED = 'added';

    public const string CANCELLED = 'cancelled';

    /** @var array<string, string> los cambios de un grupo que sigue que ve el público, en el orden en que se dicen */
    public const array KINDS = ['schedule' => 'horario', 'room' => 'salón', 'teacher' => 'docente'];

    /**
     * @param  list<string>  $kinds  `added`, `cancelled` o las llaves de KINDS que cambiaron
     * @param  string  $line  la línea de publicEntries(), con el grupo («ACF-0904 Cálculo Vectorial, grupo B (C2): …»)
     * @param  string|null  $detail  en un grupo que sigue, lo que cambió sin repetir el grupo («Horario de … a …»)
     */
    public function __construct(
        public array $kinds,
        public string $line,
        public ?string $detail = null,
    ) {}

    /**
     * Las marcas de los grupos de un diff (la forma de SnapshotDiff), por id de grupo: los nuevos, los cancelados y los
     * cambiados en horario, salón o docente. Cada línea se arma con SnapshotDiffText::publicEntries() sobre ese grupo.
     *
     * @param  array<string, mixed>  $diff
     * @return array<string, self> por id de grupo
     */
    public static function ofDiff(array $diff): array
    {
        $changes = [];

        foreach ($diff['groups']['added'] as $group) {
            $changes[$group['id']] = new self([self::ADDED], self::lineOf(['added' => [$group]]));
        }

        foreach ($diff['groups']['cancelled'] as $group) {
            $changes[$group['id']] = new self([self::CANCELLED], self::lineOf(['cancelled' => [$group]]));
        }

        foreach ($diff['groups']['changed'] as $entry) {
            $kinds = array_keys(array_intersect_key(self::KINDS, $entry['changes']));

            if ($kinds === []) {
                continue;
            }

            $line = self::lineOf(['changed' => [$entry]]);
            $prefix = SnapshotDiffText::group($entry['group']).': ';
            $changes[$entry['group']['id']] = new self($kinds, $line, Str::ucfirst(Str::startsWith($line, $prefix) ? Str::after($line, $prefix) : $line));
        }

        return $changes;
    }

    /**
     * @param  array<string, mixed>  $data  la forma de toArray()
     */
    public static function fromArray(array $data): self
    {
        return new self($data['kinds'], $data['line'], $data['detail']);
    }

    /**
     * @return array{kinds: list<string>, line: string, detail: string|null}
     */
    public function toArray(): array
    {
        return ['kinds' => $this->kinds, 'line' => $this->line, 'detail' => $this->detail];
    }

    public function isAdded(): bool
    {
        return $this->kinds === [self::ADDED];
    }

    public function isCancelled(): bool
    {
        return $this->kinds === [self::CANCELLED];
    }

    /**
     * El texto de la marca: «Grupo nuevo», «Cancelado» o «Cambió: horario, salón y docente».
     */
    public function label(): string
    {
        if ($this->isAdded()) {
            return 'Grupo nuevo';
        }

        if ($this->isCancelled()) {
            return 'Cancelado';
        }

        $nouns = array_map(fn (string $kind): string => self::KINDS[$kind], $this->kinds);
        $last = array_pop($nouns);

        return 'Cambió: '.($nouns === [] ? $last : implode(', ', $nouns).' y '.$last);
    }

    /**
     * El tono de la marca, como el tablero de versiones: nuevo en `success`, cancelado en `error` y cambiado en `info`.
     */
    public function tone(): StatusTone
    {
        return match (true) {
            $this->isAdded() => StatusTone::Success,
            $this->isCancelled() => StatusTone::Error,
            default => StatusTone::Info,
        };
    }

    public function icon(): string
    {
        return match (true) {
            $this->isAdded() => 'o-plus-circle',
            $this->isCancelled() => 'o-x-circle',
            default => 'o-arrow-path',
        };
    }

    /**
     * La línea de publicEntries() de un diff con solo ese grupo.
     *
     * @param  array<string, list<array<string, mixed>>>  $groups
     */
    private static function lineOf(array $groups): string
    {
        return SnapshotDiffText::publicEntries(['groups' => [...['added' => [], 'cancelled' => [], 'changed' => []], ...$groups]])[0];
    }
}
