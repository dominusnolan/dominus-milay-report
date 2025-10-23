<?php
/**
 * Plugin Name: Dominus Milay Report
 * Description: Workorder reports (Yearly Performance, Rescheduled, Lead Category) with Engineer role & Workorder CPT.
 * Version: 1.0.0
 * Author: Nolan T.
 * License: GPL-2.0+
 */

if (!defined('ABSPATH')) {
    exit;
}

define('DMR_PLUGIN_VERSION', '1.0.0');
define('DMR_PLUGIN_FILE', __FILE__);
define('DMR_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('DMR_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once DMR_PLUGIN_DIR . 'includes/class-dmr-cpt.php';
require_once DMR_PLUGIN_DIR . 'includes/class-dmr-reports.php';
require_once DMR_PLUGIN_DIR . 'includes/admin/menu.php';

register_activation_hook(__FILE__, ['DMR_CPT', 'activate']);
register_deactivation_hook(__FILE__, ['DMR_CPT', 'deactivate']);

add_action('init', ['DMR_CPT', 'register_cpt']);
add_action('init', ['DMR_CPT', 'register_role']);
add_action('admin_init', ['DMR_CPT', 'map_default_caps']);

// Keep report date synced as content changes
add_action('save_post_workorder', ['DMR_Reports', 'sync_report_date'], 20, 1);
add_action('acf/save_post', ['DMR_Reports', 'sync_report_date_from_acf'], 20, 1);
add_action('set_object_terms', function($object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids) {
    if ('category' === $taxonomy && 'workorder' === get_post_type($object_id)) {
        DMR_Reports::sync_report_date((int)$object_id);
    }
}, 10, 6);

// Assets only on our pages
add_action('admin_enqueue_scripts', function($hook) {
    // Enqueue only on our plugin's pages
    $valid_pages = ['dmr-reports', 'dmr-rescheduled', 'dmr-lead-category'];
    $current_page = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : '';
    if (in_array($current_page, $valid_pages, true)) {
        wp_enqueue_style('dmr-admin', DMR_PLUGIN_URL . 'assets/admin.css', [], DMR_PLUGIN_VERSION);
        wp_enqueue_script('dmr-admin', DMR_PLUGIN_URL . 'assets/admin.js', ['jquery'], DMR_PLUGIN_VERSION, true);
    }
});
