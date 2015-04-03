<?php

ini_set('display_errors',true);
ini_set('error_reporting',E_ALL);

require_once 'class.envato_scraper.php';
$envato = new envato_scraper();
//$envato->do_login('dtbaker');

// get forum messages from the Admin page.
$url = "http://codecanyon.net/admin";
$html = $envato->get_url($url,array(),true);
if(!$html || strpos($html,'<title>Page Not Found')){
	echo "Login required. Please visit this script in a browser to continue.";
	$envato->do_login('dtbaker');
}else{
	echo "Got html: $html";
}