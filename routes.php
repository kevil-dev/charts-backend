<?php

use App\Enums\ChartsEnum;

$router = new \Library\Router();

$router->setBasePath(ROUTER_BASE_PATH);
$router->setNamespace("\App\Controllers");

$router->mount('/auth', function() use ($router) {
    $router->post('/register', 'AuthController@register');
    $router->post('/login',    'AuthController@login');
    $router->get('/me',        'AuthController@me');
});

$router->mount('/charts', function() use ($router) {
    $router->get('/', 'ChartsController@getCharts');
    $router->get('/filters', 'ChartsController@getFilters');

});

$router->get('/public/status', function() {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'ok']);
    exit;
});
$router->set404(function() {
    header('HTTP/1.1 404 Not Found');
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Route not found']);
    exit;
});

$router->run();