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


    // Runtime token storage
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
        // Return cached token if still valid
        if ($this->access_token && $this->token_expires && time() < $this->token_expires) {
            return $this->access_token;
        }

        // Refresh token
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
        $res = json_decode(curl_exec($ch), true);
        curl_close($ch);

        if (isset($res['access_token'])) {
            // Cache token in memory
            $this->access_token = $res['access_token'];
            $this->token_expires = time() + 3500;

            return $this->access_token;
        }

        log_message('error', '[ZOHO] Token refresh failed: ' . json_encode($res));
        return null;
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
        // Convert date format: Y-m-d to d-M-Y (e.g., 2025-12-21 to 21-Dec-2025)
        $formatted_date = DateTime::createFromFormat('Y-m-d', $date)->format('d-M-Y');

        // Build API URL with date and format parameters
        $url = $this->people_url . '/people/api/attendance/getAttendanceEntries?date=' . $formatted_date . '&dateFormat=d-MMM-yyyy';

        // Add employee ID filter if provided
        if ($empId) {
            $url .= '&empId=' . $empId;
        }

        return $this->curl($url);
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
}
