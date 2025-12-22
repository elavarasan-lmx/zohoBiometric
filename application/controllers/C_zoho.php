<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * C_zoho Controller - Zoho People Integration & Dashboard
 * 
 * Complete biometric attendance system with Zoho People API integration
 * Handles ZKTeco device data, employee sync, attendance import/export, and real-time monitoring
 * 
 * Available endpoints:
 * - /C_zoho/sync_attendance - Push unsynced records for specific date (defaults to today)
 * - /C_zoho/push_attendance - Push attendance with flexible date options
 * - /C_zoho/sync_employees - Sync employee master data from Zoho People
 * - /C_zoho/import_attendance - Import attendance from Zoho for single employee
 * - /C_zoho/import_all_attendance - Bulk import attendance for all employees
 * - /index.php/C_zoho/dashboard - Real-time attendance dashboard with date filtering
 * - /C_zoho/cleanup_future_dates - Remove invalid future date records
 * 
 * Key Features:
 * - Bulk processing: Import entire months of data (?from=2024-12-01&to=2024-12-31)
 * - Future date validation: Automatically skips dates beyond today
 * - Placeholder data filtering: Rejects identical check-in/check-out times
 * - No timeout limits: Handles large datasets with unlimited execution time
 * - Progress tracking: Logs every 50 records for monitoring
 * - Idempotent operations: Safe to run multiple times (updates existing records)
 * - Enhanced cURL timeouts: 60-second API timeouts for reliability
 * - Comprehensive logging: Detailed logs for all operations
 * - Email notifications: Alerts for sync failures
 * - Performance optimized: Limited dashboard queries for fast response
 * 
 * Database Integration:
 * - zoho_employees: Employee master data from Zoho People
 * - zoho_attendance_daily: Processed daily attendance (first_in/last_out)
 * - zoho_attendance_raw: Raw biometric punch data from ZKTeco devices
 * - Automatic duplicate prevention and data validation
 * 
 * Production Ready:
 * - SSL certificate bypass for development environments
 * - In-memory OAuth token caching for performance
 * - Comprehensive error handling and recovery
 * - Rate limiting awareness for API calls
 */
class C_zoho extends CI_Controller
{

    public function __construct()
    {
        parent::__construct();
        $this->load->model('Zoho_model');
    }

    /**
     * Manual employee sync from Zoho People
     * URL: GET /C_zoho/sync_employees
     * 
     * Process:
     * - Fetches employee data from Zoho People API
     * - Updates local zoho_employees table
     * - Creates new records or updates existing ones
     * 
     * Returns JSON with sync summary
     */
    public function sync_employees()
    {
        $employees = $this->Zoho_model->getEmployees();

        if (!isset($employees['response']['result'])) {
            echo json_encode(['status' => 'error', 'message' => 'No employees found in Zoho response']);
            return;
        }

        $imported = 0;
        $updated = 0;
        
        foreach ($employees['response']['result'] as $emp) {
            $data = [
                'emp_id' => $emp['Employee_ID'] ?? '',
                'name' => $emp['Display_Name'] ?? ($emp['FirstName'] ?? ''),
                'email' => $emp['EmailID'] ?? null,
                'department' => $emp['Department'] ?? null
            ];

            // Skip if employee ID is empty
            if (empty($data['emp_id'])) {
                continue;
            }

            // Check if employee exists
            $existing = $this->db->where('emp_id', $data['emp_id'])->get('zoho_employees')->row();

            if ($existing) {
                $this->db->update('zoho_employees', $data, ['emp_id' => $data['emp_id']]);
                $updated++;
            } else {
                $this->db->insert('zoho_employees', $data);
                $imported++;
            }
        }

        log_message('info', '[EMPLOYEE_SYNC] Completed: ' . $imported . ' imported, ' . $updated . ' updated');
        
        echo json_encode([
            'status' => 'completed',
            'summary' => [
                'imported' => $imported,
                'updated' => $updated,
                'total_processed' => $imported + $updated
            ]
        ]);
    }


    /**
     * Manual attendance sync to Zoho People
     * URL: GET /C_zoho/sync_attendance?date=2025-12-21
     * 
     * Process:
     * - Gets unsynced attendance records for specified date
     * - Sends each record to Zoho People API
     * - Updates sync status in database
     * - Logs detailed sync results
     * - Sends email notification on failures
     * 
     * Returns JSON:
     * {
     *   "status": "completed",
     *   "date": "2025-12-21",
     *   "total": 50,
     *   "success": 48,
     *   "failed": 2,
     *   "details": [...]
     * }
     */
    public function sync_attendance()
    {
        $date = $this->input->get('date') ?: date('Y-m-d');

        // Get unsynced attendance records for the date from zoho_attendance_daily table
        $records = $this->db->where(['work_date' => $date, 'synced' => 0])
            ->get('zoho_attendance_daily')->result();

        if (empty($records)) {
            log_message('info', '[ZOHO_SYNC] No records to sync for date: ' . $date);
            echo json_encode(['status' => 'info', 'message' => 'No records to sync']);
            return;
        }

        $success = 0;
        $failed = 0;
        $sync_details = [];

        foreach ($records as $record) {
            // Prepare attendance data for Zoho API
            $employee_data = [
                'empId' => $record->emp_id,
                'date' => $record->work_date,
                'checkIn' => $record->first_in,
                'checkOut' => $record->last_out
            ];

            log_message('info', '[ZOHO_SYNC] Sending to Zoho: Emp ' . $record->emp_id . ', Date ' . $record->work_date);

            $response = $this->Zoho_model->pushAttendance($employee_data);

            // Check for success or duplicate (duplicate means already in Zoho)
            // Note: Zoho API returns duplicate error when record already exists
            // We treat duplicates as success since the data is already in Zoho
            $isSuccess = false;
            $isDuplicate = false;
            
            if (is_array($response) && isset($response[0]['response'])) {
                if ($response[0]['response'] === 'success') {
                    $isSuccess = true;
                } elseif (isset($response[0]['msg']) && 
                         (stripos($response[0]['msg'], 'duplicate') !== false)) {
                    $isDuplicate = true;
                }
            }

            if ($isSuccess || $isDuplicate) {
                // Mark as synced in database
                $this->db->update('zoho_attendance_daily', [
                    'synced' => 1,
                    'synced_at' => date('Y-m-d H:i:s'),
                    'zoho_status' => $isDuplicate ? 'DUPLICATE' : 'PUSHED'
                ], ['id' => $record->id]);

                $success++;

                log_message('info', '[ZOHO_SYNC] Successfully synced: Emp ' . $record->emp_id . ($isDuplicate ? ' (duplicate - already exists)' : ''));

                $sync_details[] = [
                    'emp_id' => $record->emp_id,
                    'status' => 'SUCCESS',
                    'in_time' => $record->first_in,
                    'out_time' => $record->last_out,
                    'note' => $isDuplicate ? 'Already exists in Zoho' : null
                ];
            } else {
                // Log error and increment retry count
                $error = $response[0]['msg'] ?? json_encode($response);
                $this->db->update('zoho_attendance_daily', [
                    'last_error' => $error,
                    'retry_count' => ($record->retry_count ?? 0) + 1
                ], ['id' => $record->id]);

                $failed++;

                log_message('error', '[ZOHO_SYNC] Failed to sync: Emp ' . $record->emp_id . ', Error: ' . $error);

                $sync_details[] = [
                    'emp_id' => $record->emp_id,
                    'status' => 'FAILED',
                    'error' => $error,
                    'in_time' => $record->first_in,
                    'out_time' => $record->last_out
                ];
            }
        }

        log_message('info', '[ZOHO_SYNC] Completed for ' . $date . ': ' . $success . ' success, ' . $failed . ' failed');

        // Send notification if there are failures
        if ($failed > 0) {
            $this->Zoho_model->send_notification(
                "Zoho Sync Alert - $date",
                "Attendance sync completed: $success success, $failed failed out of " . count($records) . " records"
            );
        }

        echo json_encode([
            'status' => 'completed',
            'date' => $date,
            'total' => count($records),
            'success' => $success,
            'failed' => $failed,
            'details' => $sync_details
        ]);
    }

    /**
     * Attendance dashboard with date range support
     * URL: GET /C_zoho/dashboard?from=2025-12-01&to=2025-12-21
     * URL: GET /C_zoho/dashboard?date=2025-12-21 (single date)
     * URL: GET /C_zoho/dashboard (today only)
     * 
     * Parameters:
     * - from: Start date (Y-m-d format)
     * - to: End date (Y-m-d format) 
     * - date: Single date (Y-m-d format)
     * 
     * Returns formatted JSON with date range data (limited for performance)
     */
    public function dashboard()
    {
        set_time_limit(60); // 1 minute limit
        
        $from = $this->input->get('from');
        $to = $this->input->get('to');
        $date = $this->input->get('date');

        // Determine date range
        if ($from && $to) {
            $start_date = $from;
            $end_date = $to;
        } elseif ($date) {
            $start_date = $end_date = $date;
        } else {
            $start_date = $end_date = date('Y-m-d');
        }

        $data = [
            'date_range' => [
                'from' => $start_date,
                'to' => $end_date
            ],
            'stats' => [
                'total_employees' => $this->db->count_all_results('zoho_employees'),
                'present_in_range' => $this->db->where('work_date >=', $start_date)
                    ->where('work_date <=', $end_date)
                    ->count_all_results('zoho_attendance_daily'),
                'pending_sync' => $this->db->where('work_date >=', $start_date)
                    ->where('work_date <=', $end_date)
                    ->where('synced', 0)
                    ->count_all_results('zoho_attendance_daily')
            ],
            'recent_punches' => $this->db->select('r.emp_id, r.punch_time, r.work_date, e.name')
                ->from('zoho_attendance_raw r')
                ->join('zoho_employees e', 'r.emp_id = e.emp_id', 'left')
                ->where('r.work_date >=', $start_date)
                ->where('r.work_date <=', $end_date)
                ->order_by('r.punch_time', 'DESC')
                ->limit(20)
                ->get()->result(),
            'daily_attendance' => $this->db->select('d.emp_id, d.work_date, d.first_in, d.last_out, d.synced, e.name')
                ->from('zoho_attendance_daily d')
                ->join('zoho_employees e', 'd.emp_id = e.emp_id', 'left')
                ->where('d.work_date >=', $start_date)
                ->where('d.work_date <=', $end_date)
                ->order_by('d.work_date', 'DESC')
                ->limit(50)
                ->get()->result()
        ];

        echo '<pre>' . json_encode($data, JSON_PRETTY_PRINT) . '</pre>';
    }

    /**
     * Import attendance data from Zoho People to local database
     * URL: GET /C_zoho/import_attendance?empId=DLMX072&date=2025-12-21
     * URL: GET /C_zoho/import_attendance?empId=DLMX072&from=2025-12-01&to=2025-12-21
     * 
     * Parameters:
     * - empId: Employee ID (REQUIRED - Zoho API needs specific employee)
     * - date: Single date (Y-m-d format)
     * - from/to: Date range (Y-m-d format)
     * 
     * Note: Zoho People API requires empId parameter - for single employee only
     * 
     * Returns JSON with import summary
     */
    public function import_attendance()
    {
        $empId = $this->input->get('empId');
        $date = $this->input->get('date');
        $from = $this->input->get('from');
        $to = $this->input->get('to');
        
        // Validate required empId parameter
        if (empty($empId)) {
            echo json_encode([
                'status' => 'error', 
                'message' => 'empId parameter is required. Zoho API does not support fetching all employees attendance at once.'
            ]);
            return;
        }
        
        // Determine date range
        if ($from && $to) {
            $start_date = $from;
            $end_date = $to;
        } elseif ($date) {
            $start_date = $end_date = $date;
        } else {
            $start_date = $end_date = date('Y-m-d');
        }
        
        $imported = 0;
        $updated = 0;
        $errors = 0;
        $details = [];
        
        // Get attendance data from Zoho for date range
        $current_date = $start_date;
        while (strtotime($current_date) <= strtotime($end_date)) {
            $zoho_data = $this->Zoho_model->getAttendance($current_date, $empId);
            
            if (isset($zoho_data['firstIn']) && isset($zoho_data['lastOut'])) {
                $first_in = date('H:i:s', strtotime($zoho_data['firstIn']));
                $last_out = date('H:i:s', strtotime($zoho_data['lastOut']));
                
                // Check if record exists
                $existing = $this->db->where([
                    'emp_id' => $empId,
                    'work_date' => $current_date
                ])->get('zoho_attendance_daily')->row();
                
                if ($existing) {
                    // Update existing record
                    $this->db->update('zoho_attendance_daily', [
                        'first_in' => $first_in,
                        'last_out' => $last_out,
                        'synced' => 1,
                        'updated_at' => date('Y-m-d H:i:s')
                    ], ['id' => $existing->id]);
                    $updated++;
                } else {
                    // Insert new record
                    $this->db->insert('zoho_attendance_daily', [
                        'emp_id' => $empId,
                        'work_date' => $current_date,
                        'first_in' => $first_in,
                        'last_out' => $last_out,
                        'synced' => 1,
                        'synced_at' => date('Y-m-d H:i:s')
                    ]);
                    $imported++;
                }
                
                $details[] = [
                    'emp_id' => $empId,
                    'date' => $current_date,
                    'first_in' => $first_in,
                    'last_out' => $last_out,
                    'total_hours' => $zoho_data['totalHrs'] ?? '0:00',
                    'status' => $zoho_data['status'] ?? 'Unknown',
                    'action' => $existing ? 'UPDATED' : 'IMPORTED'
                ];
            } else {
                $errors++;
                log_message('error', '[ZOHO_IMPORT] No attendance data for empId: ' . $empId . ', date: ' . $current_date);
                
                $details[] = [
                    'emp_id' => $empId,
                    'date' => $current_date,
                    'error' => 'No attendance data found',
                    'action' => 'SKIPPED'
                ];
            }
            
            $current_date = date('Y-m-d', strtotime($current_date . ' +1 day'));
        }
        
        log_message('info', '[ZOHO_IMPORT] Completed for empId ' . $empId . ': ' . $imported . ' imported, ' . $updated . ' updated, ' . $errors . ' errors');
        
        echo json_encode([
            'status' => 'completed',
            'date_range' => [
                'from' => $start_date,
                'to' => $end_date
            ],
            'employee_id' => $empId,
            'summary' => [
                'imported' => $imported,
                'updated' => $updated,
                'errors' => $errors,
                'total_processed' => $imported + $updated
            ],
            'details' => $details
        ]);
    }

    /**
     * Import attendance data for all employees from Zoho People
     * URL: GET /C_zoho/import_all_attendance?from=2024-12-01&to=2024-12-31
     * URL: GET /C_zoho/import_all_attendance?date=2025-12-21
     * 
     * Parameters:
     * - date: Single date (Y-m-d format)
     * - from/to: Date range (Y-m-d format) - for entire months
     * 
     * Process:
     * - Gets all employees from local database
     * - Uses working getAttendance API for each employee and each date
     * - Stores data in local zoho_attendance_daily table
     * - Extended timeout for processing all employees and dates
     * 
     * Returns JSON with import summary for all employees and dates
     */
    public function import_all_attendance()
    {
        // Remove execution time limit for large datasets
        set_time_limit(0);
        ini_set('memory_limit', '512M');
        
        // Prevent accidental double-runs - require confirmation parameter
        $confirm = $this->input->get('confirm');
        if ($confirm !== 'yes') {
            echo json_encode([
                'status' => 'error',
                'message' => 'This operation imports attendance for ALL employees. Add &confirm=yes to proceed.',
                'warning' => 'This may take several minutes depending on date range and employee count.'
            ]);
            return;
        }
        
        // Stop processing if user closes browser
        ignore_user_abort(false);
        
        $date = $this->input->get('date');
        $from = $this->input->get('from');
        $to = $this->input->get('to');
        
        // Determine date range
        if ($from && $to) {
            $start_date = $from;
            $end_date = $to;
        } elseif ($date) {
            $start_date = $end_date = $date;
        } else {
            $start_date = $end_date = date('Y-m-d');
        }
        
        // Get all employees from local database
        $employees = $this->db->select('emp_id')->get('zoho_employees')->result();
        
        if (empty($employees)) {
            echo json_encode(['status' => 'error', 'message' => 'No employees found. Run sync_employees first.']);
            return;
        }
        
        $imported = 0;
        $updated = 0;
        $errors = 0;
        $processed_records = 0;
        $total_employees = count($employees);
        
        // Calculate total days in date range
        $total_days = (strtotime($end_date) - strtotime($start_date)) / (60 * 60 * 24) + 1;
        $total_records = $total_employees * $total_days;
        
        foreach ($employees as $employee) {
            // Check if browser connection is still active
            if (connection_aborted()) {
                log_message('info', '[ZOHO_IMPORT_ALL] Connection aborted by user at record ' . $processed_records);
                break;
            }
            
            $current_date = $start_date;
            
            while (strtotime($current_date) <= strtotime($end_date)) {
                $processed_records++;
                
                // Skip future dates - cannot have attendance for dates that haven't occurred
                if (strtotime($current_date) > time()) {
                    $current_date = date('Y-m-d', strtotime($current_date . ' +1 day'));
                    continue;
                }
                
                // Fetch attendance from Zoho API for this employee and date
                $zoho_data = $this->Zoho_model->getAttendance($current_date, $employee->emp_id);
                
                if (isset($zoho_data['firstIn']) && isset($zoho_data['lastOut'])) {
                    // Skip if Zoho returns dash values (absent/weekend employees)
                    if ($zoho_data['firstIn'] === '-' || $zoho_data['lastOut'] === '-') {
                        $errors++;
                        log_message('info', '[ZOHO_IMPORT_ALL] Skipped absent employee: ' . $employee->emp_id . ', date: ' . $current_date . ' - Status: ' . ($zoho_data['status'] ?? 'Unknown'));
                        $current_date = date('Y-m-d', strtotime($current_date . ' +1 day'));
                        continue;
                    }
                    
                    $first_in = date('H:i:s', strtotime($zoho_data['firstIn']));
                    $last_out = date('H:i:s', strtotime($zoho_data['lastOut']));
                    
                    // Comprehensive validation for Zoho default/invalid data
                    // Zoho sometimes returns placeholder times like 05:30:00 or 00:00:00
                    $is_invalid = (
                        // Identical times (placeholder data)
                        $first_in === $last_out ||
                        // Zoho default times (IST timezone offset 05:30:00)
                        $first_in === '05:30:00' || $last_out === '05:30:00' ||
                        $first_in === '00:00:00' || $last_out === '00:00:00' ||
                        // Check-out before check-in (impossible)
                        strtotime($zoho_data['firstIn']) >= strtotime($zoho_data['lastOut']) ||
                        // Very short duration (less than 1 hour - likely invalid)
                        (strtotime($zoho_data['lastOut']) - strtotime($zoho_data['firstIn'])) < 3600 ||
                        // Check status for non-working days
                        (isset($zoho_data['status']) && in_array(strtolower($zoho_data['status']), ['absent', 'leave', 'holiday', 'weekend']))
                    );
                    
                    if ($is_invalid) {
                        $errors++;
                        // Determine specific reason for rejection
                        $reason = '';
                        if ($first_in === $last_out) $reason = 'identical times';
                        elseif ($first_in === '05:30:00' || $last_out === '05:30:00') $reason = 'default 05:30:00';
                        elseif ($first_in === '00:00:00' || $last_out === '00:00:00') $reason = 'default 00:00:00';
                        elseif (strtotime($zoho_data['firstIn']) >= strtotime($zoho_data['lastOut'])) $reason = 'checkout before checkin';
                        elseif ((strtotime($zoho_data['lastOut']) - strtotime($zoho_data['firstIn'])) < 3600) $reason = 'duration < 1 hour';
                        elseif (isset($zoho_data['status'])) $reason = 'status: ' . $zoho_data['status'];
                        
                        log_message('info', '[ZOHO_IMPORT_ALL] Skipped invalid data for emp: ' . $employee->emp_id . ', date: ' . $current_date . ' - Reason: ' . $reason . ' (firstIn: ' . $first_in . ', lastOut: ' . $last_out . ')');
                        $current_date = date('Y-m-d', strtotime($current_date . ' +1 day'));
                        continue;
                    }
                    
                    // Check if record exists in local database
                    $existing = $this->db->where([
                        'emp_id' => $employee->emp_id,
                        'work_date' => $current_date
                    ])->get('zoho_attendance_daily')->row();
                    
                    if ($existing) {
                        // Update existing record with latest data from Zoho
                        $this->db->update('zoho_attendance_daily', [
                            'first_in' => $first_in,
                            'last_out' => $last_out,
                            'synced' => 1,
                            'updated_at' => date('Y-m-d H:i:s')
                        ], ['id' => $existing->id]);
                        $updated++;
                    } else {
                        // Insert new record
                        $this->db->insert('zoho_attendance_daily', [
                            'emp_id' => $employee->emp_id,
                            'work_date' => $current_date,
                            'first_in' => $first_in,
                            'last_out' => $last_out,
                            'synced' => 1,
                            'synced_at' => date('Y-m-d H:i:s')
                        ]);
                        $imported++;
                    }
                } else {
                    // No attendance data found for this employee/date
                    $errors++;
                }
                
                // Log progress every 50 records for monitoring
                if ($processed_records % 50 == 0) {
                    log_message('info', '[ZOHO_IMPORT_ALL] Progress: ' . $processed_records . '/' . $total_records . ' records processed');
                }
                
                // Move to next date
                $current_date = date('Y-m-d', strtotime($current_date . ' +1 day'));
            }
        }
        
        log_message('info', '[ZOHO_IMPORT_ALL] Completed: ' . $imported . ' imported, ' . $updated . ' updated, ' . $errors . ' errors');
        
        echo '<pre>' . json_encode([
            'status' => 'completed',
            'date_range' => [
                'from' => $start_date,
                'to' => $end_date,
                'total_days' => $total_days
            ],
            'total_employees' => $total_employees,
            'total_records_processed' => $processed_records,
            'summary' => [
                'imported' => $imported,
                'updated' => $updated,
                'errors' => $errors
            ]
        ], JSON_PRETTY_PRINT) . '</pre>';
    }

    /**
     * Clean up future date records and invalid attendance data
     * URL: GET /C_zoho/cleanup_future_dates
     * 
     * Removes:
     * - Records with future dates
     * - Records with identical check-in/check-out times (05:30:00)
     * 
     * Returns JSON with cleanup summary
     */
    public function cleanup_future_dates()
    {
        $today = date('Y-m-d');
        
        // Delete future date records
        $this->db->where('work_date >', $today)->delete('zoho_attendance_daily');
        $future_deleted = $this->db->affected_rows();
        
        // Delete records with identical check-in/check-out times (placeholder data)
        $this->db->where('first_in = last_out')->delete('zoho_attendance_daily');
        $placeholder_deleted = $this->db->affected_rows();
        
        log_message('info', '[CLEANUP] Deleted ' . $future_deleted . ' future records and ' . $placeholder_deleted . ' placeholder records');
        
        echo '<pre>' . json_encode([
            'status' => 'completed',
            'cleanup_date' => $today,
            'summary' => [
                'future_records_deleted' => $future_deleted,
                'placeholder_records_deleted' => $placeholder_deleted,
                'total_deleted' => $future_deleted + $placeholder_deleted
            ]
        ], JSON_PRETTY_PRINT) . '</pre>';
    }

    /**
     * Push attendance to Zoho People
     * URL: GET /C_zoho/push_attendance?date=2024-12-21 (single date)
     * URL: GET /C_zoho/push_attendance?from=2024-12-01&to=2024-12-31 (date range)
     * URL: GET /C_zoho/push_attendance (today only)
     * 
     * Parameters:
     * - date: Single date (Y-m-d format) - optional, defaults to today
     * - from/to: Date range (Y-m-d format) - optional
     * 
     * Process:
     * - Gets unsynced attendance records for specified date(s)
     * - Pushes each record to Zoho People API
     * - Updates sync status in database
     * 
     * Returns JSON with push summary
     */
    public function push_attendance()
    {
        $date = $this->input->get('date');
        $from = $this->input->get('from');
        $to = $this->input->get('to');
        
        // Determine date range
        if ($from && $to) {
            $start_date = $from;
            $end_date = $to;
        } elseif ($date) {
            $start_date = $end_date = $date;
        } else {
            $start_date = $end_date = date('Y-m-d');
        }
        
        // Get unsynced records
        $records = $this->db->where('work_date >=', $start_date)
            ->where('work_date <=', $end_date)
            ->where('synced', 0)
            ->get('zoho_attendance_daily')->result();
        
        if (empty($records)) {
            echo json_encode([
                'status' => 'info',
                'message' => 'No unsynced records found',
                'date_range' => ['from' => $start_date, 'to' => $end_date]
            ]);
            return;
        }
        
        $success = 0;
        $failed = 0;
        $details = [];
        
        foreach ($records as $record) {
            $data = [
                'empId' => $record->emp_id,
                'date' => $record->work_date,
                'checkIn' => $record->first_in,
                'checkOut' => $record->last_out
            ];
            
            $response = $this->Zoho_model->pushAttendance($data);
            
            if (is_array($response) && isset($response[0]['response']) && $response[0]['response'] === 'success') {
                $this->db->update('zoho_attendance_daily', [
                    'synced' => 1,
                    'synced_at' => date('Y-m-d H:i:s')
                ], ['id' => $record->id]);
                $success++;
                $details[] = ['emp_id' => $record->emp_id, 'date' => $record->work_date, 'status' => 'SUCCESS'];
            } else {
                $error = $response[0]['msg'] ?? json_encode($response);
                $this->db->update('zoho_attendance_daily', ['sync_error' => $error], ['id' => $record->id]);
                $failed++;
                $details[] = ['emp_id' => $record->emp_id, 'date' => $record->work_date, 'status' => 'FAILED', 'error' => $error];
            }
        }
        
        log_message('info', '[PUSH_ATTENDANCE] Completed: ' . $success . ' success, ' . $failed . ' failed');
        
        echo json_encode([
            'status' => 'completed',
            'date_range' => ['from' => $start_date, 'to' => $end_date],
            'summary' => ['total' => count($records), 'success' => $success, 'failed' => $failed],
            'details' => $details
        ]);
    }
}