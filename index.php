<?php
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', 0);
if(isset($_REQUEST["debug"]) && $_REQUEST["debug"] == 1):
    ini_set("display_errors", "on");
    ini_set("error_reporting", E_ALL);
endif;

header('Access-Control-Allow-Headers:Content-Type, Authorization,Cache-Control,Pragma,Expires');
header("Access-Control-Allow-Origin: *");

if($_SERVER['REQUEST_METHOD'] === "OPTIONS"):
    header("Access-Control-Allow-Methods: POST, GET, DELETE, PUT, PATCH, OPTIONS");
    die();
endif;

require('config.php');
require('bootstrap.php');
require('routes.php');