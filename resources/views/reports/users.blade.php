<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Users Report - StudentLink</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            font-size: 12px;
            line-height: 1.4;
            color: #333;
            margin: 0;
            padding: 20px;
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 2px solid #1E2A78;
            padding-bottom: 20px;
        }
        .header h1 {
            color: #1E2A78;
            margin: 0;
            font-size: 24px;
        }
        .header p {
            margin: 5px 0;
            color: #666;
        }
        .filters {
            background-color: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .filters h3 {
            margin: 0 0 10px 0;
            color: #1E2A78;
            font-size: 14px;
        }
        .filter-row {
            display: flex;
            gap: 20px;
            margin-bottom: 5px;
        }
        .filter-label {
            font-weight: bold;
            min-width: 120px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
            vertical-align: top;
        }
        th {
            background-color: #1E2A78;
            color: white;
            font-weight: bold;
        }
        tr:nth-child(even) {
            background-color: #f9f9f9;
        }
        .role {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 10px;
            font-weight: bold;
            text-transform: uppercase;
        }
        .role-admin {
            background-color: #fef3c7;
            color: #92400e;
        }
        .role-department_head {
            background-color: #dbeafe;
            color: #1e40af;
        }
        .role-student {
            background-color: #d1fae5;
            color: #065f46;
        }
        .status {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 10px;
            font-weight: bold;
            text-transform: uppercase;
        }
        .status-active {
            background-color: #d1fae5;
            color: #065f46;
        }
        .status-inactive {
            background-color: #fecaca;
            color: #991b1b;
        }
        .footer {
            margin-top: 30px;
            text-align: center;
            font-size: 10px;
            color: #666;
            border-top: 1px solid #ddd;
            padding-top: 10px;
        }
        .summary {
            background-color: #f0f9ff;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .summary h3 {
            margin: 0 0 10px 0;
            color: #1E2A78;
        }
        .summary-stats {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
        }
        .stat-item {
            background-color: white;
            padding: 10px;
            border-radius: 4px;
            border: 1px solid #e5e7eb;
            min-width: 120px;
        }
        .stat-value {
            font-size: 18px;
            font-weight: bold;
            color: #1E2A78;
        }
        .stat-label {
            font-size: 10px;
            color: #666;
            text-transform: uppercase;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>StudentLink - Users Report</h1>
        <p>Generated on: {{ $generated_at->format('F j, Y \a\t g:i A') }}</p>
        <p>Total Records: {{ $total_count }}</p>
    </div>

    @if(!empty($filters))
    <div class="filters">
        <h3>Report Filters</h3>
        @if(!empty($filters['date_from']))
        <div class="filter-row">
            <span class="filter-label">From Date:</span>
            <span>{{ \Carbon\Carbon::parse($filters['date_from'])->format('F j, Y') }}</span>
        </div>
        @endif
        @if(!empty($filters['date_to']))
        <div class="filter-row">
            <span class="filter-label">To Date:</span>
            <span>{{ \Carbon\Carbon::parse($filters['date_to'])->format('F j, Y') }}</span>
        </div>
        @endif
        @if(!empty($filters['department_id']))
        <div class="filter-row">
            <span class="filter-label">Department:</span>
            <span>{{ $users->first()->department->name ?? 'N/A' }}</span>
        </div>
        @endif
        @if(!empty($filters['status']))
        <div class="filter-row">
            <span class="filter-label">Status:</span>
            <span class="status status-{{ $filters['status'] === 'active' ? 'active' : 'inactive' }}">
                {{ ucfirst($filters['status']) }}
            </span>
        </div>
        @endif
    </div>
    @endif

    <div class="summary">
        <h3>Summary Statistics</h3>
        <div class="summary-stats">
            <div class="stat-item">
                <div class="stat-value">{{ $users->where('role', 'admin')->count() }}</div>
                <div class="stat-label">Admins</div>
            </div>
            <div class="stat-item">
                <div class="stat-value">{{ $users->where('role', 'department_head')->count() }}</div>
                <div class="stat-label">Department Heads</div>
            </div>
            <div class="stat-item">
                <div class="stat-value">{{ $users->where('role', 'student')->count() }}</div>
                <div class="stat-label">Students</div>
            </div>
            <div class="stat-item">
                <div class="stat-value">{{ $users->where('is_active', true)->count() }}</div>
                <div class="stat-label">Active Users</div>
            </div>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Name</th>
                <th>Email</th>
                <th>Display ID</th>
                <th>Role</th>
                <th>Department</th>
                <th>Phone</th>
                <th>Status</th>
                <th>Last Login</th>
                <th>Created</th>
            </tr>
        </thead>
        <tbody>
            @foreach($users as $user)
            <tr>
                <td>{{ $user->name }}</td>
                <td>{{ $user->email }}</td>
                <td>{{ $user->display_id ?? 'N/A' }}</td>
                <td>
                    <span class="role role-{{ $user->role }}">
                        {{ ucfirst(str_replace('_', ' ', $user->role)) }}
                    </span>
                </td>
                <td>{{ $user->department->name ?? 'N/A' }}</td>
                <td>{{ $user->phone ?? 'N/A' }}</td>
                <td>
                    <span class="status status-{{ $user->is_active ? 'active' : 'inactive' }}">
                        {{ $user->is_active ? 'Active' : 'Inactive' }}
                    </span>
                </td>
                <td>{{ $user->last_login_at ? $user->last_login_at->format('M j, Y') : 'Never' }}</td>
                <td>{{ $user->created_at->format('M j, Y') }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <div class="footer">
        <p>This report was generated by StudentLink System</p>
        <p>Bestlink College of the Philippines</p>
    </div>
</body>
</html>

