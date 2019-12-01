<?php
/**
 * Created by PhpStorm.
 * User: etouraille
 * Date: 30/11/19
 * Time: 19:07
 */
include './vendor/autoload.php';

$curl = new \Curl\Curl();

$curl->get('http://localhost/api');
var_dump( $curl->response );
var_dump( $curl->error);
