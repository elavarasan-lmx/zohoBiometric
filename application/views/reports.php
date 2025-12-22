<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Reports - Attendance</title>
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
	<link href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css" rel="stylesheet">
	<link href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.bootstrap5.min.css" rel="stylesheet">
	<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
	<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
	<style>
		body { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; }
		.card { border: none; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
		.btn-load { background: linear-gradient(135deg, #3b82f6, #2563eb); border: none; font-weight: 600; }
		.nav-pills .nav-link { color: #2563eb; background: rgba(255,255,255,0.9); margin: 0 5px; }
		.nav-pills .nav-link.active { background: #2563eb; color: #fff; }
		#reportTable thead th, #detailTable thead th { background: #2563eb !important; color: #fff !important; font-weight: 600; }
		.select2-container--default .select2-selection--single { height: 38px; padding: 6px 12px; }
		.clickable { cursor: pointer; }
		.clickable:hover { background: #f0f0f0; }
		.emp-id-link { cursor: pointer; color: #2563eb; text-decoration: underline; }
		.emp-id-link:hover { color: #1d4ed8; }
		.modal-body { max-height: 500px; overflow-y: auto; }
	</style>
</head>
<body>
	<div class="container-fluid p-4">
		<div class="row mb-4">
			<div class="col-12">
				<div class="card bg-white">
					<div class="card-body py-3">
						<div class="d-flex justify-content-between align-items-center">
							<h3 class="mb-0"><i class="fas fa-file-alt me-2 text-primary"></i>Attendance Reports</h3>
							<ul class="nav nav-pills">
								<li class="nav-item"><a class="nav-link" href="<?= site_url('C_zoho/dashboard') ?>">Home</a></li>
								<li class="nav-item"><a class="nav-link active" href="<?= site_url('C_zoho/reports') ?>">Reports</a></li>
							</ul>
						</div>
					</div>
				</div>
			</div>
		</div>

		<div class="row mb-4">
			<div class="col-12">
				<div class="card">
					<div class="card-body">
						<div class="row g-3">
							<div class="col-md-2">
								<label class="form-label fw-bold"><i class="fas fa-calendar-alt me-1"></i>From Date</label>
								<input type="date" id="from_date" class="form-control" value="<?= date('Y-m-01') ?>">
							</div>
							<div class="col-md-2">
								<label class="form-label fw-bold"><i class="fas fa-calendar-alt me-1"></i>To Date</label>
								<input type="date" id="to_date" class="form-control" value="<?= date('Y-m-d') ?>">
							</div>
							<div class="col-md-2">
								<label class="form-label fw-bold"><i class="fas fa-user me-1"></i>Employee</label>
								<select id="emp_filter" class="form-control">
									<option value="">All Employees</option>
								</select>
							</div>
							<div class="col-md-1">
								<label class="form-label fw-bold">&nbsp;</label>
								<button type="button" id="btnLoad" class="btn btn-load w-100 text-white">
									<i class="fas fa-sync-alt me-2"></i>Load
								</button>
							</div>
							<div class="col-md-5">
								<label class="form-label fw-bold">Quick Filters</label>
								<div class="btn-group w-100">
									<button type="button" class="btn btn-outline-primary btn-sm" data-range="today">Today</button>
									<button type="button" class="btn btn-outline-primary btn-sm" data-range="yesterday">Yesterday</button>
									<button type="button" class="btn btn-outline-primary btn-sm" data-range="month">This Month</button>
								</div>
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>

		<div class="row mb-4" id="monthlyReport" style="display:none;">
			<div class="col-12">
				<div class="card">
					<div class="card-body">
						<h5 class="mb-3"><i class="fas fa-calendar-check me-2"></i>Monthly Report</h5>
						<table id="reportTable" class="table table-striped w-100">
							<thead>
								<tr>
									<th>Emp ID</th>
									<th>Employee</th>
									<th class="" data-type="present">Present </i></th>
								</tr>
							</thead>
							<tbody></tbody>
						</table>
					</div>
				</div>
			</div>
		</div>

		<div class="row" id="detailReport" style="display:none;">
			<div class="col-12">
				<div class="card">
					<div class="card-body">
						<h5 class="mb-3" id="detailTitle"></h5>
						<button class="btn btn-secondary btn-sm mb-3" id="btnBack"><i class="fas fa-arrow-left me-2"></i>Back</button>
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

	<div class="modal fade" id="empModal" tabindex="-1">
		<div class="modal-dialog modal-md">
			<div class="modal-content">
				<div class="modal-header">
					<h5 class="modal-title" id="modalTitle"></h5>
					<button type="button" class="btn-close" data-bs-dismiss="modal"></button>
				</div>
				<div class="modal-body">
					<h6 class="text-success"><i class="fas fa-check-circle me-2"></i>Present Dates</h6>
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
			$('#emp_filter').select2({ placeholder: 'All Employees', allowClear: true });

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

				$('#btnLoad').prop('disabled', false).html('<i class="fas fa-sync-alt me-2"></i>Load');
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
				rows.push(['<span class="emp-id-link" data-empid="' + empId + '" data-empname="' + s.name + '">' + empId + '</span>', s.name, s.present]);
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

			const typeText = type === 'present' ? 'Present Days' : type === 'absent' ? 'Absent Days' : 'All Days';
			$('#detailTitle').html('<i class="fas fa-user me-2"></i>' + empName + ' - ' + typeText);
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
				return '<li class="text-success">✓ ' + d + ' (' + dayName + ')</li>';
			}).join('') : '<li class="text-muted">No present dates</li>');
			
			new bootstrap.Modal(document.getElementById('empModal')).show();
		}
	</script>
</body>
</html>
