<?php

namespace App\Modules\Core\Enums;

/**
 * Alcance de un parámetro de `settings`: `global` no lleva `scope_id`; `campus` y `department`
 * llevan el id del campus o del departamento.
 */
enum SettingScope: string
{
    case Global = 'global';
    case Campus = 'campus';
    case Department = 'department';
}
