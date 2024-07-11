<?php

class Attendance_Tracker {

    private $wpdb;

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
    }

    public function run() {
        // Initialize any necessary hooks or actions here
    }

    public function submit_attendance($data) {
        $table_name = $this->wpdb->prefix . 'pal_attendance';

        $result = $this->wpdb->insert(
            $table_name,
            array(
                'store_id' => $data['store_id'],
                'meeting_date' => $data['meeting_date'],
                'week_number' => date('W', strtotime($data['meeting_date'])),
                'men' => $data['men'],
                'women' => $data['women'],
                'new_attendees' => $data['new_attendees'],
                'total_attendees' => $data['men'] + $data['women'],
                'no_meeting' => isset($data['no_meeting']) ? 1 : 0,
                'facilitator_id' => $data['facilitator_id']
            ),
            array('%d', '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%d')
        );

        return $result;
    }

    public function get_historical_data($store_id, $page = 1, $per_page = 15) {
        $table_name = $this->wpdb->prefix . 'pal_attendance';
        $offset = ($page - 1) * $per_page;

        $query = $this->wpdb->prepare(
            "SELECT * FROM $table_name WHERE store_id = %d ORDER BY meeting_date DESC LIMIT %d OFFSET %d",
            $store_id, $per_page, $offset
        );

        $results = $this->wpdb->get_results($query);

        $total_query = $this->wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name WHERE store_id = %d",
            $store_id
        );

        $total = $this->wpdb->get_var($total_query);
        $total_pages = ceil($total / $per_page);

        return array(
            'data' => $results,
            'pages' => $total_pages
        );
    }

    public function update_attendance($data) {
        $table_name = $this->wpdb->prefix . 'pal_attendance';

        $result = $this->wpdb->update(
            $table_name,
            array(
                'men' => $data['men'],
                'women' => $data['women'],
                'new_attendees' => $data['new_attendees'],
                'total_attendees' => $data['men'] + $data['women'],
                'no_meeting' => isset($data['no_meeting']) ? 1 : 0,
                'facilitator_id' => $data['facilitator_id']
            ),
            array('id' => $data['id']),
            array('%d', '%d', '%d', '%d', '%d', '%d'),
            array('%d')
        );

        return $result;
    }

    public function get_attendance_record($id) {
        $table_name = $this->wpdb->prefix . 'pal_attendance';

        $query = $this->wpdb->prepare(
            "SELECT * FROM $table_name WHERE id = %d",
            $id
        );

        return $this->wpdb->get_row($query);
    }

    public function check_missing_attendance_records() {
        $stores = $this->get_all_stores();
        $current_week = date('W');
        $current_year = date('Y');

        foreach ($stores as $store) {
            $custom = json_decode($store->custom, true);
            $meeting_day = $custom['meeting_day'];
            $status = $custom['meeting_status'];
            $facilitators = explode('|', $custom['facilitators']);

            $last_meeting_date = date('Y-m-d', strtotime("last $meeting_day"));

            $record_exists = $this->wpdb->get_var($this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->wpdb->prefix}pal_attendance 
                WHERE store_id = %d AND meeting_date = %s",
                $store->id, $last_meeting_date
            ));

            if (!$record_exists) {
                $this->create_missing_record($store, $last_meeting_date, $status, $facilitators[0]);

                if (date('W', strtotime($last_meeting_date)) == $current_week - 1) {
                    $this->send_reminder_email($facilitators[0], $store);
                }
            }
        }
    }

    private function get_all_stores() {
        return $this->wpdb->get_results("SELECT * FROM {$this->wpdb->prefix}asl_stores");
    }

    private function create_missing_record($store, $meeting_date, $status, $facilitator_id) {
        $this->wpdb->insert(
            $this->wpdb->prefix . 'pal_attendance',
            array(
                'store_id' => $store->id,
                'meeting_date' => $meeting_date,
                'week_number' => date('W', strtotime($meeting_date)),
                'men' => null,
                'women' => null,
                'new_attendees' => null,
                'total_attendees' => null,
                'no_meeting' => ($status === 'inactive' || $status === 'on hold') ? 1 : 0,
                'facilitator_id' => $facilitator_id
            ),
            array('%d', '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%d')
        );
    }

    private function send_reminder_email($facilitator_id, $store) {
        $facilitator = get_userdata($facilitator_id);
        $subject = "Reminder: Update attendance for {$store->title}";
        $message = "Dear {$facilitator->display_name},\n\n";
        $message .= "This is a reminder to update the attendance record for {$store->title} for last week's meeting. ";
        $message .= "Please log in to the Attendance Tracker and fill in the missing information.\n\n";
        $message .= "Don't forget to review and address any other meetings highlighted in yellow.\n\n";
        $message .= "Best regards,\nAttendance Tracker System";

        wp_mail($facilitator->user_email, $subject, $message);
    }
}
