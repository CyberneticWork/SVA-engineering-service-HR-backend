<?php

namespace App\Http\Controllers;

use App\Models\allowances;
use App\Models\deduction;
use App\Models\employee;
use App\Models\employee_allowances;
use App\Models\employee_deductions;
use App\Services\SalaryAdvanceService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class AssignSalaryComponentController extends Controller
{
    public function listAllowances(Request $request)
    {
        $query = employee_allowances::with([
            'employee:id,attendance_employee_no,name_with_initials,full_name,display_name',
            'allowance:id,allowance_code,allowance_name,allowance_type,amount,company_id',
        ])->where('is_active', 1);

        if ($request->filled('company_id')) {
            $companyId = (int) $request->company_id;
            $query->whereHas('allowance', fn ($q) => $q->where('company_id', $companyId));
        }
        if ($request->filled('employee_id')) {
            $query->where('employee_id', (int) $request->employee_id);
        }
        if ($request->filled('month')) {
            $query->where('month', (int) $request->month);
        }
        if ($request->filled('year')) {
            $query->where('year', (int) $request->year);
        }

        $rows = $query->orderByDesc('year')->orderByDesc('month')->orderBy('id')->get()
            ->map(fn ($row) => $this->formatAllowanceAssignment($row));

        return response()->json(['data' => $rows]);
    }

    public function listDeductions(Request $request)
    {
        SalaryAdvanceService::ensureDeductFromColumns();
        $deductionCols = 'id,deduction_code,deduction_name,deduction_type,amount,company_id';
        if (Schema::hasColumn('deductions', 'deduct_from')) {
            $deductionCols .= ',deduct_from';
        }

        $query = employee_deductions::with([
            'employee:id,attendance_employee_no,name_with_initials,full_name,display_name',
            'deduction:' . $deductionCols,
        ])->where('is_active', 1);

        if ($request->filled('company_id')) {
            $companyId = (int) $request->company_id;
            $query->whereHas('deduction', fn ($q) => $q->where('company_id', $companyId));
        }
        if ($request->filled('employee_id')) {
            $query->where('employee_id', (int) $request->employee_id);
        }
        if ($request->filled('month')) {
            $query->where('month', (int) $request->month);
        }
        if ($request->filled('year')) {
            $query->where('year', (int) $request->year);
        }

        $rows = $query->orderByDesc('year')->orderByDesc('month')->orderBy('id')->get()
            ->map(fn ($row) => $this->formatDeductionAssignment($row));

        return response()->json(['data' => $rows]);
    }

    public function assignAllowance(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'apply_to' => 'required|in:all,employee',
            'employee_id' => 'required_if:apply_to,employee|nullable|exists:employees,id',
            'company_id' => 'nullable|exists:companies,id',
            'allowance_id' => 'required|exists:allowances,id',
            'value_type' => 'required|in:fixed,variable',
            'from_month' => 'required_if:value_type,fixed|nullable|integer|min:1|max:12',
            'from_year' => 'required_if:value_type,fixed|nullable|integer|min:2000|max:2100',
            'to_month' => 'required_if:value_type,fixed|nullable|integer|min:1|max:12',
            'to_year' => 'required_if:value_type,fixed|nullable|integer|min:2000|max:2100',
            'month' => 'required_if:value_type,variable|nullable|integer|min:1|max:12',
            'year' => 'required_if:value_type,variable|nullable|integer|min:2000|max:2100',
            'amount' => 'required_if:value_type,variable|nullable|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $allowance = allowances::findOrFail($request->allowance_id);
        $companyId = $request->company_id ?: $allowance->company_id;

        $months = $this->resolveMonths($request);
        if (isset($months['error'])) {
            return response()->json(['message' => $months['error']], 422);
        }

        $amount = $request->value_type === 'variable'
            ? (float) $request->amount
            : (float) ($allowance->amount ?? 0);

        $employeeIds = $this->resolveEmployeeIds($request->apply_to, $request->employee_id, $companyId, $allowance->department_id);
        if (empty($employeeIds)) {
            return response()->json(['message' => 'No employees found for this assignment.'], 422);
        }

        $created = 0;
        DB::beginTransaction();
        try {
            foreach ($employeeIds as $employeeId) {
                $attendanceNo = employee::where('id', $employeeId)->value('attendance_employee_no');
                foreach ($months as [$month, $year]) {
                    employee_allowances::updateOrCreate(
                        [
                            'employee_id' => $employeeId,
                            'allowance_id' => $allowance->id,
                            'month' => $month,
                            'year' => $year,
                        ],
                        [
                            'attendance_employee_no' => $attendanceNo,
                            'custom_amount' => $amount,
                            'is_active' => 1,
                        ]
                    );
                    $created++;
                }
            }
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to assign allowance: ' . $e->getMessage()], 500);
        }

        return response()->json([
            'message' => 'Allowance assigned successfully.',
            'affected_employees' => count($employeeIds),
            'months' => count($months),
            'rows_written' => $created,
            'amount' => $amount,
            'value_type' => $request->value_type,
        ]);
    }

    public function assignDeduction(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'apply_to' => 'required|in:all,employee',
            'employee_id' => 'required_if:apply_to,employee|nullable|exists:employees,id',
            'company_id' => 'nullable|exists:companies,id',
            'deduction_id' => 'required|exists:deductions,id',
            'value_type' => 'required|in:fixed,variable',
            'from_month' => 'required_if:value_type,fixed|nullable|integer|min:1|max:12',
            'from_year' => 'required_if:value_type,fixed|nullable|integer|min:2000|max:2100',
            'to_month' => 'required_if:value_type,fixed|nullable|integer|min:1|max:12',
            'to_year' => 'required_if:value_type,fixed|nullable|integer|min:2000|max:2100',
            'month' => 'required_if:value_type,variable|nullable|integer|min:1|max:12',
            'year' => 'required_if:value_type,variable|nullable|integer|min:2000|max:2100',
            'amount' => 'required_if:value_type,variable|nullable|numeric|min:0',
            'deduct_from' => 'nullable|in:basic,bonus',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        SalaryAdvanceService::ensureDeductFromColumns();
        $deduction = deduction::findOrFail($request->deduction_id);
        $companyId = $request->company_id ?: $deduction->company_id;
        $isAdvance = SalaryAdvanceService::isAdvanceDeduction($deduction);
        $deductFrom = $isAdvance
            ? SalaryAdvanceService::normalizeDeductFrom(
                $request->input('deduct_from') ?: ($deduction->deduct_from ?? null)
            )
            : null;

        $months = $this->resolveMonths($request);
        if (isset($months['error'])) {
            return response()->json(['message' => $months['error']], 422);
        }

        $amount = $request->value_type === 'variable'
            ? (float) $request->amount
            : (float) ($deduction->amount ?? 0);

        $employeeIds = $this->resolveEmployeeIds($request->apply_to, $request->employee_id, $companyId, $deduction->department_id);
        if (empty($employeeIds)) {
            return response()->json(['message' => 'No employees found for this assignment.'], 422);
        }

        $created = 0;
        DB::beginTransaction();
        try {
            foreach ($employeeIds as $employeeId) {
                $attendanceNo = employee::where('id', $employeeId)->value('attendance_employee_no');
                foreach ($months as [$month, $year]) {
                    $payload = [
                            'attendance_employee_no' => $attendanceNo,
                            'custom_amount' => $amount,
                            'is_active' => 1,
                    ];
                    if ($isAdvance && Schema::hasColumn('employee_deductions', 'deduct_from')) {
                        $payload['deduct_from'] = $deductFrom;
                    }
                    employee_deductions::updateOrCreate(
                        [
                            'employee_id' => $employeeId,
                            'deduction_id' => $deduction->id,
                            'month' => $month,
                            'year' => $year,
                        ],
                        $payload
                    );
                    $created++;
                }
            }
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to assign deduction: ' . $e->getMessage()], 500);
        }

        return response()->json([
            'message' => 'Deduction assigned successfully.',
            'affected_employees' => count($employeeIds),
            'months' => count($months),
            'rows_written' => $created,
            'amount' => $amount,
            'value_type' => $request->value_type,
            'deduct_from' => $deductFrom,
        ]);
    }

    public function destroyAllowance(int $id)
    {
        $row = employee_allowances::findOrFail($id);
        $row->delete();
        return response()->json(['message' => 'Allowance assignment removed.']);
    }

    public function destroyDeduction(int $id)
    {
        $row = employee_deductions::findOrFail($id);
        $row->delete();
        return response()->json(['message' => 'Deduction assignment removed.']);
    }

    private function resolveMonths(Request $request): array
    {
        if ($request->value_type === 'variable') {
            return [[(int) $request->month, (int) $request->year]];
        }

        $from = Carbon::create((int) $request->from_year, (int) $request->from_month, 1)->startOfMonth();
        $to = Carbon::create((int) $request->to_year, (int) $request->to_month, 1)->startOfMonth();

        if ($to->lt($from)) {
            return ['error' => 'Period end must be on or after period start.'];
        }

        if ($from->diffInMonths($to) > 36) {
            return ['error' => 'Fixed period cannot exceed 36 months.'];
        }

        $months = [];
        $cursor = $from->copy();
        while ($cursor->lte($to)) {
            $months[] = [(int) $cursor->month, (int) $cursor->year];
            $cursor->addMonth();
        }

        return $months;
    }

    private function resolveEmployeeIds(string $applyTo, $employeeId, $companyId, $departmentId = null): array
    {
        if ($applyTo === 'employee') {
            return [(int) $employeeId];
        }

        $query = employee::query()
            ->where(function ($q) {
                $q->where('is_active', 1)->orWhereNull('is_active');
            })
            ->whereHas('organizationAssignment', function ($q) use ($companyId, $departmentId) {
                if ($companyId) {
                    $q->where('company_id', $companyId);
                }
                if ($departmentId) {
                    $q->where('department_id', $departmentId);
                }
            });

        return $query->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private function formatAllowanceAssignment(employee_allowances $row): array
    {
        $emp = $row->employee;
        return [
            'id' => $row->id,
            'employee_id' => $row->employee_id,
            'employee_name' => $emp->display_name ?? $emp->name_with_initials ?? $emp->full_name ?? '',
            'attendance_no' => $emp->attendance_employee_no ?? $row->attendance_employee_no,
            'allowance_id' => $row->allowance_id,
            'allowance_code' => $row->allowance->allowance_code ?? '',
            'allowance_name' => $row->allowance->allowance_name ?? '',
            'allowance_type' => $row->allowance->allowance_type ?? '',
            'month' => $row->month,
            'year' => $row->year,
            'amount' => (float) ($row->custom_amount ?? $row->allowance->amount ?? 0),
        ];
    }

    private function formatDeductionAssignment(employee_deductions $row): array
    {
        $emp = $row->employee;
        $assignedFrom = strtolower(trim((string) ($row->deduct_from ?? '')));
        $masterFrom = strtolower(trim((string) ($row->deduction?->deduct_from ?? '')));
        $deductFrom = in_array($assignedFrom, ['basic', 'bonus'], true)
            ? $assignedFrom
            : (in_array($masterFrom, ['basic', 'bonus'], true) ? $masterFrom : null);
        $isAdvance = SalaryAdvanceService::isAdvanceDeduction($row->deduction)
            || SalaryAdvanceService::isNamedAdvanceDeduction($row->deduction?->deduction_name ?? null)
            || $deductFrom !== null;

        return [
            'id' => $row->id,
            'employee_id' => $row->employee_id,
            'employee_name' => $emp->display_name ?? $emp->name_with_initials ?? $emp->full_name ?? '',
            'attendance_no' => $emp->attendance_employee_no ?? $row->attendance_employee_no,
            'deduction_id' => $row->deduction_id,
            'deduction_code' => $row->deduction->deduction_code ?? '',
            'deduction_name' => $row->deduction->deduction_name ?? '',
            'deduction_type' => $row->deduction->deduction_type ?? '',
            'month' => $row->month,
            'year' => $row->year,
            'amount' => (float) ($row->custom_amount ?? $row->deduction->amount ?? 0),
            'deduct_from' => $isAdvance ? ($deductFrom ?: 'bonus') : null,
        ];
    }
}
