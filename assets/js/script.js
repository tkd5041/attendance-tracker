jQuery(document).ready(function($) {
    var AttendanceTracker = {
        init: function() {
            this.cacheDom();
            this.setupStoreSelector();
            this.bindEvents();
            this.setDefaultDate();
            if (this.currentStoreId) {
                this.loadHistoricalData(1);
            }
        },

        cacheDom: function() {
            this.$container = $('#attendance-tracker-container');
            this.$submitForm = $('#submit-attendance-form');
            this.$editForm = $('#edit-attendance-form');
            this.$editModal = $('#edit-attendance-modal');
            this.$cancelEdit = $('#cancel-edit');
            this.$historyTable = $('#attendance-history');
            this.$pagination = $('#pagination');
            this.$meetingDate = $('#meeting_date');
            this.$storeSelector = $('<select id="store-selector"></select>');
            this.$container.prepend(this.$storeSelector);
        },

        setupStoreSelector: function() {
            if (attendance_tracker_data.stores.length > 1) {
                attendance_tracker_data.stores.forEach(function(store) {
                    this.$storeSelector.append($('<option></option>').attr('value', store.id).text(store.title));
                }.bind(this));
                this.$storeSelector.show();
                this.currentStoreId = this.$storeSelector.val();
            } else if (attendance_tracker_data.stores.length === 1) {
                this.currentStoreId = attendance_tracker_data.stores[0].id;
                this.$storeSelector.hide();
            } else {
                alert('You are not associated with any stores.');
                this.$storeSelector.hide();
            }
        },

        bindEvents: function() {
            this.$submitForm.on('submit', this.submitAttendance.bind(this));
            this.$editForm.on('submit', this.updateAttendance.bind(this));
            this.$cancelEdit.on('click', this.hideEditModal.bind(this));
            this.$historyTable.on('click', '.edit-attendance', this.editAttendance.bind(this));
            this.$pagination.on('click', '.page-link', this.changePage.bind(this));
            this.$storeSelector.on('change', this.onStoreChange.bind(this));
        },

        setDefaultDate: function() {
            var today = new Date();
            var day = today.getDay();
            var diff = today.getDate() - day + (day === 0 ? -6 : 1); // Adjust when day is Sunday
            var lastMonday = new Date(today.setDate(diff));
            this.$meetingDate.val(lastMonday.toISOString().split('T')[0]);
        },

        onStoreChange: function() {
            this.currentStoreId = this.$storeSelector.val();
            this.loadHistoricalData(1);
        },

        submitAttendance: function(e) {
            e.preventDefault();
            var formData = this.$submitForm.serialize();
            formData += '&store_id=' + this.currentStoreId;
            $.ajax({
                url: attendance_tracker_data.ajax_url,
                type: 'POST',
                data: {
                    action: 'submit_attendance',
                    nonce: attendance_tracker_data.nonce,
                    ...formData
                },
                success: function(response) {
                    if (response.success) {
                        alert('Attendance submitted successfully');
                        this.loadHistoricalData(1);
                    } else {
                        alert('Error submitting attendance');
                    }
                }.bind(this)
            });
        },

        loadHistoricalData: function(page) {
            $.ajax({
                url: attendance_tracker_data.ajax_url,
                type: 'GET',
                data: {
                    action: 'get_historical_data',
                    nonce: attendance_tracker_data.nonce,
                    store_id: this.currentStoreId,
                    page: page
                },
                success: function(response) {
                    if (response.success) {
                        this.displayHistoricalData(response.data.data);
                        this.displayPagination(response.data.pages, page);
                    } else {
                        alert('Error loading historical data');
                    }
                }.bind(this)
            });
        },

        displayHistoricalData: function(data) {
            var tbody = this.$historyTable.find('tbody');
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
        },

        displayPagination: function(totalPages, currentPage) {
            this.$pagination.empty();
            for (var i = 1; i <= totalPages; i++) {
                var pageLink = $('<a href="#" class="page-link">').text(i);
                if (i === currentPage) {
                    pageLink.addClass('current');
                }
                pageLink.data('page', i);
                this.$pagination.append(pageLink);
            }
        },

        changePage: function(e) {
            e.preventDefault();
            var page = $(e.target).data('page');
            this.loadHistoricalData(page);
        },

        editAttendance: function(e) {
            var id = $(e.target).data('id');
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
                        this.populateEditForm(response.data);
                        this.$editModal.show();
                    } else {
                        alert('Error loading attendance record');
                    }
                }.bind(this)
            });
        },

        populateEditForm: function(data) {
            $('#edit-id').val(data.id);
            $('#edit-store-id').val(data.store_id);
            $('#edit-facilitator-id').val(data.facilitator_id);
            $('#edit-meeting-date').val(data.meeting_date);
            $('#edit-men').val(data.men);
            $('#edit-women').val(data.women);
            $('#edit-new-attendees').val(data.new_attendees);
            $('#edit-no-meeting').prop('checked', data.no_meeting == 1);
        },

        updateAttendance: function(e) {
            e.preventDefault();
            $.ajax({
                url: attendance_tracker_data.ajax_url,
                type: 'POST',
                data: {
                    action: 'update_attendance',
                    nonce: attendance_tracker_data.nonce,
                    ...this.$editForm.serialize()
                },
                success: function(response) {
                    if (response.success) {
                        alert('Attendance updated successfully');
                        this.hideEditModal();
                        this.loadHistoricalData(1);
                    } else {
                        alert('Error updating attendance');
                    }
                }.bind(this)
            });
        },

        hideEditModal: function() {
            this.$editModal.hide();
        }
    };

    AttendanceTracker.init();
});
