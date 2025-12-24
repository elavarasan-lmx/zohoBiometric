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
            transition: transform 0.2s;
		}
        
        .stat-card:hover {
            transform: translateY(-5px);
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
        
        .btn-outline-primary {
            color: #fff;
            border-color: #fff;
        }
        .btn-outline-primary:hover {
            background-color: #fff;
            color: #2563eb;
        }
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
                <a href="<?= site_url('C_zoho/reports') ?>" class="btn btn-sm btn-outline-primary text-primary border-primary me-2">
                    <i class="fas fa-file-alt me-1"></i> Reports
                </a>
                 <a href="<?= site_url('C_zoho/admin') ?>" class="btn btn-sm btn-primary">
                    <i class="fas fa-cogs me-1"></i> Admin Panel
                </a>
            </div>
        </div>
    </nav>

	<div class="container-fluid px-4">
		<!-- Filter Section -->
		<div class="row mb-4">
			<div class="col-12">
				<div class="card bg-white">
					<div class="card-body">
						<div class="row g-3 align-items-end">
							<div class="col-md-3">
								<label class="form-label fw-bold">Select Date</label>
								<input type="date" id="date" class="form-control" value="<?= date('Y-m-d') ?>">
							</div>
							<div class="col-md-2">
								<button type="button" id="btnLoad" class="btn btn-load w-100 text-white">
									<i class="fas fa-sync-alt me-2"></i>Load Data
								</button>
							</div>
							<div class="col-md-4 ms-auto text-end">
                                <label class="form-label fw-bold d-block">Quick Select</label>
								<div class="btn-group">
									<button type="button" class="btn btn-secondary" data-range="today">Today</button>
									<button type="button" class="btn btn-secondary" data-range="yesterday">Yesterday</button>
								</div>
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>

		<!-- Statistics -->
		<div class="row mb-4">
			<div class="col-md-3 mb-3">
				<div class="card stat-card employees h-100">
					<div class="card-body text-center">
						<i class="fas fa-users fa-3x text-success mb-2"></i>
						<div class="text-muted small">Total Employees</div>
						<h2 class="fw-bold mb-0" id="totalEmployees">0</h2>
					</div>
				</div>
			</div>
			<div class="col-md-3 mb-3">
				<div class="card stat-card present h-100">
					<div class="card-body text-center">
						<i class="fas fa-user-check fa-3x text-primary mb-2"></i>
						<div class="text-muted small">Present</div>
						<h2 class="fw-bold mb-0" id="presentCount">0</h2>
						<small class="text-muted" id="presentPercent"></small>
					</div>
				</div>
			</div>
			<div class="col-md-3 mb-3">
				<div class="card stat-card absent h-100">
					<div class="card-body text-center">
						<i class="fas fa-user-times fa-3x text-danger mb-2"></i>
						<div class="text-muted small">Absent</div>
						<h2 class="fw-bold mb-0" id="absentCount">0</h2>
					</div>
				</div>
			</div>
			<div class="col-md-3 mb-3">
				<div class="card stat-card pending h-100">
					<div class="card-body text-center">
						<i class="fas fa-clock fa-3x text-warning mb-2"></i>
						<div class="text-muted small">Pending Sync</div>
						<h2 class="fw-bold mb-0" id="pendingSync">0</h2>
					</div>
				</div>
			</div>
		</div>

        <!-- Data Table -->
		<div class="row">
			<div class="col-12">
				<div class="card">
					<div class="card-body">
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
								</tr>
							</thead>
							<tbody></tbody>
						</table>
					</div>
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
		let table;

		$(document).ready(function() {
			table = $('#attendanceTable').DataTable({
				pageLength: 25,
				dom: "<'row mb-2'<'col-md-6'B><'col-md-6'f>><'row'<'col-12'tr>><'row mt-2'<'col-md-5'i><'col-md-7'p>>",
				buttons: [{
					extend: 'excelHtml5',
					text: '<i class="fas fa-file-excel"></i> Excel',
					className: 'btn btn-success btn-sm'
				}],
				order: [
					[2, 'desc']
				]
			});

			$('#btnLoad').on('click', loadData);
			$('.btn-group button').on('click', function() {
				const range = $(this).data('range');
				const today = new Date();
				let date = today.toISOString().split('T')[0];
				if (range === 'yesterday') {
					today.setDate(today.getDate() - 1);
					date = today.toISOString().split('T')[0];
				}
				$('#date').val(date);
				loadData();
			});

			loadData();
		});

		function loadData() {
			const date = $('#date').val();
			$('#btnLoad').prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-2"></i>Loading...');

			$.get(API_URL, {
				from: date,
				to: date
			}, function(data) {
				$('#totalEmployees').text(data.stats.total_employees);
				$('#presentCount').text(data.stats.present_in_range);
				$('#absentCount').text(data.stats.total_employees - data.stats.present_in_range);
				$('#pendingSync').text(data.stats.pending_sync);
				$('#presentPercent').text(data.stats.total_employees > 0 ? ((data.stats.present_in_range / data.stats.total_employees) * 100).toFixed(1) + '%' : '0%');

				let rows = [];
				$.each(data.daily_attendance, function(i, d) {
					let hours = '-';
					if (d.first_in && d.last_out && d.first_in !== '-' && d.last_out !== '-') {
						const [h1, m1] = d.first_in.split(':').map(Number);
						const [h2, m2] = d.last_out.split(':').map(Number);
						let totalMinutes = (h2 * 60 + m2) - (h1 * 60 + m1);
						if (totalMinutes < 0) totalMinutes += 24 * 60;
						hours = Math.floor(totalMinutes / 60) + 'h ' + (totalMinutes % 60) + 'm';
					}
					let status = d.first_in ? '<span class="badge bg-success">Present</span>' : '<span class="badge bg-danger">Absent</span>';
					rows.push([d.emp_id, d.name || d.emp_id, d.work_date, d.first_in || '-', d.last_out || '-', hours, status]);
				});

				table.clear().rows.add(rows).draw();
				$('#btnLoad').prop('disabled', false).html('<i class="fas fa-sync-alt me-2"></i>Load Data');
			});
		}
	</script>
</body>
</html>
