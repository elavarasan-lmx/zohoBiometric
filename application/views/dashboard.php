<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Biometric Dashboard</title>
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
	<link href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css" rel="stylesheet">
	<link href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.bootstrap5.min.css" rel="stylesheet">
	<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
	<style>
		body {
			background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
			min-height: 100vh;
            font-family: 'Roboto', sans-serif;
		}

		.stat-card {
            background: #fff;
			border-left: 5px solid;
			border-radius: 12px;
			box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
		}
        
        .stat-card:hover {
            /* Animation removed */
        }

		.stat-card.employees { border-left-color: #10b981; }
		.stat-card.present { border-left-color: #3b82f6; }
		.stat-card.absent { border-left-color: #ef4444; }
		.stat-card.pending { border-left-color: #f59e0b; }

		#attendanceTable thead th {
			background: #2563eb !important;
			color: #fff !important;
			font-weight: 600;
		}

		.card {
			border: none;
			box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            border-radius: 12px;
		}

		.btn-load {
			background: linear-gradient(135deg, #3b82f6, #2563eb);
			border: none;
			font-weight: 600;
		}
        
        .navbar {
            background: rgba(255, 255, 255, 0.95);
            margin-bottom: 2rem;
            border-radius: 0 0 1rem 1rem;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }

        .status-pulse {
            width: 10px;
            height: 10px;
            background: #00d2ff;
            border-radius: 50%;
            box-shadow: 0 0 0 0 rgba(0, 210, 255, 0.7);
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(0, 210, 255, 0.7); }
            70% { transform: scale(1); box-shadow: 0 0 0 10px rgba(0, 210, 255, 0); }
            100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(0, 210, 255, 0); }
        }

        .activity-item {
            border-left: 2px solid #e2e8f0;
            margin-left: 1rem;
            padding-left: 1.5rem;
            position: relative;
        }
        .activity-item::before {
            content: '';
            width: 12px;
            height: 12px;
            background: #3b82f6;
            border-radius: 50%;
            position: absolute;
            left: -7px;
            top: 5px;
        }
        .ls-1 { letter-spacing: 1px; }
	</style>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>

<body>
    
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-light py-3 px-4">
        <div class="container-fluid">
            <a class="navbar-brand fw-bold text-primary" href="<?= site_url('C_zoho/dashboard') ?>"><i class="fas fa-fingerprint me-2"></i>Biometric System</a>
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
	<div class="container-fluid px-4 pb-4">
        
		<!-- Top Row: Welcome & Status -->
		<div class="row mb-4">
			<div class="col-md-8">
                <div class="text-white">
                    <h2 class="fw-bold mb-0"><i class="fas fa-chart-line me-2"></i>Live Overview</h2>
                    <p class="opacity-75" id="displayCompanyName">System Dashboard</p>
                </div>
            </div>
            <div class="col-md-4 text-end">
                <div class="card bg-white bg-opacity-10 text-white border-0 shadow-sm py-2 px-3 d-inline-block">
                    <div class="d-flex align-items-center">
                        <div class="status-pulse me-2"></div>
                        <span class="small fw-bold text-uppercase ls-1">Device: <span class="text-info">Connected</span></span>
                    </div>
                </div>
            </div>
		</div>

		<!-- Statistics Summary -->
		<div class="row mb-4">
			<div class="col-lg-3 col-md-6 mb-3">
				<div class="card stat-card employees h-100">
					<div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
						        <div class="text-muted small text-uppercase fw-bold">Team Strength</div>
						        <h2 class="fw-bold mb-0" id="totalEmployees">0</h2>
                            </div>
						    <i class="fas fa-users fa-2x text-success opacity-50"></i>
                        </div>
					</div>
				</div>
			</div>
			<div class="col-lg-3 col-md-6 mb-3">
				<div class="card stat-card present h-100">
					<div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
						        <div class="text-muted small text-uppercase fw-bold">In Office</div>
						        <h2 class="fw-bold mb-0" id="presentCount">0</h2>
                                <small class="badge bg-primary-subtle text-primary border border-primary-subtle" id="presentPercent">0%</small>
                            </div>
						    <i class="fas fa-user-check fa-2x text-primary opacity-50"></i>
                        </div>
					</div>
				</div>
			</div>
			<div class="col-lg-3 col-md-6 mb-3">
				<div class="card stat-card absent h-100">
					<div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
						        <div class="text-muted small text-uppercase fw-bold">Missing</div>
						        <h2 class="fw-bold mb-0" id="absentCount">0</h2>
                            </div>
						    <i class="fas fa-user-times fa-2x text-danger opacity-50"></i>
                        </div>
					</div>
				</div>
			</div>
			<div class="col-lg-3 col-md-6 mb-3">
				<div class="card stat-card pending h-100">
					<div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
						        <div class="text-muted small text-uppercase fw-bold">Unsynced</div>
						        <h2 class="fw-bold mb-0" id="pendingSync">0</h2>
                            </div>
						    <i class="fas fa-cloud-upload-alt fa-2x text-warning opacity-50"></i>
                        </div>
					</div>
				</div>
			</div>
		</div>

        <!-- Main Visuals Section -->
        <div class="row g-4 mb-4">
            <!-- Weekly Trend -->
            <div class="col-lg-8">
                <div class="card h-100 border-0 shadow-lg">
                    <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
                        <h6 class="mb-0 fw-bold"><i class="fas fa-chart-area me-2 text-primary"></i>Weekly Attendance Trend</h6>
                        <div class="btn-group btn-group-sm">
                            <button class="btn btn-outline-secondary active">7 Days</button>
                        </div>
                    </div>
                    <div class="card-body">
                        <div style="height: 300px;">
                            <canvas id="weeklyChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recent Activity Feed -->
            <div class="col-lg-4">
                <div class="card h-100 border-0 shadow-lg overflow-hidden">
                    <div class="card-header bg-white border-0 py-3">
                        <h6 class="mb-0 fw-bold"><i class="fas fa-stream me-2 text-info"></i>Live Activity Feed</h6>
                    </div>
                    <div class="card-body p-0">
                        <div class="list-group list-group-flush" id="activityFeed" style="max-height: 400px; overflow-y: auto;">
                            <!-- Activity list -->
                        </div>
                    </div>
                    <div class="card-footer bg-white border-0 text-center py-2">
                        <a href="<?= site_url('C_zoho/reports') ?>" class="text-decoration-none small fw-bold">View Full Logs &rarr;</a>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4">
            <!-- Today's Late Comers -->
            <div class="col-12">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white border-0 py-3">
                        <h6 class="mb-0 fw-bold text-danger"><i class="fas fa-user-clock me-2"></i>Today's Late Arrivals</h6>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0" id="lateTable">
                                <thead class="bg-light">
                                    <tr>
                                        <th class="ps-4">Employee</th>
                                        <th>Expected</th>
                                        <th>Arrived</th>
                                        <th>Delay</th>
                                        <th class="pe-4 text-end">Action</th>
                                    </tr>
                                </thead>
                                <tbody id="lateComersBody">
                                    <!-- Populated via JS -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

		<!-- Daily Detail Section (Collapsed by default or simplified) -->
		<div class="row d-none">
            <!-- Table hidden in favor of feed, but kept for JS compatibility if needed -->
			<div class="col-12">
						<table id="attendanceTable" class="table table-striped w-100">
							<thead>
								<tr>
									<th>Emp ID</th>
									<th>Employee Name</th>
									<th>Date</th>
									<th>In Time</th>
									<th>Out Time</th>
									<th>Hours</th>
									<th>Status</th>
									<th>Actions</th>
								</tr>
							</thead>
							<tbody></tbody>
						</table>
			</div>
		</div>
	</div>

    <!-- Edit Attendance Modal -->
	<div class="modal" id="editModal" tabindex="-1">
		<div class="modal-dialog">
			<div class="modal-content">
				<div class="modal-header">
					<h5 class="modal-title fw-bold">Edit Attendance</h5>
					<button type="button" class="btn-close" data-bs-dismiss="modal"></button>
				</div>
				<div class="modal-body">
					<form id="editForm">
						<input type="hidden" id="edit_emp_id">
						<input type="hidden" id="edit_date">
						<div class="mb-3">
							<label class="form-label fw-bold">Employee</label>
							<input type="text" id="edit_emp_name" class="form-control" readonly style="background: #f8f9fa;">
						</div>
						<div class="mb-3">
							<label class="form-label fw-bold">Date</label>
							<input type="text" id="edit_date_display" class="form-control" readonly style="background: #f8f9fa;">
						</div>
						<div class="row">
							<div class="col-md-6 mb-3">
								<label class="form-label fw-bold">First In</label>
								<input type="time" id="edit_first_in" class="form-control" step="1">
							</div>
							<div class="col-md-6 mb-3">
								<label class="form-label fw-bold">Last Out</label>
								<input type="time" id="edit_last_out" class="form-control" step="1">
							</div>
						</div>
                        <div class="alert alert-warning py-2 small mb-0">
                            <i class="fas fa-info-circle me-1"></i> Saving will mark this record as <b>Pending</b> for sync.
                        </div>
					</form>
				</div>
				<div class="modal-footer">
					<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
					<button type="button" id="btnSaveEdit" class="btn btn-primary">Save Changes</button>
				</div>
			</div>
		</div>
	</div>

	<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
	<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
	<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
	<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
	<script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
	<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.bootstrap5.min.js"></script>
	<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
	<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>
	<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.print.min.js"></script>

	<script>
		const API_URL = "<?= site_url('C_zoho/dashboard_api') ?>";
		const UPDATE_URL = "<?= site_url('C_zoho/update_attendance') ?>";
		let weeklyChart = null;

		$(document).ready(function() {
			loadData();
		});

		function initTrendChart(trendData) {
            const ctx = document.getElementById('weeklyChart').getContext('2d');
            
            if (weeklyChart) weeklyChart.destroy();

            const labels = trendData.map(d => {
                const date = new Date(d.work_date);
                return date.toLocaleDateString('en-US', { weekday: 'short', day: 'numeric' });
            });
            const counts = trendData.map(d => d.present_count);

            weeklyChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Present Count',
                        data: counts,
                        fill: true,
                        backgroundColor: 'rgba(59, 130, 246, 0.1)',
                        borderColor: '#3b82f6',
                        tension: 0.4,
                        borderWidth: 3,
                        pointBackgroundColor: '#fff',
                        pointBorderColor: '#3b82f6',
                        pointHoverRadius: 6
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        y: { beginAtZero: true, grid: { display: false } },
                        x: { grid: { display: false } }
                    }
                }
            });
        }

		function loadData() {
			$.get(API_URL, function(data) {
				$('#totalEmployees').text(data.stats.total_employees);
				$('#presentCount').text(data.stats.present_in_range);
				$('#absentCount').text(data.stats.total_employees - data.stats.present_in_range);
				$('#pendingSync').text(data.stats.pending_sync);
				$('#presentPercent').text(data.stats.total_employees > 0 ? ((data.stats.present_in_range / data.stats.total_employees) * 100).toFixed(0) + '%' : '0%');
				
                if(data.settings && data.settings.company_name) {
                    $('#displayCompanyName').text(data.settings.company_name);
                }

                // Update Weekly Chart
                if (data.weekly_trend) initTrendChart(data.weekly_trend);

                // Update Activity Feed
				let feedHtml = '';
                if (data.recent_punches.length === 0) {
                    feedHtml = '<div class="p-4 text-center text-muted">No recent activity detected</div>';
                } else {
                    $.each(data.recent_punches, function(i, p) {
                        const time = p.punch_time.substring(0, 5);
                        feedHtml += `
                            <div class="list-group-item border-0 px-4 py-3">
                                <div class="activity-item">
                                    <div class="d-flex justify-content-between">
                                        <h6 class="mb-0 fw-bold">${p.name || p.emp_id}</h6>
                                        <span class="badge bg-light text-dark shadow-sm">${time}</span>
                                    </div>
                                    <p class="text-muted small mb-0">Biometric Punch Recorded</p>
                                </div>
                            </div>
                        `;
                    });
                }
                $('#activityFeed').html(feedHtml);

                // Update Late Comers
                let lateHtml = '';
                let lateCount = 0;
                const threshold = data.settings ? data.settings.late_threshold : '09:15';
                
                $.each(data.daily_attendance, function(i, d) {
                    if (d.first_in && d.first_in > threshold + ':00') {
                        lateCount++;
                        const firstIn = d.first_in.substring(0, 5);
                        lateHtml += `
                            <tr>
                                <td class="ps-4">
                                    <div class="fw-bold text-dark">${d.name || d.emp_id}</div>
                                    <div class="small text-muted">${d.emp_id}</div>
                                </td>
                                <td>${threshold} AM</td>
                                <td><span class="text-danger fw-bold">${firstIn} AM</span></td>
                                <td><span class="badge bg-danger-subtle text-danger font-monospace">Late</span></td>
                                <td class="pe-4 text-end">
                                    <a href="<?= site_url('C_zoho/reports') ?>?emp_id=${d.emp_id}" class="btn btn-sm btn-outline-secondary">View Profile</a>
                                </td>
                            </tr>
                        `;
                    }
                });
                
                if (lateCount === 0) {
                    lateHtml = '<tr><td colspan="5" class="text-center py-4 text-muted">Exemplary punctuality today! No late arrivals detected.</td></tr>';
                }
                $('#lateComersBody').html(lateHtml);
			});
		}
	</script>
</body>
</html>
