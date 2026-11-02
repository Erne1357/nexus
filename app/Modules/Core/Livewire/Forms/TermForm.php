<?php

namespace App\Modules\Core\Livewire\Forms;

use App\Modules\Core\Models\Term;
use App\Modules\Core\Rules\TermRules;
use Closure;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Form;

/**
 * Los campos del formulario de un periodo (código, nombre y fechas), los mismos en el modal de core::pages.terms
 * (alta y edición) y en el de core::pages.term (edición desde la ficha); sus campos están en
 * `core::terms.form-fields`. Solo valida la forma: el formato del código, su unicidad y el orden de las fechas los
 * decide TermRules en la Action. Cada página autoriza antes de usarlo.
 */
class TermForm extends Form
{
    public string $code = '';

    public string $name = '';

    public string $starts_on = '';

    public string $ends_on = '';

    /** El último nombre que sugirió el código: mientras el nombre sea ese (o esté vacío) se vuelve a sugerir. */
    #[Locked]
    public string $suggestedName = '';

    /**
     * @return array<string, list<string>>
     */
    protected function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'code' => 'código',
            'name' => 'nombre',
            'starts_on' => 'inicio',
            'ends_on' => 'fin',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'starts_on.date_format' => 'El campo :attribute debe ser una fecha válida.',
            'ends_on.date_format' => 'El campo :attribute debe ser una fecha válida.',
        ];
    }

    /**
     * Sugiere el nombre del periodo con el código, si el usuario no escribió uno propio.
     */
    public function suggestName(string $code, TermRules $rules): void
    {
        $suggestion = $rules->suggestedName($code);

        if ($suggestion !== null && ($this->name === '' || $this->name === $this->suggestedName)) {
            $this->name = $suggestion;
        }

        $this->suggestedName = $suggestion ?? $this->suggestedName;
    }

    /**
     * Carga el formulario con un periodo, para editarlo.
     */
    public function fillFrom(Term $term, TermRules $rules): void
    {
        $this->code = $term->code;
        $this->name = $term->name;
        $this->starts_on = $term->starts_on->toDateString();
        $this->ends_on = $term->ends_on->toDateString();
        $this->suggestedName = $rules->suggestedName($term->code) ?? '';
    }

    /**
     * Valida la forma y llama a la Action con los datos; los errores que ella lance (código con mal formato o repetido,
     * fechas en desorden) se pasan al campo del formulario, que se llama `form.<campo>`.
     *
     * @template T
     *
     * @param  Closure(array{code: string, name: string, starts_on: string, ends_on: string}): T  $action
     * @return T
     *
     * @throws ValidationException
     */
    public function submit(Closure $action): mixed
    {
        /** @var array{code: string, name: string, starts_on: string, ends_on: string} $data */
        $data = $this->validate();

        try {
            return $action($data);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(
                collect($e->errors())->mapWithKeys(fn (array $messages, string $field): array => ["form.{$field}" => $messages])->all(),
            );
        }
    }
}
