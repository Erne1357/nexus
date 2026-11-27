<?php

namespace App\Modules\Core\Notifications;

/**
 * Un aviso que puede ir a un correo personal por una excepción declarada (CE-01): personalMailRule() da el id de la
 * regla que lo permite para esta instancia (SEL-13, SEL-15) o null si esta no va al personal. La guardia
 * (GuardMailRecipient) solo lo respeta si la clase está en PersonalMailExceptions con esa misma regla, y una prueba de
 * arquitectura exige que ninguna otra clase la implemente. Implementarla no basta para mandar al personal.
 */
interface AllowsPersonalMail
{
    public function personalMailRule(): ?string;
}
