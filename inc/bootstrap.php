<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

date_default_timezone_set('Europe/Rome');
define("PROJECT_ROOT_PATH", __DIR__ . "/../");
#define("PROJECT_ROOT_PATH", __DIR__ . "/..");

// include main configuration file
require_once PROJECT_ROOT_PATH . "/inc/config.php";

// include the base controller file
require_once PROJECT_ROOT_PATH . "/Controller/Api/BaseController.php";

// include the use model file
require_once PROJECT_ROOT_PATH . "/Model/UserModel.php";
require_once PROJECT_ROOT_PATH . "/Model/SapModel.php";

// include the logger file
require_once PROJECT_ROOT_PATH . "phplogger.php";