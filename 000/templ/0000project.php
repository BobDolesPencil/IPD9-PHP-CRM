<?php

session_cache_limiter(false);
session_start();

require_once '/vendor/autoload.php';

DB::$host = '127.0.0.1';
DB::$user = 'crmproject';
DB::$password = 'EdBsYzQNu3zPZAQZ';
DB::$dbName = 'crmproject';
//DB::$port = 3306;
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

// INDEX

$app->get('/crm', function() use ($app) {
    $app->render('index.html.twig');
});

// CALENEDER

$app->get('/calender', function() use ($app) {
    $app->render('calender.html.twig');
});

//Dasboard
$app->get('/dash', function() use ($app) {
    $app->render('dashboard.html.twig');
});

//LOGIN --------- BEGIN
$app->get('/login', function() use ($app) {
    $app->render('login.html.twig');
});

$app->post('/login', function() use ($app) {
    $email = $app->request()->post('email');
    $pass = $app->request()->post('pass');
    // verification    
    $error = false;
    $user = DB::queryFirstRow("SELECT * FROM employees WHERE email=%s", $email);
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
//LOGIN ----------------END

//LOGOUT -------- BEGIN
$app->get('/logout', function() use ($app) {
    unset($_SESSION['user']);
    $app->render('logout.html.twig');
});
//LOGOUT -------- END

//ADD employee ------------BEGIN
$app->get('/addemployee', function() use ($app) {
    $app->render('addemployee.html.twig');
});

$app->post('/addemployee', function() use ($app) {
    // extract variables
    $lname = $app->request()->post('lname');
    $fname = $app->request()->post('fname');
    $posId = $app->request()->post('posId');
    $email = $app->request()->post('email');
    $pass1 = $app->request()->post('pass1');
    $pass2 = $app->request()->post('pass2');
    // list of values to retain after a failed submission
    $valueList = array('email' => $email);
    // check for errors and collect error messages
    $errorList = array();
    if (filter_var($email, FILTER_VALIDATE_EMAIL) === FALSE) {
        array_push($errorList, "Email is invalid");
    } else {
        $user = DB::queryFirstRow("SELECT * FROM employees WHERE email=%s", $email);
        if ($user) {
            array_push($errorList, "Email already in use");
        }
    }
    if ($pass1 != $pass2) {
        array_push($errorList, "Passwors do not match");
    } else {
        if (strlen($pass1) < 6) {
            array_push($errorList, "Password too short, must be 6 characters or longer");
        } 
        if (preg_match('/[A-Z]/', $pass1) != 1
         || preg_match('/[a-z]/', $pass1) != 1
         || preg_match('/[0-9]/', $pass1) != 1) {
            array_push($errorList, "Password must contain at least one lowercase, "
                    . "one uppercase letter, and a digit");
        }
    }
    //
    if ($errorList) {
        $app->render('addemployee.html.twig', array(
            'errorList' => $errorList,
            'v' => $valueList
        ));
    } else {
        DB::insert('employees', array(
            'fname' => $fname,
            'lname' => $lname,
            'email' => $email,
            'password' => $pass1,
            'posId' => $posId
            
        ));
        $app->render('employee_added.html.twig');
    }
});


//ADD employee ---------------END

//ADD product ------------BEGIN
$app->get('/addproduct', function() use ($app) {
    $app->render('addproduct.html.twig');
});

$app->post('/addproduct', function() use ($app) {
    // extract variables
    $productname = $app->request()->post('productname');
    $description = $app->request()->post('description');
    $price = $app->request()->post('price');
    $vendorid = $app->request()->post('vendorId');
    
    // list of values to retain after a failed submission
    $valueList = array('productname' => $productname);
    // check for errors and collect error messages
    $errorList = array();
    //
    if ($errorList) {
        $app->render('addproductemployee.html.twig', array(
            'errorList' => $errorList,
            'v' => $valueList
        ));
    } else {
        DB::insert('products', array(
            'productname' => $productname,
            'description' => $description,
            'price' => $price,
            'vendorId' => $vendorid
            
        ));
        $app->render('product_added.html.twig');
    }
});


//ADD product ---------------END



//ADD customer ------------BEGIN
$app->get('/addcustomer', function() use ($app) {
    $app->render('addcustomer.html.twig');
});

$app->post('/addcustomer', function() use ($app) {
    // extract variables
    $lname = $app->request()->post('lname');
    $fname = $app->request()->post('fname');
    $address = $app->request()->post('address');
    $email = $app->request()->post('email');
    // list of values to retain after a failed submission
    $valueList = array('email' => $email);
    // check for errors and collect error messages
    $errorList = array();
    if (filter_var($email, FILTER_VALIDATE_EMAIL) === FALSE) {
        array_push($errorList, "Email is invalid");
    } else {
        $user = DB::queryFirstRow("SELECT * FROM customers WHERE email=%s", $email);
        if ($user) {
            array_push($errorList, "Email already in use, is this person already a customer?");
        }
    }
    
    //
    if ($errorList) {
        $app->render('addcustomer.html.twig', array(
            'errorList' => $errorList,
            'v' => $valueList
        ));
    } else {
        DB::insert('customers', array(
            'fname' => $fname,
            'lname' => $lname,
            'email' => $email,
            'address' => $address
            
        ));
        $app->render('customer_added.html.twig');
    }
});


//ADD customer ---------------END

$app->run();


