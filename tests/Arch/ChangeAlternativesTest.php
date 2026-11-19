<?php

/*
| CR-03: aprobar con una alternativa del motor se salta el bloqueo de choques de lo que se pidió, así que
| ApproveChangeRequest::approveWithAlternative() solo la llama ChooseChangeAlternative, que revisa que la alternativa
| siga vigente.
*/

test('only ChooseChangeAlternative approves a change request with an alternative', function () {
    $root = dirname(__DIR__, 2);
    $callers = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$root}/app", FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() === 'php' && str_contains((string) file_get_contents($file->getPathname()), 'approveWithAlternative(')) {
            $callers[] = str_replace("{$root}/", '', $file->getPathname());
        }
    }

    sort($callers);

    expect($callers)->toBe([
        'app/Modules/Staffing/Actions/ChangeRequests/ApproveChangeRequest.php',
        'app/Modules/Staffing/Actions/ChangeRequests/ChooseChangeAlternative.php',
    ]);
});
