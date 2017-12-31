<?php
/*
 * VerySimplePHPFramework
 * http://github.com/gnh1201/verysimplephpframework
 * Go Namhyeon <gnh1201@gmail.com>
 * Date: 2017-12-18
 */

define("_DEF_VSPF_", true);
ini_set("max_execution_time", 0);

// including vendor autoloader
include_once('./vendor/autoload.php');

// load system files
$load_systems = array('base', 'config', 'database', 'uri');
foreach($load_systems as $system_name) {
	$system_inc_file = './system/' . $system_name . '.php';
	if(file_exists($system_inc_file)) {
		include_once($system_inc_file);
	}
}

// route controller
$route = '';
if(array_key_exists('route', $_REQUEST)) {
	$route = $_REQUEST['route'];
}

if(empty($route)) {
	$route = 'welcome';
} else {
	$route_names = explode('/', $route);
	if(count($route) > 1) {
		$route = end($route_names);
	}
}

// view render
function renderView($name, $data=array()) {
	if(count($data) > 0) {
		extract($data);
	}

	$viewfile = './view/' . $name . '.php';
	if(file_exists($viewfile)) {
		include($viewfile);
	}
}

// including route file
$route_file_name = './route/' . $route . '.php';
if(file_exists($route_file_name)) {
	include($route_file_name);
} else {
	echo "404 Not Found";
}
