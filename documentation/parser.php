<?php
require_once '../config/config.php';
require_once '../src/Apiql.php';
$ApiQL = new Apiql($config);

$tables = $ApiQL->db->list_tables();

$endpoints = [];

foreach($tables as $table){
    $fieldsData = $ApiQL->db->query("SHOW FIELDS FROM ".$table)->result_array();
    if(in_array($table, array_keys($config['allowed_actions']))){
        $endpoints[$table] = $fieldsData;
    }
}

$postdata = http_build_query(
    array(
        'config' => $config,
        'endpoints' => $endpoints
    )
);

$opts = array('http' =>
    array(
        'method'  => 'POST',
        'header'  => 'Content-Type: application/x-www-form-urlencoded',
        'content' => $postdata
    )
);

$context  = stream_context_create($opts);

echo file_get_contents('https://apiql.net/documentation/build.php', false, $context);