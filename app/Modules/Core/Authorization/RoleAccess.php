<?php

namespace App\Modules\Core\Authorization;

use App\Modules\Core\Enums\CorePermission;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Enums\SettingScope;
use App\Modules\Core\Models\Setting;
use App\Modules\Core\Settings\SettingDefinition;
use App\Modules\Core\Settings\SettingType;

/**
 * Qué perfiles pueden iniciar sesión (AC-21, tarea 1.29): el admin apaga en «Parámetros» (`core.role_access`) un puesto o
 * al personal sin puesto, y entonces nadie entra ni actúa con él. No borra ni termina nombramientos: el puesto apagado
 * deja de contar para Actor (no está en heldRoles(), así que el cambio de perfil no lo ofrece, activeRole() nunca lo
 * resuelve y no concede nada), y quien se queda sin ninguno que valga no entra (login) y, con la sesión abierta, sale
 * en su siguiente petición (EnsureAccessIsEnabled). El admin nunca se apaga: no es un campo del parámetro.
 *
 * `scoped` (Octane): lee el parámetro una vez por petición. ForgetRoleAccess lo olvida si cambia en la misma petición
 * (SettingsUpdated) y al terminar cada una (RequestHandled), también sin Octane.
 */
final class RoleAccess
{
    public const string SETTING = 'core.role_access';

    /**
     * El campo del personal sin ningún puesto que valga (sin nombramiento vigente, como el administrativo sin
     * nombramiento).
     */
    public const string WITHOUT_ROLE = 'sin_puesto';

    /**
     * Lo que ve quien no tiene ningún perfil habilitado, al entrar o con la sesión abierta.
     */
    public const string DENIED_MESSAGE = 'Tu acceso a Nexus todavía no está habilitado.';

    /** @var array<string, bool>|null por campo */
    private ?array $table = null;

    /**
     * Si el puesto inicia sesión y actúa: el admin siempre; los demás, según su interruptor (encendido por omisión).
     */
    public function allows(Role $role): bool
    {
        return $role === Role::Admin || $this->table()[$role->value];
    }

    /**
     * Si entra quien no tiene ningún puesto que valga.
     */
    public function allowsWithoutRole(): bool
    {
        return $this->table()[self::WITHOUT_ROLE];
    }

    /**
     * Olvida el valor leído, para que la siguiente pregunta lo vuelva a leer.
     */
    public function forget(): void
    {
        $this->table = null;
    }

    /**
     * Los campos del parámetro: cada puesto menos el admin, en el orden del enum, y el personal sin puesto.
     *
     * @return array<string, string>
     */
    public static function fields(): array
    {
        $fields = [];

        foreach (Role::cases() as $role) {
            if ($role !== Role::Admin) {
                $fields[$role->value] = $role->listLabel();
            }
        }

        return $fields + [self::WITHOUT_ROLE => 'Personal sin puesto'];
    }

    /**
     * El parámetro para el catálogo de Core (CF-S7): solo lo cambia quien tiene `core.access.manage` (el admin, candado
     * CF-03); `core.setting.update` no basta.
     */
    public static function definition(): SettingDefinition
    {
        return new SettingDefinition(
            self::SETTING,
            'core',
            'Módulos',
            label: 'Quién inicia sesión',
            help: 'Apagado, nadie entra ni actúa con ese perfil. Quien tiene varios solo puede usar los encendidos; quien se queda sin ninguno no entra y, si tenía la sesión abierta, sale en su siguiente clic con el aviso «'.self::DENIED_MESSAGE.'». El admin siempre entra. No se borran ni terminan nombramientos.',
            type: SettingType::BooleanTable,
            default: array_fill_keys(array_keys(self::fields()), true),
            fields: self::fields(),
            permission: CorePermission::AccessManage,
            permissionOnly: true,
        );
    }

    /**
     * El interruptor de cada campo: el guardado si es sí o no; si falta o no lo es, encendido (el valor por omisión).
     *
     * @return array<string, bool>
     */
    private function table(): array
    {
        if ($this->table === null) {
            $stored = Setting::query()
                ->where('scope', SettingScope::Global)->whereNull('scope_id')->where('key', self::SETTING)
                ->first()?->value;

            $this->table = [];

            foreach (array_keys(self::fields()) as $field) {
                $value = is_array($stored) ? ($stored[$field] ?? null) : null;
                $this->table[$field] = is_bool($value) ? $value : true;
            }
        }

        return $this->table;
    }
}
