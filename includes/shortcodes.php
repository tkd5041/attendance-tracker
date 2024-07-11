<?php
function attendance_tracker_shortcode($atts) {
    $atts = shortcode_atts(
        array(
            'type' => 'default',
        ),
        $atts,
        'attendance_tracker'
    );

    ob_start();
    include ATTENDANCE_TRACKER_PATH . 'templates/attendance-tracker-display.php';
    return ob_get_clean();
}
add_shortcode('attendance_tracker', 'attendance_tracker_shortcode');

