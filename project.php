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
///////////////////////////// All Employee Actions /////////////////////
//List All Employees
$app->get('/listemployee', function() use($app) {
    $listallemployee = DB::query("SELECT * FROM employee");
    $app->render('listemployee.html.twig', array(
        'listemployees' => $listallemployee));
});
$app->get('/viewphotousers/:userId', function($userId) use ($app) {
    $emp = DB::queryFirstRow("SELECT image, mimetype FROM employee WHERE id=%i", $userId);
    $app->response->headers->set('Content-Type', $emp['mimetype']);
    echo $emp['image'];
});
//Add Employee
$app->get('/addemployee', function() use($app) {
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
        $app->flash('addemployee', 'Employee Added successfully.');
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
        $app->flash('editemployee', 'Employee Edited successfully.');
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
    $app->flash('deleteemployee', 'Employee Deleted successfully.');
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
/////////////////////////////End of All Employee Actions /////////////////////
///////////////////////////// All Customer Actions /////////////////////
//List All Customers
$app->get('/listcustomers', function() use($app) {
    $listallcustomers = DB::query("SELECT * FROM customers");
    $app->render('listcustomers.html.twig', array(
        'listcustomers' => $listallcustomers));
});
//Add Customer
$app->get('/addcustomer', function() use ($app) {
    $app->render('addcustomer.html.twig');
});
$app->post('/addcustomer', function() use ($app) {
    // extract variables
    $fname = $app->request()->post('firstname');
    $lname = $app->request()->post('lastname');
    $birthdate = $app->request()->post('birthdate');
    $address = $app->request()->post('address');
    $appNo = $app->request()->post('appNo');
    $postalcode = $app->request()->post('postalcode');
    $country = $app->request()->post('country');
    $email = $app->request()->post('email');
    $phone = $app->request()->post('phone');
    $valuelist = array(
        'firstname' => $fname,
        'lastname' => $lname,
        'birthdate' => $birthdate,
        'address' => $address,
        'appNo' => $appNo,
        'postalcode' => $postalcode,
        'country' => $country,
        'email' => $email,
        'phone' => $phone);
    $errorList = array();
    if (!$errorList) {
        DB::insert('customers', array(
            'firstname' => $fname,
            'lastname' => $lname,
            'birthDate' => $birthdate,
            'address' => $address,
            'appNo' => $appNo,
            'postalcode' => $postalcode,
            'country' => $country,
            'email' => $email,
            'phone' => $phone,
        ));
        $listallcustomers = DB::query("SELECT * FROM customers");
        $app->render('listcustomers.html.twig', array(
            'listcustomers' => $listallcustomers));
    } else {

        $app->render('listcustomers.html.twig', array(
            'errorList' => $errorList,
            'v' => $valueList
        ));
    }
});
//Edit Customer
$app->get('/editcustomer/:id', function($id = 0) use($app) {
    /* if (($_SESSION['user']['title'] != "manager")) {
      $app->render('forbidden.html.twig');
      return;
      } */
    $valuelist = DB::queryFirstRow("SELECT * FROM customers WHERE id=%i", $id);
    $app->render("editcustomer.html.twig", array(
        'v' => $valuelist));
});
$app->post('/editcustomer/:id', function($id = 0) use($app) {

    $fname = $app->request()->post('firstname');
    $lname = $app->request()->post('lastname');
    $birthdate = $app->request()->post('birthdate');
    $address = $app->request()->post('address');
    $appNo = $app->request()->post('appNo');
    $postalcode = $app->request()->post('postalcode');
    $country = $app->request()->post('country');
    $email = $app->request()->post('email');
    $phone = $app->request()->post('phone');
    $valuelist = array(
        'firstname' => $fname,
        'lastname' => $lname,
        'birthdate' => $birthdate,
        'address' => $address,
        'appNo' => $appNo,
        'postalcode' => $postalcode,
        'country' => $country,
        'email' => $email,
        'phone' => $phone);
    $errorList = array();

    // receive data and insert
    if (!$errorList) {
        DB::update('customers', array(
            'firstname' => $fname,
            'lastname' => $lname,
            'birthDate' => $birthdate,
            'address' => $address,
            'appNo' => $appNo,
            'postalcode' => $postalcode,
            'country' => $country,
            'email' => $email,
            'phone' => $phone
                ), "id=%i", $id);
        $app->flash('editcustomer', 'Customer Edited successfully.');
        $listallcustomers = DB::query("SELECT * FROM customers");
        $app->render('listcustomers.html.twig', array(
            'listcustomers' => $listallcustomers));
    } else {
        //keep values entered on failed submission
        $app->render('editcustomer.html.twig', array(
            'v' => $valuelist,
            'error' => $errorList
        ));
    }
});
// Delete Customer
$app->get('/deletecustomer/:id', function($id = 0) use ($app) {
    $customer = DB::queryFirstRow('SELECT * FROM customers WHERE id=%i', $id);
    $app->flash('deletecustomers', 'Customer Deleted successfully.');
    $app->render('deletecustomer.html.twig', array(
        'customer' => $customer
    ));
});
$app->get('/deletecustomer/delete/:id', function($id = 0) use ($app) {
    DB::delete('customers', 'id=%i', $id);
    $listallcustomers = DB::query("SELECT * FROM customers");
    $app->render('listcustomers.html.twig', array(
        'listcustomers' => $listallcustomers));
});
/////////////////////////////End of All Customer Actions /////////////////////
///////////////////////////// All Products Actions /////////////////////
//List All Products
$app->get('/listproducts', function() use($app) {
    $listallproducts = DB::query("SELECT * FROM products");
    $app->render('listproducts.html.twig', array(
        'listproducts' => $listallproducts));
});
$app->get('/viewphotoproduct/:productId', function($productId) use ($app) {
    $emp = DB::queryFirstRow("SELECT image, mimetype FROM products WHERE id=%i", $productId);
    $app->response->headers->set('Content-Type', $emp['mimetype']);
    echo $emp['image'];
});
//Add Product
$app->get('/addproduct', function() use ($app) {
    $app->render('addproduct.html.twig');
});
$app->post('/addproduct', function() use ($app) {
    $name = $app->request()->post('productname');
    $price = $app->request()->post('price');
    $discount = $app->request()->post('discount');
    $startdate = $app->request()->post('startdate');
    $enddate = $app->request()->post('enddate');
    $catname = $app->request()->post('category');
    $catid = DB::queryFirstRow("SELECT * FROM category WHERE name=%s", $catname);
    $image = $_FILES['image'];
    $valuelist = array(
        'productname' => $name,
        'price' => $price,
        'discount' => $discount
    );
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
    if (!$errorList) {
        $imageBinaryData = file_get_contents($image['tmp_name']);
        $mimeType = mime_content_type($image['tmp_name']);
        DB::insert('products', array(
            'name' => $name,
            'price' => $price,
            'categoryId' => $catid['id'],
            'discount' => $discount,
            'discountstartdate' => $startdate,
            'discountenddate' => $enddate,
            'image' => $imageBinaryData,
            'mimetype' => $mimeType
        ));
        $app->flash('addproduct', 'Product Added Successfully');
        $listallproducts = DB::query("SELECT * FROM products");
        $app->render('listproducts.html.twig', array(
            'listproducts' => $listallproducts));
    } else {
        //keep values entered on failed submission
        $app->render('addproduct.html.twig', array(
            'v' => $valuelist,
            'error' => $errorList
        ));
    }
});
//Edit Product
$app->get('/editproduct/:id', function($id = 0) use($app) {
    /* if (($_SESSION['user']['title'] != "manager")) {
      $app->render('forbidden.html.twig');
      return;
      } */
    $valuelist = DB::queryFirstRow("SELECT * FROM products WHERE id=%i", $id);
    $app->render("editproduct.html.twig", array(
        'v' => $valuelist));
});
$app->post('/editproduct/:id', function($id = 0) use($app) {

    $name = $app->request()->post('productname');
    $price = $app->request()->post('price');
    $discount = $app->request()->post('discount');
    $startdate = $app->request()->post('startdate');
    $enddate = $app->request()->post('enddate');
    $catname = $app->request()->post('category');
    $catid = DB::queryFirstRow("SELECT * FROM category WHERE name=%s", $catname);
    $image = $_FILES['image'];
    $valuelist = array(
        'name' => $name,
        'price' => $price,
        'discount' => $discount,
        'discountstartdate'=>$startdate,
        'discountenddate'=>$enddate, 
        'id'=>$id
    );
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
    if (!$errorList) {
        $imageBinaryData = file_get_contents($image['tmp_name']);
        $mimeType = mime_content_type($image['tmp_name']);
        DB::update('products', array(
            'name' => $name,
            'price' => $price,
            'categoryId' => $catid['id'],
            'discount' => $discount,
            'discountstartdate' => $startdate,
            'discountenddate' => $enddate,
            'image' => $imageBinaryData,
            'mimetype' => $mimeType
                ), "id=%i", $id);
        $app->flash('editproduct', 'Product Edited successfully.');
        $listallproducts = DB::query("SELECT * FROM products");
        $app->render('listproducts.html.twig', array(
            'listproducts' => $listallproducts));
    } else {
        //keep values entered on failed submission
        $app->render('editproduct.html.twig', array(
            'v' => $valuelist,
            'error' => $errorList
        ));
    }
});
// Delete Customer
$app->get('/deleteproduct/:id', function($id = 0) use ($app) {
    $product = DB::queryFirstRow('SELECT * FROM products WHERE id=%i', $id);
    $app->flash('deleteproduct', 'Product Deleted successfully.');
    $app->render('deleteproduct.html.twig', array(
        'p' => $product
    ));
});
$app->get('/deleteproduct/delete/:id', function($id = 0) use ($app) {
    DB::delete('products', 'id=%i', $id);
    $listallproducts = DB::query("SELECT * FROM products");
    $app->render('listproducts.html.twig', array(
        'listproducts' => $listallproducts));
});

/////////////////////////////End of All Products Actions /////////////////////

$app->run();


