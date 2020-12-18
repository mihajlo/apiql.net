<?php
$config = [
    //required general config
    'app_name'=> 'Test project',
    'app_desc' => 'Test description here...',
    'base_url' => 'http://localhost/apiql/',
    'token' => 'auth_token_here',
    'max_limit_per_page' => 100,
    'default_per_page' => 10,
    //endpoints / tables
    'allowed_actions' => [
        'table1' => ['list', 'add', 'edit', 'delete'],
        'table2' => ['list', 'add', 'edit', 'delete'],
        'table3' => ['list', 'add', 'edit', 'delete'],
    ],
    'disabled_columns' => [
        'email',
        'password'
    ],
    'debug' => true, //true,
    'debug_info' => [
        'primary_column' => true,
        'fields' => true,
        'sql' => true,
        'request' => true,
        'explain' => true,
    ],
    'defaultStatusMessages' => [
        200 => 'OK',
        401 => 'Autherntication failed!',
        403 => 'Not allowed!',
        404 => 'End-point not found!',
        405 => 'Bad request',
        500 => 'Internal server error!'
    ],
    
    
    //required DB config
    'hostname' => 'localhost',
    'username' => 'dbuser',
    'password' => 'dbpass',
    'database' => 'dbname',
    'dbdriver' => 'mysqli',
    
    //optional DB config
    'dsn' => '',
    'dbprefix' => '',
    'db_debug' => false,
    'char_set' => 'utf8',
    'dbcollat' => 'utf8_general_ci',
    'swap_pre' => '',
    'encrypt' => FALSE,
    'compress' => FALSE,
    'stricton' => FALSE,
    'failover' => array(),
    'save_queries' => TRUE
];

