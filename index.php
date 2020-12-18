<?php
error_reporting(E_ALL);
ini_set('display_errors',1);

require_once './config/config.php';
require_once './src/Apiql.php';
$ApiQL = new Apiql($config);

$ApiQL->config->debug = true;
$ApiQL->config->token = 123456;

$ApiQL->handleRequest();

