<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Concerns Report - StudentLink</title>
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
        .status {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 10px;
            font-weight: bold;
            text-transform: uppercase;
        }
        .status-pending {
            background-color: #fef3c7;
            color: #92400e;
        }
        .status-in-progress {
            background-color: #dbeafe;
            color: #1e40af;
        }
        .status-resolved {
            background-color: #d1fae5;
            color: #065f46;
        }
        .status-closed {
            background-color: #f3f4f6;
            color: #374151;
        }
        .priority {
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 10px;
            font-weight: bold;
        }
        .priority-low {
            background-color: #e5e7eb;
            color: #374151;
        }
        .priority-medium {
            background-color: #fef3c7;
            color: #92400e;
        }
        .priority-high {
            background-color: #fed7d7;
            color: #c53030;
        }
        .priority-urgent {
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
        <h1>StudentLink - Concerns Report</h1>
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
            <span>{{ $concerns->first()->department->name ?? 'N/A' }}</span>
        </div>
        @endif
        @if(!empty($filters['status']))
        <div class="filter-row">
            <span class="filter-label">Status:</span>
            <span class="status status-{{ $filters['status'] }}">{{ ucfirst(str_replace('_', ' ', $filters['status'])) }}</span>
        </div>
        @endif
    </div>
    @endif

    <div class="summary">
        <h3>Summary Statistics</h3>
        <div class="summary-stats">
            <div class="stat-item">
                <div class="stat-value">{{ $concerns->where('status', 'pending')->count() }}</div>
                <div class="stat-label">Pending</div>
            </div>
            <div class="stat-item">
                <div class="stat-value">{{ $concerns->where('status', 'in_progress')->count() }}</div>
                <div class="stat-label">In Progress</div>
            </div>
            <div class="stat-item">
                <div class="stat-value">{{ $concerns->where('status', 'resolved')->count() }}</div>
                <div class="stat-label">Resolved</div>
            </div>
            <div class="stat-item">
                <div class="stat-value">{{ $concerns->where('status', 'closed')->count() }}</div>
                <div class="stat-label">Closed</div>
            </div>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Reference #</th>
                <th>Subject</th>
                <th>Student</th>
                <th>Department</th>
                <th>Status</th>
                <th>Priority</th>
                <th>Assigned To</th>
                <th>Created</th>
                <th>Resolved</th>
            </tr>
        </thead>
        <tbody>
            @foreach($concerns as $concern)
            <tr>
                <td>{{ $concern->reference_number }}</td>
                <td>{{ $concern->subject }}</td>
                <td>
                    {{ $concern->student->name ?? 'N/A' }}<br>
                    <small>{{ $concern->student->display_id ?? 'N/A' }}</small>
                </td>
                <td>{{ $concern->department->name ?? 'N/A' }}</td>
                <td>
                    <span class="status status-{{ $concern->status }}">
                        {{ ucfirst(str_replace('_', ' ', $concern->status)) }}
                    </span>
                </td>
                <td>
                    <span class="priority priority-{{ $concern->priority }}">
                        {{ ucfirst($concern->priority) }}
                    </span>
                </td>
                <td>{{ $concern->assignedTo->name ?? 'Unassigned' }}</td>
                <td>{{ $concern->created_at->format('M j, Y') }}</td>
                <td>{{ $concern->resolved_at ? $concern->resolved_at->format('M j, Y') : 'N/A' }}</td>
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

