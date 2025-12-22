<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Global Constants Class
 * Author: Logimax Team
 */
class Globals
{
	/** Base URLs */
	public static $web_base_url = "http://localhost/bullion/zohoBiometric/";
	public static $api_base_url = "http://localhost/bullion/zohoBiometric/api/";

	/** Database credentials */
	public static $hostname = "ls-ed7d20aed62ced9021416fc1faa405d4b4711541.ckx0j1c4rlnl.ap-south-1.rds.amazonaws.com";
	public static $username = "demotrade";
	public static $password = "logimax*987";
	public static $database = "winbullSource";

	/** Timezone */
	public static $timezone = "Asia/Kolkata";
}

/* =========================
   Global Bootstrap
   ========================= */

// Set timezone globally
date_default_timezone_set(Globals::$timezone);

// cURL check
if (!function_exists('curl_version')) {
	exit('Enable cURL in PHP to proceed...');
}
