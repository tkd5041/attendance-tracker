<?php
// Ensure this file is being included by a parent file
if (!defined('ABSPATH')) exit;

// Get current user
//$current_user = wp_get_current_user();

function attendance_tracker_dashboard_page() {
    $current_user = wp_get_current_user();
    // Rest of your dashboard code here
}

// Define date ranges
$date_ranges = array(
    'this-year' => array(
        'label' => 'This Year',
        'start' => date('Y-01-01'),
        'end' => date('Y-12-31')
    ),
    'last-year' => array(
        'label' => 'Last Year',
        'start' => date('Y-01-01', strtotime('-1 year')),
        'end' => date('Y-12-31', strtotime('-1 year'))
    ),
    'this-quarter' => array(
        'label' => 'This Quarter',
        'start' => date('Y-m-d', strtotime('first day of this quarter')),
        'end' => date('Y-m-d', strtotime('last day of this quarter'))
    ),
    'last-quarter' => array(
        'label' => 'Last Quarter',
        'start' => date('Y-m-d', strtotime('first day of last quarter')),
        'end' => date('Y-m-d', strtotime('last day of last quarter'))
    )
);

// Handle custom date range submission
if (isset($_POST['custom_date_range'])) {
    $start_date = sanitize_text_field($_POST['start_date']);
    $end_date = sanitize_text_field($_POST['end_date']);
    $date_ranges['custom'] = array(
        'label' => 'Custom Range',
        'start' => $start_date,
        'end' => $end_date
    );
}

// Get selected date range
$selected_range = isset($_GET['date_range']) ? $_GET['date_range'] : 'this-year';
$current_range = $date_ranges[$selected_range];

// Function to get attendance data for a given date range
function get_attendance_data($start_date, $end_date) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'pal_attendance';
    
    $total_meetings = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $table_name WHERE meeting_date BETWEEN %s AND %s AND no_meeting = 0",
        $start_date, $end_date
    ));
    
    $total_attendees = $wpdb->get_var($wpdb->prepare(
        "SELECT SUM(total_attendees) FROM $table_name WHERE meeting_date BETWEEN %s AND %s",
        $start_date, $end_date
    ));
    
    $average_attendance = $total_meetings > 0 ? round($total_attendees / $total_meetings, 2) : 0;
    
    return array(
        'meetings' => $total_meetings, 
        'attendees' => $total_attendees,
        'average' => $average_attendance
    );
}

// Get attendance data for the selected range
$attendance_data = get_attendance_data($current_range['start'], $current_range['end']);
?>

<div class="wrap">
    <h1>Attendance Tracker Dashboard</h1>
    
    <div class="date-range-selector">
        <form method="get">
            <input type="hidden" name="page" value="attendance-tracker-dashboard">
            <select name="date_range" onchange="this.form.submit()">
                <?php foreach ($date_ranges as $key => $range): ?>
                    <option value="<?php echo esc_attr($key); ?>" <?php selected($selected_range, $key); ?>>
                        <?php echo esc_html($range['label']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>

    <div class="custom-date-range">
        <form method="post">
            <label for="start_date">Start Date:</label>
            <input type="date" id="start_date" name="start_date" required>
            <label for="end_date">End Date:</label>
            <input type="date" id="end_date" name="end_date" required>
            <input type="submit" name="custom_date_range" value="Apply Custom Range" class="button">
        </form>
    </div>

    <div id="dashboard-widgets-wrap">
        <div id="dashboard-widgets" class="metabox-holder">
            <div id="postbox-container-1" class="postbox-container">
                <div class="meta-box-sortables">
                    <div class="postbox">
                        <h2 class="hndle"><span>Attendance Overview (<?php echo esc_html($current_range['label']); ?>)</span></h2>
                        <div class="inside">
                            <p>Total Meetings: <?php echo esc_html($attendance_data['meetings']); ?></p>
                            <p>Total Attendees: <?php echo esc_html($attendance_data['attendees']); ?></p>
                            <p>Average Attendance: <?php echo esc_html($attendance_data['average']); ?></p>
                        </div>
                    </div>
                    
                    <div class="postbox">
                        <h2 class="hndle"><span>Recent Activities</span></h2>
                        <div class="inside">
                            <?php
                            $recent_activities = get_recent_activities(5);
                            if (!empty($recent_activities)) {
                                echo '<ul>';
                                foreach ($recent_activities as $activity) {
                                    echo '<li>' . esc_html($activity) . '</li>';
                                }
                                echo '</ul>';
                            } else {
                                echo '<p>No recent activities.</p>';
                            }
                            ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <div id="postbox-container-2" class="postbox-container">
                <div class="meta-box-sortables">
                    <div class="postbox">
                        <h2 class="hndle"><span>Quick Actions</span></h2>
                        <div class="inside">
                            <p><a href="<?php echo admin_url('admin.php?page=attendance-tracker-meetings'); ?>" class="button">Manage Meetings</a></p>
                            <p><a href="<?php echo admin_url('admin.php?page=attendance-tracker-settings'); ?>" class="button">Plugin Settings</a></p>
                        </div>
                    </div>
                    
                    <div class="postbox">
                        <h2 class="hndle"><span>System Status</span></h2>
                        <div class="inside">
                            <?php
                            $cron_enabled = get_option('attendance_tracker_cron_enabled');
                            $cron_time = get_option('attendance_tracker_cron_time');
                            ?>
                            <p>Cron Job: <?php echo $cron_enabled ? 'Enabled' : 'Disabled'; ?></p>
                            <p>Cron Time: <?php echo $cron_time ? esc_html($cron_time) : 'Not set'; ?></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    $('.meta-box-sortables').sortable({
        opacity: 0.6,
        stop: function() {
            // You could save the order to user meta here
        }
    });
});
</script>

<?php
function get_recent_activities($limit = 5) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'pal_attendance';
    
    $activities = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $table_name ORDER BY meeting_date DESC LIMIT %d",
        $limit
    ));
    
    $formatted_activities = array();
    foreach ($activities as $activity) {
        $store = get_store_name($activity->store_id);
        $formatted_activities[] = sprintf(
            "%s: %s attendees at %s",
            $activity->meeting_date,
            $activity->total_attendees,
            $store
        );
    }
    
    return $formatted_activities;
}

function get_store_name($store_id) {
    global $wpdb;
    $store_name = $wpdb->get_var($wpdb->prepare(
        "SELECT title FROM {$wpdb->prefix}asl_stores WHERE id = %d",
        $store_id
    ));
    return $store_name ? $store_name : 'Unknown Store';
}
?>
