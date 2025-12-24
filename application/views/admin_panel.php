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
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

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
            transition: transform 0.2s;
        }

        .card:hover {
            transform: translateY(-5px);
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
            <div class="ms-auto">
                <a href="<?= site_url('C_zoho/dashboard') ?>" class="btn btn-sm btn-outline-primary text-primary border-primary">
                    <i class="fas fa-arrow-left me-1"></i> Back to Dashboard
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
                    <div class="col-md-6">
                         <label class="form-label fw-bold text-muted text-uppercase small">Operation Date Range</label>
                         <div class="input-group">
                            <span class="input-group-text bg-light border-end-0">From</span>
                            <input type="date" id="from_date" value="<?php echo date('Y-m-01'); ?>" class="form-control border-start-0">
                            <span class="input-group-text bg-light border-start-0 border-end-0">To</span>
                            <input type="date" id="to_date" value="<?php echo date('Y-m-d'); ?>" class="form-control border-start-0">
                         </div>
                    </div>
                    <div class="col-md-8">
                        <div id="status-box" class="alert alert-info d-none mb-0 d-flex align-items-center py-2">
                            <i id="status-icon" class="fas fa-info-circle me-2"></i>
                            <span id="status-message" class="fw-medium">Ready</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4">
            
            <!-- Inbound Operations -->
            <div class="col-lg-6">
                <h5 class="text-white mb-3 fw-bold border-bottom border-white border-opacity-25 pb-2">Data Acquisition</h5>
                
                <!-- Sync Employees -->
                <div class="card mb-3">
                    <div class="card-body d-flex align-items-center justify-content-between">
                        <div class="d-flex align-items-center">
                            <div class="icon-box bg-purple-100 text-purple-600 bg-opacity-10 text-primary">
                                <i class="fas fa-users"></i>
                            </div>
                            <div>
                                <h6 class="mb-0 fw-bold">Sync Employees</h6>
                                <small class="text-muted">Update local employee master from Zoho</small>
                            </div>
                        </div>
                        <button onclick="runAction('sync_employees')" class="btn btn-dark btn-sm px-3">Sync Now</button>
                    </div>
                </div>

                <!-- Import ZKTeco -->
                <div class="card mb-3">
                    <div class="card-body d-flex align-items-center justify-content-between">
                        <div class="d-flex align-items-center">
                            <div class="icon-box bg-blue-100 text-primary bg-opacity-10">
                                <i class="fas fa-download"></i>
                            </div>
                            <div>
                                <h6 class="mb-0 fw-bold">Import Device Logs</h6>
                                <small class="text-muted">Pull raw punches from ZKTeco</small>
                            </div>
                        </div>
                        <button onclick="runAction('import_zkteco_attendance')" class="btn btn-primary btn-sm px-3">Import</button>
                    </div>
                </div>

                <!-- Sync ZKTeco Users -->
                <div class="card mb-3">
                    <div class="card-body d-flex align-items-center justify-content-between">
                        <div class="d-flex align-items-center">
                            <div class="icon-box bg-cyan-100 text-info bg-opacity-10">
                                <i class="fas fa-id-card"></i>
                            </div>
                            <div>
                                <h6 class="mb-0 fw-bold">Sync ZKTeco Users</h6>
                                <small class="text-muted">Import employees from ZKTeco device</small>
                            </div>
                        </div>
                        <button onclick="runAction('import_zkteco_employees')" class="btn btn-info btn-sm px-3 text-white">Sync Users</button>
                    </div>
                </div>

                <!-- Bulk Import -->
                <div class="card mb-3">
                    <div class="card-body d-flex align-items-center justify-content-between">
                        <div class="d-flex align-items-center">
                            <div class="icon-box bg-orange-100 text-warning bg-opacity-10">
                                <i class="fas fa-cloud-download-alt"></i>
                            </div>
                            <div>
                                <h6 class="mb-0 fw-bold">Sync Attendance (Zoho &rarr; Local)</h6>
                                <small class="text-muted">Download records from Zoho to local DB</small>
                            </div>
                        </div>
                        <button onclick="runAction('import_all_attendance')" class="btn btn-warning btn-sm px-3 text-white">Sync from Zoho</button>
                    </div>
                </div>
            </div>

            <!-- Outbound Operations -->
            <div class="col-lg-6">
                <h5 class="text-white mb-3 fw-bold border-bottom border-white border-opacity-25 pb-2">Process & Export</h5>

                <!-- Process Data -->
                <div class="card mb-3">
                    <div class="card-body d-flex align-items-center justify-content-between">
                        <div class="d-flex align-items-center">
                            <div class="icon-box bg-teal-100 text-success bg-opacity-10">
                                <i class="fas fa-cogs"></i>
                            </div>
                            <div>
                                <h6 class="mb-0 fw-bold">Process Local Data</h6>
                                <small class="text-muted">Calculate First-In & Last-Out</small>
                            </div>
                        </div>
                        <button onclick="runAction('process_local_attendance')" class="btn btn-success btn-sm px-3">Process</button>
                    </div>
                </div>

                <!-- Push to Zoho -->
                <div class="card mb-3">
                    <div class="card-body d-flex align-items-center justify-content-between">
                        <div class="d-flex align-items-center">
                            <div class="icon-box bg-indigo-100 text-info bg-opacity-10">
                                <i class="fas fa-cloud-upload-alt"></i>
                            </div>
                            <div>
                                <h6 class="mb-0 fw-bold">Push to Zoho</h6>
                                <small class="text-muted">Upload processed data to Zoho</small>
                            </div>
                        </div>
                        <button onclick="runAction('sync_attendance')" class="btn btn-info btn-sm px-3 text-white">Push Data</button>
                    </div>
                </div>

                <!-- Bulk Push to Zoho -->
                <div class="card mb-3">
                    <div class="card-body d-flex align-items-center justify-content-between">
                        <div class="d-flex align-items-center">
                            <div class="icon-box bg-indigo-100 text-primary bg-opacity-10">
                                <i class="fas fa-layer-group"></i>
                            </div>
                            <div>
                                <h6 class="mb-0 fw-bold">Bulk Push to Zoho</h6>
                                <small class="text-muted">Push multiple days/records at once</small>
                            </div>
                        </div>
                        <button onclick="runAction('sync_attendance_bulk')" class="btn btn-primary btn-sm px-3 text-white">Bulk Push</button>
                    </div>
                </div>

                <!-- Cleanup -->
                <div class="card mb-3 border-danger border-opacity-25">
                    <div class="card-body d-flex align-items-center justify-content-between">
                        <div class="d-flex align-items-center">
                            <div class="icon-box bg-red-100 text-danger bg-opacity-10">
                                <i class="fas fa-broom"></i>
                            </div>
                            <div>
                                <h6 class="mb-0 fw-bold text-danger">Cleanup Invalid Data</h6>
                                <small class="text-muted">Remove future dates & placeholder times</small>
                            </div>
                        </div>
                        <button onclick="runAction('cleanup_future_dates')" class="btn btn-outline-danger btn-sm px-3">Cleanup</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Logs -->
        <div class="mt-4">
            <h6 class="text-white text-uppercase small fw-bold mb-2">Operation Logs</h6>
            <div id="console-output" class="p-3 shadow-sm">
                <div class="text-muted">> System ready... awaiting command.</div>
            </div>
        </div>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        const baseUrl = '<?php echo site_url("C_zoho/"); ?>';

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
            
            if (!from || !to) {
                alert('Please select both From and To dates');
                return;
            }

            setStatus(true, 'Running ' + action + '...');
            log('Starting ' + action + ' for range: ' + from + ' to ' + to + '...', 'info');

            $.ajax({
                url: baseUrl + action,
                method: 'GET',
                data: { 
                    from: from, 
                    to: to,
                    date: to // Fallback for single-date functions
                },
                dataType: 'json',
                success: function(response) {
                    if (response.status === 'success' || response.status === 'completed') {
                        log('Success: ' + (JSON.stringify(response.summary || response)), 'info');
                        setStatus(false, 'Operation completed successfully', 'success');
                    } else {
                        log('Warning: ' + (response.message || 'Check logs'), 'warn');
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
    </script>
</body>
</html>
