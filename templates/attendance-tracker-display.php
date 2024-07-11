<?php
// Ensure this file is being included by a parent file
if ( ! defined( 'ABSPATH' ) ) exit;

// Get the current user
$current_user = wp_get_current_user();

// Check if the user is logged in
if ( !is_user_logged_in() ) {
    echo '<p>You must be logged in to access this page.</p>';
    return;
}

global $wpdb;

// Get all stores where the user is a facilitator
$stores = $wpdb->get_results($wpdb->prepare(
    "SELECT id, title, custom FROM {$wpdb->prefix}asl_stores 
    WHERE custom->'$.facilitators' REGEXP %s
    OR custom->'$.facilitators' REGEXP %s
    OR custom->'$.facilitators' = %s
    OR custom->'$.facilitators' REGEXP %s",
    '^' . $current_user->ID . '($|\\|)',
    '(^|\\|)' . $current_user->ID . '($|\\|)',
    $current_user->ID,
    '\\|' . $current_user->ID . '$'
));

if (empty($stores) && !current_user_can('edit_posts')) {
    echo '<p>You are not associated with any store. Please contact an administrator.</p>';
    return;
}

// If the user is associated with multiple stores, let them choose
if (count($stores) > 1) {
    if (isset($_POST['store_select'])) {
        $store_id = intval($_POST['store_select']);
        $store = array_filter($stores, function($s) use ($store_id) { return $s->id == $store_id; });
        $store = reset($store);
        if (!$store) {
            echo '<p>Invalid store selection.</p>';
            return;
        }
    } else {
        echo '<form method="post">';
        echo '<select name="store_select">';
        foreach ($stores as $s) {
            echo '<option value="' . esc_attr($s->id) . '">' . esc_html($s->title) . '</option>';
        }
        echo '</select>';
        echo '<input type="submit" value="Select Store">';
        echo '</form>';
        return;
    }
} elseif (count($stores) == 1) {
    $store = $stores[0];
} else {
    // Fallback to the original logic for administrators
    $store_id = get_user_meta($current_user->ID, 'associated_store', true);
    if (!$store_id) {
        echo '<p>You are not associated with any store. Please contact an administrator.</p>';
        return;
    }
    $store = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}asl_stores WHERE id = %d", $store_id));
    if (!$store) {
        echo '<p>Associated store not found. Please contact an administrator.</p>';
        return;
    }
}

$store_id = $store->id;
$custom = json_decode($store->custom, true);
$meeting_day = $custom['meeting_day'] ?? 'Monday';
?>

<div id="attendance-tracker-container">
    <h2>Attendance Tracker for <?php echo esc_html($store->title); ?></h2>
    
    <div id="attendance-form">
        <h3>Submit Attendance</h3>
        <form id="submit-attendance-form">
            <input type="hidden" name="store_id" value="<?php echo esc_attr($store_id); ?>">
            <input type="hidden" name="facilitator_id" value="<?php echo esc_attr($current_user->ID); ?>">
            
            <label for="meeting_date">Meeting Date:</label>
            <input type="date" id="meeting_date" name="meeting_date" required>
            
            <label for="men">Men:</label>
            <input type="number" id="men" name="men" min="0" required>
            
            <label for="women">Women:</label>
            <input type="number" id="women" name="women" min="0" required>
            
            <label for="new_attendees">New Attendees:</label>
            <input type="number" id="new_attendees" name="new_attendees" min="0" required>
            
            <label for="no_meeting">No Meeting:</label>
            <input type="checkbox" id="no_meeting" name="no_meeting">
            
            <button type="submit">Submit Attendance</button>
        </form>
    </div>
    
    <div id="historical-data">
        <h3>Historical Data</h3>
        <table id="attendance-history">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Men</th>
                    <th>Women</th>
                    <th>New Attendees</th>
                    <th>Total</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <!-- Data will be populated via AJAX -->
            </tbody>
        </table>
        <div id="pagination">
            <!-- Pagination will be populated via AJAX -->
        </div>
    </div>
    
    <div id="edit-attendance-modal" style="display: none;">
        <h3>Edit Attendance</h3>
        <form id="edit-attendance-form">
            <input type="hidden" name="id" id="edit-id">
            <input type="hidden" name="store_id" id="edit-store-id">
            <input type="hidden" name="facilitator_id" id="edit-facilitator-id">
            
            <label for="edit-meeting-date">Meeting Date:</label>
            <input type="date" id="edit-meeting-date" name="meeting_date" required readonly>
            
            <label for="edit-men">Men:</label>
            <input type="number" id="edit-men" name="men" min="0" required>
            
            <label for="edit-women">Women:</label>
            <input type="number" id="edit-women" name="women" min="0" required>
            
            <label for="edit-new-attendees">New Attendees:</label>
            <input type="number" id="edit-new-attendees" name="new_attendees" min="0" required>
            
            <label for="edit-no-meeting">No Meeting:</label>
            <input type="checkbox" id="edit-no-meeting" name="no_meeting">
            
            <button type="submit">Update Attendance</button>
            <button type="button" id="cancel-edit">Cancel</button>
        </form>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    // Set the default meeting date to the most recent <?php echo $meeting_day; ?>
    var today = new Date();
    var day = today.getDay();
    var diff = today.getDate() - day + (day === 0 ? -6 : 1); // Adjust when day is Sunday
    var lastMonday = new Date(today.setDate(diff));
    $('#meeting_date').val(lastMonday.toISOString().split('T')[0]);

    // Load historical data
    loadHistoricalData(1);

    // Submit attendance form
    $('#submit-attendance-form').on('submit', function(e) {
        e.preventDefault();
        $.ajax({
            url: attendance_tracker_data.ajax_url,
            type: 'POST',
            data: {
                action: 'submit_attendance',
                nonce: attendance_tracker_data.nonce,
                ...$(this).serialize()
            },
            success: function(response) {
                if (response.success) {
                    alert('Attendance submitted successfully');
                    loadHistoricalData(1);
                } else {
                    alert('Error submitting attendance');
                }
            }
        });
    });

    // Load historical data
    function loadHistoricalData(page) {
        $.ajax({
            url: attendance_tracker_data.ajax_url,
            type: 'GET',
            data: {
                action: 'get_historical_data',
                nonce: attendance_tracker_data.nonce,
                store_id: <?php echo $store_id; ?>,
                page: page
            },
            success: function(response) {
                if (response.success) {
                    displayHistoricalData(response.data.data);
                    displayPagination(response.data.pages, page);
                } else {
                    alert('Error loading historical data');
                }
            }
        });
    }

    // Display historical data
    function displayHistoricalData(data) {
        var tbody = $('#attendance-history tbody');
        tbody.empty();
        data.forEach(function(record) {
            var row = $('<tr>');
            row.append($('<td>').text(record.meeting_date));
            row.append($('<td>').text(record.men));
            row.append($('<td>').text(record.women));
            row.append($('<td>').text(record.new_attendees));
            row.append($('<td>').text(record.total_attendees));
            row.append($('<td>').html('<button class="edit-attendance" data-id="' + record.id + '">Edit</button>'));
            tbody.append(row);
        });
    }

    // Display pagination
    function displayPagination(totalPages, currentPage) {
        var pagination = $('#pagination');
        pagination.empty();
        for (var i = 1; i <= totalPages; i++) {
            var pageLink = $('<a href="#" class="page-link">').text(i);
            if (i === currentPage) {
                pageLink.addClass('current');
            }
            pageLink.on('click', function(e) {
                e.preventDefault();
                loadHistoricalData($(this).text());
            });
            pagination.append(pageLink);
        }
    }

    // Edit attendance
    $(document).on('click', '.edit-attendance', function() {
        var id = $(this).data('id');
        $.ajax({
            url: attendance_tracker_data.ajax_url,
            type: 'GET',
            data: {
                action: 'get_attendance_record',
                nonce: attendance_tracker_data.nonce,
                id: id
            },
            success: function(response) {
                if (response.success) {
                    populateEditForm(response.data);
                    $('#edit-attendance-modal').show();
                } else {
                    alert('Error loading attendance record');
                }
            }
        });
    });

    // Populate edit form
    function populateEditForm(data) {
        $('#edit-id').val(data.id);
        $('#edit-store-id').val(data.store_id);
        $('#edit-facilitator-id').val(data.facilitator_id);
        $('#edit-meeting-date').val(data.meeting_date);
        $('#edit-men').val(data.men);
        $('#edit-women').val(data.women);
        $('#edit-new-attendees').val(data.new_attendees);
        $('#edit-no-meeting').prop('checked', data.no_meeting == 1);
    }

    // Update attendance
    $('#edit-attendance-form').on('submit', function(e) {
        e.preventDefault();
        $.ajax({
            url: attendance_tracker_data.ajax_url,
            type: 'POST',
            data: {
                action: 'update_attendance',
                nonce: attendance_tracker_data.nonce,
                ...$(this).serialize()
            },
            success: function(response) {
                if (response.success) {
                    alert('Attendance updated successfully');
                    $('#edit-attendance-modal').hide();
                    loadHistoricalData(1);
                } else {
                    alert('Error updating attendance');
                }
            }
        });
    });

    // Cancel edit
    $('#cancel-edit').on('click', function() {
        $('#edit-attendance-modal').hide();
    });
});
</script>
