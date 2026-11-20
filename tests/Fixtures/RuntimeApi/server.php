<?php

// Router for `php -S`, standing in for the Runtime API in RuntimeApi tests

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($path === '/echo') {
    http_response_code(201);
    header('Content-Type: application/json');
    header('X-Multi: a');
    header('X-Multi: b', false);
    echo json_encode([
        'method' => $_SERVER['REQUEST_METHOD'],
        'body' => file_get_contents('php://input'),
        'header' => $_SERVER['HTTP_X_TEST'] ?? null,
    ]);

    return;
}

if ($path === '/2018-06-01/runtime/invocation/next') {
    // Like Lambda at shutdown: the next invocation never comes
    sleep(30);

    return;
}

http_response_code(404);
echo 'not found';
