<?php

use App\Enums\ChartsEnum;

$router = new \Library\Router();

$router->setBasePath(ROUTER_BASE_PATH);
$router->setNamespace("\App\Controllers");

// --- Charts API ---
$router->mount('/charts', function() use ($router) {

    // GET /charts
    // returns chart rows for the given platform/country/chart
    // query params: ?platform=apple&country=US&chart=top
    $router->get('/', 'ChartsController@getCharts');

    // GET /charts/filters
    // returns available countries + genres for the dropdowns
    // (built from the data, not hardcoded)
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