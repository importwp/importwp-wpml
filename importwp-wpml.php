<?php

/**
 * Plugin Name: Import WP - WPML Addon
 * Plugin URI: https://www.importwp.com
 * Description: WPML Integration for ImportWP
 * Author: James Collings <james@jclabs.co.uk>
 * Version: __STABLE_TAG__ 
 * Author URI: https://www.importwp.com
 * Network: True
 */

if (!defined('IWP_WPML_MIN_CORE_VERSION')) {
    define('IWP_WPML_MIN_CORE_VERSION', '2.14.1');
}


add_action('admin_init', 'iwp_wpml_check');

function iwp_wpml_requirements_met()
{
    if (!class_exists('\SitePress')) {
        return false;
    }

    if (!function_exists('import_wp')) {
        return false;
    }

    if (version_compare(IWP_VERSION, IWP_WPML_MIN_CORE_VERSION, '<')) {
        return false;
    }

    return true;
}

function iwp_wpml_check()
{
    if (!iwp_wpml_requirements_met()) {

        add_action('admin_notices', 'iwp_wpml_notice');

        deactivate_plugins(plugin_basename(__FILE__));

        if (isset($_GET['activate'])) {
            unset($_GET['activate']);
        }
    }
}

function iwp_wpml_setup()
{
    if (!iwp_wpml_requirements_met()) {
        return;
    }

    $base_path = dirname(__FILE__);

    // require_once $base_path . '/class/autoload.php';
    require_once $base_path . '/setup.php';

    // Install updater
    if (file_exists($base_path . '/updater.php') && !class_exists('IWP_Updater')) {
        require_once $base_path . '/updater.php';
    }

    if (class_exists('IWP_Updater')) {
        $updater = new IWP_Updater(__FILE__, 'importwp-wpml');
        $updater->initialize();
    }
}
add_action('plugins_loaded', 'iwp_wpml_setup', 9);

function iwp_wpml_notice()
{
    echo '<div class="error">';
    echo '<p><strong>Import WP - WPML Importer Addon</strong> requires that you have <strong>Import WP v' . IWP_WPML_MIN_CORE_VERSION . ' or newer</strong>, and <strong>WPML</strong> installed.</p>';
    echo '</div>';
}
