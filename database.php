<?php

$db = new PDO(
    'mysql:dbname=' . $config['db']['database'] . ';host=' . $config['db']['host'],
    $config['db']['user'],
    $config['db']['pass'],
    [
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8"
    ]
);

$db->exec("SET time_zone='+0:00'");

return $db;