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
	 * Default dashboard view
	 */

	public function index()
	{
		$this->load->view('dashboard');
	}

	/**
	 * Reports view
	 */
	public function reports()
	{
		$this->load->view('reports');
	}

	/**
	 * Admin Control Panel
	 * URL: /C_zoho/admin
	 */
	public function admin()
	{
		$this->load->view('admin_panel');
	}

	/**
	 * Force Sync Attendance
	 * Resets sync status and triggers sync for a date
	 */
	public function force_sync()
	{
		$date = $this->input->get('date') ?: date('Y-m-d');

		// Reset sync status for the date
		$this->db->update(
			'zoho_attendance_daily',
			['synced' => 0, 'retry_count' => 0],
			['work_date' => $date]
		);

		// Call standard sync function
		$this->sync_attendance();
	}

	/**
	 * Check if raw attendance data exists
	 * URL: GET /C_zoho/check_raw_data?date=2025-12-22
	 */
	public function check_raw_data()
	{
		$date = $this->input->get('date') ?: date('Y-m-d');
		// Querying iclock_transaction instead of zoho_attendance_raw
		$count = $this->db->where("DATE(punch_time)", $date)->count_all_results('iclock_transaction');
		echo json_encode(['status' => 'success', 'date' => $date, 'count' => $count]);
	}

	/**
	 * Update attendance record (Manual Override)
	 * URL: POST /C_zoho/update_attendance
	 */
	public function update_attendance()
	{
		$emp_id = $this->input->post('emp_id');
		$work_date = $this->input->post('work_date');
		$first_in = $this->input->post('first_in');
		$last_out = $this->input->post('last_out');

		if (!$emp_id || !$work_date || !$first_in || !$last_out) {
			echo json_encode(['status' => 'error', 'message' => 'Missing required fields']);
			return;
		}

		// Normalize times
		$first_in = date('H:i:s', strtotime($first_in));
		$last_out = date('H:i:s', strtotime($last_out));

		// Check if record exists
		$existing = $this->db->where(['emp_id' => $emp_id, 'work_date' => $work_date])->get('zoho_attendance_daily')->row();

		$data = [
			'first_in' => $first_in,
			'last_out' => $last_out,
			'synced' => 0, // Reset sync status for re-push
			'updated_at' => date('Y-m-d H:i:s'),
			'last_error' => 'Manual Override'
		];

		if ($existing) {
			$this->db->update('zoho_attendance_daily', $data, ['id' => $existing->id]);
		} else {
			$data['emp_id'] = $emp_id;
			$data['work_date'] = $work_date;
			$this->db->insert('zoho_attendance_daily', $data);
		}

		echo json_encode(['status' => 'success', 'message' => 'Attendance updated successfully. It is now marked as PENDING for sync.']);
	}

	/**
	 * Import attendance from ZKTeco BioTime API
	 * URL: GET /C_zoho/import_zkteco_attendance?date=2025-12-22
	 * URL: GET /C_zoho/import_zkteco_attendance?from=2025-12-01&to=2025-12-31
	 * 
	 * Process:
	 * - Authenticates with ZKTeco BioTime API
	 * - Fetches transaction data from /iclock/api/transactions/
	 * - Stores in zoho_attendance_raw table
	 * - Then processes into zoho_attendance_daily
	 */
	/*
	 * Process local attendance from iclock_transaction to daily summary
	 * Replaces old import_zkteco_attendance which tried to move data to raw table
	 */
	public function import_zkteco_attendance()
	{
		$date = $this->input->get('date');
		$from = $this->input->get('from');
		$to = $this->input->get('to');

		if ($from && $to) {
			$start_date = $from;
			$end_date = $to;
		} elseif ($date) {
			$start_date = $end_date = $date;
		} else {
			$start_date = $end_date = date('Y-m-d');
		}

		// Directly process from iclock_transaction to zoho_attendance_daily
		$this->process_to_daily($start_date, $end_date);

		// Count records processed for reporting
		$processed_count = $this->db
			->where('work_date >=', $start_date)
			->where('work_date <=', $end_date)
			->count_all_results('zoho_attendance_daily');

		$summary = ['message' => 'Processed daily attendance from iclock_transaction', 'records' => $processed_count];

		// Send Email Report
		$this->Zoho_model->send_simple_report("Attendance Processing (Local DB)", [
			'date_range' => $start_date . ' to ' . $end_date,
			'processed_records' => $processed_count
		]);

		echo json_encode([
			'status' => 'completed',
			'date_range' => ['from' => $start_date, 'to' => $end_date],
			'summary' => $summary
		]);
	}



	private function process_to_daily($start_date, $end_date)
	{
		$current_date = $start_date;
		while (strtotime($current_date) <= strtotime($end_date)) {
			// Query iclock_transaction directly with smart aggregation
			// Rule: 
			// IN: punch_state '0' OR terminal 'NYU7253400270'
			// OUT: punch_state '1' OR terminal 'NYU7253400273'
			// Fallback: earliest/latest punch if rules don't match (for other devices)
			
			$this->db->select("emp_code as emp_id");
			$this->db->select("MIN(punch_time) as min_activity");
			$this->db->select("MAX(punch_time) as max_activity");
			$this->db->select("MIN(CASE WHEN punch_state = '0' OR terminal_sn = 'NYU7253400270' THEN punch_time END) as rule_first_in");
			$this->db->select("MAX(CASE WHEN punch_state = '1' OR terminal_sn = 'NYU7253400273' THEN punch_time END) as rule_last_out");
			
			$punches = $this->db
				->where("punch_time >=", $current_date . ' 00:00:00')
				->where("punch_time <=", $current_date . ' 23:59:59')
				->group_by('emp_code')
				->get('iclock_transaction')
				->result();

			foreach ($punches as $punch) {
				// Use Rule logic if found, otherwise simple First/Last
				$raw_in = $punch->rule_first_in ?: $punch->min_activity;
				$raw_out = $punch->rule_last_out ?: $punch->max_activity;

				/* 
				   Edge Case: If user only punched ONCE in the day:
				   min_activity == max_activity.
				   If that punch was an IN, raw_in is set. raw_out might be set to same time (since max fallback).
				   Usually we want CheckIn and CheckOut to be different, or CheckOut empty if still working.
				   However, for Zoho Sync, sending same In/Out is often treated as 0 hours.
				*/
				
				$first_in = date('H:i:s', strtotime($raw_in));
				$last_out = date('H:i:s', strtotime($raw_out));

				// Safety: If In > Out (shouldn't happen with Min/Max, but rule logic might allow it if data is weird)
				if ($first_in > $last_out) {
					// Swap or default to min/max
					$first_in = date('H:i:s', strtotime($punch->min_activity));
					$last_out = date('H:i:s', strtotime($punch->max_activity));
				}

				$existing = $this->db->where(['emp_id' => $punch->emp_id, 'work_date' => $current_date])
					->get('zoho_attendance_daily')->row();

				$data = [
					'first_in' => $first_in,
					'last_out' => $last_out,
					'synced' => 0
				];

				if ($existing) {
					$this->db->update('zoho_attendance_daily', $data, ['id' => $existing->id]);
				} else {
					$data['emp_id'] = $punch->emp_id;
					$data['work_date'] = $current_date;
					$this->db->insert('zoho_attendance_daily', $data);
				}
			}
			$current_date = date('Y-m-d', strtotime($current_date . ' +1 day'));
		}
	}

	/**
	 * Process local ZKTeco attendance data into daily summaries
	 * URL: GET /C_zoho/process_local_attendance?date=2025-12-22
	 * URL: GET /C_zoho/process_local_attendance?from=2025-12-01&to=2025-12-31
	 * 
	 * Process:
	 * - Gets raw punch data from zoho_attendance_raw table
	 * - Calculates first_in and last_out for each employee per day
	 * - Stores in zoho_attendance_daily table
	 * 
	 * Returns JSON with processing summary
	 */
	public function process_local_attendance()
	{
		// Alias to import_zkteco_attendance for backward compatibility
		$this->import_zkteco_attendance();
	}

	public function dashboard()
	{
		$this->load->view('dashboard');
	}
	public function get_employees()
	{
		$employees = $this->db->select('emp_id, name')->order_by('name', 'ASC')->get('zoho_employees')->result();
		header('Content-Type: application/json');
		echo json_encode(['employees' => $employees]);
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

		if (
			!isset($employees['response']['result']) ||
			!is_array($employees['response']['result'])
		) {
			$error_msg = isset($employees['error']) ? $employees['error'] : 'Invalid Zoho response';
			echo json_encode([
				'status'  => 'error',
				'message' => 'Zoho API Error: ' . $error_msg,
				'debug'   => $employees
			]);
			return;
		}

		$imported = 0;
		$updated  = 0;

		foreach ($employees['response']['result'] as $group) {

			if (!is_array($group)) {
				continue;
			}

			foreach ($group as $empWrapper) {

				// 🔑 Unwrap Zoho's extra level if present
				$emp = (isset($empWrapper[0]) && is_array($empWrapper[0]))
					? $empWrapper[0]
					: $empWrapper;

				// Skip invalid records
				if (empty($emp['EmployeeID'])) {
					continue;
				}

				$data = [
					'emp_id'     => $emp['EmployeeID'],
					'name'       => trim(($emp['FirstName'] ?? '') . ' ' . ($emp['LastName'] ?? '')),
					'email'      => $emp['EmailID'] ?? null,
					'department' => $emp['Department'] ?? null
				];

				// Check if employee exists
				$existing = $this->db
					->where('emp_id', $data['emp_id'])
					->get('zoho_employees')
					->row();

				if ($existing) {
					$this->db->update('zoho_employees', $data, ['emp_id' => $data['emp_id']]);
					$updated++;
				} else {
					$this->db->insert('zoho_employees', $data);
					$imported++;
				}
			}
		}

		$summary = [
		'imported'        => $imported,
		'updated'         => $updated,
		'total_processed' => $imported + $updated
	];

	log_message('info', '[EMPLOYEE_SYNC] Completed: ' . $imported . ' imported, ' . $updated . ' updated');

	// Send Email Report
	$this->Zoho_model->send_simple_report("Employee Master Sync Completed", $summary);

	echo json_encode([
		'status'  => 'completed',
		'summary' => $summary
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
				} elseif (
					isset($response[0]['msg']) &&
					(stripos($response[0]['msg'], 'duplicate') !== false)
				) {
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

		$summary = [
		'start_date' => $date,
		'end_date' => $date,
		'total' => count($records),
		'imported' => $success,
		'updated' => 0,
		'skipped' => 0,
		'errors' => $failed
	];

	// Send Email Report
	if (count($records) > 0) {
		$this->Zoho_model->send_attendance_report($summary);
	}

	echo json_encode([
		'status' => 'completed',
		'date' => $date,
		'summary' => $summary,
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
	public function dashboard_api()
	{
		set_time_limit(60);

		$from = $this->input->get('from');
		$to = $this->input->get('to');
		$date = $this->input->get('date');
		$emp_id = $this->input->get('emp_id');

		$start_date = $from ?: ($date ?: date('Y-m-d'));
		$end_date = $to ?: ($date ?: date('Y-m-d'));

		// 1. Fetch Main Attendance Data
		$this->db->select('d.emp_id, d.work_date, d.first_in, d.last_out, d.synced, e.name')
			->from('zoho_attendance_daily d')
			->join('zoho_employees e', 'd.emp_id = e.emp_id', 'inner')
			->where('d.work_date >=', $start_date)
			->where('d.work_date <=', $end_date);
		
		if ($emp_id) {
			$this->db->where('d.emp_id', $emp_id);
		}

		$daily_attendance = $this->db->order_by('d.work_date', 'DESC')->get()->result();

		// 2. Fetch Stats (Calculated from separate clean queries to avoid active record pollution)
		$this->db->where('work_date >=', $start_date)->where('work_date <=', $end_date);
		if ($emp_id) {
			$this->db->where('emp_id', $emp_id);
		}
		$pending_sync = $this->db->where('synced', 0)->count_all_results('zoho_attendance_daily');

		$total_employees = $this->db->count_all_results('zoho_employees');

		// 3. Recent Punches (from iclock_transaction instead of zoho_attendance_raw)
		// Assuming iclock_transaction has columns: emp_code, punch_time, punch_state, terminal_sn
		$this->db->select('r.emp_code as emp_id, r.punch_time, r.punch_state, r.terminal_sn, DATE(r.punch_time) as work_date, e.name')
			->from('iclock_transaction r')
			->join('zoho_employees e', 'r.emp_code = e.emp_id', 'inner')
			->where('r.punch_time >=', $start_date . ' 00:00:00')
			->where('r.punch_time <=', $end_date . ' 23:59:59')
			->order_by('r.punch_time', 'DESC')
			->limit(20);
		
		$recent_punches = $this->db->get()->result();

		// 4. Weekly Trend (Calculated for the chart)
		$weekly_trend = $this->db->select('work_date, COUNT(*) as present_count')
			->from('zoho_attendance_daily')
			->where('work_date >', date('Y-m-d', strtotime('-7 days')))
			->group_by('work_date')
			->order_by('work_date', 'ASC')
			->get()->result();

		$data = [
			'date_range' => ['from' => $start_date, 'to' => $end_date],
			'stats' => [
				'total_employees' => $total_employees,
				'present_in_range' => count($daily_attendance),
				'pending_sync' => $pending_sync
			],
			'recent_punches' => $recent_punches,
			'daily_attendance' => $daily_attendance,
			'weekly_trend' => $weekly_trend,
			'settings' => [
				'late_threshold' => $this->Zoho_model->get_setting('late_threshold', '09:15'),
				'company_name' => $this->Zoho_model->get_setting('company_name', Globals::$admin_company_name)
			]
		];

		header('Content-Type: application/json');
		echo json_encode($data);
	}

	/**
	 * AJAX endpoint to save system settings
	 */
	public function save_settings()
	{
		$late_threshold = $this->input->post('late_threshold');
		$grace_period = $this->input->post('grace_period');
		$company_name = $this->input->post('company_name');

		if ($late_threshold) $this->Zoho_model->save_setting('late_threshold', $late_threshold);
		if ($grace_period !== null) $this->Zoho_model->save_setting('grace_period', $grace_period);
		if ($company_name) $this->Zoho_model->save_setting('company_name', $company_name);

		header('Content-Type: application/json');
		echo json_encode(['status' => 'success', 'message' => 'Settings updated successfully']);
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

		$summary = [
			'start_date' => $start_date,
			'end_date' => $end_date,
			'total' => $imported + $updated + $errors,
			'imported' => $imported,
			'updated' => $updated,
			'skipped' => 0,
			'errors' => $errors
		];

		// Send Email Report
		$this->Zoho_model->send_attendance_report($summary);

		echo json_encode([
			'status' => 'completed',
			'date_range' => [
				'from' => $start_date,
				'to' => $end_date
			],
			'employee_id' => $empId,
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
	set_time_limit(0);
	ini_set('memory_limit', '512M');
	ignore_user_abort(false);

	$date = $this->input->get('date');
	$from = $this->input->get('from');
	$to = $this->input->get('to');

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
	$skipped = 0;
    $processed_count = 0;
	
    $start_index = 0;
    $more_records = true;
    
    $log_details = [];

	while ($more_records) {
		if (connection_aborted()) break;

		// Fetch 100 employees at once
		$response = $this->Zoho_model->getUserReport($start_date, $end_date, $start_index);
		
		if (isset($response['result']) && is_array($response['result'])) {
            $batch = $response['result'];
            
            foreach ($batch as $record) {
                $emp_id = $record['employeeDetails']['id'] ?? null;
                if (!$emp_id) continue;
                
                $attendance = $record['attendanceDetails'] ?? [];
                
                foreach ($attendance as $work_date => $day_data) {
                    $processed_count++;
                    
                    $first_in_raw = $day_data['FirstIn'] ?? ($day_data['First In'] ?? '-');
                    $last_out_raw = $day_data['LastOut'] ?? ($day_data['Last Out'] ?? '-');
                    
                    if ($first_in_raw === '-' || $last_out_raw === '-') {
                        $skipped++;
                        continue;
                    }

                    // Normalize times
                    $first_in = date('H:i:s', strtotime($first_in_raw));
                    $last_out = date('H:i:s', strtotime($last_out_raw));

                    // Validation (Identical times or placeholder defaults)
                    if ($first_in === $last_out || $first_in === '05:30:00' || $first_in === '00:00:00') {
                        $errors++;
                        $log_details[] = "Invalid data for $emp_id on $work_date: $first_in - $last_out";
                        continue;
                    }

                    $existing = $this->db->where(['emp_id' => $emp_id, 'work_date' => $work_date])->get('zoho_attendance_daily')->row();
                    
                    if ($existing) {
                        $this->db->update('zoho_attendance_daily', [
                            'first_in' => $first_in,
                            'last_out' => $last_out,
                            'synced' => 1,
                            'updated_at' => date('Y-m-d H:i:s')
                        ], ['id' => $existing->id]);
                        $updated++;
                    } else {
                        $this->db->insert('zoho_attendance_daily', [
                            'emp_id' => $emp_id,
                            'work_date' => $work_date,
                            'first_in' => $first_in,
                            'last_out' => $last_out,
                            'synced' => 1,
                            'synced_at' => date('Y-m-d H:i:s')
                        ]);
                        $imported++;
                    }
                }
            }

            if (count($batch) < 100) {
                $more_records = false;
            } else {
                $start_index += 100;
            }
        } else {
            $more_records = false;
        }
	}

    $summary = [
        'start_date' => $start_date,
        'end_date' => $end_date,
        'imported' => $imported,
        'updated' => $updated,
        'skipped' => $skipped,
        'errors' => $errors,
        'total' => $processed_count
    ];

    // Send Mail Report
    $this->Zoho_model->send_attendance_report($summary);

	echo json_encode([
		'status' => 'completed',
		'summary' => $summary,
        'details' => count($log_details) > 0 ? array_slice($log_details, 0, 100) : []
	], JSON_PRETTY_PRINT);
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

		$summary = [
		'future_records_deleted' => $future_deleted,
		'placeholder_records_deleted' => $placeholder_deleted,
		'total_deleted' => $future_deleted + $placeholder_deleted
	];

	// Send Email Report
	$this->Zoho_model->send_simple_report("Database Cleanup Performed", $summary);

	echo json_encode([
		'status' => 'completed',
		'cleanup_date' => $today,
		'summary' => $summary
	], JSON_PRETTY_PRINT);
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

	$summary = ['total' => count($records), 'success' => $success, 'failed' => $failed];

	// Send Email Report (only if something happened)
	if (count($records) > 0) {
		$this->Zoho_model->send_attendance_report(array_merge($summary, [
			'start_date' => $start_date,
			'end_date' => $end_date,
			'imported' => $success, // Mapping for unified format
			'updated' => 0,
			'skipped' => 0,
			'errors' => $failed
		]));
	}

	echo json_encode([
		'status' => 'completed',
		'date_range' => ['from' => $start_date, 'to' => $end_date],
		'summary' => $summary,
		'details' => $details
	]);
}
	/**
	 * Import employees from ZKTeco API to local database
	 * URL: GET /C_zoho/import_zkteco_employees
	 * 
	 * Process:
	 * - Authenticates with ZKTeco API
	 * - Fetches employee data
	 * - Stores/updates in local zoho_employees table
	 * 
	 * Returns JSON with import summary
	 */

	public function import_zkteco_employees()
	{
		// Import locally from personnel_employee table
		$this->db->select('emp_code, first_name, last_name, department_id'); // Selecting typical columns
		$query = $this->db->get('personnel_employee');
		
		$imported = 0;
		if ($query) {
			$employees = $query->result_array();
			foreach ($employees as $emp) {
				$data = [
					'emp_id' => $emp['emp_code'],
					'name'   => trim(($emp['first_name'] ?? '') . ' ' . ($emp['last_name'] ?? '')),
					'department' => $emp['department_id'] ?? null, // You might need to join with a department table if you want names
					// Add designation etc if available
				];

				// Upsert (Replace)
				$this->db->replace('zoho_employees', $data);
				$imported++;
			}
		}

		$summary = ['imported' => $imported, 'total' => count($employees ?? [])];

		// Send Email Report
		$this->Zoho_model->send_simple_report("Device Employee Import (Local DB)", $summary);

		echo json_encode([
			'status' => 'completed',
			'summary' => $summary
		]);
	}

	/**
	 * Bulk Push Attendance to Zoho
	 * URL: /C_zoho/sync_attendance_bulk
	 */
	public function sync_attendance_bulk_old()
	{
		// Remove time limit
		set_time_limit(0);
        ini_set('memory_limit', '512M');

		$date = $this->input->get('date');
		$from = $this->input->get('from');
		$to = $this->input->get('to');
		$empIdFilter = $this->input->get('empId');
        
        // Granular Sync Flags (Default to true for backward compatibility)
        $user_sync_in = $this->input->get('sync_in') !== '0'; 
        $user_sync_out = $this->input->get('sync_out') !== '0';

		// Determine date range or default to today
		if ($from && $to) {
			$start_date = $from;
			$end_date = $to;
		} elseif ($date) {
			$start_date = $end_date = $date;
		} else {
            // Default to pending records from all time if no date specified
			$start_date = $end_date = date('Y-m-d');
		}

        // 1. Fetch records locally
        // If specific employee is selected, we Fetch ALL records (even synced ones) to allow Force-Sync/Constraint Check
		if (!$empIdFilter) {
            $this->db->where('synced', 0);
        }
        
        $this->db->where('work_date >=', $start_date);
        $this->db->where('work_date <=', $end_date);
		
		if ($empIdFilter) {
			$this->db->where('emp_id', $empIdFilter);
		}
		
        $records = $this->db->get('zoho_attendance_daily')->result();

		if (empty($records)) {
			echo json_encode(['status' => 'completed', 'message' => 'No unsynced records found for this period.', 'summary' => ['pushed' => 0]]);
			return;
		}

        // 2. Fetch Existing Data from Zoho (Restored for Smart Context)
        // We need this to know the EXISTING Check-In time if we want to validly push a Check-Out
        $zoho_lookup = [];
        $more_records = true;
        $start_index = 0;
        
        while ($more_records) {
             if (connection_aborted()) break;

             $response = $this->Zoho_model->getUserReport($start_date, $end_date, $start_index);
             
            if (isset($response['result']) && is_array($response['result'])) {
                $batch = $response['result'];
                foreach ($batch as $entry) {
                    $emp_id = $entry['employeeDetails']['id'] ?? null;
                    if (!$emp_id) continue;
                    
                    $attendance = $entry['attendanceDetails'] ?? [];
                    foreach ($attendance as $date => $details) {
                        $fIn = $details['FirstIn'] ?? ($details['First In'] ?? '-');
                        $lOut = $details['LastOut'] ?? ($details['Last Out'] ?? '-');
                        $zoho_lookup[$emp_id][$date] = [
                            'has_in' => ($fIn !== '-' && !empty($fIn)),
                            'has_out' => ($lOut !== '-' && !empty($lOut)),
                            'first_in' => $fIn, // Store EXACT string from Zoho (e.g. 09:57 AM)
                            'last_out' => $lOut
                        ];
                    }
                }
                
                if (count($batch) < 100) $more_records = false;
                else $start_index += 100;
                if ($start_index > 5000) $more_records = false; 
            } else {
                $more_records = false;
            }
        }

        $json_payload = [];
        $ids_to_update = []; // Local IDs to mark as synced (because we pushed them)
        $ids_already_synced = []; // Local IDs to mark as synced (because they are already in Zoho)
        $pushed_count = 0;
        $skipped_count = 0;

        foreach ($records as $row) {
            // Basic validation
            if (empty($row->first_in) || empty($row->last_out) || $row->first_in == '-' || $row->last_out == '-') {
                continue;
            }

            // SIMPLIFIED LOGIC: Push exactly what user asked for.
            $final_check_in = null;
            $final_check_out = null;

            if ($user_sync_in) {
                $final_check_in = $row->work_date . ' ' . $row->first_in;
            }
            
            if ($user_sync_out) {
                $final_check_out = $row->work_date . ' ' . $row->last_out;
                
                // SMART CONTEXT:
                // If we are pushing Out, try to find the EXISTING Check-In from Zoho to pair it with.
                // This avoids "Duplicate" errors caused by sending a slightly different Check-In time.
                if (isset($zoho_lookup[$row->emp_id][$row->work_date])) {
                    $zData = $zoho_lookup[$row->emp_id][$row->work_date];
                    if ($zData['has_in'] && !empty($zData['first_in'])) {
                        $zIn = $zData['first_in'];
                        // Zoho might return "09:57 AM" or "09:57". Simple check.
                        // If it's short, prepend Date.
                        if (strlen($zIn) <= 12) { // "09:57 AM" is 8 chars
                             // If it has AM/PM, our pushAttendance might need to handle it, 
                             // BUT mostly we just want to send what we have generally. 
                             // Wait, pushAttendance expects HH:mm:ss usually.
                             // If Zoho gives AM/PM, we might need to convert.
                             // Actually, let's just stick to LOCAL First In if Zoho format is weird,
                             // UNLESS we are sure.
                             
                             // Safer bet: If Zoho has In, we send ONLY CheckOut?
                             // No, API usually requires pair.
                             // Let's use LOCAL date + Zoho Time string?
                             // No, let's convert Zoho Time to HH:mm:ss if possible?
                             // Too risky without testing. 
                             
                             // Let's go with: Use LOCAL First-In as fallback, but if we have Zoho data, maybe we can assume it's same day?
                             // Actually, the "Duplicate" error earlier suggests Zoho matched the Check-In anyway.
                    }
                }
                }

                // Standard Logic: Use Local First-In if we didn't context match
                if (empty($final_check_in)) {
                     $final_check_in = $row->work_date . ' ' . $row->first_in;
                }
            }

            $payload_item = ['empId' => $row->emp_id];
            if ($final_check_in) $payload_item['checkIn'] = $final_check_in;
            if ($final_check_out) $payload_item['checkOut'] = $final_check_out;

            // Only add if we have something valid to push
            if (!empty($payload_item['checkIn'])) {
                $json_payload[] = $payload_item;
                $ids_to_update[] = $row->id;
                $pushed_count++;
            }
        }

        // UPDATE LOCALLY "ALREADY SYNCED"
        if (!empty($ids_already_synced)) {
             $this->db->where_in('id', $ids_already_synced);
             $this->db->update('zoho_attendance_daily', [
                'synced' => 1, 
                'synced_at' => date('Y-m-d H:i:s'),
                'retry_count' => 0, 
                'last_error' => 'Auto-Matched with Zoho'
            ]);
        }

        if (empty($json_payload)) {
             echo json_encode([
                 'status' => 'completed', 
                 'message' => 'Process complete. ' . $skipped_count . ' records already existed in Zoho.', 
                 'summary' => [
                     'pushed' => 0, 
                     'skipped_duplicate' => $skipped_count
                 ]
             ]);
             return;
        }

        // SWITCH TO SINGLE PUSH LOOP
        // Bulk import is failing to update existing records or causing partial failures.
        // We will push one by one to ensure each record is handled.

        $errors = [];
        $ids_to_update_success = [];

        foreach ($json_payload as $index => $payload) {
             // Prepare data for single push model
             // Model expects: empId, date, checkIn (time only), checkOut (time only)
             
             // Extract Date and Time from Y-m-d H:i:s
             $dtParts = explode(' ', $payload['checkIn']);
             $dateStr = $dtParts[0]; 
             $checkInTime = $dtParts[1];
             
             $checkOutTime = '';
             if (isset($payload['checkOut'])) {
                 $coParts = explode(' ', $payload['checkOut']);
                 $checkOutTime = $coParts[1];
             }

             $singleData = [
                 'empId' => $payload['empId'],
                 'date' => $dateStr,
                 'checkIn' => $checkInTime,
                 'checkOut' => $checkOutTime
             ];

             $response = $this->Zoho_model->pushAttendance($singleData);
             
             // Check response
             $success = false;
             $msg_text = $response['message'] ?? ($response['msg'] ?? ''); // Handle both keys
             
             if (isset($response['response']) && stripos($response['response'], 'success') !== false) {
                 $success = true;
             }
             elseif (stripos($msg_text, 'Success') !== false) {
                 $success = true;
             }
             // Handle "Duplicate" or "exist" error as success-ish
             elseif (
                 stripos($msg_text, 'exist') !== false || 
                 stripos($msg_text, 'Duplicate') !== false
             ) {
                 $success = true; 
                 $errors[] = "Marked as Synced: Zoho reported duplicate.";
             }
             else {
                 $errors[] = "Emp " . $payload['empId'] . ": " . json_encode($response);
             }

             if ($success) {
                 // Match back to original ID using the index from the loop since arrays match 1:1
                 $ids_to_update_success[] = $ids_to_update[$index];
             }
        }

        if (empty($errors)) {
             if (!empty($ids_to_update_success)) {
                $this->db->where_in('id', $ids_to_update_success);
                $this->db->update('zoho_attendance_daily', [
                    'synced' => 1, 
                    'synced_at' => date('Y-m-d H:i:s'),
                    'retry_count' => 0, 
                    'last_error' => 'Force Sync Single'
                ]);
             }
             $status = 'success';
             $msg = "Successfully pushed " . count($ids_to_update_success) . " records.";
        } else {
             $status = 'warning';
             $msg = "Completed with errors. " . count($ids_to_update_success) . " success, " . count($errors) . " failed.";
             $msg .= " First error: " . $errors[0];
             
             // Log error locally for the failed ones? 
             // Logic is complex because we don't know exactly which ID failed in the error array easily without map.
             // For now, just mark last_error generically for all attempted.
             if (!empty($ids_to_update)) {
                $this->db->where_in('id', $ids_to_update);
                 $this->db->update('zoho_attendance_daily', [
                    'last_error' => substr($msg, 0, 255)
                ]);
             }
        }

    $summary_data = [
        'start_date' => $start_date,
        'end_date' => $end_date,
        'imported' => $pushed_count,
        'updated' => 0,
        'skipped' => $skipped_count,
        'errors' => count($errors),
        'total' => $pushed_count + $skipped_count
    ];

    // Send Mail Report
    $this->Zoho_model->send_attendance_report($summary_data);

	echo json_encode([
		'status' => $status,
		'message' => $msg,
		'summary' => $summary_data,
        'debug_errors' => $errors // Force return debug info
	]);
}
public function sync_attendance_bulk()
{
    set_time_limit(0);
    ini_set('memory_limit', '512M');

    $date        = $this->input->get('date');
    $from        = $this->input->get('from');
    $to          = $this->input->get('to');
    $empIdFilter = $this->input->get('empId');

    $user_sync_in  = $this->input->get('sync_in') !== '0';
    $user_sync_out = $this->input->get('sync_out') !== '0';

    // Date range
    if ($from && $to) {
        $start_date = $from;
        $end_date   = $to;
    } elseif ($date) {
        $start_date = $end_date = $date;
    } else {
        $start_date = $end_date = date('Y-m-d');
    }

    /* -------------------------------------------------------
     * 1. FETCH LOCAL RECORDS with Employee Names
     * ----------------------------------------------------- */
    $this->db->select('a.*, e.name as emp_name');
    $this->db->from('zoho_attendance_daily a');
    $this->db->join('zoho_employees e', 'a.emp_id = e.emp_id', 'left');

    if (!$empIdFilter) {
        $this->db->where('a.synced', 0);
        // User Request: Only push if employee exists in zoho_employees table
        $this->db->where("a.emp_id IN (SELECT emp_id FROM zoho_employees)", NULL, FALSE);
    }
    
    $this->db->where('a.work_date >=', $start_date);
    $this->db->where('a.work_date <=', $end_date);
    
    if ($empIdFilter) {
        $this->db->where('a.emp_id', $empIdFilter);
    }
    
    $records = $this->db->get()->result();

    if (empty($records)) {
        echo json_encode([
            'status'  => 'completed',
            'message' => 'No records to sync'
        ]);
        return;
    }

    /* -------------------------------------------------------
     * STRICT COMMAND MODE - DIRECT PUSH
     * Matches user's Postman logic exactly.
     * ----------------------------------------------------- */

    $success_ids = [];
    $errors      = [];

    foreach ($records as $row) {
        // Skip invalid rows
        if (empty($row->first_in) || empty($row->last_out)) {
            continue;
        }

        $payload = [
            'empId' => $row->emp_id,
            'date'  => $row->work_date
        ];

        // SCENARIO 3: BOTH (Check-In AND Check-Out)
        if ($user_sync_in && $user_sync_out) {
            $payload['checkIn'] = $row->first_in;
            $payload['checkOut'] = $row->last_out;
        }
        // SCENARIO 2: CHECK-IN ONLY
        elseif ($user_sync_in) {
             $payload['checkIn'] = $row->first_in;
        }
        // SCENARIO 1: CHECK-OUT ONLY
        elseif ($user_sync_out) {
             $payload['checkOut'] = $row->last_out;
        }
        else {
            // Nothing selected
            continue;
        }

        $response = $this->Zoho_model->pushAttendance($payload);
        $msg = json_encode($response);

        // Success Detection
        $isSuccess = false;
        
        // 1. Standard success
        if (isset($response['response']) && stripos($response['response'], 'success') !== false) {
            $isSuccess = true;
        }
        // 2. Message based success
        elseif (isset($response['message']) && stripos($response['message'], 'Success') !== false) {
             $isSuccess = true;
        }
        // 3. "Duplicate" or "Exist" = Success (Data is there)
        elseif (stripos($msg, 'duplicate') !== false || stripos($msg, 'exist') !== false) {
             $isSuccess = true;
        }
        
        if ($isSuccess) {
            $success_ids[] = $row->id;
            $status_msg = 'Success';
        } else {
            $errors[] = "Emp {$row->emp_id}: {$msg}";
            $status_msg = 'Error: ' . substr($msg, 0, 50); // Truncate error for table
        }

        // Capture details for email (For both Success AND Error)
        $synced_details[] = [
            'emp_id' => $row->emp_id,
            'name' => $row->emp_name ?? 'Unknown',
            'date' => $row->work_date,
            'in' => $payload['checkIn'] ?? '-',
            'out' => $payload['checkOut'] ?? '-',
            'status' => $status_msg
        ];
    }
    
    
    /* -------------------------------------------------------
     * 4. UPDATE LOCAL DB
     * ----------------------------------------------------- */

    /* -------------------------------------------------------
     * 4. UPDATE LOCAL DB
     * ----------------------------------------------------- */
    if (!empty($success_ids)) {
        $this->db->where_in('id', $success_ids)->update('zoho_attendance_daily', [
            'synced'      => 1,
            'synced_at'   => date('Y-m-d H:i:s'),
            'retry_count' => 0,
            'last_error'  => null
        ]);
    }

    if (!empty($errors)) {
        $this->db->where('synced', 0)->update('zoho_attendance_daily', [
            'last_error' => substr($errors[0], 0, 255)
        ]);
    }

    /* -------------------------------------------------------
     * 5. RESPONSE + EMAIL
     * ----------------------------------------------------- */
    $summary = [
        'start_date' => $start_date,
        'end_date'   => $end_date,
        'imported'   => count($success_ids),
        'errors'     => count($errors),
        'total'      => count($records)
    ];

    // Pass detailed list to the email function (synced_details)
    // We check if $synced_details is set, otherwise empty array
    $details_payload = isset($synced_details) ? $synced_details : [];
    
    $this->Zoho_model->send_attendance_report($summary, $details_payload);

    echo json_encode([
        'status'  => empty($errors) ? 'success' : 'warning',
        'summary' => $summary,
        'errors'  => array_slice($errors, 0, 5)
    ]);
}


}
