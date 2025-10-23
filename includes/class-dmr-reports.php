<?php
if (!defined('ABSPATH')) { exit; }

class DMR_Reports {

    /**
     * Determine report date for a workorder based on its status category.
     * Priority: Closed > Scheduled > Open/Dispatched
     * Returns Y-m-d string or empty string if none.
     */
    public static function compute_report_date($post_id) {
        $post_id = (int) $post_id;
        if (!$post_id) { return ''; }

        // Terms by slug & name (case-insensitive)
        $terms = wp_get_post_terms($post_id, 'category', ['fields' => 'all']);
        $names = [];
        $slugs = [];
        foreach ($terms as $t) {
            $names[] = strtolower($t->name);
            $slugs[] = strtolower($t->slug);
        }
        $has = function($needle) use ($names, $slugs) {
            $needle = strtolower($needle);
            return in_array($needle, $names, true) || in_array($needle, $slugs, true);
        };

        // Choose which ACF field to read
        $field_key = '';
        if ($has('closed')) {
            $field_key = 'closed_on';
        } elseif ($has('scheduled')) {
            $field_key = 'schedule_date_time';
        } elseif ($has('open') || $has('dispatched')) {
            $field_key = 'date_requested_by_customer';
        } else {
            // Fallback: try requested date first, then schedule, then closed
            $field_key = 'date_requested_by_customer';
        }

        $raw = function_exists('get_field') ? get_field($field_key, $post_id) : get_post_meta($post_id, $field_key, true);
        $date = self::parse_any_date($raw);
        if (!$date) {
            // ultimate fallback: WP post_date
            $pd = get_post_field('post_date', $post_id);
            $date = self::parse_any_date($pd);
        }
        return $date ? $date->format('Y-m-d') : '';
    }

    /**
     * Parse various date formats from ACF (Ymd, Y-m-d, m/d/Y, datetime, etc.) into DateTime (site timezone).
     */
    public static function parse_any_date($val) {
        if (!$val) return null;
        $tz = wp_timezone();
        if (is_numeric($val) && preg_match('/^\d{8}$/', (string)$val)) {
            $dt = DateTime::createFromFormat('Ymd', (string)$val, $tz);
            return $dt ?: null;
        }
        if (is_string($val)) {
            // Common ACF outputs
            $formats = ['Y-m-d H:i:s', 'Y-m-d', 'm/d/Y', 'm/d/Y H:i', 'd/m/Y', 'd/m/Y H:i', DateTime::RFC3339, 'Ymd'];
            foreach ($formats as $fmt) {
                $dt = DateTime::createFromFormat($fmt, $val, $tz);
                if ($dt) return $dt;
            }
            $ts = strtotime($val);
            if ($ts !== false) {
                $dt = new DateTime('@' . $ts);
                $dt->setTimezone($tz);
                return $dt;
            }
        }
        if ($val instanceof DateTimeInterface) {
            return (new DateTime($val->format('c')))->setTimezone($tz);
        }
        return null;
    }

    /**
     * Save or refresh dmr_report_date meta for a workorder.
     */
    public static function sync_report_date($post_id) {
        $post_type = get_post_type($post_id);
        if ($post_type !== 'workorder') return;
        if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) return;

        $date = self::compute_report_date($post_id);
        if ($date) {
            update_post_meta($post_id, 'dmr_report_date', $date);
        } else {
            delete_post_meta($post_id, 'dmr_report_date');
        }
    }

    /**
     * Hook from ACF save stream. $post_id could be "post_123".
     */
    public static function sync_report_date_from_acf($acf_post_id) {
        if (is_numeric($acf_post_id)) {
            $pid = (int) $acf_post_id;
        } elseif (is_string($acf_post_id) && strpos($acf_post_id, 'post_') === 0) {
            $pid = (int) substr($acf_post_id, 5);
        } else {
            return;
        }
        if ($pid && get_post_type($pid) === 'workorder') {
            self::sync_report_date($pid);
        }
    }

    /**
     * Get available years from dmr_report_date meta.
     */
    public static function get_available_years() {
        global $wpdb;
        $meta_key = 'dmr_report_date';
        $sql = $wpdb->prepare(
            "SELECT DISTINCT LEFT(pm.meta_value, 4) AS y
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = %s
               AND p.post_type = %s
               AND p.post_status IN ('publish','private')
             ORDER BY y DESC",
            $meta_key, 'workorder'
        );
        $years = $wpdb->get_col($sql);
        if (empty($years)) {
            $years = [ current_time('Y') ];
        }
        return array_map('intval', $years);
    }

    /**
     * Fetch all workorders whose dmr_report_date is in the given year.
     */
    public static function query_posts_for_year($year) {
        $year = (int)$year;
        $start = sprintf('%04d-01-01', $year);
        $end   = sprintf('%04d-12-31', $year);
        $q = new WP_Query([
            'post_type'      => 'workorder',
            'posts_per_page' => -1,
            'post_status'    => ['publish','private'],
            'fields'         => 'ids',
            'meta_query'     => [[
                'key'     => 'dmr_report_date',
                'value'   => [$start, $end],
                'compare' => 'BETWEEN',
                'type'    => 'DATE',
            ]],
            'no_found_rows'  => true,
        ]);

        $ids = $q->posts;

        // In case older items lack the cached meta, compute on the fly (soft backfill)
        if (empty($ids)) {
            $fallback = get_posts([
                'post_type' => 'workorder',
                'numberposts' => -1,
                'post_status' => ['publish','private'],
                'fields' => 'ids',
            ]);
            $ids = [];
            foreach ($fallback as $pid) {
                $date = get_post_meta($pid, 'dmr_report_date', true);
                if (!$date) {
                    self::sync_report_date($pid);
                    $date = get_post_meta($pid, 'dmr_report_date', true);
                }
                if ($date && $date >= $start && $date <= $end) {
                    $ids[] = $pid;
                }
            }
        }

        return $ids;
    }

    /**
     * Aggregate monthly counts per engineer for a set of workorder IDs.
     * Returns [ engineer_id => ['name'=>..., 'months'=>[1..12], 'total'=>N] ]
     */
    public static function aggregate_monthly_by_engineer(array $post_ids) {
        $rows = [];
        foreach ($post_ids as $pid) {
            $author = (int) get_post_field('post_author', $pid);
            if (!$author) continue;

            $date = get_post_meta($pid, 'dmr_report_date', true);
            if (!$date) {
                $date = self::compute_report_date($pid);
            }
            if (!$date) continue;

            $month = (int) date('n', strtotime($date));
            if (!isset($rows[$author])) {
                $user = get_user_by('id', $author);
                $rows[$author] = [
                    'name'   => $user ? $user->display_name : ("User #".$author),
                    'months' => array_fill(1, 12, 0),
                    'total'  => 0,
                ];
            }
            $rows[$author]['months'][$month] += 1;
            $rows[$author]['total'] += 1;
        }

        // Also include engineers with 0s so they show in the table
        $engineers = get_users(['role' => 'engineer', 'fields' => ['ID','display_name']]);
        foreach ($engineers as $u) {
            if (!isset($rows[$u->ID])) {
                $rows[$u->ID] = [
                    'name'   => $u->display_name,
                    'months' => array_fill(1, 12, 0),
                    'total'  => 0,
                ];
            }
        }

        // Sort by display name asc
        uasort($rows, function($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });

        return $rows;
    }

    /**
     * Compute totals by state (ACF: wo_state) for a set of posts.
     * Returns [ state => count ]
     */
    public static function aggregate_by_state(array $post_ids) {
        $states = [];
        foreach ($post_ids as $pid) {
            $state = function_exists('get_field') ? get_field('wo_state', $pid) : get_post_meta($pid, 'wo_state', true);
            $state = is_string($state) ? trim($state) : '';
            if ($state === '') $state = '(Unspecified)';
            if (!isset($states[$state])) $states[$state] = 0;
            $states[$state] += 1;
        }
        ksort($states);
        return $states;
    }

    /**
     * Get HTML for a user's ACF profile_picture below name.
     */
    public static function get_engineer_thumbnail_html($user_id) {
        $user_id = (int)$user_id;
        $url = '';
        if (function_exists('get_field')) {
            $img = get_field('profile_picture', 'user_' . $user_id);
            if (is_array($img) && !empty($img['sizes']['thumbnail'])) {
                $url = $img['sizes']['thumbnail'];
            } elseif (is_numeric($img)) {
                $url = wp_get_attachment_image_url($img, 'thumbnail');
            }
        }
        if (!$url) {
            // Fallback to WP avatar
            return get_avatar($user_id, 48);
        }
        $alt = esc_attr(get_the_author_meta('display_name', $user_id));
        return sprintf('<img class="dmr-avatar" src="%s" alt="%s" width="48" height="48" />', esc_url($url), $alt);
    }

    /**
     * Utility: draw a year filter dropdown.
     */
    public static function render_year_filter($selected_year, $action_slug) {
        $years = self::get_available_years();
        echo '<form method="get" class="dmr-year-filter" action="">';
        // Preserve page hook slug for submenu routing
        printf('<input type="hidden" name="page" value="%s" />', esc_attr($action_slug));
        echo '<label for="dmr_year" style="margin-right:8px;">Year:</label>';
        echo '<select name="dmr_year" id="dmr_year">';
        foreach ($years as $y) {
            printf('<option value="%d" %s>%d</option>', $y, selected($selected_year, $y, false), $y);
        }
        echo '</select> ';
        submit_button(__('Filter'), 'secondary', '', false);
        echo '</form>';
    }
}
