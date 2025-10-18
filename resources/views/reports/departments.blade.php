<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Departments Report - StudentLink</title>
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
        .type {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 10px;
            font-weight: bold;
            text-transform: uppercase;
        }
        .type-academic {
            background-color: #dbeafe;
            color: #1e40af;
        }
        .type-administrative {
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
        .resolution-rate {
            font-weight: bold;
        }
        .resolution-rate.high {
            color: #065f46;
        }
        .resolution-rate.medium {
            color: #92400e;
        }
        .resolution-rate.low {
            color: #991b1b;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>StudentLink - Departments Report</h1>
        <p>Generated on: {{ $generated_at->format('F j, Y \a\t g:i A') }}</p>
        <p>Total Records: {{ $total_count }}</p>
    </div>

    @if(!empty($filters))
    <div class="filters">
        <h3>Report Filters</h3>
        <div class="filter-row">
            <span class="filter-label">Report Type:</span>
            <span>Department Performance Analysis</span>
        </div>
    </div>
    @endif

    <div class="summary">
        <h3>Summary Statistics</h3>
        <div class="summary-stats">
            <div class="stat-item">
                <div class="stat-value">{{ $departments->where('type', 'academic')->count() }}</div>
                <div class="stat-label">Academic Departments</div>
            </div>
            <div class="stat-item">
                <div class="stat-value">{{ $departments->where('type', 'administrative')->count() }}</div>
                <div class="stat-label">Administrative Departments</div>
            </div>
            <div class="stat-item">
                <div class="stat-value">{{ $departments->sum('users_count') }}</div>
                <div class="stat-label">Total Users</div>
            </div>
            <div class="stat-item">
                <div class="stat-value">{{ $departments->sum('concerns_count') }}</div>
                <div class="stat-label">Total Concerns</div>
            </div>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Department</th>
                <th>Code</th>
                <th>Type</th>
                <th>Status</th>
                <th>Users</th>
                <th>Total Concerns</th>
                <th>Pending</th>
                <th>Resolved</th>
                <th>Resolution Rate</th>
                <th>Created</th>
            </tr>
        </thead>
        <tbody>
            @foreach($departments as $department)
            @php
                $resolutionRate = $department->concerns_count > 0 
                    ? round(($department->resolved_concerns_count / $department->concerns_count) * 100, 1)
                    : 0;
                $rateClass = $resolutionRate >= 80 ? 'high' : ($resolutionRate >= 60 ? 'medium' : 'low');
            @endphp
            <tr>
                <td>
                    <strong>{{ $department->name }}</strong>
                    @if($department->description)
                    <br><small>{{ $department->description }}</small>
                    @endif
                </td>
                <td>{{ $department->code }}</td>
                <td>
                    <span class="type type-{{ $department->type }}">
                        {{ ucfirst($department->type) }}
                    </span>
                </td>
                <td>
                    <span class="status status-{{ $department->is_active ? 'active' : 'inactive' }}">
                        {{ $department->is_active ? 'Active' : 'Inactive' }}
                    </span>
                </td>
                <td>{{ $department->users_count }}</td>
                <td>{{ $department->concerns_count }}</td>
                <td>{{ $department->pending_concerns_count }}</td>
                <td>{{ $department->resolved_concerns_count }}</td>
                <td>
                    <span class="resolution-rate {{ $rateClass }}">
                        {{ $resolutionRate }}%
                    </span>
                </td>
                <td>{{ $department->created_at->format('M j, Y') }}</td>
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

