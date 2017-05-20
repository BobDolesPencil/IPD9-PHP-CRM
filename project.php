<?php

session_cache_limiter(false);
session_start();

require_once '/vendor/autoload.php';

use Monolog\Logger;
use Monolog\Handler\StreamHandler;

// create a log channel
$log = new Logger('main');
$log->pushHandler(new StreamHandler('logs/everything.log', Logger::DEBUG));
$log->pushHandler(new StreamHandler('logs/errors.log', Logger::ERROR));

//connect to Database
DB::$host = '127.0.0.1';
DB::$user = 'crm'; //change
DB::$password = '2u1VGtbINtmgiE9M'; //change
DB::$dbName = 'crm'; //change
DB::$port = 3333;
DB::$encoding = 'utf8';

//Database Error Handler
DB::$error_handler = 'sql_error_handler';
DB::$nonsql_error_handler = 'nonsql_error_handler';

function nonsql_error_handler($params) {
    global $app, $log;
    $log->error("Database error: " . $params['error']);
    http_response_code(500);
    $app->render('error_internal.html.twig');
    die;
}

function sql_error_handler($params) {
    global $app, $log;
    $log->error("SQL error: " . $params['error']);
    $log->error(" in query: " . $params['query']);
    http_response_code(500);
    $app->render('error_internal.html.twig');
    die; // don't want to keep going if a query broke
}

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


//State 1;First show - Login
$app->get('/', function() use($app) {
    $app->flash('logedin', 'Loged in successfully.');
    $app->render('login.html.twig');
});
$app->post('/', function() use ($app) {
    $username = $app->request()->post('username');
    $pass = $app->request()->post('pass');
    // verification    
    $error = false;
    $user = DB::queryFirstRow("SELECT * FROM employee WHERE username=%s", $username);
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
        $listallemployee = DB::query("SELECT * FROM employee");
        $app->render('listemployee.html.twig', array(
            'listemployees' => $listallemployee));
    }
});
//LogOut
$app->get('/logout', function() use ($app) {
    unset($_SESSION['user']);
    $app->render('logout.html.twig');
});

//List All Employee
$app->get('/listemployee', function() use($app) {
    //var_dump($_SESSION['slim.flash']);
    $listallemployee = DB::query("SELECT * FROM employee");
    $app->render('listemployee.html.twig', array(
        'listemployees' => $listallemployee));
})->name('logedin');
$app->get('/viewphotousers/:userId', function($userId) use ($app) {
    $emp = DB::queryFirstRow("SELECT image, mimetype FROM employee WHERE id=%i", $userId);
    $app->response->headers->set('Content-Type', $emp['mimetype']);
    echo $emp['image'];
});
//Add Employee
$app->get('/addemployee', function() use($app) {
    $app->flash('addemployee', 'Employee Added successfully.');
    $app->render('addemployee.html.twig');
});
$app->post('/addemployee', function() use($app) {
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
        'firstname' => $fname,
        'lastname' => $lname,
        'birthdate' => $birthdate,
        'hiredate' => $hiredate,
        'address' => $address,
        'appNo' => $appNo,
        'postalcode' => $postalcode,
        'country' => $country,
        'email' => $email,
        'phone' => $phone,
        'title' => $title,
        'username' => $username,
        'password' => $pass);

    $errorList = array();
    if ($_FILES['image']['size'] == 0) {
        array_push($errorList, "Image must be uploaded. You can not leave it empty");
    }
    if ($image['error'] == 0) {
        $imageInfo = getimagesize($image["tmp_name"]);
        if (!$imageInfo) {
            array_push($errorList, "File does not look like an valid image");
        }
    }
    // receive data and insert
    if (!$errorList) {
        $imageBinaryData = file_get_contents($image['tmp_name']);
        $mimeType = mime_content_type($image['tmp_name']);
        DB::insert('employee', array(
            'firstname' => $fname,
            'lastname' => $lname,
            'hireDate' => $hiredate,
            'birthDate' => $birthdate,
            'address' => $address,
            'appNo' => $appNo,
            'postalcode' => $postalcode,
            'country' => $country,
            'email' => $email,
            'phone' => $phone,
            'title' => $title,
            'username' => $username,
            'password' => $pass,
            'image' => $imageBinaryData,
            'mimetype' => $mimeType
        ));
        $listallemployee = DB::query("SELECT * FROM employee");
        $app->render('listemployee.html.twig', array(
            'listemployees' => $listallemployee));
    } else {
        //keep values entered on failed submission
        $app->render('addemployee.html.twig', array(
            'v' => $valuelist,
            'error' => $errorList
        ));
    }
});
//Edit Employee
$app->get('/editemployee/:id', function($id = 0) use($app) {
    /* if (($_SESSION['user']['title'] != "manager")) {
      $app->render('forbidden.html.twig');
      return;
      } */
    $valuelist = DB::queryFirstRow("SELECT * FROM employee WHERE id=%i", $id);
    $app->render("editemployee.html.twig", array(
        'v' => $valuelist));
});
$app->post('/editemployee/:id', function($id = 0) use($app) {

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
        'firstname' => $fname,
        'lastname' => $lname,
        'birthDate' => $birthdate,
        'hireDate' => $hiredate,
        'address' => $address,
        'appNo' => $appNo,
        'postalcode' => $postalcode,
        'country' => $country,
        'email' => $email,
        'phone' => $phone,
        'title' => $title,
        'username' => $username,
        'password' => $pass);

    $errorList = array();
    if ($_FILES['image']['size'] == 0) {
        array_push($errorList, "Image must be uploaded. You can not leave it empty");
    }
    if ($image['error'] == 0) {
        $imageInfo = getimagesize($image["tmp_name"]);
        if (!$imageInfo) {
            array_push($errorList, "File does not look like an valid image");
        }
    }
    // receive data and insert
    if (!$errorList) {
        $imageBinaryData = file_get_contents($image['tmp_name']);
        $mimeType = mime_content_type($image['tmp_name']);
        DB::update('employee', array(
            'firstname' => $fname,
            'lastname' => $lname,
            'hireDate' => $hiredate,
            'birthDate' => $birthdate,
            'address' => $address,
            'appNo' => $appNo,
            'postalcode' => $postalcode,
            'country' => $country,
            'email' => $email,
            'phone' => $phone,
            'title' => $title,
            'username' => $username,
            'password' => $pass,
            'image' => $imageBinaryData,
            'mimetype' => $mimeType
                ), "id=%i", $id);
        $listallemployee = DB::query("SELECT * FROM employee");
        $app->render('listemployee.html.twig', array(
            'listemployees' => $listallemployee));
    } else {
        //keep values entered on failed submission
        $app->render('editemployee.html.twig', array(
            'v' => $valuelist,
            'error' => $errorList
        ));
    }
});
// Delete Employee
$app->get('/deleteemployee/:id', function($id = 0) use ($app) {
    $employee = DB::queryFirstRow('SELECT * FROM employee WHERE id=%i', $id);
    $app->render('deleteemployee.html.twig', array(
        'employee' => $employee
    ));
});
$app->get('/deleteemployee/delete/:id', function($id = 0) use ($app) {
    DB::delete('employee', 'id=%i', $id);
    $listallemployee = DB::query("SELECT * FROM employee");
    $app->render('listemployee.html.twig', array(
        'listemployees' => $listallemployee));
});

//ADD customer ------------BEGIN
$app->get('/addcustomer', function() use ($app) {
    $app->render('addcustomer.html.twig');
});
$app->post('/addcustomer', function() use ($app) {
    // extract variables
    $fname = $app->request()->post('firstname');
    $lname = $app->request()->post('lastname');
    $address = $app->request()->post('address');
    $appNo = $app->request()->post('appNo');
    $postalcode = $app->request()->post('postalcode');
    $country = $app->request()->post('country');
    $email = $app->request()->post('email');
    $phone = $app->request()->post('phone');

    $valuelist = array(
        'firstname' => $fname,
        'lastname' => $lname,
        'address' => $address,
        'appNo' => $appNo,
        'postalcode' => $postalcode,
        'country' => $country,
        'email' => $email,
        'phone' => $phone);
    $errorList = array();
    //
    if ($errorList) {
        $app->render('addcustomer.html.twig', array(
            'errorList' => $errorList,
            'v' => $valueList
        ));
    } else {
        DB::insert('customers', array(
            'firstname' => $fname,
            'lastname' => $lname,
            'address' => $address,
            'appNo' => $appNo,
            'postalcode' => $postalcode,
            'country' => $country,
            'email' => $email,
            'phone' => $phone
        ));
        $app->render('customer_added.html.twig');
    }
});


//ADD customer ---------------END

$app->run();


