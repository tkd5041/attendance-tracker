<?php
/**
 * Plugin Name: Attendance Tracker
 * Plugin URI: https://example.com/attendance-tracker
 * Description: A custom and comprehensive attendance tracking system for PalGroup.
 * Version: 1.0.1
 * Author: Toby Dunn For PAL
 * Author URI: https://example.com
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: attendance-tracker
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

define('ATTENDANCE_TRACKER_VERSION', '1.0.1');
define('ATTENDANCE_TRACKER_PATH', plugin_dir_path(__FILE__));
define('ATTENDANCE_TRACKER_URL', plugin_dir_url(__FILE__));

// Include necessary files
require_once ATTENDANCE_TRACKER_PATH . 'includes/class-attendance-tracker.php';
require_once ATTENDANCE_TRACKER_PATH . 'admin/dashboard.php';
require_once ATTENDANCE_TRACKER_PATH . 'includes/shortcodes.php';


// Register activation and deactivation hooks
register_activation_hook(__FILE__, 'attendance_tracker_activation');
register_deactivation_hook(__FILE__, 'attendance_tracker_deactivation');

/* function attendance_tracker_activation() {
    if (!wp_next_scheduled('attendance_tracker_weekly_check')) {
        wp_schedule_event(time(), 'weekly', 'attendance_tracker_weekly_check');
    }
}
*/

function attendance_tracker_activation() {
    global $wpdb;

    // Check if tables exist
    $stores_table = $wpdb->prefix . 'asl_stores';
    $attendance_table = $wpdb->prefix . 'pal_attendance';

    if($wpdb->get_var("SHOW TABLES LIKE '$stores_table'") != $stores_table) {
        // Table doesn't exist, so create it and insert data
        $stores_sql = file_get_contents(ATTENDANCE_TRACKER_PATH . 'wp_asl_stores.sql');
        $wpdb->query($stores_sql);
    }

    if($wpdb->get_var("SHOW TABLES LIKE '$attendance_table'") != $attendance_table) {
        // Table doesn't exist, so create it and insert data
        $attendance_sql = file_get_contents(ATTENDANCE_TRACKER_PATH . 'wp_pal_attendance.sql');
        $wpdb->query($attendance_sql);
    }

    // Create admin user if it doesn't exist
    if (!username_exists('paladmin')) {
        $admin_id = wp_create_user('paladmin', 'GetGood1!', 'palgroup.org@gmail.com');
        $admin_user = new WP_User($admin_id);
        $admin_user->set_role('administrator');
        wp_update_user(array('ID' => $admin_id, 'user_pass' => 'GetGood1!'));
    }

    // Create facilitator user if it doesn't exist
    if (!username_exists('tobydunn')) {
        $facilitator_id = wp_create_user('tobydunn', 'GetGood1!', 'toby@sprolo.com');
        $facilitator_user = new WP_User($facilitator_id);
        $facilitator_user->set_role('editor'); // Assuming 'editor' is appropriate for a facilitator
        wp_update_user(array('ID' => $facilitator_id, 'user_pass' => 'GetGood1!'));
    }

    // Schedule cron job
    if (!wp_next_scheduled('attendance_tracker_weekly_check')) {
        wp_schedule_event(time(), 'weekly', 'attendance_tracker_weekly_check');
    }
}


function attendance_tracker_deactivation() {
    wp_clear_scheduled_hook('attendance_tracker_weekly_check');
}

// Register shortcode
  add_shortcode('attendance_tracker', 'attendance_tracker_shortcode');

function attendance_tracker_enqueue_scripts() {
    // Check if we're on the correct page (you might need to adjust this condition)
    if (is_page('attendance-tracker') || is_singular('post_type_with_shortcode')) {
        wp_enqueue_style('attendance-tracker-style', ATTENDANCE_TRACKER_URL . 'assets/css/style.css', array(), ATTENDANCE_TRACKER_VERSION);
        wp_enqueue_script('attendance-tracker-script', ATTENDANCE_TRACKER_URL . 'assets/js/script.js', array('jquery'), ATTENDANCE_TRACKER_VERSION, true);
        
        // Get the current user's associated stores
        $current_user = wp_get_current_user();
        global $wpdb;
        $stores = $wpdb->get_results($wpdb->prepare(
            "SELECT id, title FROM {$wpdb->prefix}asl_stores 
            WHERE custom->'$.facilitators' REGEXP %s
            OR custom->'$.facilitators' REGEXP %s
            OR custom->'$.facilitators' = %s
            OR custom->'$.facilitators' REGEXP %s",
            '^' . $current_user->ID . '($|\\|)',
            '(^|\\|)' . $current_user->ID . '($|\\|)',
            $current_user->ID,
            '\\|' . $current_user->ID . '$'
        ));

        // If no stores found and user can edit posts, fall back to associated_store meta
        if (empty($stores) && current_user_can('edit_posts')) {
            $store_id = get_user_meta($current_user->ID, 'associated_store', true);
            if ($store_id) {
                $store = $wpdb->get_row($wpdb->prepare("SELECT id, title FROM {$wpdb->prefix}asl_stores WHERE id = %d", $store_id));
                if ($store) {
                    $stores = array($store);
                }
            }
        }

        wp_localize_script('attendance-tracker-script', 'attendance_tracker_data', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('attendance_tracker_nonce'),
            'stores' => $stores,
            'current_user_id' => $current_user->ID
        ));
    }
}
add_action('wp_enqueue_scripts', 'attendance_tracker_enqueue_scripts');


// Initialize the plugin
function run_attendance_tracker() {
    $tracker = new Attendance_Tracker();
    $tracker->run();
}
add_action('plugins_loaded', 'run_attendance_tracker');

// Admin menu and pages
add_action('admin_menu', 'attendance_tracker_admin_menu');

function attendance_tracker_admin_menu() {
    add_menu_page(
        'Attendance Tracker',
        'Attendance Tracker',
        'manage_options',
        'attendance-tracker-dashboard',
        'attendance_tracker_dashboard_page',
        'dashicons-groups',
        30
    );

    add_submenu_page(
        'attendance-tracker-dashboard',
        'Attendance Tracker Settings',
        'Settings',
        'manage_options',
        'attendance-tracker-settings',
        'attendance_tracker_settings_page'
    );

    add_submenu_page(
        'attendance-tracker-dashboard',
        'Manage Meetings',
        'Meetings',
        'manage_options',
        'attendance-tracker-meetings',
        'attendance_tracker_meetings_page'
    );
}

function attendance_tracker_settings_page() {
    ?>
    <div class="wrap">
        <h1>Attendance Tracker Settings</h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('attendance_tracker_options');
            do_settings_sections('attendance_tracker_options');
            ?>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">Enable Cron Job</th>
                    <td><input type="checkbox" name="attendance_tracker_cron_enabled" value="1" <?php checked(1, get_option('attendance_tracker_cron_enabled'), true); ?> /></td>
                </tr>
                <tr valign="top">
                    <th scope="row">Cron Job Time</th>
                    <td><input type="time" name="attendance_tracker_cron_time" value="<?php echo esc_attr(get_option('attendance_tracker_cron_time')); ?>" /></td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}

function attendance_tracker_meetings_page() {
    echo '<div class="wrap"><h1>Manage Meetings</h1><p>Meeting management interface coming soon.</p></div>';
}

// Register settings
add_action('admin_init', 'attendance_tracker_register_settings');

function attendance_tracker_register_settings() {
    register_setting('attendance_tracker_options', 'attendance_tracker_cron_enabled');
    register_setting('attendance_tracker_options', 'attendance_tracker_cron_time');
}

// AJAX handlers
add_action('wp_ajax_submit_attendance', 'attendance_tracker_submit_attendance');
add_action('wp_ajax_get_historical_data', 'attendance_tracker_get_historical_data');
add_action('wp_ajax_update_attendance', 'attendance_tracker_update_attendance');
add_action('wp_ajax_get_attendance_record', 'attendance_tracker_get_attendance_record');

function attendance_tracker_submit_attendance() {
    check_ajax_referer('attendance_tracker_nonce', 'nonce');

    $data = array(
        'store_id' => intval($_POST['store_id']),
        'meeting_date' => sanitize_text_field($_POST['meeting_date']),
        'men' => intval($_POST['men']),
        'women' => intval($_POST['women']),
        'new_attendees' => intval($_POST['new_attendees']),
        'no_meeting' => isset($_POST['no_meeting']) ? 1 : 0,
        'facilitator_id' => get_current_user_id()
    );

    $tracker = new Attendance_Tracker();
    $result = $tracker->submit_attendance($data);

    if ($result) {
        wp_send_json_success('Attendance submitted successfully');
    } else {
        wp_send_json_error('Error submitting attendance');
    }
}

function attendance_tracker_get_historical_data() {
    check_ajax_referer('attendance_tracker_nonce', 'nonce');

    $store_id = intval($_GET['store_id']);
    $page = isset($_GET['page']) ? intval($_GET['page']) : 1;

    $tracker = new Attendance_Tracker();
    $result = $tracker->get_historical_data($store_id, $page);

    if ($result) {
        wp_send_json_success($result);
    } else {
        wp_send_json_error('Error retrieving historical data');
    }
}

function attendance_tracker_update_attendance() {
    check_ajax_referer('attendance_tracker_nonce', 'nonce');

    $data = array(
        'id' => intval($_POST['id']),
        'store_id' => intval($_POST['store_id']),
        'meeting_date' => sanitize_text_field($_POST['meeting_date']),
        'men' => intval($_POST['men']),
        'women' => intval($_POST['women']),
        'new_attendees' => intval($_POST['new_attendees']),
        'no_meeting' => isset($_POST['no_meeting']) ? 1 : 0,
        'facilitator_id' => get_current_user_id()
    );

    $tracker = new Attendance_Tracker();
    $result = $tracker->update_attendance($data);

    if ($result) {
        wp_send_json_success('Attendance updated successfully');
    } else {
        wp_send_json_error('Error updating attendance');
    }
}

function attendance_tracker_get_attendance_record() {
    check_ajax_referer('attendance_tracker_nonce', 'nonce');

    $id = intval($_GET['id']);

    $tracker = new Attendance_Tracker();
    $result = $tracker->get_attendance_record($id);

    if ($result) {
        wp_send_json_success($result);
    } else {
        wp_send_json_error('Error retrieving attendance record');
    }
}

// Cron job function
add_action('attendance_tracker_weekly_check', 'run_attendance_tracker_check');

function run_attendance_tracker_check() {
    $tracker = new Attendance_Tracker();
    $tracker->check_missing_attendance_records();
}
