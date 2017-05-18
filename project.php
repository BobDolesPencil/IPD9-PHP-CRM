<?php

session_cache_limiter(false);
session_start();

require_once '/vendor/autoload.php';

DB::$host = '127.0.0.1';
DB::$user = 'crm'; //change
DB::$password = '2u1VGtbINtmgiE9M'; //change
DB::$dbName = 'crm'; //change
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
    $app->render('addemployee.html.twig');
});

/*$app->post('/', function() use ($app) {
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
});*/

$app->post('/', function() use($app){
    $fname = $app->request()->post('firstname');
    $lname = $app->request()->post('lastname');
    $birthdate = $app->request()->post('birthdate');
    $hiredate = $app->request()->post('hiredate');
    $address = $app->request()->post('address');
    $appNo = $app->request()->post('appNo');
    $postalcode = $app->request()->post('postalcode');
    $country = $app->request()->post('country');
    $email = $app->request()->post('email');
    $phone = $app->request()->post('phone');
    $title = $app->request()->post('title');
    $username = $app->request()->post('username');
    $pass = $app->request()->post('password');
    $image = $_FILES['image'];
    $valuelist = array(
        'firstname'=>$fname,
        'lastname'=>$lname,
        'birthdate'=> $birthdate,
        'hiredate'=>$hiredate,
        'address'=>$address,
        'appNo'=>$appNo,
        'postalcode'=>$postalcode,
        'country'=>$country,
        'email'=>$email,
        'phone'=>$phone,
        'title'=>$title,
        'username'=>$username,
        'password'=>$pass);
    
    $errorList = array();
     if ($image['error'] == 0) {
        $imageInfo = getimagesize($image["tmp_name"]);
        if (!$imageInfo) {
            array_push($errorList, "File does not look like an valid image");
        } /*else {
            $width = $imageInfo[0];
            $height = $imageInfo[1];
            if ($width > 300 || $height > 300) {
                array_push($errorList, "Image must at most 300 by 300 pixels");
            }
        }*/
    }    
    // receive data and insert
    if (!$errorList) {
        $imageBinaryData = file_get_contents($image['tmp_name']);
        $mimeType = mime_content_type($image['tmp_name']);
        DB::insert('employee',array(
            'firstname'=>$fname,
            'lastname'=>$lname,
            'hireDate'=>$hiredate,
            'birthDate'=>$birthdate,
            'address'=>$address,
            'appNo'=>$appNo,
            'postalcode'=>$postalcode,
            'country'=>$country,
            'email'=>$email,
            'phone'=>$phone,
            'title'=>$title,
            'username'=>$username,
            'password'=>$pass,
            'image'=>$imageBinaryData,
            'mimetype'=>$mimeType
        ));   
        $app->render('addemployee_success.html.twig');
    } else {
        print_r($errorList);
        //keep values entered on failed submission
        $app->render('addemployee.html.twig', array(
            'v' => $valueList
        ));
    }

});

$app->run();


