<?php

session_cache_limiter(false);
session_start();

require_once '/vendor/autoload.php';

DB::$host = '127.0.0.1';
DB::$user = 'test'; //change
DB::$password = '5Cq5KgBkz9713wz0'; //change
DB::$dbName = 'test'; //change
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

//State 1;First show
$app->get('/', function() use($app) {
    $app->render('login.html.twig');
});

$app->post('/', function() use ($app) {
    $username = $app->request()->post('username');
    $pass = $app->request()->post('pass');
    // verification    
    $error = false;
    $user = DB::queryFirstRow("SELECT * FROM users WHERE username=%s", $username);
    if (!$user) {
        $error = true;
    } else {
        if ($user['password'] != $pass) {
            $error = true;
        }
    }
    // decide what to render
    if ($error) {
        $app->render('login.html.twig', array("error" => true));
    } else {
        unset($user['password']);
        $_SESSION['user'] = $user;
        $app->render('login_success.html.twig');
    }
});


$app->run();


