<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Concern;
use App\Models\Announcement;
use App\Models\Department;
use Illuminate\Http\Response;
use Barryvdh\DomPDF\Facade\Pdf;

trait ExportMethods
{
    /**
     * Export users report
     */
    private function exportUsersReport(string $format, array $filters)
    {
        $query = User::with(['department']);

        // Apply filters
        if (!empty($filters['date_from'])) {
            $query->where('created_at', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $query->where('created_at', '<=', $filters['date_to']);
        }
        if (!empty($filters['department_id'])) {
            $query->where('department_id', $filters['department_id']);
        }
        if (!empty($filters['status'])) {
            $query->where('is_active', $filters['status'] === 'active');
        }

        $users = $query->orderBy('created_at', 'desc')->get();

        switch ($format) {
            case 'csv':
                return $this->exportUsersToCSV($users);
            case 'pdf':
                return $this->exportUsersToPDF($users, $filters);
            case 'excel':
                return $this->exportUsersToExcel($users);
            default:
                throw new \InvalidArgumentException('Unsupported format: ' . $format);
        }
    }

    /**
     * Export users to CSV
     */
    private function exportUsersToCSV($users)
    {
        $filename = 'users_report_' . date('Y-m-d_H-i-s') . '.csv';
        
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ];

        $callback = function() use ($users) {
            $file = fopen('php://output', 'w');
            
            // CSV headers
            fputcsv($file, [
                'Name',
                'Email',
                'Display ID',
                'Role',
                'Department',
                'Phone',
                'Status',
                'Last Login',
                'Created At',
                'Updated At'
            ]);

            // CSV data
            foreach ($users as $user) {
                fputcsv($file, [
                    $user->name,
                    $user->email,
                    $user->display_id ?? 'N/A',
                    $user->role,
                    $user->department->name ?? 'N/A',
                    $user->phone ?? 'N/A',
                    $user->is_active ? 'Active' : 'Inactive',
                    $user->last_login_at ? $user->last_login_at->format('Y-m-d H:i:s') : 'Never',
                    $user->created_at->format('Y-m-d H:i:s'),
                    $user->updated_at->format('Y-m-d H:i:s')
                ]);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Export users to PDF
     */
    private function exportUsersToPDF($users, $filters)
    {
        $filename = 'users_report_' . date('Y-m-d_H-i-s') . '.pdf';
        
        $data = [
            'users' => $users,
            'filters' => $filters,
            'generated_at' => now(),
            'total_count' => $users->count(),
        ];

        $pdf = Pdf::loadView('reports.users', $data);
        
        return $pdf->download($filename);
    }

    /**
     * Export users to Excel
     */
    private function exportUsersToExcel($users)
    {
        return $this->exportUsersToCSV($users);
    }

    /**
     * Export announcements report
     */
    private function exportAnnouncementsReport(string $format, array $filters)
    {
        $query = Announcement::with(['author']);

        // Apply filters
        if (!empty($filters['date_from'])) {
            $query->where('created_at', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $query->where('created_at', '<=', $filters['date_to']);
        }
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $announcements = $query->orderBy('created_at', 'desc')->get();

        switch ($format) {
            case 'csv':
                return $this->exportAnnouncementsToCSV($announcements);
            case 'pdf':
                return $this->exportAnnouncementsToPDF($announcements, $filters);
            case 'excel':
                return $this->exportAnnouncementsToExcel($announcements);
            default:
                throw new \InvalidArgumentException('Unsupported format: ' . $format);
        }
    }

    /**
     * Export announcements to CSV
     */
    private function exportAnnouncementsToCSV($announcements)
    {
        $filename = 'announcements_report_' . date('Y-m-d_H-i-s') . '.csv';
        
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ];

        $callback = function() use ($announcements) {
            $file = fopen('php://output', 'w');
            
            // CSV headers
            fputcsv($file, [
                'Title',
                'Content',
                'Type',
                'Priority',
                'Status',
                'Author',
                'View Count',
                'Bookmark Count',
                'Published At',
                'Expires At',
                'Created At',
                'Updated At'
            ]);

            // CSV data
            foreach ($announcements as $announcement) {
                fputcsv($file, [
                    $announcement->title,
                    $announcement->content,
                    $announcement->type,
                    $announcement->priority,
                    $announcement->status,
                    $announcement->author->name ?? 'N/A',
                    $announcement->view_count,
                    $announcement->bookmark_count,
                    $announcement->published_at ? $announcement->published_at->format('Y-m-d H:i:s') : 'N/A',
                    $announcement->expires_at ? $announcement->expires_at->format('Y-m-d H:i:s') : 'N/A',
                    $announcement->created_at->format('Y-m-d H:i:s'),
                    $announcement->updated_at->format('Y-m-d H:i:s')
                ]);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Export announcements to PDF
     */
    private function exportAnnouncementsToPDF($announcements, $filters)
    {
        $filename = 'announcements_report_' . date('Y-m-d_H-i-s') . '.pdf';
        
        $data = [
            'announcements' => $announcements,
            'filters' => $filters,
            'generated_at' => now(),
            'total_count' => $announcements->count(),
        ];

        $pdf = Pdf::loadView('reports.announcements', $data);
        
        return $pdf->download($filename);
    }

    /**
     * Export announcements to Excel
     */
    private function exportAnnouncementsToExcel($announcements)
    {
        return $this->exportAnnouncementsToCSV($announcements);
    }

    /**
     * Export departments report
     */
    private function exportDepartmentsReport(string $format, array $filters)
    {
        $departments = Department::withCount([
            'users',
            'concerns',
            'concerns as pending_concerns' => function ($query) {
                $query->where('status', 'pending');
            },
            'concerns as resolved_concerns' => function ($query) {
                $query->where('status', 'resolved');
            }
        ])->get();

        switch ($format) {
            case 'csv':
                return $this->exportDepartmentsToCSV($departments);
            case 'pdf':
                return $this->exportDepartmentsToPDF($departments, $filters);
            case 'excel':
                return $this->exportDepartmentsToExcel($departments);
            default:
                throw new \InvalidArgumentException('Unsupported format: ' . $format);
        }
    }

    /**
     * Export departments to CSV
     */
    private function exportDepartmentsToCSV($departments)
    {
        $filename = 'departments_report_' . date('Y-m-d_H-i-s') . '.csv';
        
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ];

        $callback = function() use ($departments) {
            $file = fopen('php://output', 'w');
            
            // CSV headers
            fputcsv($file, [
                'Name',
                'Code',
                'Description',
                'Type',
                'Status',
                'Total Users',
                'Total Concerns',
                'Pending Concerns',
                'Resolved Concerns',
                'Resolution Rate (%)',
                'Created At',
                'Updated At'
            ]);

            // CSV data
            foreach ($departments as $department) {
                $resolutionRate = $department->concerns_count > 0 
                    ? round(($department->resolved_concerns_count / $department->concerns_count) * 100, 2)
                    : 0;

                fputcsv($file, [
                    $department->name,
                    $department->code,
                    $department->description ?? 'N/A',
                    $department->type,
                    $department->is_active ? 'Active' : 'Inactive',
                    $department->users_count,
                    $department->concerns_count,
                    $department->pending_concerns_count,
                    $department->resolved_concerns_count,
                    $resolutionRate,
                    $department->created_at->format('Y-m-d H:i:s'),
                    $department->updated_at->format('Y-m-d H:i:s')
                ]);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Export departments to PDF
     */
    private function exportDepartmentsToPDF($departments, $filters)
    {
        $filename = 'departments_report_' . date('Y-m-d_H-i-s') . '.pdf';
        
        $data = [
            'departments' => $departments,
            'filters' => $filters,
            'generated_at' => now(),
            'total_count' => $departments->count(),
        ];

        $pdf = Pdf::loadView('reports.departments', $data);
        
        return $pdf->download($filename);
    }

    /**
     * Export departments to Excel
     */
    private function exportDepartmentsToExcel($departments)
    {
        return $this->exportDepartmentsToCSV($departments);
    }
}
