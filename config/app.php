<?php
/**
 * Application Core Configuration
 * RDM Information System
 */

define('APP_NAME', 'Research Data Management System');
define('APP_VERSION', '1.0.0');
define('APP_DEFAULT_TIMEZONE', 'Africa/Lagos');

if (function_exists('date_default_timezone_set')) {
    date_default_timezone_set(APP_DEFAULT_TIMEZONE);
}
