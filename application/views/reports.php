<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Reports - Biometric System</title>
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
	<link href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css" rel="stylesheet">
	<link href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.bootstrap5.min.css" rel="stylesheet">
	<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
	<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
	<style>
		body {
			background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
			min-height: 100vh;
            font-family: 'Roboto', sans-serif;
		}

        .navbar {
            background: rgba(255, 255, 255, 0.95);
            margin-bottom: 2rem;
            border-radius: 0 0 1rem 1rem;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
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
        
		#reportTable thead th, #detailTable thead th {
			background: #2563eb !important;
			color: #fff !important;
			font-weight: 600;
		}

		.select2-container--default .select2-selection--single { height: 38px; padding: 6px 12px; border: 1px solid #dee2e6; border-radius: 0.375rem; }
        .select2-container--default .select2-selection--single .select2-selection__arrow { height: 36px; }
        
		.clickable { cursor: pointer; }
		.clickable:hover { background: #f8fafc !important; }
		.emp-id-link { cursor: pointer; color: #2563eb; text-decoration: none; font-weight: 500; }
		.emp-id-link:hover { text-decoration: underline; }
		.modal-body { max-height: 500px; overflow-y: auto; }

		/* New Professional Styles */
		.report-card-stat {
			border: none;
			border-radius: 12px;
			transition: none; /* No animations as per previous request */
			background: #fff;
			box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
		}
		.stat-label { font-size: 0.85rem; font-weight: 500; color: #64748b; margin-bottom: 5px; }
		.stat-value { font-size: 1.5rem; font-weight: 700; color: #1e293b; }
		.stat-delta { font-size: 0.75rem; color: #10b981; font-weight: 600; }
		
		.badge-status-present { background-color: #dcfce7; color: #166534; padding: 6px 12px; border-radius: 6px; font-weight: 600; font-size: 0.75rem; border: 1px solid #bbf7d0; }
		.badge-status-late { background-color: #fef9c3; color: #854d0e; padding: 6px 12px; border-radius: 6px; font-weight: 600; font-size: 0.75rem; border: 1px solid #fef08a; }
		.badge-status-absent { background-color: #fee2e2; color: #991b1b; padding: 6px 12px; border-radius: 6px; font-weight: 600; font-size: 0.75rem; border: 1px solid #fecaca; }
		
		#attendanceChart { height: 250px !important; }
	</style>
	<!-- Chart.js CDN -->
	<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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

	<div class="container-fluid px-4">
        
        <!-- Filter Card -->
		<div class="row mb-4">
			<div class="col-12">
				<div class="card">
                    <div class="card-header bg-white border-bottom py-3">
                        <h5 class="mb-0 fw-bold text-dark"><i class="fas fa-filter me-2 text-primary"></i>Report Filters</h5>
                    </div>
					<div class="card-body">
						<div class="row g-3 align-items-end">
							<div class="col-md-2">
								<label class="form-label fw-bold">From Date</label>
								<input type="date" id="from_date" class="form-control" value="<?= date('Y-m-01') ?>">
							</div>
							<div class="col-md-2">
								<label class="form-label fw-bold">To Date</label>
								<input type="date" id="to_date" class="form-control" value="<?= date('Y-m-d') ?>">
							</div>
							<div class="col-md-2">
								<label class="form-label fw-bold">Employee</label>
								<select id="emp_filter" class="form-control w-100">
									<option value="">All Employees</option>
								</select>
							</div>
							<div class="col-md-2">
								<button type="button" id="btnLoad" class="btn btn-load w-100 text-white">
									<i class="fas fa-sync-alt me-2"></i>Generate Report
								</button>
							</div>
							<div class="col-md-4 ms-auto text-end">
								<label class="form-label fw-bold d-block">Quick Filters</label>
								<div class="btn-group">
									<button type="button" class="btn btn-secondary" data-range="today">Today</button>
									<button type="button" class="btn btn-secondary" data-range="yesterday">Yesterday</button>
									<button type="button" class="btn btn-secondary" data-range="month">This Month</button>
								</div>
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>

		<!-- Summary Metrics -->
		<div id="summaryRow" class="row mb-4" style="display: none;">
			<div class="col-md-3">
				<div class="card report-card-stat p-3 h-100">
					<div class="stat-label">Total Working Days</div>
					<div class="stat-value" id="stat_total_days">0</div>
					<div class="stat-delta">Data for selected period</div>
				</div>
			</div>
			<div class="col-md-3">
				<div class="card report-card-stat p-3 h-100" style="border-left: 4px solid #f59e0b !important;">
					<div class="stat-label">Late Comings</div>
					<div class="stat-value" id="stat_late_count">0</div>
					<div class="stat-delta text-warning"><i class="fas fa-clock me-1"></i>Delayed arrival</div>
				</div>
			</div>
			<div class="col-md-3">
				<div class="card report-card-stat p-3 h-100" style="border-left: 4px solid #10b981 !important;">
					<div class="stat-label">On-Time Percentage</div>
					<div class="stat-value" id="stat_ontime_percent">0%</div>
					<div class="stat-delta">Efficiency Score</div>
				</div>
			</div>
			<div class="col-md-3">
				<div class="card report-card-stat p-3 h-100" style="border-left: 4px solid #3b82f6 !important;">
					<div class="stat-label">Avg. Working Hours</div>
					<div class="stat-value" id="stat_avg_hours">0h</div>
					<div class="stat-delta">Daily Median</div>
				</div>
			</div>
		</div>

		<div class="row mb-4" id="chartRow" style="display: none;">
			<div class="col-md-12">
				<div class="card">
					<div class="card-header bg-white border-bottom py-3">
						<h5 class="mb-0 fw-bold text-dark"><i class="fas fa-chart-bar me-2 text-primary"></i>Attendance Trend Analysis</h5>
					</div>
					<div class="card-body">
						<canvas id="attendanceChart"></canvas>
					</div>
				</div>
			</div>
		</div>

        <!-- Monthly Report Section -->
		<div class="row mb-4" id="monthlyReport" style="display:none;">
			<div class="col-12">
				<div class="card">
                    <div class="card-header bg-white border-bottom py-3 d-flex justify-content-between align-items-center">
                        <h5 class="mb-0 fw-bold text-dark"><i class="fas fa-calendar-check me-2 text-success"></i>Monthly Summary</h5>
						<div class="small text-muted"><i class="fas fa-info-circle me-1"></i>Late threshold set to <span id="displayLateThreshold">09:15 AM</span></div>
                    </div>
					<div class="card-body">
						<table id="reportTable" class="table table-striped w-100 table-hover">
							<thead>
								<tr>
									<th>Emp ID</th>
									<th>Employee Name</th>
									<th class="" data-type="present">Total Present Days</th>
								</tr>
							</thead>
							<tbody></tbody>
						</table>
					</div>
				</div>
			</div>
		</div>

        <!-- Detail Report Section -->
		<div class="row" id="detailReport" style="display:none;">
			<div class="col-12">
				<div class="card">
                    <div class="card-header bg-white border-bottom py-3 d-flex justify-content-between align-items-center">
                        <h5 class="mb-0 fw-bold text-dark" id="detailTitle"></h5>
                        <button class="btn btn-secondary btn-sm" id="btnBack"><i class="fas fa-arrow-left me-2"></i>Back to Summary</button>
                    </div>
					<div class="card-body">
						<table id="detailTable" class="table table-striped w-100">
							<thead>
								<tr>
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

    <!-- Modal -->
	<div class="modal" id="empModal" tabindex="-1">
		<div class="modal-dialog modal-md">
			<div class="modal-content">
				<div class="modal-header">
					<h5 class="modal-title fw-bold" id="modalTitle"></h5>
					<button type="button" class="btn-close" data-bs-dismiss="modal"></button>
				</div>
				<div class="modal-body">
					<h6 class="text-success fw-bold border-bottom pb-2 mb-3"><i class="fas fa-check-circle me-2"></i>Present Dates</h6>
					<ul id="presentList" class="list-unstyled"></ul>
				</div>
			</div>
		</div>
	</div>

	<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
	<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
	<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
	<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
	<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
	<script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
	<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.bootstrap5.min.js"></script>
	<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
	<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>

	<script>
		const API_URL = "<?= site_url('C_zoho/dashboard_api') ?>";
		const EMP_API_URL = "<?= site_url('C_zoho/get_employees') ?>";
		const UPDATE_URL = "<?= site_url('C_zoho/update_attendance') ?>";
		let reportTable, detailTable, allData = [], attendanceChartInstance = null;
		let LATE_THRESHOLD = "09:15"; // Default, updated from API response

		$(document).ready(function() {
			$('#emp_filter').select2({ placeholder: 'Select Employee', allowClear: true, width: '100%' });

			$.get(EMP_API_URL, function(data) {
				data.employees.forEach(emp => {
					$('#emp_filter').append(new Option(emp.name + ' (' + emp.emp_id + ')', emp.emp_id));
				});
			});

			reportTable = $('#reportTable').DataTable({
				pageLength: 25,
				dom: "<'row mb-2'<'col-md-6'B><'col-md-6'f>><'row'<'col-12'tr>><'row mt-2'<'col-md-5'i><'col-md-7'p>>",
				buttons: [
					{extend: 'excelHtml5', text: '<i class="fas fa-file-excel me-1"></i> Excel', className: 'btn btn-success btn-sm px-3'},
					{extend: 'pdfHtml5', text: '<i class="fas fa-file-pdf me-1"></i> PDF', className: 'btn btn-danger btn-sm px-3'}
				]
			});

			detailTable = $('#detailTable').DataTable({
				pageLength: 25,
				dom: "<'row mb-2'<'col-md-6'B><'col-md-6'f>><'row'<'col-12'tr>><'row mt-2'<'col-md-5'i><'col-md-7'p>>",
				buttons: [
					{extend: 'excelHtml5', text: '<i class="fas fa-file-excel me-1"></i> Excel', className: 'btn btn-success btn-sm px-3'},
					{extend: 'pdfHtml5', text: '<i class="fas fa-file-pdf me-1"></i> PDF', className: 'btn btn-danger btn-sm px-3'}
				],
				order: [[0, 'desc']]
			});

			$('#btnLoad').on('click', loadReport);
			$('.btn-group button').on('click', function() {
				const range = $(this).data('range');
				const today = new Date();
				let from = '', to = today.toISOString().split('T')[0];
				
				if (range === 'today') {
					from = to;
				} else if (range === 'yesterday') {
					today.setDate(today.getDate() - 1);
					from = to = today.toISOString().split('T')[0];
				} else if (range === 'month') {
					from = new Date(today.getFullYear(), today.getMonth(), 1).toISOString().split('T')[0];
					to = new Date().toISOString().split('T')[0];
				}
				
				$('#from_date').val(from);
				$('#to_date').val(to);
				loadReport();
			});

			$('#reportTable').on('click', '.clickable', function() {
				const empId = $(this).closest('tr').find('td:first').text();
				const empName = $(this).closest('tr').find('td:eq(1)').text();
				const type = $(this).data('type');
				showDetail(empId, empName, type);
			});

			$('#btnBack').on('click', function() {
				$('#detailReport').hide();
				$('#monthlyReport').show();
			});

            $('#detailTable').on('click', '.btn-edit', function() {
                const data = $(this).data();
                $('#edit_emp_id').val(data.empid);
                $('#edit_emp_name').val(data.empname);
                $('#edit_date').val(data.date);
                $('#edit_date_display').val(data.date);
                $('#edit_first_in').val(data.in === '-' ? '' : data.in);
                $('#edit_last_out').val(data.out === '-' ? '' : data.out);
                new bootstrap.Modal('#editModal').show();
            });

            $('#btnSaveEdit').on('click', function() {
                const formData = {
                    emp_id: $('#edit_emp_id').val(),
                    work_date: $('#edit_date').val(),
                    first_in: $('#edit_first_in').val(),
                    last_out: $('#edit_last_out').val()
                };

                $('#btnSaveEdit').prop('disabled', true).text('Saving...');

                $.post(UPDATE_URL, formData, function(res) {
                    $('#btnSaveEdit').prop('disabled', false).text('Save Changes');
                    if (res.status === 'success') {
                        bootstrap.Modal.getInstance('#editModal').hide();
                        alert(res.message);
                        loadReport(); // Reload to show updated times
                    } else {
                        alert('Error: ' + res.message);
                    }
                }, 'json');
            });
		});

		function loadReport() {
			const from = $('#from_date').val();
			const to = $('#to_date').val();
			const empId = $('#emp_filter').val();

			$('#btnLoad').prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-2"></i>Loading...');

			$.get(API_URL, { from: from, to: to, emp_id: empId }, function(data) {
				allData = data.daily_attendance;
				if (data.settings && data.settings.late_threshold) {
					LATE_THRESHOLD = data.settings.late_threshold;
					$('#displayLateThreshold').text(LATE_THRESHOLD);
				}
				
				calculateSummary();
				initAttendanceChart();

				if (empId) {
					showDetail(empId, $('#emp_filter option:selected').text(), 'all');
				} else {
					showMonthlyReport();
				}

				$('#btnLoad').prop('disabled', false).html('<i class="fas fa-sync-alt me-2"></i>Generate Report');
			});
		}

		function calculateSummary() {
			if (!allData || allData.length === 0) return;

			let totalPresent = 0;
			let lateCount = 0;
			let totalMinutes = 0;
			let workRecords = 0;
			const uniqueDates = new Set();

			allData.forEach(d => {
				uniqueDates.add(d.work_date);
				if (d.first_in && d.first_in !== '-') {
					totalPresent++;
					if (d.first_in > LATE_THRESHOLD) lateCount++;
					
					if (d.last_out && d.last_out !== '-') {
						const [h1, m1] = d.first_in.split(':').map(Number);
						const [h2, m2] = d.last_out.split(':').map(Number);
						let diff = (h2 * 60 + m2) - (h1 * 60 + m1);
						if (diff < 0) diff += 1440;
						totalMinutes += diff;
						workRecords++;
					}
				}
			});

			const totalDays = uniqueDates.size;
			const onTimePercent = totalPresent > 0 ? Math.round(((totalPresent - lateCount) / totalPresent) * 100) : 0;
			const avgHours = workRecords > 0 ? (totalMinutes / workRecords / 60).toFixed(1) : 0;

			$('#stat_total_days').text(totalDays);
			$('#stat_late_count').text(lateCount);
			$('#stat_ontime_percent').text(onTimePercent + '%');
			$('#stat_avg_hours').text(avgHours + 'h');
			
			$('#summaryRow').fadeIn();
			$('#chartRow').fadeIn();
		}

		function initAttendanceChart() {
			const ctx = document.getElementById('attendanceChart').getContext('2d');
			const trendData = {};
			
			// Group by date
			allData.forEach(d => {
				if (!trendData[d.work_date]) trendData[d.work_date] = 0;
				if (d.first_in && d.first_in !== '-') trendData[d.work_date]++;
			});

			const labels = Object.keys(trendData).sort();
			const values = labels.map(l => trendData[l]);

			if (attendanceChartInstance) attendanceChartInstance.destroy();

			attendanceChartInstance = new Chart(ctx, {
				type: 'line',
				data: {
					labels: labels,
					datasets: [{
						label: 'Employees Present',
						data: values,
						borderColor: '#3b82f6',
						backgroundColor: 'rgba(59, 130, 246, 0.1)',
						fill: true,
						tension: 0.3,
						pointRadius: 4,
						pointBackgroundColor: '#3b82f6'
					}]
				},
				options: {
					responsive: true,
					maintainAspectRatio: false,
					plugins: { legend: { display: false } },
					scales: {
						y: { beginAtZero: true, grid: { display: false }, ticks: { stepSize: 1 } },
						x: { grid: { display: false } }
					}
				}
			});
		}

		function showMonthlyReport() {
			$('#detailReport').hide();
			$('#monthlyReport').show();

			const summary = {};
			allData.forEach(d => {
				if (!summary[d.emp_id]) {
					summary[d.emp_id] = { name: d.name || d.emp_id, present: 0, absent: 0 };
				}
				if (d.first_in && d.first_in !== '-') summary[d.emp_id].present++;
				else summary[d.emp_id].absent++;
			});

			let rows = [];
			Object.keys(summary).forEach(empId => {
				const s = summary[empId];
				rows.push([
                    `<span class="emp-id-link fw-bold text-primary" data-empid="${empId}" data-empname="${s.name}">${empId}</span>`, 
                    s.name, 
                    `<span class="badge bg-success-subtle text-success border border-success-subtle px-3">${s.present} Days</span>`
                ]);
			});

			reportTable.clear().rows.add(rows).draw();

			$('.emp-id-link').on('click', function() {
				const empId = $(this).data('empid');
				const empName = $(this).data('empname');
				showModal(empId, empName);
			});
		}

		function showDetail(empId, empName, type) {
			$('#monthlyReport').hide();
			$('#detailReport').show();
			
			const filtered = allData.filter(d => d.emp_id === empId);
			let rows = [];

			filtered.forEach(d => {
				const isPresent = (d.first_in && d.first_in !== '-') ? true : false;
				const isLate = isPresent && d.first_in > LATE_THRESHOLD;
				
				if (type === 'present' && !isPresent) return;
				if (type === 'absent' && isPresent) return;

				let hours = '-';
				if (isPresent && d.last_out && d.last_out !== '-') {
					const [h1, m1] = d.first_in.split(':').map(Number);
					const [h2, m2] = d.last_out.split(':').map(Number);
					let totalMinutes = (h2 * 60 + m2) - (h1 * 60 + m1);
					if (totalMinutes < 0) totalMinutes += 1440;
					hours = Math.floor(totalMinutes / 60) + 'h ' + (totalMinutes % 60) + 'm';
				}

				let statusBadge = '';
				if (!isPresent) statusBadge = '<span class="badge-status-absent">Absent</span>';
				else if (isLate) statusBadge = '<span class="badge-status-late">Late Entry</span>';
				else statusBadge = '<span class="badge-status-present">On Time</span>';
				
                const editBtn = `<button class="btn btn-sm btn-outline-primary btn-edit" 
                    data-empid="${d.emp_id}" 
                    data-empname="${d.name || d.emp_id}" 
                    data-date="${d.work_date}" 
                    data-in="${d.first_in || '-'}" 
                    data-out="${d.last_out || '-'}">
                    <i class="fas fa-edit"></i>
                </button>`;

                rows.push([d.work_date, d.first_in || '-', d.last_out || '-', hours, statusBadge, editBtn]);
			});

			// If empName has extra ID info, clean it
            if(empName.includes('(')) empName = empName.split('(')[0].trim();

			const typeText = type === 'present' ? 'Present Days' : type === 'absent' ? 'Absent Days' : 'Detailed Report';
			$('#detailTitle').html('<i class="fas fa-user-circle me-2 text-primary"></i>' + empName + ' <small class="text-muted ms-2">| ' + typeText + '</small>');
			detailTable.clear().rows.add(rows).draw();
		}

		function showModal(empId, empName) {
			const filtered = allData.filter(d => d.emp_id === empId);
			const presentDates = [];
			const lateDates = [];
			const absentDates = [];

			filtered.forEach(d => {
				const isPresent = (d.first_in && d.first_in !== '-') ? true : false;
				if (isPresent) {
					presentDates.push(d.work_date);
					if (d.first_in > LATE_THRESHOLD) lateDates.push(d.work_date);
				} else {
					absentDates.push(d.work_date);
				}
			});

			$('#modalTitle').html('<i class="fas fa-user-tie me-2"></i>' + empName + ' <span class="badge bg-primary ms-2">' + empId + '</span>');
			
			let listHtml = presentDates.length ? presentDates.map(d => {
				const date = new Date(d);
				const isLate = filtered.find(f => f.work_date === d).first_in > LATE_THRESHOLD;
				const dayName = date.toLocaleDateString('en-US', { weekday: 'short' });
				const lateBadge = isLate ? ' <span class="badge bg-warning text-dark small ms-1">Late</span>' : '';
				return '<li class="mb-2 d-flex justify-content-between align-items-center border-bottom pb-1">' + 
					   '<span><i class="fas fa-check-circle text-success me-2"></i><b>' + d + '</b> <small class="text-muted">(' + dayName + ')</small>' + lateBadge + '</span>' +
					   '<span class="text-muted small">' + filtered.find(f => f.work_date === d).first_in + '</span></li>';
			}).join('') : '<li class="text-muted">No attendance found.</li>';

			$('#presentList').html(listHtml);
			
			new bootstrap.Modal(document.getElementById('empModal')).show();
		}
	</script>
</body>
</html>
