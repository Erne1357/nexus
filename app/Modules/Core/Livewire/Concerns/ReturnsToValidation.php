<?php

namespace App\Modules\Core\Livewire\Concerns;

use App\Modules\Core\Support\ValidationReturn;
use Livewire\Attributes\Url;

/**
 * Las páginas a las que se entra desde la validación de catálogos recuerdan de dónde vinieron (`?desde=validacion`,
 * `depto`, `cat`) para mostrar «Regresar» (x-core::back-link). Son propiedades de URL para que sobrevivan a las
 * peticiones de Livewire; el destino se arma siempre con ValidationReturn::url() (lista blanca, sin URLs crudas).
 */
trait ReturnsToValidation
{
    #[Url(as: 'desde')]
    public string $from = '';

    #[Url(as: 'depto')]
    public string $fromDepartment = '';

    #[Url(as: 'cat')]
    public string $fromCatalog = '';

    /**
     * Los parámetros que las páginas hijas (p. ej. del ficha al editor de disponibilidad) llevan para conservar el
     * regreso; vacío si no se entró desde la validación.
     *
     * @return array<string, string>
     */
    public function validationReturnParams(): array
    {
        return $this->validationReturnUrl() === null ? [] : ['desde' => $this->from, 'depto' => $this->fromDepartment, 'cat' => $this->fromCatalog];
    }

    /** A dónde vuelve «Regresar» si se entró desde la validación; null si no. */
    public function validationReturnUrl(): ?string
    {
        return ValidationReturn::url($this->from, $this->fromDepartment, $this->fromCatalog);
    }
}
