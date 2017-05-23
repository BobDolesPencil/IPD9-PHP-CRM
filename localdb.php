<?php

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
