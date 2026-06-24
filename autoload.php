<?php
require DOCUMENT_ROOT.'vendor/autoload.php';

spl_autoload_register(function($className) {
        $className = str_replace("\\", DIRECTORY_SEPARATOR, $className);
        include_once DOCUMENT_ROOT . $className . '.php';
});