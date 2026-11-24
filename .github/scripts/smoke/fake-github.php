<?php

/*
| API falsa de GitHub para el smoke (prod-smoke.sh, escenario 10): lo mínimo de Deployments y de codeload que usa el
| agente del deployer con DEPLOY_SOURCE=github. Corre con `php -S 0.0.0.0:8080 fake-github.php` en la imagen de la
| app, dentro de la red del stack. Nunca entra a la app.
|
|   GET  /repos/{dueño}/{repo}/deployments                 los Deployments, el más nuevo primero
|   GET  /repos/{dueño}/{repo}/deployments/{id}/statuses   sus estados, el más nuevo primero
|   POST /repos/{dueño}/{repo}/deployments/{id}/statuses   agrega un estado
|   GET  /{dueño}/{repo}/tar.gz/{sha}                      el tarball de /fixtures/<sha>.tar.gz (codeload)
|   POST /_deployments                                     crea un Deployment {payload} en `queued` (solo el smoke)
*/

$stateFile = '/tmp/fake-github.json';
$state = is_file($stateFile) ? json_decode((string) file_get_contents($stateFile), true) : ['next' => 1, 'deployments' => []];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];
$body = json_decode((string) file_get_contents('php://input'), true) ?? [];

function fakeGithubRespond(mixed $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
}

if ($method === 'POST' && $path === '/_deployments') {
    $id = $state['next']++;
    $state['deployments'][$id] = ['id' => $id, 'payload' => $body['payload'] ?? [], 'statuses' => [['state' => 'queued']]];
    file_put_contents($stateFile, json_encode($state));
    fakeGithubRespond(['id' => $id], 201);
} elseif ($method === 'GET' && preg_match('#^/repos/[^/]+/[^/]+/deployments$#', $path)) {
    $list = array_values(array_map(fn (array $d): array => ['id' => $d['id'], 'payload' => $d['payload']], $state['deployments']));
    fakeGithubRespond(array_reverse($list));
} elseif (preg_match('#^/repos/[^/]+/[^/]+/deployments/(\d+)/statuses$#', $path, $match)) {
    $id = (int) $match[1];
    if (! isset($state['deployments'][$id])) {
        fakeGithubRespond(['message' => 'Not Found'], 404);
    } elseif ($method === 'POST') {
        $state['deployments'][$id]['statuses'][] = ['state' => $body['state'] ?? '', 'description' => $body['description'] ?? ''];
        file_put_contents($stateFile, json_encode($state));
        fakeGithubRespond(['state' => $body['state'] ?? ''], 201);
    } else {
        fakeGithubRespond(array_reverse($state['deployments'][$id]['statuses']));
    }
} elseif ($method === 'GET' && preg_match('#^/[^/]+/[^/]+/tar\.gz/([A-Za-z0-9]+)$#', $path, $match) && is_file("/fixtures/{$match[1]}.tar.gz")) {
    header('Content-Type: application/gzip');
    readfile("/fixtures/{$match[1]}.tar.gz");
} else {
    fakeGithubRespond(['message' => 'Not Found'], 404);
}
