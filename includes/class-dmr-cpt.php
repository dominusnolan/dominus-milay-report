<?php
if (!defined('ABSPATH')) { exit; }

class DMR_CPT {

    public static function register_cpt() {
        $labels = [
            'name'               => __('Work Orders', 'dmr'),
            'singular_name'      => __('Work Order', 'dmr'),
            'add_new'            => __('Add New', 'dmr'),
            'add_new_item'       => __('Add New Work Order', 'dmr'),
            'edit_item'          => __('Edit Work Order', 'dmr'),
            'new_item'           => __('New Work Order', 'dmr'),
            'all_items'          => __('All Work Orders', 'dmr'),
            'view_item'          => __('View Work Order', 'dmr'),
            'search_items'       => __('Search Work Orders', 'dmr'),
            'not_found'          => __('No work orders found', 'dmr'),
            'not_found_in_trash' => __('No work orders found in Trash', 'dmr'),
            'menu_name'          => __('Work Orders', 'dmr')
        ];

        $caps = [
            'edit_post'          => 'edit_workorder',
            'read_post'          => 'read_workorder',
            'delete_post'        => 'delete_workorder',
            'edit_posts'         => 'edit_workorders',
            'edit_others_posts'  => 'edit_others_workorders',
            'publish_posts'      => 'publish_workorders',
            'read_private_posts' => 'read_private_workorders',
            'delete_posts'       => 'delete_workorders',
            'delete_private_posts' => 'delete_private_workorders',
            'delete_published_posts' => 'delete_published_workorders',
            'delete_others_posts' => 'delete_others_workorders',
            'edit_private_posts' => 'edit_private_workorders',
            'edit_published_posts' => 'edit_published_workorders',
            'create_posts'       => 'edit_workorders',
        ];

        register_post_type('workorder', [
            'labels'              => $labels,
            'public'              => true,
            'show_in_menu'        => true,
            'menu_icon'           => 'dashicons-clipboard',
            'supports'            => ['title', 'editor', 'author', 'custom-fields'],
            'taxonomies'          => ['category'],
            'has_archive'         => true,
            'rewrite'             => ['slug' => 'workorders'],
            'show_in_rest'        => true,
            'capability_type'     => ['workorder', 'workorders'],
            'map_meta_cap'        => true,
            'capabilities'        => $caps,
        ]);
    }

    public static function register_role() {
        // Create role if not exists
        if (!get_role('engineer')) {
            add_role('engineer', __('Engineer', 'dmr'), [
                'read'                          => true,
                'upload_files'                  => true,
                // workorder caps (own posts)
                'edit_workorders'               => true,
                'publish_workorders'            => true,
                'read_workorder'                => true,
                'read_private_workorders'       => true,
                'edit_published_workorders'     => true,
                'delete_workorders'             => true,
                // report viewing
                'read_workorder_reports'        => true,
            ]);
        }

        // Ensure admins can see reports
        $admin = get_role('administrator');
        if ($admin && !$admin->has_cap('read_workorder_reports')) {
            $admin->add_cap('read_workorder_reports');
            $admin->add_cap('edit_workorders');
            $admin->add_cap('edit_others_workorders');
            $admin->add_cap('publish_workorders');
            $admin->add_cap('read_private_workorders');
            $admin->add_cap('delete_workorders');
            $admin->add_cap('delete_others_workorders');
            $admin->add_cap('delete_published_workorders');
            $admin->add_cap('edit_published_workorders');
        }
    }

    public static function map_default_caps() {
        // Make sure engineer has expected caps on every load (idempotent).
        $eng = get_role('engineer');
        if ($eng) {
            $caps = [
                'read', 'upload_files',
                'edit_workorders', 'publish_workorders', 'read_workorder',
                'read_private_workorders', 'edit_published_workorders',
                'delete_workorders', 'read_workorder_reports'
            ];
            foreach ($caps as $cap) {
                if (!$eng->has_cap($cap)) {
                    $eng->add_cap($cap);
                }
            }
        }
    }

    public static function activate() {
        self::register_role();
        self::register_cpt();
        flush_rewrite_rules();
    }

    public static function deactivate() {
        flush_rewrite_rules();
    }
}
