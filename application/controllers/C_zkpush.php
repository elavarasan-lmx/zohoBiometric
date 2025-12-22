<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * C_Zkpush Controller - ZKTeco Biometric Data Handler
 * 
 * Handles biometric attendance data from ZKTeco devices
 * Provides device management and statistics
 */
class C_Zkpush extends CI_Controller
{

    public function __construct()
    {
        parent::__construct();
        $this->load->database();
    }

    /**
     * Main endpoint for ZKTeco devices to send biometric data
     * URL: POST /C_zkpush/receive
     * Input: PIN=12345&DateTime=2024-01-15 09:30:00
     * 
     * Process:
     * - Validates PIN (numeric) and DateTime format
     * - Checks device IP is registered in database
     * - Prevents duplicate punches within 1 minute
     * - Stores attendance in zoho_attendance_raw (GROUND floor) or zoho_break_logs (other floors)
     * - Triggers auto-sync to Zoho if complete attendance available
     * - Logs all activity with detailed data
     * 
     * Responses:
     * - OK - Success
     * - INVALID_DATA - Missing/invalid PIN or DateTime
     * - DEVICE_NOT_REGISTERED - Unknown device IP
     * - DUPLICATE - Same punch within 1 minute
     */
    public function receive()
    {
        // Get raw POST data from ZKTeco device
        $raw = file_get_contents("php://input");
        parse_str($raw, $data);

        // Validate incoming data format
        $validation = $this->validate_attendance_data($data);
        if (!$validation['valid']) {
            log_message('error', '[BIOMETRIC] Invalid data: ' . $validation['error'] . ' | Data: ' . json_encode($data));
            http_response_code(400);
            echo "INVALID_DATA";
            return;
        }

        // Verify device is registered and authorized
        $device = $this->get_device_by_ip($_SERVER['REMOTE_ADDR']);
        if (!$device) {
            log_message('error', '[BIOMETRIC] Unknown device IP: ' . $_SERVER['REMOTE_ADDR']);
            http_response_code(403);
            echo "DEVICE_NOT_REGISTERED";
            return;
        }

        $time = date('Y-m-d H:i:s', strtotime($data['DateTime']));

        // Prevent duplicate entries within 1 minute window
        // This handles cases where device sends same punch multiple times
        $existing = $this->db->where([
            'emp_id' => $data['PIN'],
            'punch_time >=' => date('Y-m-d H:i:s', strtotime($time) - 60),
            'punch_time <=' => date('Y-m-d H:i:s', strtotime($time) + 60)
        ])->get('zoho_attendance_raw')->row();

        if ($existing) {
            echo "DUPLICATE";
            return;
        }

        // Store punch data based on device floor
        // GROUND floor = main attendance (zoho_attendance_raw)
        // Other floors = break logs (zoho_break_logs)
        if ($device->floor === 'GROUND') {
            $this->db->insert('zoho_attendance_raw', [
                'emp_id'     => $data['PIN'],
                'work_date'  => date('Y-m-d', strtotime($time)),
                'punch_time' => $time,
                'punch_type' => $device->direction,  // IN or OUT
                'device_id'  => $device->id
            ]);
        } else {
            $this->db->insert('zoho_break_logs', [
                'emp_id'     => $data['PIN'],
                'punch_time' => $time,
                'punch_type' => $device->direction,
                'device_id'  => $device->id
            ]);
        }

        log_message('info', '[BIOMETRIC] Data received successfully | Emp: ' . $data['PIN'] . ', Device: ' . $device->device_name);

        // Auto-sync to Zoho if enabled and attendance is complete
        $this->auto_sync_to_zoho($data['PIN'], $time);

        echo "OK";
    }

    /**
     * Automatically sync complete attendance to Zoho
     * Trigger: After each punch received
     * Logic: Only syncs when employee has both IN and OUT times for the day
     * Result: Updates daily_attendance.synced = 1 on success
     */
    private function auto_sync_to_zoho($emp_id, $punch_time)
    {
        // Feature flag - set to false to disable auto-sync
        $auto_sync = true;
        if (!$auto_sync) return;

        $this->load->model('Zoho_model');

        $today = date('Y-m-d', strtotime($punch_time));
        
        // Check if employee has complete attendance (both check-in and check-out)
        $attendance = $this->db->where(['emp_id' => $emp_id, 'work_date' => $today])
            ->get('daily_attendance')->row();

        // Only sync if both times exist and not already synced
        if ($attendance && $attendance->check_in && $attendance->check_out && !$attendance->synced) {
            // Push complete attendance to Zoho
            $res = $this->Zoho_model->pushAttendance([
                'empId' => $emp_id,
                'date' => $today,
                'checkIn' => $attendance->check_in,
                'checkOut' => $attendance->check_out
            ]);

            // Mark as synced if successful
            if (is_array($res) && isset($res[0]['response']) && $res[0]['response'] === 'success') {
                $this->db->update('daily_attendance', ['synced' => 1], ['id' => $attendance->id]);
                log_message('info', '[AUTO_SYNC] Successfully auto-synced ' . $emp_id . ' for ' . $today);
            }
        }
    }

    /**
     * Validate incoming biometric data format
     * Checks: PIN exists and is numeric, DateTime exists and is valid format
     * Returns: ['valid' => true/false, 'error' => 'message']
     */
    private function validate_attendance_data($data)
    {
        if (
            !isset($data['PIN'], $data['DateTime']) ||
            empty($data['PIN']) ||
            !is_numeric($data['PIN']) ||
            !strtotime($data['DateTime'])
        ) {
            return ['valid' => false, 'error' => 'Invalid PIN or DateTime'];
        }
        return ['valid' => true];
    }

    /**
     * Find registered device by IP address
     * Returns: Device object or null
     * Used: To verify device is authorized to send data
     */
    private function get_device_by_ip($ip)
    {
        return $this->db->get_where('zoho_biometric_devices', [
            'device_ip' => $ip,
            'status' => 'active'
        ])->row();
    }

    /**
     * Register new biometric device
     * URL: POST /C_zkpush/register_device
     * Parameters:
     * - $name - Device name (e.g., "Main Entry IN")
     * - $ip - Device IP address
     * - $floor - GROUND/FIRST (GROUND = attendance, others = break logs)
     * - $direction - IN/OUT punch type
     * Usage: Add new ZKTeco device to system
     */
    public function register_device($name, $ip, $floor = 'GROUND', $direction = 'IN')
    {
        return $this->db->insert('zoho_biometric_devices', [
            'device_name' => $name,
            'device_ip' => $ip,
            'floor' => $floor,
            'direction' => $direction,
            'status' => 'active'
        ]);
    }

    /**
     * Get daily attendance statistics
     * URL: GET /C_zkpush/stats?date=2024-01-15
     * Returns JSON:
     * {
     *   "total_punches": 150,
     *   "unique_employees": 75,
     *   "pending_sync": 5
     * }
     */
    public function stats($date = null)
    {
        $date = $date ?: date('Y-m-d');

        $stats = [
            'total_punches' => $this->db->where('work_date', $date)
                ->count_all_results('zoho_attendance_raw'),
            'unique_employees' => $this->db->select('COUNT(DISTINCT emp_id) as count')
                ->where('work_date', $date)
                ->get('zoho_attendance_raw')
                ->row()->count,
            'pending_sync' => $this->db->where(['work_date' => $date, 'synced' => 0])
                ->count_all_results('daily_attendance')
        ];

        echo json_encode($stats);
    }
}
