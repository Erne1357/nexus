<?php

namespace App\Modules\AcademicLoad\Events;

use App\Modules\AcademicLoad\Models\AcademicLoad;
use LogicException;

/**
 * La publicación (PublishLoad) pide los grupos de la carga para su foto. AcademicLoad no ve grupos, sesiones,
 * docentes ni salones (están a su derecha), así que los da Scheduling al contestar este evento, como
 * `LoadStatusesRequested`. Se despacha dentro de la transacción de la publicación, después de la transición y con la
 * carga bloqueada; quien contesta solo lee. La forma de los grupos es la de `groups` en `Rules\SnapshotPayload`.
 */
final class LoadSnapshotRequested
{
    /** @var list<array<string, mixed>>|null */
    private ?array $groups = null;

    public function __construct(public readonly AcademicLoad $load) {}

    /**
     * @param  list<array<string, mixed>>  $groups
     *
     * @throws LogicException si otro ya los dio: una foto tiene una sola fuente de grupos
     */
    public function provideGroups(array $groups): void
    {
        if ($this->groups !== null) {
            throw new LogicException('Los grupos de la foto ya los dio otro módulo.');
        }

        $this->groups = $groups;
    }

    /**
     * @return list<array<string, mixed>>|null null si nadie contestó
     */
    public function groups(): ?array
    {
        return $this->groups;
    }
}
