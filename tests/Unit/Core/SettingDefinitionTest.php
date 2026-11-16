<?php

use App\Modules\AcademicLoad\Rules\ProposalAnomalies;
use App\Modules\AcademicLoad\Rules\SuggestedGroups;
use App\Modules\Core\Settings\SettingDefinition;
use App\Modules\Core\Settings\SettingRegistry;
use App\Modules\Core\Settings\SettingType;
use App\Modules\Staffing\Rules\RankingWeights;
use App\Modules\Staffing\Rules\RankingWindowSizes;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\Authorization\FakePermission;
use Tests\TestCase;

/*
| Las definiciones de parámetros (CF-S7) y el catálogo que arman los módulos. Usan el validador de Laravel, así que la
| aplicación arranca, pero no la base de datos.
*/

uses(TestCase::class);

function demoIntSetting(string $key = 'demo.size', int $default = 5, array $rules = ['min:1'], bool $nullable = false): SettingDefinition
{
    return new SettingDefinition($key, 'demo', 'Demo', 'Tamaño', 'Ayuda', SettingType::Integer, $nullable ? null : $default, $rules, nullable: $nullable);
}

function demoWeightsSetting(): SettingDefinition
{
    return new SettingDefinition('demo.weights', 'demo', 'Demo', 'Pesos', 'Ayuda', SettingType::DecimalTable, ['a' => 1.0, 'b' => 2.0], ['min:0'], ['a' => 'Uno', 'b' => 'Dos']);
}

function settingErrorsOf(Closure $call): array
{
    try {
        $call();
    } catch (ValidationException $e) {
        return array_keys($e->errors());
    }

    return [];
}

it('CF-S7: valida un entero y lo devuelve con su tipo', function () {
    expect(demoIntSetting()->validated('7'))->toBe(7)
        ->and(demoIntSetting()->validated(3))->toBe(3);
});

it('CF-S7: rechaza lo que no es entero, lo que baja del mínimo y lo vacío', function (mixed $value) {
    expect(settingErrorsOf(fn () => demoIntSetting()->validated($value)))->toBe(['value']);
})->with(['decimal' => [2.5], 'texto' => ['abc'], 'cero' => [0], 'negativo' => [-3], 'vacío' => [''], 'nulo' => [null]]);

it('CF-S7: un parámetro nullable acepta vacío como «sin valor»', function () {
    $definition = demoIntSetting('demo.cap', 0, ['min:1'], nullable: true);

    expect($definition->validated(''))->toBeNull()
        ->and($definition->validated(null))->toBeNull()
        ->and($definition->validated('40'))->toBe(40)
        ->and(settingErrorsOf(fn () => $definition->validated(0)))->toBe(['value']);
});

it('CF-S7: valida un decimal y un sí o no', function () {
    $decimal = new SettingDefinition('demo.rate', 'demo', 'Demo', 'Tasa', 'Ayuda', SettingType::Decimal, 1.5, ['min:0']);
    $flag = new SettingDefinition('demo.flag', 'demo', 'Demo', 'Activo', 'Ayuda', SettingType::Boolean, false);

    expect($decimal->validated('2.25'))->toBe(2.25)
        ->and(settingErrorsOf(fn () => $decimal->validated('-1')))->toBe(['value'])
        ->and($flag->validated('1'))->toBeTrue()
        ->and($flag->validated(false))->toBeFalse()
        ->and(settingErrorsOf(fn () => $flag->validated('quizá')))->toBe(['value']);
});

it('CF-S7: valida una tabla campo por campo, sin campos de más ni de menos', function () {
    $definition = demoWeightsSetting();

    expect($definition->validated(['a' => '3', 'b' => '0.5']))->toBe(['a' => 3.0, 'b' => 0.5])
        ->and(settingErrorsOf(fn () => $definition->validated(['a' => -1, 'b' => 2])))->toBe(['value.a'])
        ->and(settingErrorsOf(fn () => $definition->validated(['a' => 1])))->toContain('value.b')
        ->and(settingErrorsOf(fn () => $definition->validated(['a' => 1, 'b' => 2, 'c' => 3])))->toBe(['value'])
        ->and(settingErrorsOf(fn () => $definition->validated(['a' => 'x', 'b' => 2])))->toBe(['value.a']);
});

it('una definición con forma inválida falla al crearla', function () {
    expect(fn () => new SettingDefinition('sin_punto', 'demo', 'Demo', 'X', 'Y', SettingType::Integer, 1))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new SettingDefinition('demo.t', 'demo', 'Demo', 'X', 'Y', SettingType::IntegerTable, [], fields: []))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new SettingDefinition('demo.n', 'demo', 'Demo', 'X', 'Y', SettingType::Integer, 1, fields: ['a' => 'A']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new SettingDefinition('demo.d', 'demo', 'Demo', 'X', 'Y', SettingType::Integer, null))->toThrow(InvalidArgumentException::class);
});

it('muestra el valor para la pantalla', function () {
    expect(demoIntSetting()->display(5))->toBe('5')
        ->and(demoWeightsSetting()->display(['a' => 1.0, 'b' => 2.5]))->toBe('Uno: 1, Dos: 2.5')
        ->and(demoIntSetting('demo.cap', 0, ['min:1'], true)->display(null))->toBe('Sin valor');
});

it('CF-S7: el catálogo agrupa por módulo, busca por llave y falla con una llave repetida', function () {
    $registry = SettingRegistry::fromDefinitions([demoIntSetting('demo.a'), demoIntSetting('demo.b'), demoWeightsSetting()]);

    expect(array_keys($registry->byModule()))->toBe(['demo'])
        ->and($registry->byModule()['demo'])->toHaveCount(3)
        ->and($registry->find('demo.b')?->key)->toBe('demo.b')
        ->and($registry->find('demo.nada'))->toBeNull()
        ->and(fn () => $registry->get('demo.nada'))->toThrow(LogicException::class)
        ->and(fn () => SettingRegistry::fromDefinitions([demoIntSetting('demo.a'), demoIntSetting('demo.a')]))->toThrow(LogicException::class, 'repetida');
});

it('CF-S7: el catálogo falla si el valor por omisión no pasa su propia validación', function () {
    expect(fn () => SettingRegistry::fromDefinitions([demoIntSetting('demo.a', 0)]))->toThrow(LogicException::class, 'valor por omisión');
});

it('CF-S7: el catálogo real de los módulos se arma y cada valor por omisión es el que usa la clase que lo lee', function () {
    $registry = app(SettingRegistry::class);

    expect(RankingWeights::fromValue($registry->get(RankingWeights::SETTING)->default))->toEqual(new RankingWeights)
        ->and(RankingWindowSizes::fromValues($registry->get(RankingWindowSizes::HISTORY_SETTING)->default, $registry->get(RankingWindowSizes::EVALUATION_SETTING)->default))->toEqual(new RankingWindowSizes)
        ->and(SuggestedGroups::fromValue($registry->get(SuggestedGroups::SETTING)->default))->toEqual(new SuggestedGroups)
        ->and(ProposalAnomalies::fromValue($registry->get(ProposalAnomalies::SETTING)->default))->toEqual(new ProposalAnomalies);
});

it('CF-S7: Asignación docente, Horarios y Carga académica declaran sus parámetros', function () {
    expect(array_map(fn (SettingDefinition $d): string => $d->key, app(SettingRegistry::class)->all()))->toEqualCanonicalizing([
        'staffing.weights', 'staffing.history_terms', 'staffing.evaluation_terms', 'staffing.extra_hours_tolerance',
        'staffing.honorarios_max_hours', 'staffing.min_campus_travel_minutes', 'scheduling.weekly_hours_tolerance',
        'demand.target_group_size', 'demand.min_group_size',
    ]);
});

it('CF-S7: un parámetro puede declarar el permiso de su módulo y un aviso al guardarlo, y por omisión no trae ninguno', function () {
    $plain = demoIntSetting();
    $owned = new SettingDefinition('demo.owned', 'demo', 'Demo', 'Tamaño', 'Ayuda', SettingType::Integer, 5, ['min:1'], permission: FakePermission::ViewAny, savedNotice: 'Se recalcula.');

    expect($plain->permission)->toBeNull()->and($plain->savedNotice)->toBeNull()
        ->and($owned->permission)->toBe(FakePermission::ViewAny)->and($owned->savedNotice)->toBe('Se recalcula.');
});
