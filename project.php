<?php

session_cache_limiter(false);
session_start();

require_once '/vendor/autoload.php';

DB::$host = '127.0.0.1';
DB::$user = 'slimtodo';//change
DB::$password = '0BSHlBr1EzbrXU50';//change
DB::$dbName = 'slimtodo';//change
DB::$port = 3333;
DB::$encoding = 'utf8';

// Slim creation and setup
$app = new \Slim\Slim(array(
    'view' => new \Slim\Views\Twig()
        ));

$view = $app->view();
$view->parserOptions = array(
    'debug' => true,
    'cache' => dirname(__FILE__) . '/cache'
);
$view->setTemplatesDirectory(dirname(__FILE__) . '/templates');

if (!isset($_SESSION['user'])) {
    $_SESSION['user'] = array();
}

$twig = $app->view()->getEnvironment();
$twig->addGlobal('user', $_SESSION['user']);

$app->run();


