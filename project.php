<?php

session_cache_limiter(false);
session_start();

require_once 'vendor/autoload.php';

use Monolog\Logger;
use Monolog\Handler\StreamHandler;

// create a log channel
$log = new Logger('main');
$log->pushHandler(new StreamHandler('logs/everything.log', Logger::DEBUG));
$log->pushHandler(new StreamHandler('logs/errors.log', Logger::ERROR));

//connect to database
require_once 'localdb.php';
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
        $twig = $app->view()->getEnvironment();
        $twig->addGlobal('user', $_SESSION['user']);
        $todolist = DB::query("SELECT customers.firstname,customers.lastname,customers.phone,customers.email,todos.id,todos.action,todos.dueDate,todos.sendFrom "
                        . "FROM customers INNER JOIN todos ON customers.id = todos.customerID WHERE todos.isdone=%i AND todos.dueDate <= %t AND todos.employeeID=%i", 0, new DateTime(), $_SESSION['user']['id']);
        $app->render('dashboard.html.twig', array(
            'todolist' => $todolist
        ));
    }
});
//LogOut
$app->get('/logout', function() use ($app) {
    //check cart before logout
    $cartitems = DB::query("SELECT * from cartitems WHERE sessionID=%s", session_id());
    if ($cartitems) {
        $app->render('checkcartbeforelogout.html.twig');
    } else {
        unset($_SESSION['user']);
        $_SESSION['user'] = array();
        $twig = $app->view()->getEnvironment();
        $twig->addGlobal('user', $_SESSION['user']);
        $app->render('logout.html.twig');
    }
});
//clear cart before logout
$app->get('/checkcartbeforelogout', function() {
    DB::delete('cartitems', "sessionID=%s", session_id());
});

// PASSWOR RESET on login
function generateRandomString($length = 10) {
    $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $charactersLength = strlen($characters);
    $randomString = '';
    for ($i = 0; $i < $length; $i++) {
        $randomString .= $characters[rand(0, $charactersLength - 1)];
    }
    return $randomString;
}

$app->map('/passreset', function () use ($app, $log) {
    // Alternative to cron-scheduled cleanup
    if (rand(1, 1000) == 111) {
        // TODO: do the cleanup 1 in 1000 accessed to /passreset URL
    }
    if ($app->request()->isGet()) {
        $app->render('passreset.html.twig');
    } else {
        $username = $app->request()->post('username');
        $user = DB::queryFirstRow("SELECT * FROM employee WHERE username=%s", $username);
        $email = $user['email'];
        if ($user) {
            $app->render('passreset_success.html.twig');
            $secretToken = generateRandomString(50);
            // VERSION 1: delete and insert
            /*
              DB::delete('passresets', 'userID=%d', $user['ID']);
              DB::insert('passresets', array(
              'userID' => $user['ID'],
              'secretToken' => $secretToken,
              'expiryDateTime' => date("Y-m-d H:i:s", strtotime("+5 hours"))
              )); */
            // VERSION 2: insert-update TODO
            DB::insertUpdate('passresets', array(
                'employeeID' => $user['id'],
                'secretToken' => $secretToken,
                'expiryDateTime' => date("Y-m-d H:i:s", strtotime("+5 minutes"))
            ));
            // email user
            $url = 'http://' . $_SERVER['SERVER_NAME'] . '/passreset/' . $secretToken;
            $html = $app->view()->render('email_passreset.html.twig', array(
                'name' => $user['firstname'],
                'url' => $url
            ));
            $headers = "MIME-Version: 1.0\r\n";
            $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
            $headers .= "From: Noreply <noreply@ipd8.info>\r\n";
            $headers .= "To: " . htmlentities($user['firstname']) . " <" . $email . ">\r\n";

            mail($email, "Password reset from SlimShop", $html, $headers);
            $log->info("Password reset for $email email sent");
        } else {
            $app->render('passreset.html.twig', array('error' => TRUE));
        }
    }
})->via('GET', 'POST');
$app->map('/passreset/:secretToken', function($secretToken) use ($app, $log) {
    $row = DB::queryFirstRow("SELECT * FROM passresets WHERE secretToken=%s", $secretToken);
    if (!$row) {
        $app->render('passreset_notfound_expired.html.twig');
        return;
    }
    if (strtotime($row['expiryDateTime']) < time()) {
        $app->render('passreset_notfound_expired.html.twig');
        return;
    }
    //
    if ($app->request()->isGet()) {
        $app->render('passreset_form.html.twig');
    } else {
        $pass1 = $app->request()->post('pass1');
        $pass2 = $app->request()->post('pass2');
        // TODO: verify password quality and that pass1 matches pass2
        $errorList = array();
        $msg = verifyPassword($pass1);
        if ($msg !== TRUE) {
            array_push($errorList, $msg);
        } else if ($pass1 != $pass2) {
            array_push($errorList, "Passwords don't match");
        }
        //
        if ($errorList) {
            $app->render('passreset_form.html.twig', array(
                'errorList' => $errorList
            ));
        } else {
            // success - reset the password
            DB::update('employee', array(
                'password' => password_hash($pass1, CRYPT_BLOWFISH)
                    ), "ID=%d", $row['employeeID']);
            DB::delete('passresets', 'secretToken=%s', $secretToken);
            $app->render('passreset_form_success.html.twig');
            $log->info("Password reset completed for " . $row['email'] . " uid=" . $row['employeeID']);
        }
    }
})->via('GET', 'POST');
//Change password in app
$app->get('/changepassword', function()use($app) {
    $app->render('changepassword.html.twig');
});
$app->post('/changepassword', function()use($app) {
    $oldpass = $app->request()->post('oldpass');
    $newpass = $app->request()->post('newpass1');
    $user = DB::queryFirstRow("SELECT * FROM employee WHERE username=%s", $_SESSION['user']['username']);
    $error = false;
    if (!$user) {
        $error = true;
    } else {
        if ($user['password'] != $oldpass) {
            $error = true;
        }
        if ($newpass == $oldpass) {
            $error = true;
        }
    }
    if ($error) {
        $app->render('changepassword.html.twig', array("error" => true));
    } else {
        DB::update('employee', array(
            'password' => $newpass
                ), "username=%s", $_SESSION['user']['username']);
        $app->flashNow('passchange', 'Password Changed successfully.');
        $listallemployee = DB::query("SELECT * FROM employee");
        $app->render('listemployee.html.twig', array(
            'listemployees' => $listallemployee));
    }
});
///////////////////////////// All Dashboard Actions /////////////////////
$app->get('/dashboard', function()use($app) {
    $todolist = DB::query("SELECT customers.firstname,customers.lastname,customers.phone,customers.email,todos.id,todos.action,todos.dueDate,todos.sendFrom "
                    . "FROM customers INNER JOIN todos ON customers.id = todos.customerID WHERE todos.isdone=%i AND todos.dueDate <= %t AND todos.employeeID=%i", 0, new DateTime(), $_SESSION['user']['id']);
    $app->render('dashboard.html.twig', array(
        'todolist' => $todolist
    ));
});
$app->get('/tododone/:resualt/:id', function($resual, $id)use($app) {
    DB::update('todos', array(
        'actionResult' => $resual,
        'doneDate' => new DateTime(),
        'isDone' => 1
            ), "id=%i", $id);
});
$app->get('/todotransfered/:id', function($id = 0)use($app) {
    $app->render('todotransfered.html.twig');
});
$app->post('/todotransfered/:id', function($id = 0)use($app) {
    $newid = $app->request()->post('employeeid');
    $newaction = $app->request()->post('action');
    $donetillnow = $app->request()->post('doneyet');
    $todo = DB::queryFirstRow("SELECT * FROM todos WHERE id=%i", $id);
    DB::insert('todos', array(
        'customerID' => $todo['customerID'],
        'employeeID' => $newid,
        'action' => $newaction,
        'isDone' => 0,
        'dueDate' => new DateTime(),
        'sendFrom' => $_SESSION['user']['id']
    ));
    DB::update('todos', array(
        'actionResult' => $donetillnow,
        'doneDate' => new DateTime(),
        'isDone' => 1
            ), "id=%i", $id);
    $todolist = DB::query("SELECT customers.firstname,customers.lastname,customers.phone,customers.email,todos.id,todos.action,todos.dueDate,todos.sendFrom "
                    . "FROM customers INNER JOIN todos ON customers.id = todos.customerID WHERE todos.isdone=%i AND todos.dueDate <= %t AND todos.employeeID=%i", 0, new DateTime(), $_SESSION['user']['id']);
    $app->render('dashboard.html.twig', array(
        'todolist' => $todolist
    ));
});
///////////////////////////// All Employee Actions /////////////////////
//List All Employees
$app->get('/listemployee', function() use ($app) {
    $listallemployee = DB::query("SELECT * FROM employee");
    $app->render('listemployee.html.twig', array(
        'listemployees' => $listallemployee));
});
// for AJAX - returns partial HTML only! Employees Search
$app->get('/listemployee/:keyword', function($keyword) use ($app) {
    if ($keyword == "all") {
        $listallemployee = DB::query("SELECT * FROM employee");
        $app->render('listemployee_table.html.twig', array(
            'listemployees' => $listallemployee));
    } else {
        $listallemployee = DB::query("SELECT * FROM employee WHERE firstname LIKE %ss OR lastname LIKE %ss OR email LIKE %ss", $keyword, $keyword, $keyword);
        $app->render('listemployee_table.html.twig', array(
            'listemployees' => $listallemployee));
    }
});
//Show employee images
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
        $app->flashNow('addemployee', 'Employee Added successfully.');
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
            'password' => $pass
                ), "id=%i", $id);
        $app->flashNow('editemployee', 'Employee Edited successfully.');
        $listallemployee = DB::query("SELECT * FROM employee");
        $app->render('listemployee.html.twig', array(
            'listemployees' => $listallemployee));
    } else {
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
            $app->flashNow('editemployee', 'Employee Edited successfully.');
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
    $app->flashNow('deleteemployee', 'Employee Deleted successfully.');
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
// for AJAX - returns partial HTML only! Customers Search
$app->get('/listcustomers/:keyword', function($keyword) use ($app) {
    if ($keyword == "all") {
        $listallcustomers = DB::query("SELECT * FROM customers");
        $app->render('listcustomers_table.html.twig', array(
            'listcustomers' => $listallcustomers));
    } else {
        $listallcustomers = DB::query("SELECT * FROM customers WHERE firstname LIKE %ss OR lastname LIKE %ss OR email LIKE %ss", $keyword, $keyword, $keyword);
        $app->render('listcustomers_table.html.twig', array(
            'listcustomers' => $listallcustomers));
    }
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
        $app->flashNow('addcustomer', 'Customer Added successfully.');
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
        $app->flashNow('editcustomer', 'Customer Edited successfully.');
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
    $app->render('deletecustomer.html.twig', array(
        'customer' => $customer
    ));
});
$app->get('/deletecustomer/delete/:id', function($id = 0) use ($app) {
    DB::delete('customers', 'id=%i', $id);
    $app->flashNow('deletecustomer', 'Customer Deleted successfully.');
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
        $app->flashNow('addproduct', 'Product Added Successfully');
        //make a todo for employees after add a new product
        $cutomerids = DB::query("SELECT customers.id FROM customers INNER JOIN orders ON customers.id =orders.customerID WHERE orders.id = ANY (SELECT orderitems.orderID from orderitems INNER JOIN products on orderitems.origProductID = products.id WHERE products.categoryID = %i)", $catid['id']);
        $employeeids = DB::query("SELECT id from employee WHERE title='employee'");
        $counter = 0;
        foreach ($cutomerids as $cust) {
            if ($counter <= sizeof($employeeids)) {
                DB::insert('todos', array(
                    'customerID' => $cust['id'],
                    'employeeID' => $employeeids[$counter]['id'],
                    'action' => "Call",
                    'isDone' => 0,
                    'dueDate' => new DateTime(),
                    'sendFrom' => "System"
                ));
                $counter++;
            } else {
                $counter = 0;
                DB::insert('todos', array(
                    'customerID' => $cust['id'],
                    'employeeID' => $employeeids[$counter]['id'],
                    'action' => "Call/Present a New Product.",
                    'isDone' => 0,
                    'dueDate' => new DateTime(),
                    'sendFrom' => "System"
                ));
                $counter++;
            }
        }
        //end
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
        'discountstartdate' => $startdate,
        'discountenddate' => $enddate,
        'id' => $id
    );
    $errorList = array();
    if ($_FILES['image']['size'] == 0) {
        DB::update('products', array(
            'name' => $name,
            'price' => $price,
            'categoryId' => $catid['id'],
            'discount' => $discount,
            'discountstartdate' => $startdate,
            'discountenddate' => $enddate
                ), "id=%i", $id);
        $app->flashNow('editproduct', 'Product Edited successfully.');
        $listallproducts = DB::query("SELECT * FROM products");
        $app->render('listproducts.html.twig', array(
            'listproducts' => $listallproducts));
    } else {
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
            $app->flashNow('editproduct', 'Product Edited successfully.');
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
    }
});
// Delete Product
$app->get('/deleteproduct/:id', function($id = 0) use ($app) {
    $product = DB::queryFirstRow('SELECT * FROM products WHERE id=%i', $id);
    $app->render('deleteproduct.html.twig', array(
        'p' => $product
    ));
});
$app->get('/deleteproduct/delete/:id', function($id = 0) use ($app) {
    DB::delete('products', 'id=%i', $id);
    $app->flashNow('deleteproduct', 'Product Deleted successfully.');
    $listallproducts = DB::query("SELECT * FROM products");
    $app->render('listproducts.html.twig', array(
        'listproducts' => $listallproducts));
});
/////////////////////////////End of All Products Actions /////////////////////
///////////////////////////// All Cart Actions /////////////////////
// Add to Cart
$app->get('/addtocart/:id', function($id = 0) use ($app) {
    $quantity = 1;
    $item = DB::queryFirstRow("SELECT * FROM cartitems WHERE productID=%d AND sessionID=%s", $id, session_id());
    if ($item) {
        echo ("<SCRIPT LANGUAGE='JavaScript'>
        window.alert('The Item Already exsist!')
         window.location.href='/listproducts';
        </SCRIPT>");
    } else {
        DB::insert('cartitems', array(
            'sessionID' => session_id(),
            'productID' => $id,
            'quantity' => $quantity
        ));
        $app->flashNow('addproduct', 'Product Added to cart Successfully');
        $listallproducts = DB::query("SELECT * FROM products");
        $app->render('listproducts.html.twig', array(
            'listproducts' => $listallproducts));
    }
});
//List of all cart Items
$app->get('/viewcart', function()use($app) {
    $cartitems = DB::query("SELECT * FROM products INNER JOIN cartitems ON products.id = cartitems.productID AND sessionID=%s", session_id());
    if ($cartitems) {
        $app->render('cartlistitems.html.twig', array(
            'items' => $cartitems
        ));
    } else {
        echo ("<SCRIPT LANGUAGE='JavaScript'>
        window.alert('Cart is empty!Perches some Item.')
         window.location.href='/listproducts';
        </SCRIPT>");
    }
});
//Delete from cart
$app->get('/deletefromcart/:id', function($id = 0) use($app) {
    DB::delete('cartitems', 'productID=%i', $id);
    $cartitems = DB::query("SELECT * FROM products INNER JOIN cartitems ON products.id = cartitems.productID");
    if ($cartitems) {
        $app->render('cartlistitems.html.twig', array(
            'items' => $cartitems
        ));
    } else {
        $listallproducts = DB::query("SELECT * FROM products");
        $app->render('listproducts.html.twig', array(
            'listproducts' => $listallproducts));
    }
});
//Update qty in cart
$app->get('/cart/update/:productID/:quantity', function($productID, $quantity) {
    $cartitem = DB::queryFirstRow("SELECT * from cartitems WHERE productID=%i AND cartitems.sessionID=%s", $productID, session_id());
    DB::update('cartitems', array('quantity' => $quantity), 'ID=%i AND sessionID=%s', $cartitem['ID'], session_id());
    echo json_encode(DB::affectedRows() == 1);
});
//place order
$app->map('/order', function () use ($app) {
    $totalBeforeTax = DB::queryFirstField(
                    "SELECT SUM(products.price * cartitems.quantity) "
                    . " FROM cartitems, products "
                    . " WHERE cartitems.sessionID=%s AND cartitems.productID=products.ID", session_id());
// TODO: properly compute taxes, shipping, ...
    $shippingBeforeTax = 15.00;
    $taxes = ($totalBeforeTax + $shippingBeforeTax) * 0.15;
    $totalWithShippingAndTaxes = $totalBeforeTax + $shippingBeforeTax + $taxes;

    if ($app->request->isGet()) {
        $app->render('placeorder.html.twig', array(
            'totalBeforeTax' => number_format($totalBeforeTax, 2),
            'shippingBeforeTax' => number_format($shippingBeforeTax, 2),
            'taxes' => number_format($taxes, 2),
            'totalWithShippingAndTaxes' => number_format($totalWithShippingAndTaxes, 2)
        ));
    } else {// SUCCESSFUL SUBMISSION
        $custID = $app->request->post('customerid');
        DB::$error_handler = FALSE;
        DB::$throw_exception_on_error = TRUE;
// PLACE THE ORDER
        try {
            DB::startTransaction();
// 1. create summary record in 'orders' table (insert)
            DB::insert('orders', array(
                'employeeID' => $_SESSION['user'] ? $_SESSION['user']['id'] : NULL,
                'customerID' => $custID,
                'totalBeforeTax' => $totalBeforeTax,
                'shippingBeforeTax' => $shippingBeforeTax,
                'taxes' => $taxes,
                'totalWithShippingAndTaxes' => $totalWithShippingAndTaxes,
                'dateTimePlaced' => date('Y-m-d H:i:s')
            ));
            $orderID = DB::insertId();
// 2. copy all records from cartitems to 'orderitems' (select & insert)
            $cartitemList = DB::query(
                            "SELECT productID as origProductID, quantity, price"
                            . " FROM cartitems, products "
                            . " WHERE cartitems.productID = products.ID AND sessionID=%s", session_id());
// add orderID to every sub-array (element) in $cartitemList
            array_walk($cartitemList, function(&$item, $key) use ($orderID) {
                $item['orderID'] = $orderID;
            });
            /* This is the same as the following foreach loop:
              foreach ($cartitemList as &$item) {
              $item['orderID'] = $orderID;
              } */
            DB::insert('orderitems', $cartitemList);
// 3. delete cartitems for this user's session (delete)
            DB::delete('cartitems', "sessionID=%s", session_id());
            DB::commit();
// TODO: send a confirmation email
            /*
              $emailHtml = $app->view()->getEnvironment()->render('email_order.html.twig');
              $headers = "MIME-Version: 1.0\r\n";
              $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
              mail($email, "Order " .$orderID . " placed ", $emailHtml, $headers);
             */
//
            $listallproducts = DB::query("SELECT * FROM products");
            $app->render('listproducts.html.twig', array(
                'listproducts' => $listallproducts));
        } catch (MeekroDBException $e) {
            DB::rollback();
            sql_error_handler(array(
                'error' => $e->getMessage(),
                'query' => $e->getQuery()
            ));
        }
    }
})->via('GET', 'POST');
/////////////////////////////End of All Cart Actions /////////////////////
$app->run();