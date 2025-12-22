<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Cron Controller - Automated Background Tasks
 * 
 * Handles scheduled jobs for attendance sync and employee data sync
 * CLI-only access for security - blocks web requests
 * 
 * Available cron jobs:
 * - sync_attendance - Push unsynced attendance from daily_attendance table to Zoho
 * - sync_employees - Sync employee master data from Zoho People
 * 
 * Schedule examples:
 * - Attendance sync: (star)/15 * * * * php /path/to/index.php cron sync_attendance
 * - Employee sync: 0 6 * * * php /path/to/index.php cron sync_employees
 */
class Cron extends CI_Controller
{

    public function __construct()
    {
        parent::__construct();

        // Only allow CLI access
        if (!$this->input->is_cli_request()) {
            show_404();
        }

        $this->load->model('Zoho_model');
    }
    /**
     * Sync attendance records to Zoho People
     * Schedule: Every 15 minutes
     * 
     * Process:
     * - Fetches unsynced attendance records from daily_attendance
     * - Sends each record to Zoho People API
     * - Updates sync status and timestamps in database
     * - Logs detailed results including errors
     * - Sends notification email if there are failures
     * 
     * Output: Console message with sync summary
     * Command: (star)/15 * * * * php /path/to/index.php cron sync_attendance
     */
    public function sync_attendance()
    {
        echo "Starting attendance sync...\n";

        try {
            // Get unsynced attendance records from yesterday and today
            // Note: Uses daily_attendance table (different from C_zoho which uses zoho_attendance_daily)
            $unsynced = $this->db->where('synced', 0)
                ->where('work_date >=', date('Y-m-d', strtotime('-1 day')))
                ->get('daily_attendance')->result();

            if (empty($unsynced)) {
                echo "No records to sync\n";
                log_message('info', '[CRON_SYNC] No attendance records to sync');
                return;
            }

            $success = 0;
            $failed = 0;

            foreach ($unsynced as $record) {
                // Skip if missing required data (emp_id or work_date)
                if (!$record->emp_id || !$record->work_date) {
                    continue;
                }

                // Prepare attendance data for Zoho API
                // Fallback to default times if not available
                $employee_data = [
                    'empId' => $record->emp_id,
                    'date' => $record->work_date,
                    'checkIn' => $record->check_in ?? $record->first_in ?? '09:00:00',
                    'checkOut' => $record->check_out ?? $record->last_out ?? '18:00:00'
                ];

                log_message('info', '[CRON_SYNC] Sending to Zoho: Emp ' . $record->emp_id . ', Date ' . $record->work_date);

                $res = $this->Zoho_model->pushAttendance($employee_data);

                // Handle response - check for success in different response formats
                // Zoho API can return response in array[0] or directly
                $isSuccess = false;
                if (is_array($res)) {
                    if (isset($res[0]['response']) && $res[0]['response'] === 'success') {
                        $isSuccess = true;
                    } elseif (isset($res['response']) && $res['response'] === 'success') {
                        $isSuccess = true;
                    }
                }

                if ($isSuccess) {
                    // Mark as synced in database
                    $this->db->update('daily_attendance', [
                        'synced' => 1,
                        'synced_at' => date('Y-m-d H:i:s')
                    ], ['id' => $record->id]);
                    $success++;

                    log_message('info', '[CRON_SYNC] Successfully synced: Emp ' . $record->emp_id);
                } else {
                    // Extract error message from response
                    $error = 'Unknown error';
                    if (is_array($res)) {
                        $error = $res[0]['msg'] ?? $res['msg'] ?? json_encode($res);
                    }

                    // Store error in database for troubleshooting
                    $this->db->update('daily_attendance', [
                        'sync_error' => $error
                    ], ['id' => $record->id]);

                    $failed++;

                    log_message('error', '[CRON_SYNC] Failed to sync: Emp ' . $record->emp_id . ', Error: ' . $error);
                }
            }

            echo "Sync completed: $success success, $failed failed\n";

            log_message('info', '[CRON_SYNC] Completed: ' . $success . ' success, ' . $failed . ' failed');

            // Send notification if there are failures
            if ($failed > 0) {
                $this->Zoho_model->send_notification(
                    'Attendance Sync Alert',
                    "Sync completed with $failed failures out of " . ($success + $failed) . " records"
                );
            }
        } catch (Exception $e) {
            echo "Error: " . $e->getMessage() . "\n";
            log_message('error', '[CRON_SYNC] Exception: ' . $e->getMessage());
        }
    }

    /**
     * Sync employee master data from Zoho People
     * Schedule: Daily at 6 AM
     * Command: 0 6 * * * php /path/to/project/index.php cron sync_employees
     * 
     * Process:
     * - Fetches all employee records from Zoho People
     * - Updates local zoho_employees table with latest data
     * - Uses REPLACE to handle new/updated employees
     * - Logs sync results
     * 
     * Data synced:
     * - Employee ID, Name, Email
     * - Department, Designation
     * - Employee Status (Active/Inactive)
     * 
     * Output: Console message with count of updated employees
     */
    public function sync_employees()
    {
        echo "Syncing employees from Zoho...\n";

        // Fetch all employee records from Zoho People API
        $employees = $this->Zoho_model->getEmployees();

        if (!isset($employees['response']['result'])) {
            echo "No employees found\n";
            log_message('error', '[EMPLOYEE_SYNC] No employees found in Zoho response');
            return;
        }

        $updated = 0;
        foreach ($employees['response']['result'] as $emp) {
            // Use REPLACE to insert new or update existing employee records
            // REPLACE = DELETE + INSERT if record exists, otherwise just INSERT
            $this->db->replace('zoho_employees', [
                'emp_id' => $emp['Employee_ID'],
                'name' => $emp['Display_Name'],
                'email' => $emp['Email_ID'] ?? null,
                'department' => $emp['Department'] ?? null,
                'designation' => $emp['Designation'] ?? null,
                'status' => $emp['Employee_Status'] ?? 'Active',
                'updated_at' => date('Y-m-d H:i:s')
            ]);
            $updated++;
        }

        echo "Employee sync completed: $updated employees updated\n";

        log_message('info', '[EMPLOYEE_SYNC] Completed: ' . $updated . ' employees updated');
    }
}
