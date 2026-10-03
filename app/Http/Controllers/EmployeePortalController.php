<?php

namespace App\Http\Controllers;

use App\Models\employee;
use App\Models\leave_master;
use App\Models\NoPayRecord;
use App\Models\over_time;
use App\Models\salary_process;
use App\Models\SalaryAdvanceRequest;
use App\Models\time_card;
use App\Services\CompanyProcessSettings;
use App\Services\FirebaseStorageService;
use App\Services\LeaveNotificationService;
use App\Services\MedicalClaimService;
use App\Services\PendingPaymentService;
use App\Services\SalaryAdvanceService;
use App\Services\WeeklyOffService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class EmployeePortalController extends Controller
{
    public function linkedEmployee(Request $request): employee
    {
        return $this->requireEmployee($request);
    }

    private function requireEmployee(Request $request): employee
    {
        $user = $request->user();
        if (!$user || !$user->employee_id) {
            abort(403, 'No employee profile is linked to your login.');
        }

        $emp = employee::with([
            'organizationAssignment.department',
            'organizationAssignment.designation',
            'organizationAssignment.company',
            'employmentType',
            'contactDetail',
        ])
            ->find($user->employee_id);

        if (!$emp) {
            abort(404, 'Employee profile not found.');
        }

        return $emp;
    }

    public function home(Request $request)
    {
        $emp = $this->requireEmployee($request);
        $month = (int) ($request->query('month') ?: now()->month);
        $year = (int) ($request->query('year') ?: now()->year);
        $from = Carbon::create($year, $month, 1)->startOfDay();
        $to = (clone $from)->endOfMonth();

        $attendanceCount = time_card::where('employee_id', $emp->id)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->distinct('date')
            ->count('date');

        $otHours = (float) over_time::where('employee_id', $emp->id)
            ->whereBetween('created_at', [$from, $to])
            ->sum('ot_hours');

        $nopayDays = (float) NoPayRecord::where('employee_id', $emp->id)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->sum('no_pay_count');

        $lastSalary = salary_process::where('employee_id', $emp->id)
            ->whereIn('status', ['processed', 'issued', 'pending'])
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->first();

        $net = 0;
        if ($lastSalary) {
            $sb = is_string($lastSalary->salary_breakdown)
                ? json_decode($lastSalary->salary_breakdown, true)
                : ($lastSalary->salary_breakdown ?? []);
            $net = (float) ($sb['net_salary'] ?? $sb['net_pay'] ?? 0);
        }

        $pendingLeave = leave_master::where('employee_id', $emp->id)
            ->whereIn('status', [
                'Pending',
                'pending',
                'Pending_Covering',
                'Pending_Supervisor',
                'Supervisor_Approved',
                'SUPERVISOR_APPROVED',
            ])
            ->count();

        $advances = SalaryAdvanceRequest::where('employee_id', $emp->id)
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        $pendingAdvance = $advances->where('status', 'PENDING')->count();

        $dept = $emp->organizationAssignment->department->name ?? null;
        $desig = $emp->organizationAssignment->designation->name ?? null;
        $company = $emp->organizationAssignment->company->name ?? null;
        $contact = $emp->contactDetail;
        $leaveWorkflow = CompanyProcessSettings::usesLeaveWorkflow($emp);
        $medicalLeaveEnabled = CompanyProcessSettings::usesMedicalLeave($emp);

        $leaveBalances = [];
        try {
            $eligRequest = Request::create('/leave-eligibility', 'GET', [
                'employee_id' => $emp->id,
            ]);
            $eligResponse = app(LeaveMasterController::class)->getLeaveEligibility($eligRequest);
            $eligJson = json_decode($eligResponse->getContent(), true) ?: [];
            $leaveBalances = $eligJson['eligible_leaves'] ?? [];
        } catch (\Throwable $e) {
            $leaveBalances = [];
        }

        $coveringPending = 0;
        if ($leaveWorkflow && Schema::hasColumn('leave_masters', 'covering_employee_id')) {
            $coveringPending = leave_master::where('covering_employee_id', $emp->id)
                ->where('status', 'Pending_Covering')
                ->count();
        }

        return response()->json([
            'employee' => [
                'id' => $emp->id,
                'fullName' => $emp->full_name ?: $emp->name_with_initials,
                'displayName' => $emp->display_name,
                'nameWithInitials' => $emp->name_with_initials,
                'title' => $emp->title,
                'employeeNo' => $emp->attendance_employee_no,
                'epf' => $emp->epf,
                'nic' => $emp->nic,
                'dob' => $emp->dob,
                'gender' => $emp->gender,
                'religion' => $emp->religion,
                'maritalStatus' => $emp->marital_status,
                'email' => $emp->email ?: ($contact->email ?? null),
                'mobile' => $contact->mobile_line ?? null,
                'landLine' => $contact->land_line ?? null,
                'permanentAddress' => $contact->permanent_address ?? null,
                'temporaryAddress' => $contact->temporary_address ?? null,
                'district' => $contact->district ?? null,
                'province' => $contact->province ?? null,
                'emergencyName' => $contact->emg_name ?? null,
                'emergencyPhone' => $contact->emg_tel ?? null,
                'emergencyRelationship' => $contact->emg_relationship ?? null,
                'designation' => $desig,
                'department' => $dept,
                'company' => $company,
                'employmentType' => $emp->employmentType->name ?? null,
                'dateOfJoining' => $emp->organizationAssignment->date_of_joining ?? null,
                'dayOff' => $emp->organizationAssignment->day_off ?? null,
                'supervisor' => $emp->organizationAssignment->current_supervisor ?? null,
                'photo' => $emp->profile_photo_path,
                'isActive' => (bool) $emp->is_active,
            ],
            'month' => $month,
            'year' => $year,
            'attendanceCount' => $attendanceCount,
            'otHours' => round($otHours, 2),
            'nopayDays' => round($nopayDays, 2),
            'lastSalary' => $lastSalary ? [
                'id' => $lastSalary->id,
                'salaryMonth' => (int) $lastSalary->month,
                'salaryYear' => (int) $lastSalary->year,
                'netPay' => $net,
                'status' => $lastSalary->status,
            ] : null,
            'pendingLeave' => $pendingLeave,
            'pendingAdvance' => $pendingAdvance,
            'advances' => $advances,
            'leaveWorkflow' => $leaveWorkflow,
            'medicalLeaveEnabled' => $medicalLeaveEnabled,
            'leaveBalances' => $leaveBalances,
            'coveringPending' => $coveringPending,
            'smsAvailable' => false,
            'weeklyOffEnabled' => CompanyProcessSettings::usesWeeklyOff($emp),
            'medicalClaimsEnabled' => CompanyProcessSettings::usesMedicalClaims($emp),
            'salaryAdvancePack' => CompanyProcessSettings::usesSalaryAdvancePack($emp),
            'weeklyOffBalance' => CompanyProcessSettings::usesWeeklyOff($emp) ? WeeklyOffService::balance($emp) : null,
            'medicalQuota' => CompanyProcessSettings::usesMedicalClaims($emp) ? MedicalClaimService::quota($emp) : null,
            'advanceQuota' => CompanyProcessSettings::usesSalaryAdvancePack($emp) ? SalaryAdvanceService::quota($emp) : null,
        ]);
    }

    public function attendance(Request $request)
    {
        $emp = $this->requireEmployee($request);
        $month = (int) ($request->query('month') ?: now()->month);
        $year = (int) ($request->query('year') ?: now()->year);
        $from = Carbon::create($year, $month, 1)->toDateString();
        $to = Carbon::create($year, $month, 1)->endOfMonth()->toDateString();

        $rows = time_card::where('employee_id', $emp->id)
            ->whereBetween('date', [$from, $to])
            ->orderByDesc('date')
            ->orderBy('time')
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'cardDate' => $r->date,
                'clockTime' => $r->time,
                'status' => $r->status,
                'workingHours' => $r->working_hours,
                'entry' => $r->entry,
            ]);

        return response()->json(['items' => $rows, 'month' => $month, 'year' => $year]);
    }

    public function overtime(Request $request)
    {
        $emp = $this->requireEmployee($request);
        $month = (int) ($request->query('month') ?: now()->month);
        $year = (int) ($request->query('year') ?: now()->year);
        $from = Carbon::create($year, $month, 1)->startOfDay();
        $to = (clone $from)->endOfMonth();

        $rows = over_time::with('timeCard')
            ->where('employee_id', $emp->id)
            ->whereBetween('created_at', [$from, $to])
            ->orderByDesc('id')
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'otDate' => optional($r->timeCard)->date ?: optional($r->created_at)->toDateString(),
                'hours' => (float) $r->ot_hours,
                'amount' => (float) $r->total_ot_amount,
                'otType' => 'OT',
                'status' => $r->status,
            ]);

        return response()->json(['items' => $rows, 'month' => $month, 'year' => $year]);
    }

    public function nopay(Request $request)
    {
        $emp = $this->requireEmployee($request);
        $month = (int) ($request->query('month') ?: now()->month);
        $year = (int) ($request->query('year') ?: now()->year);
        $from = Carbon::create($year, $month, 1)->toDateString();
        $to = Carbon::create($year, $month, 1)->endOfMonth()->toDateString();

        $rows = NoPayRecord::where('employee_id', $emp->id)
            ->whereBetween('date', [$from, $to])
            ->orderByDesc('date')
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'nopayDate' => $r->date,
                'nopayDays' => (float) $r->no_pay_count,
                'type' => $r->type,
                'status' => $r->status,
                'description' => $r->description,
            ]);

        return response()->json(['items' => $rows, 'month' => $month, 'year' => $year]);
    }

    public function salary(Request $request)
    {
        $emp = $this->requireEmployee($request);
        $rows = salary_process::where('employee_id', $emp->id)
            ->whereIn('status', ['processed', 'issued', 'pending'])
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->limit(24)
            ->get()
            ->map(function ($s) {
                $sb = is_string($s->salary_breakdown)
                    ? json_decode($s->salary_breakdown, true)
                    : ($s->salary_breakdown ?? []);
                return [
                    'id' => $s->id,
                    'salaryMonth' => (int) $s->month,
                    'salaryYear' => (int) $s->year,
                    'employeeNo' => $s->employee_no ?: $emp->attendance_employee_no,
                    'fullName' => $s->full_name ?: ($emp->full_name ?: $emp->name_with_initials),
                    'companyName' => $s->company_name ?: ($emp->organizationAssignment->company->name ?? null),
                    'departmentName' => $s->department_name ?: ($emp->organizationAssignment->department->name ?? null),
                    'basicSalary' => (float) ($s->basic_salary ?? $sb['basic_salary'] ?? 0),
                    'grossPay' => (float) ($sb['gross_salary'] ?? 0),
                    'netPay' => (float) ($sb['net_salary'] ?? $sb['net_pay'] ?? 0),
                    'totalDeductions' => (float) ($sb['total_deductions'] ?? 0),
                    'status' => $s->status,
                    'enableEpfEtf' => (bool) $s->enable_epf_etf,
                    'allowances' => is_array($s->allowances) ? $s->allowances : [],
                    'deductions' => is_array($s->deductions) ? $s->deductions : [],
                    'breakdown' => $sb,
                ];
            });

        return response()->json(['items' => $rows]);
    }

    public function leaves(Request $request)
    {
        $emp = $this->requireEmployee($request);
        $rows = leave_master::where('employee_id', $emp->id)
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return response()->json(['items' => $rows]);
    }

    public function storeLeave(Request $request)
    {
        $emp = $this->requireEmployee($request);
        $leaveType = (string) $request->leave_type;
        $isMedical = CompanyProcessSettings::isMedicalLeaveType($leaveType);
        if ($isMedical && !CompanyProcessSettings::usesMedicalLeave($emp)) {
            return response()->json(['message' => 'Medical leave is not enabled for your company.'], 422);
        }
        if ($isMedical) {
            $leaveType = 'Medical Leave';
        }

        $validator = Validator::make($request->all(), [
            'leave_type' => 'required|string|max:255',
            'leave_from' => 'required|date',
            'leave_to' => 'required|date|after_or_equal:leave_from',
            'reason' => 'required|string|max:1000',
            'day_type' => 'nullable|in:FULL,HALF,SHORT',
            'covering_employee_id' => 'nullable|exists:employees,id',
            'evidence' => 'nullable|file|mimes:jpg,jpeg,png,pdf,webp|max:8192',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $evidencePath = null;
        $evidenceName = null;
        if ($request->hasFile('evidence')) {
            $file = $request->file('evidence');
            $evidencePath = app(FirebaseStorageService::class)->storeFile($file, 'hr/medical-leave');
            $evidenceName = $file->getClientOriginalName();
        }

        $dayType = strtoupper((string) $request->input('day_type', 'FULL'));
        $inner = Request::create('/leave-masters', 'POST', [
            'employee_id' => $emp->id,
            'reporting_date' => now()->toDateString(),
            'leave_type' => $leaveType,
            'leave_from' => $request->leave_from,
            'leave_to' => $request->leave_to,
            'leave_date' => $request->leave_from === $request->leave_to ? $request->leave_from : null,
            'reason' => $request->reason,
            'status' => 'Pending',
            'is_half_day' => $dayType === 'HALF',
            'is_short_leave' => $dayType === 'SHORT',
            'covering_employee_id' => $request->input('covering_employee_id') ?: null,
            'evidence_path' => $evidencePath,
            'evidence_name' => $evidenceName,
        ]);
        $inner->setUserResolver(fn () => $request->user());
        $response = app(LeaveMasterController::class)->store($inner);
        if ($response->getStatusCode() >= 400) {
            return $response;
        }

        $payload = json_decode($response->getContent(), true);
        return response()->json([
            'message' => $isMedical
                ? 'Medical leave submitted. HR can approve only after medical evidence is attached.'
                : ($payload['message'] ?? 'Leave request submitted'),
            'data' => $payload,
        ], 201);
    }

    public function attachLeaveEvidence(Request $request, $id)
    {
        $emp = $this->requireEmployee($request);
        if (!CompanyProcessSettings::usesMedicalLeave($emp)) {
            return response()->json(['message' => 'Medical leave is not enabled for your company.'], 422);
        }
        $leave = leave_master::where('employee_id', $emp->id)->findOrFail($id);
        if (!CompanyProcessSettings::isMedicalLeaveType((string) $leave->leave_type)) {
            return response()->json(['message' => 'Evidence is only for medical leave.'], 422);
        }
        if (in_array($leave->status, ['Approved', 'HR_Approved', 'Rejected'], true)) {
            return response()->json(['message' => 'This leave can no longer accept evidence.'], 422);
        }
        $validator = Validator::make($request->all(), [
            'evidence' => 'required|file|mimes:jpg,jpeg,png,pdf,webp|max:8192',
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Upload a medical report or photo (JPG, PNG, PDF).'], 422);
        }
        $file = $request->file('evidence');
        $path = app(FirebaseStorageService::class)->storeFile($file, 'hr/medical-leave');
        $leave->update([
            'requires_evidence' => true,
            'evidence_path' => $path,
            'evidence_name' => $file->getClientOriginalName(),
        ]);

        return response()->json(['message' => 'Medical evidence attached', 'data' => $leave->fresh()]);
    }

    public function coveringColleagues(Request $request)
    {
        $emp = $this->requireEmployee($request);
        $emp->loadMissing('organizationAssignment');
        $companyId = $emp->organizationAssignment->company_id ?? null;
        $query = employee::query()->where('id', '!=', $emp->id);
        if ($request->filled('q')) {
            $q = trim((string) $request->query('q'));
            $query->where(function ($w) use ($q) {
                $w->where('attendance_employee_no', 'like', "%{$q}%")
                    ->orWhere('full_name', 'like', "%{$q}%")
                    ->orWhere('name_with_initials', 'like', "%{$q}%");
            });
        }
        $scoped = clone $query;
        if ($companyId) {
            $scoped->whereHas('organizationAssignment', function ($q) use ($companyId) {
                $q->where('company_id', $companyId);
            });
        }
        $items = $scoped->orderBy('full_name')->limit(400)->get([
            'id',
            'full_name',
            'name_with_initials',
            'attendance_employee_no',
        ]);
        if ($items->isEmpty() && $companyId) {
            $items = $query->orderBy('full_name')->limit(400)->get([
                'id',
                'full_name',
                'name_with_initials',
                'attendance_employee_no',
            ]);
        }

        return response()->json(['items' => $items]);
    }

    public function coveringLeaves(Request $request)
    {
        $emp = $this->requireEmployee($request);
        if (!Schema::hasColumn('leave_masters', 'covering_employee_id')) {
            return response()->json(['items' => []]);
        }
        $rows = leave_master::with('employee')
            ->where('covering_employee_id', $emp->id)
            ->where('status', 'Pending_Covering')
            ->orderByDesc('id')
            ->get();

        return response()->json(['items' => $rows]);
    }

    public function respondCovering(Request $request, $id)
    {
        $emp = $this->requireEmployee($request);
        $action = strtolower((string) $request->input('action'));
        if (!in_array($action, ['approve', 'reject'], true)) {
            return response()->json(['message' => 'Action must be approve or reject.'], 422);
        }
        if (!CompanyProcessSettings::usesLeaveWorkflow($emp)) {
            return response()->json(['message' => 'Covering workflow is not enabled for this company.'], 422);
        }

        $leave = leave_master::with('employee')->findOrFail($id);
        if ((int) $leave->covering_employee_id !== (int) $emp->id) {
            return response()->json(['message' => 'This covering request is not assigned to you.'], 403);
        }
        if ($leave->status !== 'Pending_Covering') {
            return response()->json(['message' => 'This covering request is no longer pending.'], 422);
        }

        $newStatus = $action === 'approve' ? 'Pending_Supervisor' : 'Rejected';
        $payload = ['status' => $newStatus];
        if (Schema::hasColumn('leave_masters', 'covering_status')) {
            $payload['covering_status'] = $action === 'approve' ? 'Approved' : 'Rejected';
        }
        if ($action === 'reject') {
            $payload['rejection_reason'] = $request->input('reason') ?: 'Rejected by covering person';
        }
        $leave->update($payload);
        LeaveNotificationService::statusChanged($leave->fresh(), $newStatus, $payload['rejection_reason'] ?? null);

        return response()->json(['message' => 'Covering response saved', 'data' => $leave->fresh()]);
    }

    public function myAdvances(Request $request)
    {
        $emp = $this->requireEmployee($request);
        $rows = SalaryAdvanceRequest::where('employee_id', $emp->id)
            ->orderByDesc('id')
            ->get();

        return response()->json(['items' => $rows]);
    }

    public function storeAdvance(Request $request)
    {
        $emp = $this->requireEmployee($request);
        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:1',
            'reason' => 'required|string|max:500',
            'needed_on' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $pending = SalaryAdvanceRequest::where('employee_id', $emp->id)
            ->where('status', 'PENDING')
            ->exists();

        if ($pending) {
            return response()->json(['message' => 'You already have a pending salary advance request.'], 422);
        }

        if (CompanyProcessSettings::usesSalaryAdvancePack($emp)) {
            $quota = SalaryAdvanceService::quota($emp);
            if ((float) $request->amount - $quota['available'] > 0.009) {
                return response()->json([
                    'message' => 'Advance exceeds available amount.',
                    'available' => $quota['available'],
                    'quota' => $quota,
                ], 422);
            }
        }

        $attrs = [
            'employee_id' => $emp->id,
            'amount' => $request->amount,
            'reason' => $request->reason,
            'needed_on' => $request->needed_on,
            'status' => 'PENDING',
            'created_by' => $request->user()->id,
        ];
        if (Schema::hasColumn('salary_advance_requests', 'source')) {
            $attrs['source'] = 'employee';
        }
        // Employee portal never chooses basic vs bonus.
        $row = SalaryAdvanceRequest::create($attrs);

        return response()->json(['message' => 'Advance request submitted', 'data' => $row], 201);
    }

    public function listAdvances(Request $request)
    {
        $user = $request->user();
        if ($user->role === 'employee') {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        SalaryAdvanceService::ensureDeductFromColumns();
        $q = SalaryAdvanceRequest::with('employee.organizationAssignment.company')
            ->orderByDesc('id');

        if ($status = $request->query('status')) {
            $q->where('status', strtoupper($status));
        }

        $items = $q->limit(200)->get()->map(function (SalaryAdvanceRequest $row) {
            $emp = $row->employee;
            $pack = $emp && CompanyProcessSettings::usesSalaryAdvancePack($emp);
            $row->setAttribute('hr_deduct_from', $pack
                ? CompanyProcessSettings::salaryAdvanceHrDeductFrom($emp)
                : null);
            $row->setAttribute('company_name', $emp?->organizationAssignment?->company?->name);
            return $row;
        });

        return response()->json(['items' => $items]);
    }

    public function hrStoreAdvance(Request $request)
    {
        $user = $request->user();
        if ($user->role === 'employee') {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validator = Validator::make($request->all(), [
            'employee_id' => 'required|integer|exists:employees,id',
            'amount' => 'required|numeric|min:1',
            'reason' => 'required|string|max:500',
            'needed_on' => 'nullable|date',
            'deduct_from' => 'nullable|in:basic,bonus',
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        if (!SalaryAdvanceService::ensureDeductFromColumns()) {
            return $this->deductFromUnavailable();
        }

        $emp = employee::with('organizationAssignment.company', 'compensation')->findOrFail((int) $request->employee_id);
        $pending = SalaryAdvanceRequest::where('employee_id', $emp->id)
            ->where('status', 'PENDING')
            ->exists();
        if ($pending) {
            return response()->json(['message' => 'This employee already has a pending salary advance request.'], 422);
        }

        if (CompanyProcessSettings::usesSalaryAdvancePack($emp)) {
            $quota = SalaryAdvanceService::quota($emp);
            if ((float) $request->amount - $quota['available'] > 0.009) {
                return response()->json([
                    'message' => 'Advance exceeds available amount.',
                    'available' => $quota['available'],
                    'quota' => $quota,
                ], 422);
            }
        }

        $attrs = [
            'employee_id' => $emp->id,
            'amount' => $request->amount,
            'reason' => $request->reason,
            'needed_on' => $request->needed_on,
            'status' => 'APPROVED',
            'created_by' => $user->id,
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
            'review_note' => 'Created by HR',
        ];
        if (Schema::hasColumn('salary_advance_requests', 'source')) {
            $attrs['source'] = 'hr';
        }
        $row = new SalaryAdvanceRequest($attrs);
        $row->employee()->associate($emp);
        SalaryAdvanceService::applyHrDeductFrom($row, $request->input('deduct_from'));
        $row->save();

        if (CompanyProcessSettings::usesSalaryAdvancePack($emp)) {
            PendingPaymentService::record($emp, 'salary_advance', $row->id, (float) $row->amount);
            LeaveNotificationService::notifyEmployee(
                $emp->id,
                'Salary advance recorded by HR',
                'HR recorded a salary advance. It was sent to Pending Payments.',
                ['type' => 'salary_advance', 'id' => $row->id, 'status' => $row->status]
            );
        }

        return response()->json(['message' => 'Salary advance created by HR', 'data' => $row], 201);
    }

    public function reviewAdvance(Request $request, $id)
    {
        $user = $request->user();
        if ($user->role === 'employee') {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validator = Validator::make($request->all(), [
            'action' => 'required|in:APPROVE,REJECT',
            'note' => 'nullable|string|max:500',
            'deduct_from' => 'nullable|in:basic,bonus',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        if ($request->action === 'APPROVE' && !SalaryAdvanceService::ensureDeductFromColumns()) {
            return $this->deductFromUnavailable();
        }

        $row = SalaryAdvanceRequest::findOrFail($id);
        $row->load('employee.organizationAssignment');
        if ($row->status !== 'PENDING') {
            return response()->json(['message' => 'This request has already been reviewed.'], 422);
        }

        $row->status = $request->action === 'APPROVE' ? 'APPROVED' : 'REJECTED';
        $row->review_note = $request->note;
        $row->reviewed_by = $user->id;
        $row->reviewed_at = now();
        if ($row->status === 'APPROVED') {
            SalaryAdvanceService::applyHrDeductFrom($row, $request->input('deduct_from'));
        }
        $row->save();

        if ($row->employee && CompanyProcessSettings::usesSalaryAdvancePack($row->employee)) {
            if ($row->status === 'APPROVED') {
                PendingPaymentService::record($row->employee, 'salary_advance', $row->id, (float) $row->amount);
            }
            LeaveNotificationService::notifyEmployee(
                $row->employee_id,
                $row->status === 'APPROVED' ? 'Salary advance approved' : 'Salary advance rejected',
                $row->status === 'APPROVED'
                    ? 'Your salary advance was approved and sent to Pending Payments.'
                    : 'Your salary advance request was rejected.',
                ['type' => 'salary_advance', 'id' => $row->id, 'status' => $row->status]
            );
        }

        return response()->json(['message' => 'Advance request updated', 'data' => $row]);
    }

    public function updateAdvanceDeductFrom(Request $request, $id)
    {
        $user = $request->user();
        if ($user->role === 'employee') {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validator = Validator::make($request->all(), [
            'deduct_from' => 'required|in:basic,bonus',
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }
        if (!SalaryAdvanceService::ensureDeductFromColumns()) {
            return $this->deductFromUnavailable();
        }

        $row = SalaryAdvanceRequest::findOrFail($id);
        if ($row->status !== 'APPROVED') {
            return response()->json(['message' => 'Only approved advances can be changed here.'], 422);
        }
        SalaryAdvanceService::applyHrDeductFrom($row, $request->input('deduct_from'));
        $row->save();

        return response()->json([
            'message' => 'Payroll deduct source updated. Re-process salary if this month was already processed.',
            'data' => $row,
        ]);
    }

    private function deductFromUnavailable()
    {
        return response()->json([
            'message' => 'Cannot save basic/bonus choice: the server database is not updated. Run "php artisan migrate" on the server.',
        ], 500);
    }

    public function changePassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'current_password' => 'required',
            'new_password' => 'required|string|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $user = $request->user();
        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json(['message' => 'Current password is incorrect.'], 401);
        }

        $user->password = Hash::make($request->new_password);
        if (isset($user->is_first_login)) {
            $user->is_first_login = false;
        }
        $user->save();

        return response()->json(['message' => 'Password changed successfully.']);
    }

    public function punchStatus(Request $request)
    {
        $emp = $this->requireEmployee($request);
        $cfg = CompanyProcessSettings::mobilePunchForEmployee($emp);
        $today = now('Asia/Colombo')->toDateString();
        $punches = time_card::where('employee_id', $emp->id)
            ->where('date', $today)
            ->orderBy('time')
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'clockTime' => $r->time,
                'status' => $r->status,
                'fingerprintClock' => $r->fingerprint_clock,
            ]);
        $last = $punches->last();
        $lastStatus = is_array($last) ? ($last['status'] ?? null) : ($last->status ?? null);
        $nextStatus = in_array($lastStatus, ['IN', 'Late Coming'], true) ? 'OUT' : 'IN';

        return response()->json([
            'enabled' => (bool) $cfg['enabled'],
            'officeName' => $cfg['officeName'],
            'latitude' => $cfg['latitude'],
            'longitude' => $cfg['longitude'],
            'radiusMeters' => $cfg['radiusMeters'],
            'requireBiometric' => $cfg['requireBiometric'],
            'gpsRequired' => true,
            'disabledReason' => $cfg['disabledReason'],
            'scope' => $cfg['scope'],
            'nextStatus' => $nextStatus,
            'today' => $punches,
            'employee' => [
                'id' => $emp->id,
                'fullName' => $emp->full_name ?: $emp->name_with_initials,
                'employeeNo' => $emp->attendance_employee_no,
            ],
        ]);
    }

    public function punch(Request $request)
    {
        $emp = $this->requireEmployee($request);
        $cfg = CompanyProcessSettings::mobilePunchForEmployee($emp);
        if (!$cfg['enabled']) {
            return response()->json([
                'message' => $cfg['disabledReason'] ?: 'Mobile fingerprint is disabled for your company or department.',
            ], 403);
        }

        if ($cfg['requireBiometric'] && !$request->boolean('biometric_ok')) {
            return response()->json(['message' => 'Confirm with fingerprint / Face ID, or hold the punch button.'], 400);
        }

        $lat = $request->input('latitude');
        $lng = $request->input('longitude');
        $distance = 0;
        if (!is_numeric($lat) || !is_numeric($lng)) {
            return response()->json(['message' => 'Turn on location and punch at the selected premises.'], 400);
        }
        $distance = (int) round($this->haversineMeters((float) $lat, (float) $lng, $cfg['latitude'], $cfg['longitude']));
        $accuracy = is_numeric($request->input('accuracy')) ? (float) $request->input('accuracy') : 80;
        $slack = min(max($accuracy, 0), 120);
        if ($distance > ($cfg['radiusMeters'] + $slack)) {
            return response()->json([
                'message' => "You are {$distance}m from {$cfg['officeName']}. Fingerprint punch is only allowed at that premises.",
            ], 403);
        }

        $now = now('Asia/Colombo');
        $recent = time_card::where('employee_id', $emp->id)
            ->where('date', $now->toDateString())
            ->where('created_at', '>=', $now->copy()->subSeconds(90))
            ->orderByDesc('id')
            ->first();
        if ($recent) {
            return response()->json(['message' => 'Punch already recorded. Wait a minute before the next punch.'], 409);
        }

        $last = time_card::where('employee_id', $emp->id)
            ->where('date', $now->toDateString())
            ->orderByDesc('time')
            ->orderByDesc('id')
            ->first();
        $isOut = $last && in_array($last->status, ['IN', 'Late Coming'], true);
        $status = $isOut ? 'OUT' : 'IN';
        $entry = $isOut ? 2 : 1;
        $clock = $now->format('H:i:s');

        $payload = [
            'employee_id' => $emp->id,
            'date' => $now->toDateString(),
            'time' => $clock,
            'status' => $status,
            'entry' => $entry,
            'reason' => 'Phone fingerprint punch at '.$cfg['officeName']." ({$distance}m)",
            'created_by' => $request->user()->id,
        ];
        if (Schema::hasColumn('time_cards', 'fingerprint_clock')) {
            $payload['fingerprint_clock'] = now('Asia/Colombo');
        }
        if (Schema::hasColumn('time_cards', 'entry_source')) {
            $payload['entry_source'] = 'MOBILE';
        }
        $card = time_card::create($payload);

        return response()->json([
            'message' => "{$status} recorded at {$clock}",
            'status' => $status,
            'clockTime' => $clock,
            'distance' => $distance,
            'officeName' => $cfg['officeName'],
            'nextStatus' => $status === 'IN' || $status === 'Late Coming' ? 'OUT' : 'IN',
            'card' => $card,
        ], 201);
    }

    private function haversineMeters(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $R = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return 2 * $R * asin(min(1, sqrt($a)));
    }
}
