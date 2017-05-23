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

//connect to database
require_once 'localdb.php';

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
    $_SESSION['user'] = array();
    $app->render('logout.html.twig');
});
// PASSWOR RESET
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
    if (rand(1,1000) == 111) {
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
            $headers.= "Content-Type: text/html; charset=UTF-8\r\n";
            $headers.= "From: Noreply <noreply@ipd8.info>\r\n";
            $headers.= "To: " . htmlentities($user['firstname']) . " <" . $email . ">\r\n";

            mail($email, "Password reset from SlimShop", $html, $headers);
            $log->info("Password reset for $email email sent");
        } else {
            $app->render('passreset.html.twig', array('error' => TRUE));
        }
    }
})->via('GET', 'POST');
$app->map('/passreset/:secretToken', function($secretToken) use ($app,$log) {
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
            DB::delete('passresets','secretToken=%s', $secretToken);
            $app->render('passreset_form_success.html.twig');
            $log->info("Password reset completed for " . $row['email'] . " uid=". $row['employeeID']);
        }
    }
})->via('GET', 'POST');
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
        'discountstartdate' => $startdate,
        'discountenddate' => $enddate,
        'id' => $id
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
        $listallproducts = DB::query("SELECT * FROM products");
        $app->render('listproducts.html.twig', array(
            'listproducts' => $listallproducts));
    }
});
//List of all cart Items
$app->get('/viewcart', function()use($app) {
    $cartitems = DB::query("SELECT * FROM products INNER JOIN cartitems ON products.id = cartitems.productID");
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
    $cartitem = DB::queryFirstRow("SELECT * from cartitems WHERE productID=%i", $productID);
    DB::update('cartitems', array('quantity' => $quantity), 'cartitems.ID=%d AND cartitems.sessionID=%s', $cartitem['id'], session_id());
    echo json_encode(DB::affectedRows() == 1);
});
//place order
// order handling
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


