<?php

namespace App\Http\Controllers\Backend\Admin\AdminManagement;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminManagement\AdminRequest;
use App\Http\Traits\AuditRelationTraits;
use App\Models\Admin;
use App\Services\Admin\AdminManagement\AdminService;
use App\Services\Admin\AdminManagement\RoleService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Yajra\DataTables\Facades\DataTables;

class AdminController extends Controller implements HasMiddleware
{
    use AuditRelationTraits;

    protected function redirectIndex(): RedirectResponse
    {
        return redirect()->route('am.admin.index');
    }

    protected function redirectTrashed(): RedirectResponse
    {
        return redirect()->route('am.admin.trash');
    }

    protected AdminService $adminService;
    protected RoleService $roleService;

    public function __construct(AdminService $adminService, RoleService $roleService)
    {
        $this->adminService = $adminService;
        $this->roleService = $roleService;
    }

    public static function middleware(): array
    {
        return [
            'auth:admin', // Applies 'auth:admin' to all methods

            // Permission middlewares using the Middleware class
            new Middleware('permission:admin-list', only: ['index']),
            new Middleware('permission:admin-details', only: ['show']),
            new Middleware('permission:admin-create', only: ['create', 'store']),
            new Middleware('permission:admin-edit', only: ['edit', 'update']),
            new Middleware('permission:admin-delete', only: ['destroy']),
            new Middleware('permission:admin-status', only: ['status']),
            new Middleware('permission:admin-trash', only: ['trash']),
            new Middleware('permission:admin-restore', only: ['restore']),
            new Middleware('permission:admin-permanent-delete', only: ['permanentDelete']),
        ];
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $status = $request->get('status');
        if ($request->ajax()) {
            $query = $this->adminService->getAdmins();
            if ($status) {
                $query = $query->where('status', array_search($status, Admin::statusList()))->verified();
            }
            return DataTables::eloquent($query)
                ->editColumn('role_id', fn($admin) => $admin->role?->name)
                ->editColumn('email_verified_at', fn($admin) => "<span class='badge badge-soft {$admin->verify_color}'>{$admin->verify_label}</span>")
                ->editColumn('status', fn($admin) => "<span class='badge badge-soft {$admin->status_color}'>{$admin->status_label}</span>")
                ->editColumn('created_by', fn($admin) => $this->creater_name($admin))
                ->editColumn('created_at', fn($admin) => $admin->created_at_formatted)
                ->editColumn('action', fn($admin) => view('components.admin.action-buttons', ['menuItems' => $this->menuItems($admin)])->render())
                ->rawColumns(['role_id', 'status', 'email_verified_at', 'created_by', 'created_at', 'action'])
                ->make(true);
        }
        return view('backend.admin.admin-management.admin.index');
    }

    protected function menuItems($model): array
    {
        return [
            [
                'routeName' => 'javascript:void(0)',
                'data-id' => encrypt($model->id),
                'className' => 'view',
                'label' => 'Details',
                'permissions' => ['admin-details']
            ],
            [
                'routeName' => 'am.admin.status',
                'params' => [encrypt($model->id)],
                'label' => $model->status_btn_label,
                'permissions' => ['admin-status']
            ],
            [
                'routeName' => 'am.admin.edit',
                'params' => [encrypt($model->id)],
                'label' => 'Edit',
                'permissions' => ['admin-edit']
            ],

            [
                'routeName' => 'am.admin.destroy',
                'params' => [encrypt($model->id)],
                'label' => 'Delete',
                'delete' => true,
                'permissions' => ['admin-delete']
            ]

        ];
    }
    
    /**
     * Show the form for creating a new resource.
     */
    public function create(): View
    {
        $data['roles'] = $this->roleService->getRoles()->select(['id', 'name'])->get();
        return view('backend.admin.admin-management.admin.create', $data);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(AdminRequest $request)
    {
        try {
            $validated = $request->validated();
            $validated['role_id'] = $request->role;
            $file = $request->validated('image') && $request->hasFile('image') ? $request->file('image') : null;
            $this->adminService->createAdmin($validated, $file);
            session()->flash('success', 'Admin created successfully!');
        } catch (\Throwable $e) {
            session()->flash('error', 'Admin create failed!');
            throw $e;
        }
        return $this->redirectIndex();
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, string $id)
    {
        $data = $this->adminService->getAdmin($id);
        $data['creater_name'] = $this->creater_name($data);
        $data['updater_name'] = $this->updater_name($data);
        $data['role_name'] = $data->role?->name;
        return response()->json($data);
    }

    public function status(string $id)
    {
        $admin = $this->adminService->getAdmin($id);
        if ($admin->role_id == 1 && admin()->role_id != 1) {
            session()->flash('error', 'Only a Super Admin can change status of another Super Admin!');
            return redirect()->back();
        }
        $this->adminService->toggleStatus($admin);
        session()->flash('success', 'Admin status updated successfully!');
        return redirect()->back();
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        $data['admin'] = $this->adminService->getAdmin($id);
        $data['roles'] = $this->roleService->getRoles()->select('id', 'name')->get();
        return view('backend.admin.admin-management.admin.edit', $data);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(AdminRequest $request, string $id)
    {
        try {
            $admin = $this->adminService->getAdmin($id);

            // if role id is super admin and admin is not super admin then can not update
            if ($admin->role_id == 1 && admin()->role_id != 1) {
                session()->flash('error', 'Only a Super Admin can update another Super Admin!');
                return $this->redirectIndex();
            }

            // if role id is super admin and admin is not super admin then can not update
            if ($admin->role_id == 1 && admin()->role_id != 1) {
                session()->flash('error', 'You can not update Super Admin!');
                return $this->redirectIndex();
            }
            $validated = $request->validated();
            $validated['role_id'] = $request->role;
            $file = $request->validated('image') && $request->hasFile('image') ? $request->file('image') : null;
            $this->adminService->updateAdmin($admin, $validated, $file);
            session()->flash('success', 'Admin updated successfully!');
        } catch (\Throwable $e) {
            session()->flash('error', 'Admin update failed!');
            throw $e;
        }
        return $this->redirectIndex();
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        try {
            $admin = $this->adminService->getAdmin($id);
            if ($admin->role_id == 1 && admin()->role_id != 1) {
                session()->flash('error', 'Only a Super Admin can delete another Super Admin!');
                return $this->redirectIndex();
            }
            $this->adminService->delete($admin);
            session()->flash('success', 'Admin deleted successfully!');
        } catch (\Throwable $e) {
            session()->flash('error', 'Admin delete failed!');
            throw $e;
        }
        return $this->redirectIndex();
    }

    public function trash(Request $request)
    {
        if ($request->ajax()) {
            $query = $this->adminService->getAdmins()->onlyTrashed();

            return DataTables::eloquent($query)
                ->editColumn('role_id', fn($admin) => $admin->role?->name)
                ->editColumn('deleted_by', fn($admin) => $this->deleter_name($admin))
                ->editColumn('deleted_at', fn($admin) => $admin->deleted_at_formatted)
                ->editColumn('action', fn($admin) => view('components.admin.action-buttons', [
                    'menuItems' => $this->trashedMenuItems($admin),
                ])->render())
                ->rawColumns(['deleted_by', 'deleted_at', 'action'])
                ->make(true);
        }

        return view('backend.admin.admin-management.admin.trash');
    }


    protected function trashedMenuItems($model): array
    {
        return [
            [
                'routeName' => 'am.admin.restore',
                'params' => [encrypt($model->id)],
                'label' => 'Restore',
                'permissions' => ['admin-restore']
            ],
            [
                'routeName' => 'am.admin.permanent-delete',
                'params' => [encrypt($model->id)],
                'label' => 'Permanent Delete',
                'p-delete' => true,
                'permissions' => ['admin-permanent-delete']
            ]

        ];
    }

    public function restore(string $id)
    {
        try {
            $this->adminService->restore($id);
            session()->flash('success', "Admin restored successfully");
        } catch (\Throwable $e) {
            session()->flash('Admin restore failed');
            throw $e;
        }
        return $this->redirectTrashed();
    }

    public function permanentDelete(string $id)
    {
        try {
            $this->adminService->permanentDelete($id);
            session()->flash('success', "Admin permanently deleted successfully");
        } catch (\Throwable $e) {
            session()->flash('Admin permanent delete failed');
            throw $e;
        }
        return $this->redirectTrashed();
    }
}
