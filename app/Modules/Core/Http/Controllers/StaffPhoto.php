<?php

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Models\User;
use App\Modules\Core\Queries\KeyLookup;
use App\Modules\Core\Support\StaffPhotos;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sirve la foto vigente de una persona (CO-04): solo con la URL firmada y vigente que arma StaffPhotoUrl, y con sesión.
 * Una versión que ya no es la vigente, o una persona sin foto, es 404. Responde `private, max-age=7200` y `nosniff`.
 */
final class StaffPhoto
{
    public function __invoke(string $user, string $version, StaffPhotos $photos): StreamedResponse
    {
        $person = KeyLookup::find(User::query(), $user);
        abort_if($person === null || $photos->version($person) !== $version, 404);

        $response = $photos->stream($person);
        abort_if($response === null, 404);

        return $response;
    }
}
