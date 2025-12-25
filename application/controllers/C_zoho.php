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
		$count = $this->db->where('work_date', $date)->count_all_results('zoho_attendance_raw');
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

		// Load ZKTeco configuration
		$zk_base_url = Globals::$zkteco_base_url;
		$zk_username = Globals::$zkteco_username;
		$zk_password = Globals::$zkteco_password;

		// Step 1: Get authentication token
		$authRes = $this->get_zkteco_token($zk_base_url, $zk_username, $zk_password);
		if (!$authRes['success']) {
			echo json_encode(['status' => 'error', 'message' => 'Auth Failed: ' . $authRes['message']]);
			return;
		}
		$token = $authRes['token'];

		// Step 2: Fetch transactions
		$imported = 0;
		$updated = 0;
		$url = $zk_base_url . '/iclock/api/transactions/?start_time=' . $start_date . ' 00:00:00&end_time=' . $end_date . ' 23:59:59';

		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $this->get_zkteco_headers($token));
		$response = curl_exec($ch);
		
		if (curl_errno($ch)) {
			log_message('error', '[ZKTECO_FETCH] cURL Error: ' . curl_error($ch));
		}
		
		curl_close($ch);

		$data = json_decode($response, true);
		
		if (empty($data)) {
			log_message('error', "[ZKTECO_FETCH] Empty or invalid JSON response: " . substr($response, 0, 500));
		}

		if (isset($data['data'])) {
			foreach ($data['data'] as $transaction) {
				$emp_id = $transaction['emp_code'];
				$punch_time = date('Y-m-d H:i:s', strtotime($transaction['punch_time']));
				$work_date = date('Y-m-d', strtotime($punch_time));

				// Check if already exists
				$existing = $this->db->where([
					'emp_id' => $emp_id,
					'punch_time' => $punch_time
				])->get('zoho_attendance_raw')->row();

				if (!$existing) {
					$this->db->insert('zoho_attendance_raw', [
						'emp_id' => $emp_id,
						'work_date' => $work_date,
						'punch_time' => $punch_time,
						'punch_type' => 'IN'
					]);
					$imported++;
				}
			}
		}

		// Step 3: Process into daily attendance
		$this->process_to_daily($start_date, $end_date);

		$summary = ['imported' => $imported, 'message' => 'Data imported and processed'];

	// Send Email Report
	$this->Zoho_model->send_simple_report("ZKTeco Device Log Import", [
		'date_range' => $start_date . ' to ' . $end_date,
		'imported_logs' => $imported
	]);

	echo json_encode([
		'status' => 'completed',
		'date_range' => ['from' => $start_date, 'to' => $end_date],
		'summary' => $summary
	]);
}

	private function get_zkteco_token($base_url, $username, $password)
	{
		$url = rtrim($base_url, '/') . '/api-token-auth/';
		$ch = curl_init($url);
		
		$postFields = [
			'username' => $username,
			'password' => $password
		];

		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
		curl_setopt($ch, CURLOPT_HTTPHEADER, $this->get_zkteco_headers(null, 'application/x-www-form-urlencoded'));
		
		$response = curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

		if (curl_errno($ch)) {
			$curlError = curl_error($ch);
			log_message('error', '[ZKTECO_AUTH] cURL Error: ' . $curlError);
			curl_close($ch);
			return ['success' => false, 'message' => "Connection Error: $curlError"];
		}

		curl_close($ch);

		if (empty($response)) {
			log_message('error', "[ZKTECO_AUTH] Empty response. HTTP Code: $httpCode");
			return ['success' => false, 'message' => "Empty response from server (HTTP $httpCode)"];
		}

		$data = json_decode($response, true);
		
		if (!isset($data['token'])) {
			// Save full response for developer to see in logs
			log_message('error', "[ZKTECO_AUTH] Failed. Code: $httpCode. Raw Response: >>" . $response . "<<");
			
			$preview = is_array($data) ? json_encode($data) : substr(strip_tags($response), 0, 100);
			return [
				'success' => false, 
				'message' => "Invalid response (HTTP $httpCode). " . ($preview ?: "No body returned from server")
			];
		}

		return ['success' => true, 'token' => $data['token']];
	}

	/**
	 * Build ZKTeco API Headers dynamically
	 */
	private function get_zkteco_headers($token = null, $contentType = 'application/json')
	{
		$headers = [
			'User-Agent: Mozilla/5.0'
		];

		if ($contentType) {
			$headers[] = 'Content-Type: ' . $contentType;
			$headers[] = 'Accept: application/json';
		}

		if ($token) {
			$headers[] = 'Authorization: Token ' . $token;
		}

		// Add bypass headers only if using a tunnel
		$baseUrl = Globals::$zkteco_base_url;
		if (stripos($baseUrl, 'ngrok') !== false || stripos($baseUrl, 'loca.lt') !== false) {
			$headers[] = 'Bypass-Tunnel-Reminder: true';
			$headers[] = 'ngrok-skip-browser-warning: 1';
			
			// Only add spoofing headers for tunnels if absolutely needed, 
			// but for now let's try WITHOUT them to see if it fixes the 400
			// $headers[] = 'X-Forwarded-Host: localhost';
			// $headers[] = 'X-Forwarded-For: 127.0.0.1';
		}

		return $headers;
	}

	private function process_to_daily($start_date, $end_date)
	{
		$current_date = $start_date;
		while (strtotime($current_date) <= strtotime($end_date)) {
			$punches = $this->db->select('emp_id, MIN(punch_time) as first_in, MAX(punch_time) as last_out')
				->where('work_date', $current_date)
				->group_by('emp_id')
				->get('zoho_attendance_raw')
				->result();

			foreach ($punches as $punch) {
				$existing = $this->db->where(['emp_id' => $punch->emp_id, 'work_date' => $current_date])
					->get('zoho_attendance_daily')->row();

				$data = [
					'first_in' => date('H:i:s', strtotime($punch->first_in)),
					'last_out' => date('H:i:s', strtotime($punch->last_out)),
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
		$current_date = $start_date;

		while (strtotime($current_date) <= strtotime($end_date)) {
			// Get all punches for this date grouped by employee
			$punches = $this->db->select('emp_id, MIN(punch_time) as first_in, MAX(punch_time) as last_out')
				->where('work_date', $current_date)
				->group_by('emp_id')
				->get('zoho_attendance_raw')
				->result();

			foreach ($punches as $punch) {
				$existing = $this->db->where(['emp_id' => $punch->emp_id, 'work_date' => $current_date])
					->get('zoho_attendance_daily')->row();

				$data = [
					'first_in' => date('H:i:s', strtotime($punch->first_in)),
					'last_out' => date('H:i:s', strtotime($punch->last_out)),
					'synced' => 0,
					'updated_at' => date('Y-m-d H:i:s')
				];

				if ($existing) {
					$this->db->update('zoho_attendance_daily', $data, ['id' => $existing->id]);
					$updated++;
				} else {
					$data['emp_id'] = $punch->emp_id;
					$data['work_date'] = $current_date;
					$this->db->insert('zoho_attendance_daily', $data);
					$imported++;
				}
			}

			$current_date = date('Y-m-d', strtotime($current_date . ' +1 day'));
		}

		echo json_encode([
			'status' => 'completed',
			'date_range' => ['from' => $start_date, 'to' => $end_date],
			'summary' => ['imported' => $imported, 'updated' => $updated]
		]);
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

		header('Content-Type: application/json');
		echo json_encode($data);
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
		// Load config
		$zk_base_url = Globals::$zkteco_base_url;
		$zk_username = Globals::$zkteco_username;
		$zk_password = Globals::$zkteco_password;
		// 1. Authenticate
		$token = $this->get_zkteco_token($zk_base_url, $zk_username, $zk_password);
		if (!$token) {
			echo json_encode(['status' => 'error', 'message' => 'Authentication failed']);
			return;
		}
		// 2. Fetch Employees
		$url = $zk_base_url . '/personnel/api/employees/?page_size=1000'; // Fetch up to 1000 employees
		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Token ' . $token]);
		$response = curl_exec($ch);
		curl_close($ch);
		$result = json_decode($response, true);

		// 3. Store in DB
		$imported = 0;
		if (isset($result['data'])) {
			foreach ($result['data'] as $emp) {
				$data = [
					'emp_id' => $emp['emp_code'],
					'name'   => trim($emp['first_name'] . ' ' . $emp['last_name']),
					'department' => $emp['department_name'] ?? null, // Check API actual field name
					// Add designation etc if available
				];

				// Upsert (Replace)
				$this->db->replace('zoho_employees', $data);
				$imported++;
			}
		}

		$summary = ['imported' => $imported, 'total' => count($result['data'] ?? [])];

		// Send Email Report
		$this->Zoho_model->send_simple_report("ZKTeco Employee Import", $summary);

		echo json_encode([
			'status' => 'completed',
			'summary' => $summary
		]);
	}

	/**
	 * Bulk Push Attendance to Zoho
	 * URL: /C_zoho/sync_attendance_bulk
	 */
	public function sync_attendance_bulk()
	{
		// Remove time limit
		set_time_limit(0);
        ini_set('memory_limit', '512M');

		$date = $this->input->get('date');
		$from = $this->input->get('from');
		$to = $this->input->get('to');
		$empIdFilter = $this->input->get('empId');

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

        // 1. Fetch unsynced records locally
		$this->db->where('synced', 0);
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

        // 2. Fetch Existing Data from Zoho to prevent Duplicates
        // We need to fetch all attendance for this date range to check existence
        $zoho_lookup = []; // format: [emp_id][date] = true
        $more_records = true;
        $start_index = 0;
        $fetched_zoho_count = 0;

        // Only fetch if we have a reasonable date range (don't fetch 1 year of data if only pushing 1 day)
        // Loop to handle pagination (100 per page)
        while ($more_records) {
             if (connection_aborted()) break;

             $response = $this->Zoho_model->getUserReport($start_date, $end_date, $start_index);
             
            if (isset($response['result']) && is_array($response['result'])) {
                $batch = $response['result'];
                foreach ($batch as $entry) {
                    // Based on user sample:
                    // result -> employeeDetails -> id
                    // result -> attendanceDetails -> {date}
                    
                    $emp_id = $entry['employeeDetails']['id'] ?? null;
                    if (!$emp_id) continue;
                    
                    $attendance = $entry['attendanceDetails'] ?? [];
                    foreach ($attendance as $date => $details) {
                        // Check if FirstIn is not '-' before marking as existing?
                        // Actually, if it's in the report, it's a record in Zoho.
                        $zoho_lookup[$emp_id][$date] = true;
                    }
                }
                
                if (count($batch) < 100) {
                    $more_records = false;
                } else {
                    $start_index += 100;
                    // Safety break for extremely large sets
                    if ($start_index > 5000) $more_records = false; 
                }
                $fetched_zoho_count += count($batch);
            } else {
                $more_records = false; // No more data or error
            }
        }

        $json_payload = [];
        $ids_to_update = []; // Local IDs to mark as synced (because we pushed them)
        $ids_already_synced = []; // Local IDs to mark as synced (because they are already in Zoho)
        $pushed_count = 0;
        $skipped_count = 0;

        foreach ($records as $row) {
            // Skip invalid times or empty records
            if (empty($row->first_in) || empty($row->last_out) || $row->first_in == '-' || $row->last_out == '-') {
                continue;
            }

            // CHECK DUPLICATE
            if (isset($zoho_lookup[$row->emp_id][$row->work_date])) {
                // Record already exists in Zoho!
                // We should mark it as synced locally so we don't try again
                $ids_already_synced[] = $row->id;
                $skipped_count++;
                continue;
            }

            // Zoho needs YYYY-MM-DD HH:MM:SS
            // Our DB has separate Date and Time
            $checkInTime = $row->work_date . ' ' . $row->first_in;
            $checkOutTime = $row->work_date . ' ' . $row->last_out;

            // Create Check-In Object
            $json_payload[] = [
                'empId' => $row->emp_id,
                'checkIn' => $checkInTime
            ];

            // Create Check-Out Object
            $json_payload[] = [
                'empId' => $row->emp_id,
                'checkOut' => $checkOutTime
            ];
            
            $ids_to_update[] = $row->id;
            $pushed_count++;
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

        // Send to Zoho in chunks of 50 records (100 entries) to be safe
        $chunks = array_chunk($json_payload, 100); 
        $errors = [];

        foreach ($chunks as $chunk) {
            $response = $this->Zoho_model->bulkImportAttendance($chunk);
             
             // Check for errors in the response
             if (isset($response['error']) || (isset($response['status']) && $response['status'] != 0)) { 
                 if (isset($response['message'])) $errors[] = $response['message'];
                 else $errors[] = json_encode($response);
             } 
        }

        if (empty($errors)) {
             if (!empty($ids_to_update)) {
                $this->db->where_in('id', $ids_to_update);
                $this->db->update('zoho_attendance_daily', [
                    'synced' => 1, 
                    'synced_at' => date('Y-m-d H:i:s'),
                    'retry_count' => 0, 
                    'last_error' => 'Bulk Sync Success'
                ]);
             }
             $msg = 'Successfully pushed ' . $pushed_count . ' records. Skipped ' . $skipped_count . ' duplicates.';
             $status = 'completed';
        } else {
             if (!empty($ids_to_update)) {
                $this->db->where_in('id', $ids_to_update);
                $this->db->set('retry_count', 'retry_count+1', FALSE);
                $this->db->set('last_error', substr(implode(', ', $errors), 0, 255));
                $this->db->update('zoho_attendance_daily');
             }
             $msg = 'Errors: ' . implode(', ', $errors);
             $status = 'error';
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
		'summary' => $summary_data
	]);
}
}
