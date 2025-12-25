<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Zoho_model - Zoho People API Integration Model
 * 
 * Complete Zoho People API wrapper with OAuth 2.0 authentication, attendance management,
 * employee synchronization, and email notifications for biometric attendance system
 * 
 * Core Features:
 * - OAuth 2.0 token management with automatic refresh and in-memory caching
 * - Employee data synchronization from Zoho People API
 * - Attendance data import/export with date range support
 * - Attendance push to Zoho People with proper date formatting
 * - Email notifications for sync failures and system alerts
 * - SSL certificate bypass for development environments
 * - Enhanced error handling and logging
 * - Optimized cURL timeouts (60s main, 30s connection)
 * 
 * Available Methods:
 * - getEmployees() - Fetch all employee records from Zoho People
 * - getAttendance($date, $empId) - Get attendance data for specific date/employee
 * - pushAttendance($data) - Push attendance record to Zoho People
 * - send_notification($subject, $message) - Send email notification to admin
 * 
 * API Endpoints Used:
 * - /api/forms/employee/getRecords - Employee master data
 * - /people/api/attendance/getAttendanceEntries - Attendance import
 * - /people/api/attendance - Attendance push/export
 * 
 * Authentication:
 * - Uses refresh token for automatic access token renewal
 * - Tokens cached in memory for performance (3500 second expiry)
 * - No database storage required for tokens
 * 
 * Data Formats:
 * - Input dates: Y-m-d format (2025-12-21)
 * - Zoho API dates: d-M-Y format (21-Dec-2025) for import
 * - Zoho API dates: d/m/Y format (21/12/2025) for export
 * - Times: H:i:s format (09:30:00)
 * 
 * Error Handling:
 * - Comprehensive cURL error detection
 * - HTTP status code validation
 * - Detailed error logging with context
 * - Graceful fallback for failed operations
 * 
 * Performance Optimizations:
 * - In-memory token caching eliminates database calls
 * - Optimized cURL settings for reliability
 * - Efficient date format conversions
 * - Minimal memory footprint
 * 
 * Important Notes:
 * - Zoho API does NOT support updating existing attendance records
 * - Both check-in and check-out must be sent together in one request
 * - Duplicate errors are treated as success (record already exists in Zoho)
 * - API returns "Duplicate Check-in/Check-out entry" for existing records
 */
class Zoho_model extends CI_Model
{
    private $people_url;
    private $accounts_url;
    private $client_id;
    private $client_secret;
    private $refresh_token;
    private $ssl_verify;
    private $access_token = null;
    private $token_expires = null;

    public function __construct()
    {
        parent::__construct();

        // Load Zoho API URLs from global config
        $this->people_url     = Globals::$zoho_people_url;
        $this->accounts_url   = Globals::$zoho_accounts_url;

        // Load Zoho credentials from global config
        $this->client_id     = Globals::$zoho_client_id;
        $this->client_secret = Globals::$zoho_client_secret;
        $this->refresh_token = Globals::$zoho_refresh_token;

        // SSL verification only in production
        $this->ssl_verify = (ENVIRONMENT === 'production');

        // Validate credentials early
        if (empty($this->client_id) || empty($this->client_secret) || empty($this->refresh_token)) {
            log_message('error', '[ZOHO] Missing OAuth credentials in global_configs.php');
        }
    }


    /**
     * Get valid access token (auto-refresh if expired)
     * 
     * @return string - Valid access token
     * 
     * Process:
     * - Checks if current token is still valid
     * - If expired, uses refresh token to get new access token
     * - Updates database with new token and expiry time
     * - Returns valid token for API calls
     */
    private function token()
    {
        $cache_file = APPPATH . 'cache/zoho_token.json';
        
        // 1. Try to read from cache file
        if (file_exists($cache_file)) {
            $cache = json_decode(file_get_contents($cache_file), true);
            if (isset($cache['access_token']) && isset($cache['expires_at'])) {
                // Return cached token if still valid (with 60s buffer)
                if (time() < ($cache['expires_at'] - 60)) {
                    $this->access_token = $cache['access_token'];
                    return $this->access_token;
                }
            }
        }

        // 2. Refresh token if cache missing or expired
        $ch = curl_init($this->accounts_url . '/oauth/v2/token');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'refresh_token' => $this->refresh_token,
                'client_id' => $this->client_id,
                'client_secret' => $this->client_secret,
                'grant_type' => 'refresh_token'
            ]),
            CURLOPT_SSL_VERIFYPEER => $this->ssl_verify,
            CURLOPT_SSL_VERIFYHOST => $this->ssl_verify ? 2 : 0
        ]);
        
        $response = curl_exec($ch);
        $res = json_decode($response, true);
        curl_close($ch);

        if (isset($res['access_token'])) {
            // 3. Save new token to cache file
            $cache_data = [
                'access_token' => $res['access_token'],
                'expires_at' => time() + ($res['expires_in'] ?? 3600), // Default to 1 hour if not provided
                'generated_at' => date('Y-m-d H:i:s')
            ];
            
            // Ensure cache directory exists
            if (!is_dir(APPPATH . 'cache')) {
                mkdir(APPPATH . 'cache', 0755, true);
            }
            
            file_put_contents($cache_file, json_encode($cache_data));

            $this->access_token = $res['access_token'];
            return $this->access_token;
        }

        log_message('error', '[ZOHO] Token refresh failed: ' . $response);
        return null; // Token refresh failed
    }

    /**
     * Generic cURL wrapper for Zoho API calls with JSON content
     * 
     * @param string $url - API endpoint URL
     * @param string $method - HTTP method (GET, POST, PUT, DELETE)
     * @param array $data - Request data (optional)
     * @return array - Decoded JSON response with http_code
     * 
     * Features:
     * - Auto-adds OAuth token to headers
     * - JSON content type for data requests
     * - SSL certificate bypass for development
     * - Error logging for failed requests
     * - 60 second timeout with 30 second connection timeout (optimized for reliability)
     */
    private function curl($url, $method = 'GET', $data = null)
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => [
                'Authorization: Zoho-oauthtoken ' . $this->token(),
                'Content-Type: application/json'
            ],
            CURLOPT_POSTFIELDS => $data ? json_encode($data) : null,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => $this->ssl_verify,
            CURLOPT_SSL_VERIFYHOST => $this->ssl_verify ? 2 : 0
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            log_message('error', 'Zoho API cURL Error: ' . $error);
            return ['error' => $error, 'http_code' => 0];
        }

        curl_close($ch);
        $decoded = json_decode($response, true);

        if ($httpCode >= 400) {
            log_message('error', 'Zoho API HTTP Error: ' . $httpCode . ' - ' . $response);
        }

        return array_merge($decoded ?: [], ['http_code' => $httpCode]);
    }

    /**
     * Form-encoded cURL wrapper for Zoho attendance API
     * 
     * @param string $url - Complete URL with query parameters
     * @return array - Decoded JSON response
     * 
     * Used specifically for:
     * - Attendance submission (requires form-encoded content)
     * - APIs that don't accept JSON format
     * 
     * Features:
     * - Form-encoded content type
     * - OAuth token authentication
     * - SSL bypass for development
     * - Error handling with logging
     */
    private function curlForm($url)
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_HTTPHEADER     => [
                'Authorization: Zoho-oauthtoken ' . $this->token(),
                'Content-Type: application/x-www-form-urlencoded'
            ],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => $this->ssl_verify,
            CURLOPT_SSL_VERIFYHOST => $this->ssl_verify ? 2 : 0
        ]);

        $res = curl_exec($ch);

        if ($res === false) {
            $error = curl_error($ch);
            curl_close($ch);
            return ['error' => $error];
        }

        curl_close($ch);
        return json_decode($res, true);
    }

    /**
     * Fetch all employee records from Zoho People
     * 
     * @return array - Zoho API response
     *   Success: ['response' => ['result' => [employee_array]]]
     *   Error: ['error' => 'error_message']
     * 
     * Employee fields returned:
     * - Employee_ID: Unique employee identifier
     * - Display_Name: Full employee name
     * - FirstName: First name only
     * - Email_ID: Employee email address
     * - Department: Department name
     * - Designation: Job title
     * - Employee_Status: Active/Inactive status
     * 
     * Used by: sync_employees() in C_zoho controller
     */
    public function getEmployees()
    {
        return $this->curl($this->people_url . '/api/forms/employee/getRecords');
    }

    /**
     * Get attendance data from Zoho People for specific date and employee
     * 
     * @param string $date - Date in Y-m-d format
     * @param string $empId - Employee ID (optional)
     * @return array - Zoho API response with attendance data
     * 
     * Response format:
     * {
     *   "firstIn": "2025-12-21 09:00:00",
     *   "lastOut": "2025-12-21 18:00:00", 
     *   "totalHrs": "09:00",
     *   "status": "Present",
     *   "entries": [...]
     * }
     * 
     * Note: Converts Y-m-d to d-M-Y format required by Zoho API
     * Used by: import_attendance() in C_zoho controller
     */
    public function getAttendance($date, $empId = null)
    {
        // Use getUserReport instead of getAttendanceEntries for better reliability
        $response = $this->getUserReport($date, $date, 0, $empId);
        
        $result = [];
        
        if (isset($response['result'][0])) {
            $data = $response['result'][0];

            // In User Report structure provided by user:
            // result -> 0 -> attendanceDetails -> date -> FirstIn
            if (isset($data['attendanceDetails'][$date])) {
                $dayData = $data['attendanceDetails'][$date];
                
                $result['firstIn'] = $dayData['FirstIn'] ?? ($dayData['First In'] ?? ($dayData['First_In'] ?? '-'));
                $result['lastOut'] = $dayData['LastOut'] ?? ($dayData['Last Out'] ?? ($dayData['Last_Out'] ?? '-'));
                $result['totalHrs'] = $dayData['TotalHours'] ?? ($dayData['Total Hours'] ?? ($dayData['Total_Hours'] ?? '00:00'));
                $result['status'] = $dayData['Status'] ?? ($dayData['Attendance Status'] ?? '-');
                $result['raw'] = $dayData;
            } else {
                $result['firstIn'] = '-';
                $result['lastOut'] = '-';
                $result['status'] = 'No details for date';
            }
        } else {
            // Return empty structure to indicate valid call but no data
            $result['firstIn'] = '-';
            $result['lastOut'] = '-';
            $result['status'] = 'Absent/No Data';
        }
        
        return $result;
    }

    /**
     * Push attendance record to Zoho People
     * 
     * @param array $data - Attendance data
     *   - empId: Employee ID
     *   - date: Work date (Y-m-d)
     *   - checkIn: Check-in time (HH:MM:SS)
     *   - checkOut: Check-out time (HH:MM:SS)
     * 
     * @return array - Zoho API response
     *   Success: [['response' => 'success', ...]]
     *   Error: [['response' => 'error', 'msg' => 'error_message']]
     *   Duplicate: [['response' => 'error', 'msg' => 'Duplicate Check-in/Check-out entry']]
     * 
     * Process:
     * - Converts date format from Y-m-d to d/m/Y
     * - Combines date and time for Zoho format
     * - Sends via form-encoded POST request
     * 
     * Note: Zoho API does NOT support updates - only new records
     */
    public function pushAttendance($data)
    {
        // Convert date format: Y-m-d to d/m/Y (e.g., 2025-12-21 to 21/12/2025)
        $date = DateTime::createFromFormat('Y-m-d', $data['date'])
            ->format('d/m/Y');

        // Build query parameters with date and time combined
        $params = http_build_query([
            'empId'      => $data['empId'],
            'dateFormat' => 'dd/MM/yyyy HH:mm:ss',
            'checkIn'    => $date . ' ' . $data['checkIn'],  // e.g., 21/12/2025 09:00:00
            'checkOut'   => $date . ' ' . $data['checkOut']  // e.g., 21/12/2025 18:00:00
        ]);

        // Send via form-encoded POST (Zoho attendance API requirement)
        return $this->curlForm(
            $this->people_url . '/people/api/attendance?' . $params
        );
    }

    /**
     * Send email notification to admin
     * 
     * @param string $subject - Email subject
     * @param string $message - Email message (HTML supported)
     * @return bool - Success/failure
     * 
     * Uses existing MLogin_model email configuration
     * Sends to admin email configured in system
     * Used for sync failure alerts and system notifications
     */
    public function send_notification($subject, $message)
    {
        // SMTP configuration for Gmail
        $config = [
            'protocol' => 'smtp',
            'smtp_host' => 'ssl://smtp.googlemail.com',
            'smtp_port' => 465,
            'smtp_user' => Globals::$admin_mail_server,
            'smtp_pass' => Globals::$admin_mail_password,
            'mailtype' => 'html',
            'charset' => 'iso-8859-1'
        ];

        $this->email->initialize($config);
        $this->email->set_newline("\r\n");
        $this->email->from(Globals::$admin_mail_server, Globals::$admin_company_name);
        $this->email->to(Globals::$admin_mail);
        $this->email->subject($subject);
        $this->email->message($message);

        return $this->email->send();
    }

    /**
     * Bulk Import Attendance to Zoho
     * 
     * @param array $records - Array of attendance entries
     * Format: [['empId' => '1', 'checkIn' => '...', 'checkOut' => '...'], ...]
     * @return array - API Response
     */
    public function bulkImportAttendance($records)
    {
        $url = $this->people_url . '/people/api/attendance/bulkImport';
        
        $postData = [
            'data' => json_encode($records),
            'dateFormat' => 'yyyy-MM-dd HH:mm:ss'
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Zoho-oauthtoken ' . $this->token(),
                'Content-Type: multipart/form-data' 
            ],
            CURLOPT_POSTFIELDS => $postData, // cURL handles multipart automatically with array
            CURLOPT_TIMEOUT => 120, // Longer timeout for bulk operations
            CURLOPT_SSL_VERIFYPEER => $this->ssl_verify,
            CURLOPT_SSL_VERIFYHOST => $this->ssl_verify ? 2 : 0
        ]);

        $res = curl_exec($ch);
        
        if ($res === false) {
            $error = curl_error($ch);
            curl_close($ch);
            return ['error' => $error];
        }

        curl_close($ch);
        return json_decode($res, true);
    }

    /**
     * Get User Report (Attendance) from Zoho
     * Used for checking existing records before sync
     * 
     * @param string $sdate - Start Date (Y-m-d)
     * @param string $edate - End Date (Y-m-d)
     * @param int $startIndex - Pagination offset (0, 100, 200...)
     * @param string $empId - Optional Employee ID filter
     * @return array - API Response
     */
    public function getUserReport($sdate, $edate, $startIndex = 0, $empId = null)
    {
        // Convert dates to dd-MM-yyyy standard (which we know now works better for 500 errors)
        $s_formatted = DateTime::createFromFormat('Y-m-d', $sdate)->format('d-m-Y');
        $e_formatted = DateTime::createFromFormat('Y-m-d', $edate)->format('d-m-Y');
        
        $params = [
            'sdate' => $s_formatted,
            'edate' => $e_formatted,
            'dateFormat' => 'dd-MM-yyyy',
            'startIndex' => $startIndex
        ];

        if ($empId) {
            $params['empId'] = $empId;
        }

        $url = $this->people_url . '/people/api/attendance/getUserReport?' . http_build_query($params);
        
        return $this->curl($url);
    }

    /**
     * Helper to send any sync report via email
     */
    public function send_simple_report($title, $stats)
    {
        $subject = "Sync Alert: " . $title . " [" . date('Y-m-d') . "]";
        $message = "<h2>$title</h2>";
        $message .= "<table border='1' cellpadding='5' style='border-collapse: collapse;'>";
        foreach ($stats as $key => $val) {
            $label = ucwords(str_replace(['_', '-'], ' ', $key));
            $message .= "<tr><td><b>$label</b></td><td>$val</td></tr>";
        }
        $message .= "</table>";
        $message .= "<p>Generated at: " . date('Y-m-d H:i:s') . "</p>";

        return $this->send_notification($subject, $message);
    }

    /**
     * Helper to send attendance sync report via email (Unified Format)
     */
    public function send_attendance_report($s)
    {
        $subject = "Zoho Attendance Sync Report: " . ($s['start_date'] ?? date('Y-m-d')) . " to " . ($s['end_date'] ?? date('Y-m-d'));
        $message = "<h2>Zoho Attendance Sync Completed</h2>";
        $message .= "<table border='1' cellpadding='5' style='border-collapse: collapse;'>";
        $message .= "<tr><td><b>Period</b></td><td>" . ($s['start_date'] ?? '-') . " to " . ($s['end_date'] ?? '-') . "</td></tr>";
        $message .= "<tr><td><b>Total Records</b></td><td>" . ($s['total'] ?? 0) . "</td></tr>";
        $message .= "<tr><td><b style='color: green;'>Imported (New)</b></td><td>" . ($s['imported'] ?? 0) . "</td></tr>";
        $message .= "<tr><td><b style='color: blue;'>Updated (Existing)</b></td><td>" . ($s['updated'] ?? 0) . "</td></tr>";
        $message .= "<tr><td><b style='color: orange;'>Skipped (Absent)</b></td><td>" . ($s['skipped'] ?? 0) . "</td></tr>";
        $message .= "<tr><td><b style='color: red;'>Errors</b></td><td>" . ($s['errors'] ?? 0) . "</td></tr>";
        $message .= "</table>";
        $message .= "<p>Date: " . date('Y-m-d H:i:s') . "</p>";

        return $this->send_notification($subject, $message);
    }
}
