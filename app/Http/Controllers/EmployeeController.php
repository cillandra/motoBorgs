<?php

namespace App\Http\Controllers;

use App\Http\Requests\EmployeeRequest;
use App\Models\AuditEvent;
use App\Models\User;
use App\Services\Auth\Reauthentication;
use App\Services\Identity\EmployeeService;
use App\Services\Settings\PreferenceService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EmployeeController extends Controller
{
    public function index(Request $request): mixed
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:120'], 'role' => ['nullable', 'in:manager,staff'], 'status' => ['nullable', 'in:active,inactive'], 'tab' => ['nullable', 'in:employees,roles']]);
        try {
            $employees = User::when(! empty($filters['q']), fn ($q) => $q->where(fn ($s) => $s->where('name', 'like', '%'.$filters['q'].'%')->orWhere('username', 'like', '%'.$filters['q'].'%')))
                ->when(! empty($filters['role']), fn ($q) => $q->where('role', $filters['role']))->when(! empty($filters['status']), fn ($q) => $q->where('active', $filters['status'] === 'active'))->orderBy('name')->paginate(app(PreferenceService::class)->rows($request->user()))->withQueryString();
            $summary = ['total' => User::count(), 'active' => User::where('active', true)->count(), 'inactive' => User::where('active', false)->count(), 'managers' => User::where('active', true)->where('role', 'manager')->count()];
            $lastSignIns = AuditEvent::where('type', 'Sign in')->whereIn('actor_id', $employees->pluck('id'))->selectRaw('actor_id, MAX(created_at) as signed_in_at')->groupBy('actor_id')->pluck('signed_in_at', 'actor_id');

            return view('employees.index', compact('employees', 'filters', 'summary', 'lastSignIns'));
        } catch (QueryException $error) {
            report($error);

            return response()->view('employees.index', compact('filters') + ['unavailable' => true], 503);
        }
    }

    public function create(): mixed
    {
        return view('employees.form', ['employee' => null]);
    }

    public function show(User $employee): mixed
    {
        $lastSignIn = AuditEvent::where('actor_id', $employee->id)->where('type', 'Sign in')->latest('id')->first()?->created_at;

        return view('employees.show', compact('employee', 'lastSignIn'));
    }

    public function edit(User $employee): mixed
    {
        return view('employees.form', compact('employee'));
    }

    private function saved(Request $request, User $employee): mixed
    {
        return $request->expectsJson() ? response()->json(['modal_url' => route('employees.show', $employee), 'message' => 'Employee account saved.']) : redirect()->route('employees.show', $employee)->with('status', 'Employee account saved.');
    }

    public function store(EmployeeRequest $request, EmployeeService $service, Reauthentication $reauthentication): mixed
    {
        $data = $request->validated();
        $reauthentication->confirm($request->user(), $data['manager_password'], 'Employee creation');
        unset($data['manager_password'],$data['role_confirmed']);

        return $this->saved($request, $service->save($request->user(), null, $data));
    }

    public function update(EmployeeRequest $request, User $employee, EmployeeService $service, Reauthentication $reauthentication): mixed
    {
        $data = $request->validated();
        $reauthentication->confirm($request->user(), $data['manager_password'], 'Employee update');
        unset($data['manager_password'],$data['role_confirmed']);

        $relogin = $employee->id === $request->user()->id && $employee->username !== $data['username'];
        $saved = $service->save($request->user(), $employee, $data);
        if ($relogin) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return $request->expectsJson() ? response()->json(['redirect' => route('login'), 'message' => 'Username changed. Sign in with your new username.']) : redirect()->route('login')->with('status', 'Username changed. Sign in with your new username.');
        }

        return $this->saved($request, $saved);
    }

    public function status(Request $request, User $employee, EmployeeService $service, Reauthentication $reauthentication): mixed
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000'], 'manager_password' => ['required', 'string', 'max:1024']]);
        $reauthentication->confirm($request->user(), $data['manager_password'], 'Employee status change');
        if ($request->routeIs('employees.deactivate')) {
            $service->deactivate($request->user(), $employee, $data['reason']);
        } else {
            $service->reactivate($request->user(), $employee);
        }

        return $this->saved($request, $employee->refresh());
    }
}
