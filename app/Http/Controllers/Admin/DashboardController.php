<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Site;
use App\Models\Service;

class DashboardController extends Controller
{
    public function index()
    {
        // Lấy thông tin thống kê cơ bản cho Admin Dashboard
        $stats = [
            'total_users' => User::count(),
            'total_sites' => Site::count(),
            'active_services' => Service::where('is_active', true)->count(),
        ];

        return view('admin.dashboard', compact('stats'));
    }

    public function users()
    {
        // Quản lý danh sách người dùng
        $users = User::latest()->paginate(20);
        return view('admin.users', compact('users'));
    }

    public function services()
    {
        // Quản lý danh sách các dịch vụ/addons
        $services = Service::all();
        return view('admin.services', compact('services'));
    }
}
