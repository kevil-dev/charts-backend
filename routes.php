<?php
// $authorize = new \App\Middleware\Authorize();
$router = new \Library\Router();

$router->setBasePath(ROUTER_BASE_PATH);
$router->setNamespace("\App\Controllers");

// $router->before("GET|POST|PUT|DELETE|PATCH|OPTIONS", "/([a-z0-9-]+).*", function($url) use ($authorize) {
//     !in_array($url, ["public", "webhook", "cron"]) ? $authorize->verifySignature() : "";
// });

$router->get("/public/status", function() {
    header('Content-Type: application/json');
    echo json_encode(["status" => 200]);
    exit;
});

// ─── Charts ──────────────────────────────────────────────────────────────────


$router->run();