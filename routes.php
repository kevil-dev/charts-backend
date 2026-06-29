<?php

use App\Enums\ChartsEnum;

$router = new \Library\Router();

$router->setBasePath(ROUTER_BASE_PATH);
$router->setNamespace("\App\Controllers");

$router->mount('/auth', function() use ($router) {
    $router->post('/register', 'AuthController@register');
    $router->post('/login',    'AuthController@login');
    $router->post('/google',   'AuthController@google');
    $router->get('/me',        'AuthController@me');
    $router->post('/logout', 'AuthController@logout');
});

$router->mount('/charts', function() use ($router) {
    $router->get('/meta', 'ChartsController@meta');
    $router->get('/filters', 'ChartsController@getFilters');
    $router->get('/', 'ChartsController@getCharts');
    
    

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