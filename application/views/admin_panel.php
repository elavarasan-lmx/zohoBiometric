<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Biometric Admin Console</title>
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Select2 -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            font-family: 'Roboto', sans-serif;
            color: #333;
        }
        
        .navbar {
            background: rgba(255, 255, 255, 0.95);
            margin-bottom: 2rem;
            border-radius: 0 0 1rem 1rem;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }

        .card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .card:hover {
            /* Animation removed */
        }

        .card-header {
            background-color: transparent;
            border-bottom: 1px solid rgba(0,0,0,0.05);
            font-weight: 700;
        }

        .btn-action {
            width: 100%;
            border-radius: 8px;
            font-weight: 500;
            padding: 0.5rem;
        }

        .icon-box {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            margin-right: 1rem;
        }

        #console-output {
            background: #1a1a1a;
            color: #00ff00;
            font-family: monospace;
            border-radius: 8px;
            height: 200px;
            overflow-y: auto;
            border: 1px solid #333;
        }
    </style>
</head>
<body>

    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-light py-3 px-4">
        <div class="container-fluid">
            <a class="navbar-brand fw-bold text-primary" href="#"><i class="fas fa-fingerprint me-2"></i>Biometric System</a>
            <?php $active_method = $this->uri->segment(2); ?>
            <div class="ms-auto">
                <a href="<?= site_url('C_zoho/dashboard') ?>" class="btn btn-sm <?= ($active_method == 'dashboard' || $active_method == '') ? 'btn-primary' : 'btn-outline-primary' ?> me-2">
                    <i class="fas fa-home me-1"></i> Dashboard
                </a>
                <a href="<?= site_url('C_zoho/reports') ?>" class="btn btn-sm <?= ($active_method == 'reports') ? 'btn-primary' : 'btn-outline-primary' ?> me-2">
                    <i class="fas fa-file-alt me-1"></i> Reports
                </a>
                <a href="<?= site_url('C_zoho/admin') ?>" class="btn btn-sm <?= ($active_method == 'admin') ? 'btn-primary' : 'btn-outline-primary' ?>">
                    <i class="fas fa-cogs me-1"></i> Admin Panel
                </a>
            </div>
        </div>
    </nav>

    <div class="container px-4 pb-5">
        
        <!-- Header -->
        <div class="text-white mb-4">
            <h2 class="fw-bold"><i class="fas fa-cogs me-2"></i>Admin Console</h2>
            <p class="opacity-75">Manage system synchronization and manual overrides</p>
        </div>

        <!-- System Status -->
        <div class="card mb-4">
            <div class="card-body">
                <div class="row align-items-end">
                    <div class="col-md-4">
                         <label class="form-label fw-bold text-muted text-uppercase small">Operation Date Range</label>
                         <div class="input-group">
                            <span class="input-group-text bg-light border-end-0">From</span>
                            <input type="date" id="from_date" value="<?php echo date('Y-m-01'); ?>" class="form-control border-start-0">
                            <span class="input-group-text bg-light border-start-0 border-end-0">To</span>
                            <input type="date" id="to_date" value="<?php echo date('Y-m-d'); ?>" class="form-control border-start-0">
                         </div>
                    </div>
                    <div class="col-md-4">
                         <label class="form-label fw-bold text-muted text-uppercase small">Filter By Employee (Optional)</label>
                         <select id="emp_id" class="form-control">
                            <option value="">All Employees</option>
                         </select>
                    </div>
                    <div class="col-md-4">
                        <div id="status-box" class="alert alert-info d-none mb-0 d-flex align-items-center py-2">
                            <i id="status-icon" class="fas fa-info-circle me-2"></i>
                            <span id="status-message" class="fw-medium">Ready</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4">
            
        <!-- Nav Tabs -->
        <ul class="nav nav-pills mb-4" id="adminTabs" role="tablist">
            <li class="nav-item">
                <button class="nav-link active text-white" id="sync-tab" data-bs-toggle="pill" data-bs-target="#sync-pane" type="button"><i class="fas fa-sync-alt me-2"></i>Sync Center</button>
            </li>
            <li class="nav-item">
                <button class="nav-link text-white" id="employees-tab" data-bs-toggle="pill" data-bs-target="#employees-pane" type="button"><i class="fas fa-users me-2"></i>Employee Sync</button>
            </li>

        </ul>

        <div class="tab-content">
            <!-- Sync Center Pane -->
            <div class="tab-pane fade show active" id="sync-pane">
                <div class="row g-4">
                    <div class="col-lg-6">
                        <div class="card h-100">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <span><i class="fas fa-cloud-download-alt me-2 text-primary"></i>Inbound Sync</span>
                                <span class="badge bg-primary rounded-pill">Device & Zoho</span>
                            </div>
                            <div class="card-body">
                                <div class="list-group list-group-flush">
                                    <div class="list-group-item px-0 py-3 d-flex align-items-center justify-content-between border-0">
                                        <div>
                                            <h6 class="mb-0 fw-bold">Sync Biometric Data</h6>
                                            <small class="text-muted">Process raw punches from iclock_transaction table</small>
                                        </div>
                                        <button onclick="runAction('import_zkteco_attendance')" class="btn btn-primary btn-sm">Sync Data</button>
                                    </div>

                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-lg-6">
                        <div class="card h-100">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <span><i class="fas fa-cloud-upload-alt me-2 text-success"></i>Outbound Process</span>
                                <span class="badge bg-success rounded-pill">Push to Cloud</span>
                            </div>
                            <div class="card-body">
                                <div class="list-group list-group-flush">

                                    <div class="list-group-item px-0 py-3 d-flex align-items-center justify-content-between border-0">
                                        <div>
                                            <h6 class="mb-0 fw-bold">Push to Zoho</h6>
                                            <small class="text-muted">Upload processed records to Zoho People</small>
                                            <div class="mt-2 d-flex gap-2">
                                                <button onclick="runSync('in')" class="btn btn-primary btn-sm flex-fill"><i class="fas fa-sign-in-alt me-1"></i>Push Check-In</button>
                                                <button onclick="runSync('out')" class="btn btn-warning btn-sm flex-fill text-dark"><i class="fas fa-sign-out-alt me-1"></i>Push Check-Out</button>
                                                <button onclick="runSync('both')" class="btn btn-success btn-sm flex-fill"><i class="fas fa-exchange-alt me-1"></i>Push Both</button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Employee Sync Pane -->
            <div class="tab-pane fade" id="employees-pane">
                 <div class="card">
                     <div class="card-body">
                         <div class="row align-items-center mb-4">
                             <div class="col">
                                 <h5 class="fw-bold mb-0">Employee Master Sync</h5>
                                 <p class="text-muted small mb-0">Maintain consistency between your device and Zoho</p>
                             </div>
                         </div>
                         <div class="row g-4">
                             <div class="col-12">
                                 <div class="p-3 border rounded-3 bg-light">
                                     <div class="d-flex align-items-center mb-3">
                                         <div class="icon-box bg-primary text-white mb-0 shadow-sm"><i class="fas fa-users"></i></div>
                                         <h6 class="mb-0 fw-bold ms-2">Zoho Employees</h6>
                                     </div>
                                     <p class="small text-muted">Update local employee database with the latest list from Zoho People.</p>
                                     <button onclick="runAction('sync_employees')" class="btn btn-primary btn-sm w-100">Sync from Zoho</button>
                                 </div>
                             </div>

                         </div>
                     </div>
                 </div>
            </div>


        </div>

        <!-- System Logs -->
        <div class="mt-4">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h6 class="text-white text-uppercase small fw-bold mb-0">Command Console</h6>
                <button onclick="$('#console-output').empty()" class="btn btn-link py-0 text-white opacity-50 text-decoration-none small"><i class="fas fa-trash-alt me-1"></i>Clear</button>
            </div>
            <div id="console-output" class="p-3 shadow-sm border-0" style="background: rgba(0,0,0,0.3); backdrop-filter: blur(5px);">
                <div class="text-white opacity-50">> System ready... awaiting command.</div>
            </div>
        </div>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        const baseUrl = '<?php echo site_url("C_zoho/"); ?>';

        $(document).ready(function() {
            $('#emp_id').select2({
                placeholder: 'Select Employee (Optional)',
                allowClear: true,
                width: '100%'
            });

            // Load Settings Initial Values


            // Save Settings


            // Load Employees
            $.get(baseUrl + 'get_employees', function(data) {
                if(data.employees) {
                    data.employees.forEach(emp => {
                        $('#emp_id').append(new Option(emp.name + ' (' + emp.emp_id + ')', emp.emp_id));
                    });
                }
            });
        });

        function log(msg, type = 'info') {
            const timestamp = new Date().toLocaleTimeString();
            let color = '#00ff00'; // green for info
            if (type === 'error') color = '#ff4444'; // red
            if (type === 'warn') color = '#ffbb33'; // orange
            
            $('#console-output').append(`<div style="color:${color}">[${timestamp}] ${msg}</div>`);
            
            const consoleDiv = document.getElementById('console-output');
            consoleDiv.scrollTop = consoleDiv.scrollHeight;
        }

        function setStatus(loading, msg, type = 'info') {
            const box = $('#status-box');
            const icon = $('#status-icon');
            const txt = $('#status-message');

            box.removeClass('d-none alert-info alert-success alert-danger alert-warning');
            icon.removeClass('fa-spin fa-circle-notch fa-check-circle fa-times-circle fa-exclamation-triangle fa-info-circle');

            if (loading) {
                box.addClass('alert-info');
                icon.addClass('fas fa-circle-notch fa-spin');
                $('button').prop('disabled', true).addClass('opacity-50');
            } else {
                 $('button').prop('disabled', false).removeClass('opacity-50');
                 if (type === 'success') {
                    box.addClass('alert-success');
                    icon.addClass('fas fa-check-circle');
                 } else if (type === 'error') {
                    box.addClass('alert-danger');
                    icon.addClass('fas fa-times-circle');
                 } else {
                    box.addClass('alert-info');
                    icon.addClass('fas fa-info-circle');
                 }
            }
            
            txt.text(msg);
        }

        function runAction(action) {
            const from = $('#from_date').val();
            const to = $('#to_date').val();
            const empId = $('#emp_id').val();
            
            // Get Checkbox Options
            const syncIn = $('#sync_in').is(':checked') ? 1 : 0;
            const syncOut = $('#sync_out').is(':checked') ? 1 : 0;
            
            if (!from || !to) {
                alert('Please select both From and To dates');
                return;
            }

            setStatus(true, 'Running ' + action + '...');
            let logMsg = 'Starting ' + action + ' for range: ' + from + ' to ' + to;
            if (empId) logMsg += ' | Employee: ' + empId;
            log(logMsg + '...', 'info');

            $.ajax({
                url: baseUrl + action,
                method: 'GET',
                data: { 
                    from: from, 
                    to: to,
                    date: to, // Fallback for single-date functions
                    empId: empId,
                    sync_in: syncIn,
                    sync_out: syncOut 
                },
                dataType: 'json',
                success: function(response) {
                    if (response.status === 'success' || response.status === 'completed') {
                        log('Success: ' + (JSON.stringify(response.summary)), 'info');
                        if (response.debug_errors && response.debug_errors.length > 0) {
                             log('Debug: ' + JSON.stringify(response.debug_errors), 'warn');
                        }
                        setStatus(false, 'Operation completed successfully', 'success');
                    } else {
                        log('Warning: ' + (response.message || 'Check logs'), 'warn');
                        if (response.debug_errors) log('Debug: ' + JSON.stringify(response.debug_errors), 'warn');
                        setStatus(false, 'Operation completed with warnings', 'warn');
                    }
                },
                error: function(xhr, status, error) {
                    log('Error: ' + error, 'error');
                    console.error('Response:', xhr.responseText);
                    setStatus(false, 'Operation failed. Check console for details.', 'error');
                }
            });
        }

        function runSync(type) {
            const from = $('#from_date').val();
            const to = $('#to_date').val();
            const empId = $('#emp_id').val();
            
            if (!from || !to) {
                alert('Please select both From and To dates');
                return;
            }

            // Determine flags
            // Determine flags
            let syncIn = (type === 'in' || type === 'both') ? 1 : 0;
            let syncOut = (type === 'out' || type === 'both') ? 1 : 0;
            
            let label = 'Action';
            if (type === 'in') label = 'Check-IN';
            else if (type === 'out') label = 'Check-OUT';
            else label = 'Check-IN & OUT';

            setStatus(true, 'Pushing ' + label + ' to Zoho...');
            log('Starting PUSH ' + label + ' for ' + from + '...' , 'info');

            $.ajax({
                url: baseUrl + 'sync_attendance_bulk',
                method: 'GET',
                data: { from, to, empId, sync_in: syncIn, sync_out: syncOut },
                dataType: 'json',
                success: function(response) {
                    // Logic same as runAction success but inline for simplicity
                    log('Result: ' + JSON.stringify(response.summary), 'info');
                    if (response.debug_errors && response.debug_errors.length > 0) {
                         log('Debug: ' + JSON.stringify(response.debug_errors), 'warn');
                    }
                    setStatus(false, label + ' Push Complete', 'success');
                },
                error: function(xhr) {
                    log('Error: ' + xhr.responseText, 'error');
                    setStatus(false, 'Failed', 'error');
                }
            });
        }
    </script>
</body>
</html>
