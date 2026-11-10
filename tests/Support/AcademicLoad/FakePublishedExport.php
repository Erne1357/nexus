<?php

namespace Tests\Support\AcademicLoad;

use App\Modules\AcademicLoad\Enums\AcademicLoadPermission;
use App\Modules\AcademicLoad\Exports\PublishedExport;
use App\Modules\Core\Authorization\Target;
use RuntimeException;

/**
 * Un exporte de prueba para PublishedExports: cuenta cuántas veces se arma (en un archivo, porque la cola serializa el
 * objeto) y puede fallar a propósito.
 */
final class FakePublishedExport implements PublishedExport
{
    public function __construct(
        public string $key = 'fake-20271-C2-abc',
        public ?AcademicLoadPermission $permission = null,
        public ?string $campusId = null,
        public ?string $unitId = null,
        public bool $fails = false,
        public string $counter = '',
    ) {}

    public function key(): string
    {
        return $this->key;
    }

    public function filename(): string
    {
        return 'Horario de prueba.pdf';
    }

    public function mimeType(): string
    {
        return 'application/pdf';
    }

    public function permission(): ?AcademicLoadPermission
    {
        return $this->permission;
    }

    public function scope(): Target
    {
        return Target::of(campus: $this->campusId, unit: $this->unitId);
    }

    public function build(): string
    {
        if ($this->counter !== '') {
            file_put_contents($this->counter, '.', FILE_APPEND);
        }

        if ($this->fails) {
            throw new RuntimeException('falla interna de prueba');
        }

        return '%PDF-1.7 prueba '.$this->key;
    }
}
