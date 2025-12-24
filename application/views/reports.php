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
	</style>
</head>
<body>

    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-light py-3 px-4">
        <div class="container-fluid">
            <a class="navbar-brand fw-bold text-primary" href="#"><i class="fas fa-fingerprint me-2"></i>Biometric System</a>
            <div class="ms-auto">
                <a href="<?= site_url('C_zoho/dashboard') ?>" class="btn btn-sm btn-outline-primary text-primary border-primary me-2">
                    <i class="fas fa-home me-1"></i> Dashboard
                </a>
                <a href="<?= site_url('C_zoho/reports') ?>" class="btn btn-sm btn-primary">
                    <i class="fas fa-file-alt me-1"></i> Reports
                </a>
                 <a href="<?= site_url('C_zoho/admin') ?>" class="btn btn-sm btn-outline-primary text-primary border-primary ms-2">
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

        <!-- Monthly Report Section -->
		<div class="row mb-4" id="monthlyReport" style="display:none;">
			<div class="col-12">
				<div class="card">
                    <div class="card-header bg-white border-bottom py-3">
                        <h5 class="mb-0 fw-bold text-dark"><i class="fas fa-calendar-check me-2 text-success"></i>Monthly Summary</h5>
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
									<th>First In</th>
									<th>Last Out</th>
									<th>Hours</th>
									<th>Status</th>
								</tr>
							</thead>
							<tbody></tbody>
						</table>
					</div>
				</div>
			</div>
		</div>
	</div>

    <!-- Modal -->
	<div class="modal fade" id="empModal" tabindex="-1">
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
		let reportTable, detailTable, allData = [];

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
				buttons: [{extend: 'excelHtml5', text: '<i class="fas fa-file-excel"></i> Excel', className: 'btn btn-success btn-sm'}]
			});

			detailTable = $('#detailTable').DataTable({
				pageLength: 25,
				dom: "<'row mb-2'<'col-md-6'B><'col-md-6'f>><'row'<'col-12'tr>><'row mt-2'<'col-md-5'i><'col-md-7'p>>",
				buttons: [{extend: 'excelHtml5', text: '<i class="fas fa-file-excel"></i> Excel', className: 'btn btn-success btn-sm'}],
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
		});

		function loadReport() {
			const from = $('#from_date').val();
			const to = $('#to_date').val();
			const empId = $('#emp_filter').val();

			$('#btnLoad').prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-2"></i>Loading...');

			$.get(API_URL, { from: from, to: to }, function(data) {
				allData = data.daily_attendance;
				
				if (empId) {
					showDetail(empId, $('#emp_filter option:selected').text(), 'all');
				} else {
					showMonthlyReport();
				}

				$('#btnLoad').prop('disabled', false).html('<i class="fas fa-sync-alt me-2"></i>Generate Report');
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
				if (d.first_in) summary[d.emp_id].present++;
				else summary[d.emp_id].absent++;
			});

			let rows = [];
			Object.keys(summary).forEach(empId => {
				const s = summary[empId];
				// Make the Employee ID distinct and clickable
				rows.push([
                    `<span class="emp-id-link" data-empid="${empId}" data-empname="${s.name}">${empId}</span>`, 
                    s.name, 
                    `<span class="badge bg-success fs-6">${s.present}</span>`
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
				const isPresent = d.first_in ? true : false;
				
				if (type === 'present' && !isPresent) return;
				if (type === 'absent' && isPresent) return;

				let hours = '-';
				if (d.first_in && d.last_out && d.first_in !== '-' && d.last_out !== '-') {
					const [h1, m1] = d.first_in.split(':').map(Number);
					const [h2, m2] = d.last_out.split(':').map(Number);
					let totalMinutes = (h2 * 60 + m2) - (h1 * 60 + m1);
					if (totalMinutes < 0) totalMinutes += 24 * 60;
					hours = Math.floor(totalMinutes / 60) + 'h ' + (totalMinutes % 60) + 'm';
				}

				const status = isPresent ? '<span class="badge bg-success">Present</span>' : '<span class="badge bg-danger">Absent</span>';
				rows.push([d.work_date, d.first_in || '-', d.last_out || '-', hours, status]);
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
			const absentDates = [];

			filtered.forEach(d => {
				if (d.first_in) presentDates.push(d.work_date);
				else absentDates.push(d.work_date);
			});

			$('#modalTitle').html('<i class="fas fa-user me-2"></i>' + empName + ' (' + empId + ')');
			$('#presentList').html(presentDates.length ? presentDates.map(d => {
				const date = new Date(d);
				const dayName = date.toLocaleDateString('en-US', { weekday: 'long' });
				return '<li class="text-success mb-1"><i class="fas fa-check me-2"></i>' + d + ' <span class="text-muted small">(' + dayName + ')</span></li>';
			}).join('') : '<li class="text-muted">No present dates found in this range.</li>');
			
			new bootstrap.Modal(document.getElementById('empModal')).show();
		}
	</script>
</body>
</html>
