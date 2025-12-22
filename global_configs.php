<?php

// Prevent direct script access
if ($_SERVER['REQUEST_METHOD'] == 'GET' && realpath(__FILE__) == realpath($_SERVER['SCRIPT_FILENAME'])) {
	header("HTTP/1.0 404 Not Found");
	echo "<h1>Not Found</h1>";
	echo "The requested URL was not found on this server.";
	exit();
}

/**
 * Global Config File
 *
 * @author Logimax Team
 * NOTE:
 * - This file MUST NOT be committed to Git
 * - Restrict permissions to 600
 */

class Globals
{
	/* =========================
       Base URLs
       ========================= */
	public static $web_base_url = 'http://localhost/bullion/zohoBiometric/';
	public static $api_base_url = 'http://localhost/bullion/zohoBiometric/api/';

	/* =========================
       Database credentials
       ========================= */
	public static $hostname = "ls-ed7d20aed62ced9021416fc1faa405d4b4711541.ckx0j1c4rlnl.ap-south-1.rds.amazonaws.com";
	public static $username = "demotrade";
	public static $password = "logimax*987";
	public static $database = "winbullSource";

	/* =========================
       Timezone
       ========================= */
	public static $timezone = 'Asia/Kolkata';

	/* =========================
	   Zoho URLs
	   ========================= */

	public static $zoho_people_url   = 'https://people.zoho.in';
	public static $zoho_accounts_url = 'https://accounts.zoho.in';

	/* =========================
       Zoho OAuth (placeholders)
       ========================= */
	public static $zoho_client_id     = '1000.63EWJTD201693QN46J4XDDXQLJUI9S';
	public static $zoho_client_secret = '49bda8c3de90024ad7b970d9e1abbd92d132feaf52';
	public static $zoho_refresh_token = '1000.d3f95b3cc5198fe9afa892b1268e61b1.e7478223c9ea36822532d30b702bdda0';

	/* =========================
       Mail credentials
       ========================= */
	public static $admin_mail = 'elavarasan@logimaxindia.com';
	public static $admin_mail_server = 'noreply@logimax.co.in';
	public static $admin_mail_password = 'ykdm rxdw wcnj gcjl';  // Gmail app password
	public static $admin_company_name = 'Logimax Technologies';
}


// Set default timezone once
date_default_timezone_set(Globals::$timezone);

// Ensure cURL is available
if (!function_exists('curl_version')) {
	show_error('cURL extension is required to run this application.');
}
