<?php
/**
 * Plugin Name: Heterodyna Inspektor
 * Description: Diagnostyka, audyt i mapowanie zależności wtyczek (tabele/endpointy/hooki) + rejestr incydentów.
 * Version: 01.001.0014
 * Author: Heterodyna Team Software
 * Author URI: https://heterodynateamsoftware.pl
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: heterodyna-inspektor
 */

if (!defined('ABSPATH')) { exit; }

define('HET_INSPECTOR_VERSION', '01.001.0014');
define('HET_INSPECTOR_SLUG', 'heterodyna-inspektor');
define('HET_INSPECTOR_PATH', plugin_dir_path(__FILE__));
define('HET_INSPECTOR_URL', plugin_dir_url(__FILE__));

require_once HET_INSPECTOR_PATH . 'includes/class-het-inspector.php';

register_activation_hook(__FILE__, array('HET_Inspector', 'activate'));
register_deactivation_hook(__FILE__, array('HET_Inspector', 'deactivate'));

add_action('plugins_loaded', function () {
    HET_Inspector::instance();
});
