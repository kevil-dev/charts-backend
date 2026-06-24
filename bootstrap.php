<?php
ini_set('date.timezone', DEFAULT_TIME_ZONE);

require('autoload.php');
include("Inc/utilities.php");

$db_config = [
    'driver'    => "mysql",
    'host'      => DB_HOST,
    'database'  => DB_NAME,
    'port'      => DB_PORT,
    'username'  => DB_USER,
    'password'  => DB_PASS,
    'charset'   => "utf8mb4",
    'collation' => "utf8mb4_unicode_ci"
];
new \Pixie\Connection('mysql', $db_config, 'QB');