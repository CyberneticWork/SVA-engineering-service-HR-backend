<?php

namespace App\Http\Controllers;

use App\Models\employee;
use App\Models\deduction;
use App\Models\over_time;
use App\Models\allowances;
use Illuminate\Http\Request;
use App\Models\salary_process;
use Illuminate\Support\Facades\DB;
use App\Models\employee_allowances;
use App\Models\employee_deductions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Maatwebsite\Excel\Facades\Excel;
use App\Imports\EmployeeAllowancesImport;
use App\Imports\EmployeeDeductionsImport;
use App\Models\loans;
use App\Models\leave_master;
use App\Models\time_card;
use App\Models\Roster;
use Carbon\Carbon;
use App\Models\EmployeeBonus;
use App\Models\SalaryProcessAudit;
use App\Models\MonthlyLateDeductionItem;
use App\Services\CompanyLocationService;
use App\Services\CompanyProcessSettings;
use App\Services\ContractEmployeeScope;
use App\Services\ExcessLateService;
use App\Services\LateGraceNoPayService;
use App\Services\SalaryAdvanceService;

class SalaryProcessController extends Controller
{
    /**
     * Late coming NoPay (after leave balances):
     * amount = (basic + monthly_bonus) / company_nopay_working_days × nopay_days
     * Entire amount is deducted from the MONTHLY BONUS side (not basic).
     */
    private function resolveLateComingBonusNoPay(
        int $employeeId,
        int $year,
        int $month,
        float $sqlMonthlyLateNoPays,
        float $sqlMajorLateNoPays,
        float $monthlyBonus,
        float $workingDays = 30.0,
        float $basicSalary = 0.0
    ): array {
        $appliedItem = null;
        try {
            $appliedItem = MonthlyLateDeductionItem::where('employee_id', $employeeId)
                ->where('year', $year)
                ->where('month', $month)
                ->where('status', 'applied')
                ->first();
        } catch (\Throwable $e) {
            $appliedItem = null;
        }

        $days = 0.0;
        $source = 'none';

        if ($appliedItem) {
            // Day-by-day late apply — only remaining NoPay days after Casual/Annual
            $days = (float) ($appliedItem->nopay_days ?? 0);
            $source = 'monthly_late_applied';
        } elseif ($sqlMonthlyLateNoPays > 0) {
            $days = $sqlMonthlyLateNoPays;
            $source = 'late_monthly_record';
        } elseif ($sqlMajorLateNoPays > 0) {
            $days = $sqlMajorLateNoPays;
            $source = 'late_in_record';
        }

        $divisor = max(1.0, (float) $workingDays);
        $rateBase = max(0, (float) $basicSalary) + max(0, (float) $monthlyBonus);
        $perDayFromTotal = $rateBase / $divisor;
        $amount = round(max(0, $days) * $perDayFromTotal, 2);

        return [
            'days' => round(max(0, $days), 4),
            'amount' => $amount,
            'source' => $source,
            'per_day_from_bonus' => round($perDayFromTotal, 4),
            'per_day_from_basic' => round($perDayFromTotal, 4),
            'per_day_from_total' => round($perDayFromTotal, 4),
            'rate_base' => 'basic_plus_bonus',
            'working_days' => $divisor,
            'deduct_from' => 'bonus',
        ];
    }

    /**
     * Leave-form shortfall NoPay (not late deduction):
     * total = (basic + monthly_bonus) / workingDays × days
     * basic portion = basic / workingDays × days  → deduct from basic
     * bonus portion = monthly_bonus / workingDays × days → deduct from monthly bonus
     * workingDays comes from company.nopay_working_days (ACL-managed; default 30).
     */
    private function resolveLeaveShortfallNoPay(
        float $basicSalary,
        float $monthlyBonus,
        float $days,
        float $workingDays = 30.0
    ): array {
        $days = max(0, (float) $days);
        $divisor = max(1.0, (float) $workingDays);
        $basicPerDay = max(0, $basicSalary) / $divisor;
        $bonusPerDay = max(0, $monthlyBonus) / $divisor;
        $basicAmount = round($basicPerDay * $days, 2);
        $bonusAmount = round($bonusPerDay * $days, 2);

        return [
            'days' => round($days, 4),
            'basic_amount' => $basicAmount,
            'bonus_amount' => $bonusAmount,
            'total_amount' => round($basicAmount + $bonusAmount, 2),
            'basic_per_day' => round($basicPerDay, 4),
            'bonus_per_day' => round($bonusPerDay, 4),
            'combined_per_day' => round($basicPerDay + $bonusPerDay, 4),
            'divisor' => $divisor,
        ];
    }

    private function getLateMinutes(?string $inTime, ?string $shiftStartTime): int
    {
        if (!$inTime || !$shiftStartTime) {
            return 0;
        }

        $in = strtotime($inTime);
        $shiftStart = strtotime($shiftStartTime);

        if ($in === false || $shiftStart === false) {
            return 0;
        }

        if ($in <= $shiftStart) {
            return 0;
        }

        return (int) floor(($in - $shiftStart) / 60);
    }

    private function getApprovedLeaveInfoForDate(int $employeeId, string $date): array
    {
        $leave = leave_master::where('employee_id', $employeeId)
            ->whereIn('status', ['Approved', 'HR_Approved'])
            ->where(function ($query) use ($date) {
                $query->whereDate('leave_date', $date)
                    ->orWhere(function ($q) use ($date) {
                        $q->whereDate('leave_from', '<=', $date)
                            ->whereDate('leave_to', '>=', $date);
                    });
            })
            ->orderByRaw("
                CASE
                    WHEN status = 'Approved' THEN 1
                    WHEN status = 'HR_Approved' THEN 2
                    ELSE 3
                END
            ")
            ->first();

        if (!$leave) {
            return [
                'has_approved_leave' => false,
                'is_half_day_leave' => false,
                'leave_type' => null,
                'leave_status' => null,
                'leave_period' => null,
            ];
        }

        return [
            'has_approved_leave' => true,
            'is_half_day_leave' => (bool) ($leave->is_half_day ?? false),
            'leave_type' => $leave->leave_type,
            'leave_status' => $leave->status,
            'leave_period' => $leave->period,
        ];
    }

    private function getRosterShiftStartTime(int $employeeId, string $date): ?string
    {
        $roster = roster::with('shift')
            ->where('employee_id', $employeeId)
            ->where(function ($q) use ($date) {
                $q->whereDate('date_from', '<=', $date)
                    ->whereDate('date_to', '>=', $date);
            })
            ->orderBy('date_from', 'desc')
            ->first();

        if (!$roster || !$roster->shift || !$roster->shift->start_time) {
            return null;
        }

        return $roster->shift->start_time;
    }

    private function getRosterShiftWorkHours(int $employeeId, string $date, float $defaultHours = 8): float
    {
        $roster = roster::with('shift')
            ->where('employee_id', $employeeId)
            ->where(function ($q) use ($date) {
                $q->whereDate('date_from', '<=', $date)
                    ->whereDate('date_to', '>=', $date);
            })
            ->orderBy('date_from', 'desc')
            ->first();

        if (!$roster || !$roster->shift || !$roster->shift->start_time || !$roster->shift->end_time) {
            return $defaultHours;
        }

        $start = strtotime($roster->shift->start_time);
        $end = strtotime($roster->shift->end_time);

        if ($end <= $start) {
            $end = strtotime('+1 day', $end);
        }

        $hours = ($end - $start) / 3600;
        return $hours > 0 ? round($hours, 2) : $defaultHours;
    }

    /**
     * Old ≤30-min occurrence policy replaced by MonthlyLateDeductionService
     * (monthly total → short leave → annual/casual → nopay). Monetary short/half
     * pay-cuts are disabled to avoid double-charging with auto-applied leaves.
     */
    private function calculateLateDeductionData(int $employeeId, string $startDate, string $endDate, float $perDaySalary): array
    {
        return [
            'approved_leave_late_count' => 0,
            'no_deduction_late_count' => 0,
            'short_leave_count' => 0,
            'half_day_count' => 0,
            'deductible_late_count' => 0,
            'short_leave_deduction' => 0,
            'half_day_deduction' => 0,
            'policy' => 'monthly_late_total',
        ];
    }

    public function getProcessedSalaries(Request $request)
    {
        $month = $request->query('month');
        $year = $request->query('year');

        $processedSalaries = salary_process::whereIn('status', ['processed', 'issued'])
            ->when($month, function ($query) use ($month) {
                $query->where('month', $month);
            })
            ->when($year, function ($query) use ($year) {
                $query->where('year', $year);
            })
            ->with([
                'employee' => function ($query) {
                    $query->select('id', 'full_name', 'attendance_employee_no')
                        ->with([
                            'compensation' => function ($q) {
                                $q->select('employee_id', 'basic_salary', 'enable_epf_etf', 'bank_name', 'bank_account_no', 'branch_name');
                            }
                        ]);
                }
            ])
            ->orderBy('created_at', 'desc')
            ->get();

        $response = $processedSalaries->map(function ($salary) {
            return [
                'id' => $salary->id,
                'employee_id' => $salary->employee_id,
                'employee_no' => $salary->employee_no,
                'full_name' => $salary->full_name,
                'company_name' => $salary->company_name,
                'department_name' => $salary->department_name,
                'stamp' => $salary->stamp,
                'basic_salary' => $salary->basic_salary,
                'ot_morning' => $salary->ot_morning,
                'ot_evening' => $salary->ot_evening,
                'month' => $salary->month,
                'year' => $salary->year,
                'status' => $salary->status,
                'compensation' => $salary->employee->compensation ?? null,
                'bank_details' => $salary->employee->bankDetails ?? null,
                'salary_breakdown' => is_string($salary->salary_breakdown) ? json_decode($salary->salary_breakdown, true) : $salary->salary_breakdown,
                'allowances' => is_string($salary->allowances) ? json_decode($salary->allowances, true) : $salary->allowances,
                'deductions' => is_string($salary->deductions) ? json_decode($salary->deductions, true) : $salary->deductions,
                'bonuses' => is_string($salary->bonuses) ? json_decode($salary->bonuses, true) : $salary->bonuses,
            ];
        });

        return response()->json($response);
    }

    public function updateEmployeesAllowances(Request $request)
    {
        $employeeIDs = $request->selectedEmployees;
        $type = $request->bulkActionType;
        $amount = $request->bulkActionAmount;
        $typeId = $request->bulkActionId;
        $month = $request->month;
        $year = $request->year;

        if (!is_array($employeeIDs) || empty($employeeIDs)) {
            return response()->json(['error' => 'No employees selected'], 400);
        }

        if (!$month || !$year) {
            return response()->json(['error' => 'Month and Year are required for bulk actions'], 400);
        }

        if (!$type || !$typeId) {
            return response()->json(['error' => 'Action type and item are required'], 400);
        }

        try {
            DB::beginTransaction();

            foreach ($employeeIDs as $employeeId) {
                if ($type === 'allowance') {
                    employee_allowances::updateOrCreate(
                        [
                            'employee_id' => $employeeId,
                            'allowance_id' => $typeId,
                            'month' => $month,
                            'year' => $year,
                        ],
                        [
                            'custom_amount' => $amount,
                            'is_active' => 1,
                        ]
                    );
                } elseif ($type === 'deduction') {
                    employee_deductions::updateOrCreate(
                        [
                            'employee_id' => $employeeId,
                            'deduction_id' => $typeId,
                            'month' => $month,
                            'year' => $year,
                        ],
                        [
                            'custom_amount' => $amount,
                            'is_active' => 1,
                        ]
                    );
                } else {
                    EmployeeBonus::updateOrCreate(
                        [
                            'employee_id' => $employeeId,
                            'bonus_id' => $typeId,
                            'month' => $month,
                            'year' => $year,
                        ],
                        [
                            'custom_amount' => $amount,
                            'is_active' => 1,
                        ]
                    );
                }
            }

            DB::commit();

            return response()->json([
                'message' => 'Bulk update successful for the selected month.',
                'type' => $type,
                'affected_employees' => count($employeeIDs),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Error performing bulk update: ' . $e->getMessage(),
            ], 500);
        }
    }

    // public function updateEmployeesAllowances(Request $request)
    // {
    //     $employeeIDs = $request->selectedEmployees;
    //     $type = $request->bulkActionType;
    //     $amount = $request->bulkActionAmount;
    //     $typeId = $request->bulkActionId;
    //     $month = $request->month;
    //     $year = $request->year;

    //     if (!is_array($employeeIDs) || empty($employeeIDs)) {
    //         return response()->json(['error' => 'No employees selected'], 400);
    //     }

    //     if (!$month || !$year) {
    //         return response()->json(['error' => 'Month and Year are required for bulk actions'], 400);
    //     }

    //     $rows = [];

    //     foreach ($employeeIDs as $employeeId) {
    //         if ($type === 'allowance') {
    //             $rows[] = [
    //                 'employee_id' => $employeeId,
    //                 'allowance_id' => $typeId,
    //                 'custom_amount' => $amount,
    //                 'is_active' => 1,
    //                 'month' => $month,
    //                 'year' => $year,
    //                 'created_at' => now(),
    //                 'updated_at' => now(),
    //             ];
    //         } elseif ($type === 'deduction') {
    //             $rows[] = [
    //                 'employee_id' => $employeeId,
    //                 'deduction_id' => $typeId,
    //                 'custom_amount' => $amount,
    //                 'is_active' => 1,
    //                 'month' => $month,
    //                 'year' => $year,
    //                 'created_at' => now(),
    //                 'updated_at' => now(),
    //             ];
    //         } else {
    //             $rows[] = [
    //                 'employee_id' => $employeeId,
    //                 'bonus_id' => $typeId,
    //                 'custom_amount' => $amount,
    //                 'is_active' => 1,
    //                 'month' => $month,
    //                 'year' => $year,
    //                 'created_at' => now(),
    //                 'updated_at' => now(),
    //             ];
    //         }
    //     }

    //     // Delete existing for this month and insert to avoid duplicates
    //     if ($type === 'allowance') {
    //         employee_allowances::whereIn('employee_id', $employeeIDs)->where('allowance_id', $typeId)->where('month', $month)->where('year', $year)->delete();
    //         employee_allowances::insert($rows);
    //     } elseif ($type === 'deduction') {
    //         employee_deductions::whereIn('employee_id', $employeeIDs)->where('deduction_id', $typeId)->where('month', $month)->where('year', $year)->delete();
    //         employee_deductions::insert($rows);
    //     } else {
    //         EmployeeBonus::whereIn('employee_id', $employeeIDs)->where('bonus_id', $typeId)->where('month', $month)->where('year', $year)->delete();
    //         EmployeeBonus::insert($rows);
    //     }

    //     return response()->json([
    //         'message' => 'Bulk update successful for the selected month.',
    //         'type' => $type,
    //         'affected_employees' => count($employeeIDs)
    //     ]);
    // }

    /*


public function getEmployeesByMonthAndCompany(Request $request)
    {
        $month = $request->query('month');
        $year = $request->query('year');
        $company_id = $request->query('company_id');
        $department_id = $request->query('department_id');
        $search = $request->query('search');

        $kpiTypeRaw = strtolower((string)$request->query('kpi_type', ''));
        $kpiType = in_array($kpiTypeRaw, ['monthly', '6month', 'six_month']) ? $kpiTypeRaw : '';

        $startDate = "{$year}-{$month}-01";
        $lastDay = date('t', strtotime($startDate));
        $endDate = "{$year}-{$month}-{$lastDay}";
        $selectedMonthYear = date('Y-m', strtotime($startDate));

        $totalDaysInMonth = (int)$lastDay;

        $query = "
            SELECT
                e.id,
                e.attendance_employee_no AS emp_no,
                e.full_name,
                e.nic,
                c.name AS company_name,
                d.name AS department_name,
                sd.name AS sub_department_name,
                comp.basic_salary,
                oa.probationary_period,
                oa.date_of_joining,
                e.epf,
                cd.permanent_address AS address,
                cd.mobile_line,
                cd.emg_name,
                cd.emg_relationship,
                cd.emg_tel,
                comp.increment_active,
                CASE WHEN comp.increment_active = 1 THEN comp.increment_value ELSE NULL END AS increment_value,
                CASE WHEN comp.increment_active = 1 THEN comp.increment_effected_date ELSE NULL END AS increment_effected_date,
                comp.ot_morning,
                comp.ot_evening,
                comp.enable_epf_etf,
                comp.br1,
                comp.br2,
                comp.stamp,
                comp.bank_name,
                comp.branch_name,
                comp.bank_account_no,
                COALESCE(SUM(lo.loan_amount), 0) AS total_loan_amount,
                MAX(lo.installment_count) AS installment_count,
                MAX(lo.installment_amount) AS installment_amount,
                MAX(lo.status) AS loan_status,
                MAX(lo.schedule) AS loan_schedule,
                MAX(lo.deduct_from) AS loan_deduct_from,
                MAX(lo.with_interest) AS with_interest,
                MAX(lo.interest_rate_per_annum) AS interest_rate_per_annum,

                -- No Pay Types Split (Approved records only)
                -- සෙනසුරාදා දවස් වෙන් කර ගැනීම (DAYNAME = 'Saturday')
                COALESCE(SUM(CASE WHEN npr.type IN ('FULL_DAY', 'PARTIAL_ABSENT') AND DAYNAME(npr.date) != 'Saturday' THEN COALESCE(npr.no_pay_count, 0) ELSE 0 END), 0) AS weekday_nopays,
                COALESCE(SUM(CASE WHEN npr.type IN ('FULL_DAY', 'PARTIAL_ABSENT') AND DAYNAME(npr.date) = 'Saturday' THEN COALESCE(npr.no_pay_count, 0) ELSE 0 END), 0) AS saturday_nopays,
                COALESCE(SUM(CASE WHEN npr.type = 'EARLY_OUT' THEN COALESCE(npr.no_pay_count, 0) ELSE 0 END), 0) AS early_out_nopays,
                COALESCE(SUM(CASE WHEN npr.type = 'LATE_IN' THEN COALESCE(npr.no_pay_count, 0) ELSE 0 END), 0) AS major_late_nopays,
                COALESCE(SUM(CASE WHEN npr.type = 'LATE_MONTHLY' THEN COALESCE(npr.no_pay_count, 0) ELSE 0 END), 0) AS monthly_late_nopays,

                (
                    SELECT COALESCE(CONCAT('[', GROUP_CONCAT(
                        CONCAT('{\"id\":', a.id, ',\"name\":\"', REPLACE(IFNULL(a.allowance_name, ''), '\"', '\\\\\"'), '\",\"amount\":', COALESCE(ea.custom_amount, a.amount, 0), ',\"is_custom\":1,\"code\":\"', REPLACE(IFNULL(a.allowance_code, ''), '\"', '\\\\\"'), '\",\"category\":\"', REPLACE(IFNULL(a.category, ''), '\"', '\\\\\"'), '\"}')
                    SEPARATOR ','), ']'), '[]')
                    FROM employee_allowances ea JOIN allowances a ON a.id = ea.allowance_id
                    WHERE ea.employee_id = e.id AND ea.is_active = 1 AND a.status = 'active' AND (ea.month = ? AND ea.year = ?)
                ) AS allowances,

                (
                    SELECT COALESCE(CONCAT('[', GROUP_CONCAT(
                        CONCAT('{\"id\":', dd.id, ',\"name\":\"', REPLACE(IFNULL(dd.deduction_name, ''), '\"', '\\\\\"'), '\",\"amount\":', COALESCE(ed.custom_amount, dd.amount, 0), ',\"is_custom\":', CASE WHEN ed.id IS NOT NULL THEN 1 ELSE 0 END, ',\"code\":\"', REPLACE(IFNULL(dd.deduction_code, ''), '\"', '\\\\\"'), '\",\"category\":\"', REPLACE(IFNULL(dd.category, ''), '\"', '\\\\\"'), '\"}')
                    SEPARATOR ','), ']'), '[]')
                    FROM deductions dd LEFT JOIN employee_deductions ed ON dd.id = ed.deduction_id AND ed.employee_id = e.id AND ed.is_active = 1 AND (ed.month = ? AND ed.year = ?)
                    WHERE dd.company_id = c.id AND (dd.department_id IS NULL OR dd.department_id = oa.department_id) AND dd.status = 'active'
                ) AS deductions,

                (
                    SELECT COALESCE(CONCAT('[', GROUP_CONCAT(
                        CONCAT('{\"id\":', b.id, ',\"name\":\"', REPLACE(IFNULL(b.bonus_name, ''), '\"', '\\\\\"'), '\",\"amount\":', COALESCE(eb.custom_amount, b.amount, 0), ',\"is_custom\":1,\"code\":\"', REPLACE(IFNULL(b.bonus_code, ''), '\"', '\\\\\"'), '\",\"category\":\"', REPLACE(IFNULL(b.bonus_type, ''), '\"', '\\\\\"'), '\"}')
                    SEPARATOR ','), ']'), '[]')
                    FROM employee_bonuses eb JOIN bonuses b ON b.id = eb.bonus_id
                    WHERE eb.employee_id = e.id AND eb.is_active = 1 AND b.status = 'active' AND (eb.month = ? AND eb.year = ?)
                ) AS bonuses

            FROM employees e
            JOIN organization_assignments oa ON e.organization_assignment_id = oa.id
            JOIN companies c ON oa.company_id = c.id
            LEFT JOIN departments d ON oa.department_id = d.id
            LEFT JOIN sub_departments sd ON oa.sub_department_id = sd.id
            LEFT JOIN compensation comp ON e.id = comp.employee_id
            LEFT JOIN contact_details cd ON e.id = cd.employee_id
            LEFT JOIN loans lo ON e.id = lo.employee_id AND lo.status = 'active'
            LEFT JOIN no_pay_records npr ON e.id = npr.employee_id AND LOWER(npr.status) = 'approved' AND npr.date BETWEEN ? AND ?

            WHERE e.is_active = '1'
        ";

        $params = [$month, $year, $month, $year, $month, $year, $startDate, $endDate];

        if ($company_id) { $query .= " AND oa.company_id = ? "; $params[] = $company_id; }
        if ($department_id) { $query .= " AND oa.department_id = ? "; $params[] = $department_id; }
        if ($search) { $query .= " AND (e.attendance_employee_no LIKE ? OR e.full_name LIKE ?) "; $params[] = "%{$search}%"; $params[] = "%{$search}%"; }

        $query .= " GROUP BY e.id, e.attendance_employee_no, e.full_name, e.nic, c.name, d.name, sd.name, comp.basic_salary, comp.br1, comp.br2, comp.increment_active, comp.increment_value, comp.increment_effected_date, comp.ot_morning, comp.ot_evening, comp.enable_epf_etf, comp.stamp, comp.bank_name, comp.branch_name, comp.bank_account_no, c.id, oa.department_id, oa.probationary_period, oa.date_of_joining, e.epf, cd.permanent_address, cd.mobile_line, cd.emg_name, cd.emg_relationship, cd.emg_tel";

        $results = DB::select($query, $params);
        $data = [];

        foreach ($results as $result) {
            $employeeData = (array)$result;

            $employeeData['compensation'] = [
                'bank_name' => $result->bank_name ?? null,
                'branch_name' => $result->branch_name ?? null,
                'bank_account_no' => $result->bank_account_no ?? null,
            ];

            $allowancesArr = json_decode($result->allowances ?? '[]', true) ?: [];
            $deductionsArr = json_decode($result->deductions ?? '[]', true) ?: [];
            $bonusesArr = json_decode($result->bonuses ?? '[]', true) ?: [];

            $stampValue = ((int)($result->stamp ?? 0) === 1) ? 25 : 0;
            $employeeData['stamp'] = $stampValue;

            $basicSalary = (float)($employeeData['basic_salary'] ?? 0);
            $brAllowance = 0;

            if ((int)$result->br1 === 1 && (int)$result->br2 === 1) { $brAllowance = 3500; }
            elseif ((int)$result->br1 === 1) { $brAllowance = 1000; }
            elseif ((int)$result->br2 === 1) { $brAllowance = 2500; }

            $basicSalary += $brAllowance;

            if (!empty($employeeData['increment_active']) && !empty($employeeData['increment_effected_date']) && strtotime($employeeData['increment_effected_date']) <= strtotime($endDate)) {
                $basicSalary += (float)($employeeData['increment_value'] ?? 0);
            }

            // LOAN CALCULATION (reducing-balance schedule; skip if approved)
            $installmentAmount = 0.0;
            $loanInterest = 0.0;
            $loanPrincipal = 0.0;
            $loanDeductFrom = $employeeData['loan_deduct_from'] ?? 'bonus';
            $loanResolved = $this->resolveLoanInstallmentForMonth($employeeData, $selectedMonthYear);
            $installmentAmount = $loanResolved['installment'];
            $loanPrincipal = $loanResolved['principal'];
            $loanInterest = $loanResolved['interest'];

            // Working Days
            $companyLeavesCount = DB::table('leave_calendars')->where('company_id', $company_id ?? 0)
                ->where(function ($q) use ($startDate, $endDate) {
                    $q->whereBetween('start_date', [$startDate, $endDate])->orWhereBetween('end_date', [$startDate, $endDate]);
                })->count();

            $workingDaysInMonth = max(1, $totalDaysInMonth - $companyLeavesCount);
            $perDaySalary = $basicSalary / $workingDaysInMonth;

            // සෙනසුරාදා සහ අනෙකුත් දවස් වල No Pay ගණනය කිරීම
            $weekdayNoPays = (float)($employeeData['weekday_nopays'] ?? 0);
            $saturdayNoPays = (float)($employeeData['saturday_nopays'] ?? 0);
            $earlyOutNoPays = (float)($employeeData['early_out_nopays'] ?? 0);
            $majorLateNoPays = (float)($employeeData['major_late_nopays'] ?? 0);
            $monthlyLateNoPays = (float)($employeeData['monthly_late_nopays'] ?? 0);

            // Prefer monthly late policy nopay; avoid double-counting daily LATE_IN when applied
            if ($monthlyLateNoPays > 0) {
                $majorLateNoPays = $monthlyLateNoPays;
            }

            $fullDayNoPayDeduction = round($weekdayNoPays * $perDaySalary, 2);
            $saturdayNoPayBonusDeduction = round($saturdayNoPays * $perDaySalary, 2); // මෙය Bonus එකෙන් කැපෙන කොටස

            $earlyOutNoPayDeduction = round($earlyOutNoPays * $perDaySalary, 2);
            $majorLateDeduction = round($majorLateNoPays * $perDaySalary, 2);

            // Probation Deduction
            $probationDeduction = 0.0;
            if ($employeeData['probationary_period']) {
                $probationLeaves = leave_master::where('employee_id', $employeeData['id'])
                    ->whereRaw('LOWER(status) = ?', ['approved'])
                    ->whereBetween('leave_date', [$startDate, $endDate])
                    ->get();

                $totalProbationOverLimit = 0;
                foreach ($probationLeaves as $pl) {
                    $totalProbationOverLimit += (float)($pl->over_limit ?? 0);
                }

                $probationDeduction = round($totalProbationOverLimit * $perDaySalary, 2);
            }

            // Minor Late Deductions (<= 30 mins)
            $minorLateData = $this->calculateLateDeductionData((int)$employeeData['id'], $startDate, $endDate, $perDaySalary);
            $shortLeaveDeduction = $minorLateData['short_leave_deduction'] ?? 0;
            $halfDayDeduction = $minorLateData['half_day_deduction'] ?? 0;

            // KPI
            $kpiAllowance = 0.0;
            $kpiBonusAllowance = 0.0;
            if ($kpiType === 'monthly') {
                $percentage = DB::table('performance_evaluations')->where('employee_id', $employeeData['id'])->whereBetween('start_date', [$startDate, $endDate])->avg('percentage');
                if (!is_null($percentage)) {
                    $kpiAllowance = round(($basicSalary * ((float)$percentage)) / 100.0, 2);
                    $allowancesArr[] = ['name' => 'KPI Allowance', 'amount' => $kpiAllowance, 'category' => 'kpi'];
                }
            } elseif ($kpiType === '6month') {
                $sixMonthStart = Carbon::parse($startDate)->subMonths(5)->startOfMonth()->toDateString();
                $percentage = DB::table('performance_appraisals')->where('employee_id', $employeeData['id'])->where('start_date', '>=', $sixMonthStart)->where('end_date', '<=', $endDate)->avg('percentage');
                if (!is_null($percentage)) {
                    $kpiBonusAllowance = round(($basicSalary * ((float)$percentage)) / 100.0, 2);
                    $bonusesArr[] = ['name' => 'KPI Bonus (6M)', 'amount' => $kpiBonusAllowance, 'category' => 'kpi_bonus'];
                }
            }

            $employeeData['allowances'] = $allowancesArr;
            $employeeData['deductions'] = $deductionsArr;
            $employeeData['bonuses'] = $bonusesArr;

            $totalAllowances = array_reduce($allowancesArr, fn($c, $i) => $c + (float)($i['amount'] ?? 0), 0);
            $totalBonuses = array_reduce($bonusesArr, fn($c, $i) => $c + (float)($i['amount'] ?? 0), 0);

            $totalFixedDeductions = array_reduce($deductionsArr, fn($c, $i) => $c + (float)($i['amount'] ?? 0), 0);

            $epfEligibleAllowances = array_reduce($allowancesArr, function ($carry, $item) {
                return (strtolower($item['category'] ?? '') === 'kpi_bonus') ? $carry : $carry + (float)($item['amount'] ?? 0);
            }, 0);
            $epfEtfBase = $basicSalary + $epfEligibleAllowances;
            $epfEmployeeDeduction = !empty($employeeData['enable_epf_etf']) ? ($epfEtfBase * 0.08) : 0;

            // Overtime
            $otRows = over_time::with('timeCard:id,date,time,actual_date')->where('employee_id', $employeeData['id'])->whereRaw('LOWER(status) = ?', ['approved'])->whereHas('timeCard', function ($q) use ($startDate, $endDate) { $q->whereBetween('date', [$startDate, $endDate]); })->get();
            $morning_ot_fees = ((int)($employeeData['ot_morning'] ?? 0) === 1) ? ((float)$otRows->sum('morning_ot_amount') + (float)$otRows->sum('morning_ot_special_amount')) : 0.0;
            $night_ot_fees = ((int)($employeeData['ot_evening'] ?? 0) === 1) ? ((float)$otRows->sum('evening_ot_amount') + (float)$otRows->sum('evening_ot_special_amount')) : 0.0;
            $holiday_ot_fees = (float)$otRows->sum('holiday_ot_amount');

            // --- DEDUCTION SPLIT ---
            $basicGross = $basicSalary + $totalAllowances;
            $bonusGross = $totalBonuses;

            // 1. Basic Deductions (සතියේ දිනවල NoPay, EPF, Probation)
            $basicDeductionsTotal = $epfEmployeeDeduction + $fullDayNoPayDeduction + $probationDeduction;

            // 2. Bonus Deductions (සෙනසුරාදා NoPay, Early Out, Short Leave, Half Day, Major Late, Custom Deductions)
            $bonusDeductionsTotal = $saturdayNoPayBonusDeduction + $earlyOutNoPayDeduction + $shortLeaveDeduction + $halfDayDeduction + $majorLateDeduction + $totalFixedDeductions;

            if ($loanDeductFrom === 'basic') {
                $basicDeductionsTotal += $loanPrincipal + $loanInterest;
            } else {
                $bonusDeductionsTotal += $loanPrincipal + $loanInterest;
            }

            // Totals
            $grossSalary = $basicGross + $bonusGross + $morning_ot_fees + $night_ot_fees + $holiday_ot_fees;
            $totalDeductions = $basicDeductionsTotal + $bonusDeductionsTotal + $stampValue;
            $netSalary = $grossSalary - $totalDeductions;

            $employeeData['salary_breakdown'] = [
                'basic_salary' => round($basicSalary, 2),
                'per_day_salary' => round($perDaySalary, 3),
                'ot_morning_fees' => round($morning_ot_fees, 2),
                'ot_night_fees' => round($night_ot_fees, 2),
                'holiday_ot_fees' => round($holiday_ot_fees, 2),
                'ot_morning_hours' => round((float)$otRows->sum('morning_ot') + (float)$otRows->sum('morning_ot_special'), 2),
                'ot_night_hours' => round((float)$otRows->sum('afternoon_ot') + (float)$otRows->sum('evening_ot_special'), 2),
                'holiday_ot_hours' => round((float)$otRows->sum('holiday_ot_hours'), 2),

                // Deductions mapping
                'full_day_nopay_deduction' => round($fullDayNoPayDeduction, 2),
                'saturday_nopay_deduction' => round($saturdayNoPayBonusDeduction, 2), // අලුත් එකතු කිරීම
                'early_out_nopay_deduction' => round($earlyOutNoPayDeduction, 2),
                'short_leave_deduction' => round($shortLeaveDeduction, 2),
                'half_day_deduction' => round($halfDayDeduction, 2),
                'major_late_deduction' => round($majorLateDeduction, 2),
                'epf_employee_deduction' => round($epfEmployeeDeduction, 2),
                'probation_deduction' => round($probationDeduction, 2),
                'stamp_duty' => $stampValue,

                // Loan mappings
                'loan_principal' => round($loanPrincipal, 2),
                'loan_interest' => round($loanInterest, 2),
                'loan_deduct_from' => $loanDeductFrom,

                'total_fixed_deductions' => round($totalFixedDeductions, 2),

                'net_salary' => round($netSalary, 2),
                'gross_salary' => round($grossSalary, 2),
                'total_deductions' => round($totalDeductions, 2),
            ];

            $data[] = $employeeData;
        }

        return response()->json(['data' => $data, 'meta' => ['count' => count($data)]]);
    }
*/


    // public function getEmployeesByMonthAndCompany(Request $request)
    // {
    //     $month = $request->query('month');
    //     $year = $request->query('year');
    //     $company_id = $request->query('company_id');
    //     $department_id = $request->query('department_id');
    //     $search = $request->query('search');

    //     $kpiTypeRaw = strtolower((string)$request->query('kpi_type', ''));
    //     $kpiType = in_array($kpiTypeRaw, ['monthly', '6month', 'six_month']) ? $kpiTypeRaw : '';

    //     $startDate = "{$year}-{$month}-01";
    //     $lastDay = date('t', strtotime($startDate));
    //     $endDate = "{$year}-{$month}-{$lastDay}";
    //     $selectedMonthYear = date('Y-m', strtotime($startDate));

    //     $totalDaysInMonth = (int)$lastDay;

    //     $query = "
    //         SELECT
    //             e.id,
    //             e.attendance_employee_no AS emp_no,
    //             e.full_name,
    //             e.nic,
    //             c.name AS company_name,
    //             d.name AS department_name,
    //             sd.name AS sub_department_name,
    //             comp.basic_salary,
    //             oa.probationary_period,
    //             oa.date_of_joining,
    //             e.epf,
    //             cd.permanent_address AS address,
    //             cd.mobile_line,
    //             cd.emg_name,
    //             cd.emg_relationship,
    //             cd.emg_tel,
    //             comp.increment_active,
    //             CASE WHEN comp.increment_active = 1 THEN comp.increment_value ELSE NULL END AS increment_value,
    //             CASE WHEN comp.increment_active = 1 THEN comp.increment_effected_date ELSE NULL END AS increment_effected_date,
    //             comp.ot_morning,
    //             comp.ot_evening,
    //             comp.enable_epf_etf,
    //             comp.br1,
    //             comp.br2,
    //             comp.stamp,
    //             comp.bank_name,
    //             comp.branch_name,
    //             comp.bank_account_no,
    //             COALESCE(SUM(lo.loan_amount), 0) AS total_loan_amount,
    //             MAX(lo.installment_count) AS installment_count,
    //             MAX(lo.installment_amount) AS installment_amount,
    //             MAX(lo.status) AS loan_status,
    //             MAX(lo.schedule) AS loan_schedule,
    //             MAX(lo.deduct_from) AS loan_deduct_from,
    //             MAX(lo.with_interest) AS with_interest,
    //             MAX(lo.interest_rate_per_annum) AS interest_rate_per_annum,

    //             -- No Pay Types Split (Approved records only)
    //             COALESCE(SUM(CASE WHEN npr.type IN ('FULL_DAY', 'PARTIAL_ABSENT') AND DAYNAME(npr.date) != 'Saturday' THEN COALESCE(npr.no_pay_count, 0) ELSE 0 END), 0) AS weekday_nopays,
    //             COALESCE(SUM(CASE WHEN npr.type IN ('FULL_DAY', 'PARTIAL_ABSENT') AND DAYNAME(npr.date) = 'Saturday' THEN COALESCE(npr.no_pay_count, 0) ELSE 0 END), 0) AS saturday_nopays,
    //             COALESCE(SUM(CASE WHEN npr.type = 'EARLY_OUT' THEN COALESCE(npr.no_pay_count, 0) ELSE 0 END), 0) AS early_out_nopays,
    //             COALESCE(SUM(CASE WHEN npr.type = 'LATE_IN' THEN COALESCE(npr.no_pay_count, 0) ELSE 0 END), 0) AS major_late_nopays,

    //             (
    //                 SELECT COALESCE(CONCAT('[', GROUP_CONCAT(
    //                     CONCAT('{\"id\":', a.id, ',\"name\":\"', REPLACE(IFNULL(a.allowance_name, ''), '\"', '\\\\\"'), '\",\"amount\":', COALESCE(ea.custom_amount, a.amount, 0), ',\"is_custom\":1,\"code\":\"', REPLACE(IFNULL(a.allowance_code, ''), '\"', '\\\\\"'), '\",\"category\":\"', REPLACE(IFNULL(a.category, ''), '\"', '\\\\\"'), '\"}')
    //                 SEPARATOR ','), ']'), '[]')
    //                 FROM employee_allowances ea JOIN allowances a ON a.id = ea.allowance_id
    //                 WHERE ea.employee_id = e.id AND ea.is_active = 1 AND a.status = 'active' AND (ea.month = ? AND ea.year = ?)
    //             ) AS allowances,

    //             (
    //                 SELECT COALESCE(SUM(da.amount), 0)
    //                 FROM dinner_allowances da
    //                 WHERE da.employee_id = e.id AND da.status = 'Approved' AND MONTH(da.date) = ? AND YEAR(da.date) = ?
    //             ) AS total_dinner_allowance,

    //             -- මෙතන තමයි කලින් අවුල තිබ්බේ (LEFT JOIN එකක් තිබුණා, ඒක JOIN කරලා හැදුවා)
    //             (
    //                 SELECT COALESCE(CONCAT('[', GROUP_CONCAT(
    //                     CONCAT('{\"id\":', dd.id, ',\"name\":\"', REPLACE(IFNULL(dd.deduction_name, ''), '\"', '\\\\\"'), '\",\"amount\":', COALESCE(ed.custom_amount, dd.amount, 0), ',\"is_custom\":1,\"code\":\"', REPLACE(IFNULL(dd.deduction_code, ''), '\"', '\\\\\"'), '\",\"category\":\"', REPLACE(IFNULL(dd.category, ''), '\"', '\\\\\"'), '\"}')
    //                 SEPARATOR ','), ']'), '[]')
    //                 FROM employee_deductions ed JOIN deductions dd ON dd.id = ed.deduction_id
    //                 WHERE ed.employee_id = e.id AND ed.is_active = 1 AND dd.status = 'active' AND (ed.month = ? AND ed.year = ?)
    //             ) AS deductions,

    //             (
    //                 SELECT COALESCE(CONCAT('[', GROUP_CONCAT(
    //                     CONCAT('{\"id\":', b.id, ',\"name\":\"', REPLACE(IFNULL(b.bonus_name, ''), '\"', '\\\\\"'), '\",\"amount\":', COALESCE(eb.custom_amount, b.amount, 0), ',\"is_custom\":1,\"code\":\"', REPLACE(IFNULL(b.bonus_code, ''), '\"', '\\\\\"'), '\",\"category\":\"', REPLACE(IFNULL(b.bonus_type, ''), '\"', '\\\\\"'), '\"}')
    //                 SEPARATOR ','), ']'), '[]')
    //                 FROM employee_bonuses eb JOIN bonuses b ON b.id = eb.bonus_id
    //                 WHERE eb.employee_id = e.id AND eb.is_active = 1 AND b.status = 'active' AND (eb.month = ? AND eb.year = ?)
    //             ) AS bonuses

    //         FROM employees e
    //         JOIN organization_assignments oa ON e.organization_assignment_id = oa.id
    //         JOIN companies c ON oa.company_id = c.id
    //         LEFT JOIN departments d ON oa.department_id = d.id
    //         LEFT JOIN sub_departments sd ON oa.sub_department_id = sd.id
    //         LEFT JOIN compensation comp ON e.id = comp.employee_id
    //         LEFT JOIN contact_details cd ON e.id = cd.employee_id
    //         LEFT JOIN loans lo ON e.id = lo.employee_id AND lo.status = 'active'
    //         LEFT JOIN no_pay_records npr ON e.id = npr.employee_id AND LOWER(npr.status) = 'approved' AND npr.date BETWEEN ? AND ?

    //         WHERE e.is_active = '1'
    //     ";

    //     $params = [$month, $year, $month, $year, $month, $year, $month, $year, $startDate, $endDate];

    //     if ($company_id) {
    //         $query .= " AND oa.company_id = ? ";
    //         $params[] = $company_id;
    //     }
    //     if ($department_id) {
    //         $query .= " AND oa.department_id = ? ";
    //         $params[] = $department_id;
    //     }
    //     if ($search) {
    //         $query .= " AND (e.attendance_employee_no LIKE ? OR e.full_name LIKE ?) ";
    //         $params[] = "%{$search}%";
    //         $params[] = "%{$search}%";
    //     }

    //     $query .= " GROUP BY e.id, e.attendance_employee_no, e.full_name, e.nic, c.name, d.name, sd.name, comp.basic_salary, comp.br1, comp.br2, comp.increment_active, comp.increment_value, comp.increment_effected_date, comp.ot_morning, comp.ot_evening, comp.enable_epf_etf, comp.stamp, comp.bank_name, comp.branch_name, comp.bank_account_no, c.id, oa.department_id, oa.probationary_period, oa.date_of_joining, e.epf, cd.permanent_address, cd.mobile_line, cd.emg_name, cd.emg_relationship, cd.emg_tel";

    //     $results = DB::select($query, $params);
    //     $data = [];

    //     foreach ($results as $result) {
    //         $employeeData = (array)$result;

    //         $employeeData['compensation'] = [
    //             'bank_name' => $result->bank_name ?? null,
    //             'branch_name' => $result->branch_name ?? null,
    //             'bank_account_no' => $result->bank_account_no ?? null,
    //         ];

    //         $allowancesArr = json_decode($result->allowances ?? '[]', true) ?: [];
    //         $deductionsArr = json_decode($result->deductions ?? '[]', true) ?: [];
    //         $bonusesArr = json_decode($result->bonuses ?? '[]', true) ?: [];

    //         $dinnerAllowanceValue = (float)($employeeData['total_dinner_allowance'] ?? 0);
    //         if ($dinnerAllowanceValue > 0) {
    //             $allowancesArr[] = [
    //                 'id' => 'dinner_allowance',
    //                 'name' => 'Dinner Allowance',
    //                 'amount' => $dinnerAllowanceValue,
    //                 'is_custom' => 1,
    //                 'code' => 'DINNER_ALW',
    //                 'category' => 'dinner_allowance'
    //             ];
    //         }

    //         $stampValue = ((int)($result->stamp ?? 0) === 1) ? 25 : 0;
    //         $employeeData['stamp'] = $stampValue;

    //         $basicSalary = (float)($employeeData['basic_salary'] ?? 0);
    //         $brAllowance = 0;

    //         if ((int)$result->br1 === 1 && (int)$result->br2 === 1) {
    //             $brAllowance = 3500;
    //         } elseif ((int)$result->br1 === 1) {
    //             $brAllowance = 1000;
    //         } elseif ((int)$result->br2 === 1) {
    //             $brAllowance = 2500;
    //         }

    //         $basicSalary += $brAllowance;

    //         if (!empty($employeeData['increment_active']) && !empty($employeeData['increment_effected_date']) && strtotime($employeeData['increment_effected_date']) <= strtotime($endDate)) {
    //             $basicSalary += (float)($employeeData['increment_value'] ?? 0);
    //         }

    //         // LOAN CALCULATION
    //         $installmentAmount = 0.0;
    //         $loanInterest = 0.0;
    //         $loanPrincipal = 0.0;
    //         $loanDeductFrom = $employeeData['loan_deduct_from'] ?? 'bonus';
    //         $loanStatus = strtolower((string)($employeeData['loan_status'] ?? ''));

    //         if ($loanStatus === 'active') {
    //             $schedule = $employeeData['loan_schedule'] ?? null;
    //             if (is_string($schedule)) {
    //                 $schedule = is_array(json_decode($schedule, true)) ? json_decode($schedule, true) : null;
    //             }

    //             if (is_array($schedule) && count($schedule) > 0) {
    //                 foreach ($schedule as $r) {
    //                     $due = $r['due_date'] ?? $r['dueDate'] ?? null;
    //                     if ($due && date('Y-m', strtotime($due)) === $selectedMonthYear) {
    //                         $installmentAmount = (float)($r['installment_amount'] ?? $r['installmentAmount'] ?? 0);
    //                         break;
    //                     }
    //                 }
    //             } else {
    //                 $installmentAmount = ((int)($employeeData['installment_count'] ?? 0) > 0) ? (float)($employeeData['installment_amount'] ?? 0) : 0.0;
    //             }

    //             if ($employeeData['with_interest'] && $employeeData['interest_rate_per_annum'] > 0) {
    //                 $loanAmountTotal = (float)($employeeData['total_loan_amount'] ?? 0);
    //                 $loanInterest = ($loanAmountTotal * ((float)$employeeData['interest_rate_per_annum'] / 100)) / 12;
    //                 if ($loanInterest > $installmentAmount) {
    //                     $loanInterest = $installmentAmount;
    //                 }
    //             }
    //             $loanPrincipal = $installmentAmount - $loanInterest;
    //         }

    //         // Working Days & No Pay Deductions
    //         $companyLeavesCount = DB::table('leave_calendars')->where('company_id', $company_id ?? 0)
    //             ->where(function ($q) use ($startDate, $endDate) {
    //                 $q->whereBetween('start_date', [$startDate, $endDate])->orWhereBetween('end_date', [$startDate, $endDate]);
    //             })->count();

    //         $workingDaysInMonth = max(1, $totalDaysInMonth - $companyLeavesCount);
    //         $perDaySalary = $basicSalary / $workingDaysInMonth;

    //         // Full Day, Partial Absent (Half day & Short leaves No pay), Early Out, Major Late Deductions
    //         $weekdayNoPays = (float)($employeeData['weekday_nopays'] ?? 0);
    //         $saturdayNoPays = (float)($employeeData['saturday_nopays'] ?? 0);
    //         $earlyOutNoPays = (float)($employeeData['early_out_nopays'] ?? 0);
    //         $majorLateNoPays = (float)($employeeData['major_late_nopays'] ?? 0);

    //         $fullDayNoPayDeduction = round($weekdayNoPays * $perDaySalary, 2);
    //         $saturdayNoPayBonusDeduction = round($saturdayNoPays * $perDaySalary, 2);

    //         $earlyOutNoPayDeduction = round($earlyOutNoPays * $perDaySalary, 2);
    //         $majorLateDeduction = round($majorLateNoPays * $perDaySalary, 2);

    //         // Probation Deduction
    //         $probationDeduction = 0.0;
    //         if ($employeeData['probationary_period']) {
    //             $probationLeaves = leave_master::where('employee_id', $employeeData['id'])
    //                 ->whereRaw('LOWER(status) = ?', ['approved'])
    //                 ->whereBetween('leave_date', [$startDate, $endDate])
    //                 ->get();

    //             $totalProbationOverLimit = 0;
    //             foreach ($probationLeaves as $pl) {
    //                 $totalProbationOverLimit += (float)($pl->over_limit ?? 0);
    //             }

    //             $probationDeduction = round($totalProbationOverLimit * $perDaySalary, 2);
    //         }

    //         // Minor Late Deductions (<= 30 mins)
    //         $minorLateData = $this->calculateLateDeductionData((int)$employeeData['id'], $startDate, $endDate, $perDaySalary);
    //         $shortLeaveDeduction = $minorLateData['short_leave_deduction'] ?? 0;
    //         $halfDayDeduction = $minorLateData['half_day_deduction'] ?? 0;

    //         // KPI
    //         $kpiAllowance = 0.0;
    //         $kpiBonusAllowance = 0.0;
    //         if ($kpiType === 'monthly') {
    //             $percentage = DB::table('performance_evaluations')->where('employee_id', $employeeData['id'])->whereBetween('start_date', [$startDate, $endDate])->avg('percentage');
    //             if (!is_null($percentage)) {
    //                 $kpiAllowance = round(($basicSalary * ((float)$percentage)) / 100.0, 2);
    //                 $allowancesArr[] = ['name' => 'KPI Allowance', 'amount' => $kpiAllowance, 'category' => 'kpi'];
    //             }
    //         } elseif ($kpiType === '6month') {
    //             $sixMonthStart = Carbon::parse($startDate)->subMonths(5)->startOfMonth()->toDateString();
    //             $percentage = DB::table('performance_appraisals')->where('employee_id', $employeeData['id'])->where('start_date', '>=', $sixMonthStart)->where('end_date', '<=', $endDate)->avg('percentage');
    //             if (!is_null($percentage)) {
    //                 $kpiBonusAllowance = round(($basicSalary * ((float)$percentage)) / 100.0, 2);
    //                 $bonusesArr[] = ['name' => 'KPI Bonus (6M)', 'amount' => $kpiBonusAllowance, 'category' => 'kpi_bonus'];
    //             }
    //         }

    //         $employeeData['allowances'] = $allowancesArr;
    //         $employeeData['deductions'] = $deductionsArr;
    //         $employeeData['bonuses'] = $bonusesArr;

    //         $totalAllowances = array_reduce($allowancesArr, fn($c, $i) => $c + (float)($i['amount'] ?? 0), 0);
    //         $totalBonuses = array_reduce($bonusesArr, fn($c, $i) => $c + (float)($i['amount'] ?? 0), 0);

    //         $totalFixedDeductions = array_reduce($deductionsArr, fn($c, $i) => $c + (float)($i['amount'] ?? 0), 0);

    //         $epfEligibleAllowances = array_reduce($allowancesArr, function ($carry, $item) {
    //             $cat = strtolower($item['category'] ?? '');
    //             return in_array($cat, ['kpi_bonus', 'dinner_allowance']) ? $carry : $carry + (float)($item['amount'] ?? 0);
    //         }, 0);
    //         $epfEtfBase = $basicSalary + $epfEligibleAllowances;
    //         $epfEmployeeDeduction = !empty($employeeData['enable_epf_etf']) ? ($epfEtfBase * 0.08) : 0;

    //         // Overtime
    //         $otRows = over_time::with('timeCard:id,date,time,actual_date')->where('employee_id', $employeeData['id'])->whereRaw('LOWER(status) = ?', ['approved'])->whereHas('timeCard', function ($q) use ($startDate, $endDate) {
    //             $q->whereBetween('date', [$startDate, $endDate]);
    //         })->get();
    //         $morning_ot_fees = ((int)($employeeData['ot_morning'] ?? 0) === 1) ? ((float)$otRows->sum('morning_ot_amount') + (float)$otRows->sum('morning_ot_special_amount')) : 0.0;
    //         $night_ot_fees = ((int)($employeeData['ot_evening'] ?? 0) === 1) ? ((float)$otRows->sum('evening_ot_amount') + (float)$otRows->sum('evening_ot_special_amount')) : 0.0;
    //         $holiday_ot_fees = (float)$otRows->sum('holiday_ot_amount');

    //         // --- DEDUCTION SPLIT ---
    //         $basicGross = $basicSalary + $totalAllowances;
    //         $bonusGross = $totalBonuses;

    //         $basicDeductionsTotal = $epfEmployeeDeduction + $fullDayNoPayDeduction + $probationDeduction;
    //         $bonusDeductionsTotal = $saturdayNoPayBonusDeduction + $earlyOutNoPayDeduction + $shortLeaveDeduction + $halfDayDeduction + $majorLateDeduction + $totalFixedDeductions;

    //         if ($loanDeductFrom === 'basic') {
    //             $basicDeductionsTotal += $loanPrincipal;
    //         } else {
    //             $bonusDeductionsTotal += $loanPrincipal;
    //         }

    //         $bonusDeductionsTotal += $loanInterest;

    //         // Totals
    //         $grossSalary = $basicGross + $bonusGross + $morning_ot_fees + $night_ot_fees + $holiday_ot_fees;
    //         $totalDeductions = $basicDeductionsTotal + $bonusDeductionsTotal + $stampValue;
    //         $netSalary = $grossSalary - $totalDeductions;

    //         $employeeData['salary_breakdown'] = [
    //             'basic_salary' => round($basicSalary, 2),
    //             'per_day_salary' => round($perDaySalary, 3),
    //             'ot_morning_fees' => round($morning_ot_fees, 2),
    //             'ot_night_fees' => round($night_ot_fees, 2),
    //             'holiday_ot_fees' => round($holiday_ot_fees, 2),
    //             'ot_morning_hours' => round((float)$otRows->sum('morning_ot') + (float)$otRows->sum('morning_ot_special'), 2),
    //             'ot_night_hours' => round((float)$otRows->sum('afternoon_ot') + (float)$otRows->sum('evening_ot_special'), 2),
    //             'holiday_ot_hours' => round((float)$otRows->sum('holiday_ot_hours'), 2),

    //             'full_day_nopay_deduction' => round($fullDayNoPayDeduction, 2),
    //             'saturday_nopay_deduction' => round($saturdayNoPayBonusDeduction, 2),
    //             'early_out_nopay_deduction' => round($earlyOutNoPayDeduction, 2),
    //             'short_leave_deduction' => round($shortLeaveDeduction, 2),
    //             'half_day_deduction' => round($halfDayDeduction, 2),
    //             'major_late_deduction' => round($majorLateDeduction, 2),
    //             'epf_employee_deduction' => round($epfEmployeeDeduction, 2),
    //             'probation_deduction' => round($probationDeduction, 2),
    //             'stamp_duty' => $stampValue,

    //             'loan_principal' => round($loanPrincipal, 2),
    //             'loan_interest' => round($loanInterest, 2),
    //             'loan_deduct_from' => $loanDeductFrom,

    //             'total_fixed_deductions' => round($totalFixedDeductions, 2),

    //             'net_salary' => round($netSalary, 2),
    //             'gross_salary' => round($grossSalary, 2),
    //             'total_deductions' => round($totalDeductions, 2),
    //         ];

    //         $data[] = $employeeData;
    //     }

    //     return response()->json(['data' => $data, 'meta' => ['count' => count($data)]]);
    // }


    public function getEmployeesByMonthAndCompany(Request $request)
    {
        $month = $request->query('month');
        $year = $request->query('year');
        $company_id = $request->query('company_id');
        $department_id = $request->query('department_id');
        $search = $request->query('search');

        $kpiTypeRaw = strtolower((string)$request->query('kpi_type', ''));
        $kpiType = in_array($kpiTypeRaw, ['monthly', '6month', 'six_month']) ? $kpiTypeRaw : '';

        $startDate = "{$year}-{$month}-01";
        $lastDay = date('t', strtotime($startDate));
        $endDate = "{$year}-{$month}-{$lastDay}";
        $selectedMonthYear = date('Y-m', strtotime($startDate));
        $loanInstallmentDeductSql = Schema::hasColumn('loans', 'installment_deduct_from')
            ? 'MAX(lo.installment_deduct_from) AS loan_installment_deduct_from,'
            : 'NULL AS loan_installment_deduct_from,';
        $loanInterestDeductSql = Schema::hasColumn('loans', 'interest_deduct_from')
            ? 'MAX(lo.interest_deduct_from) AS loan_interest_deduct_from,'
            : 'NULL AS loan_interest_deduct_from,';
        $hasEdDeduct = Schema::hasColumn('employee_deductions', 'deduct_from');
        $hasDdDeduct = Schema::hasColumn('deductions', 'deduct_from');
        if ($hasEdDeduct && $hasDdDeduct) {
            $edDeductFromSql = "REPLACE(IFNULL(NULLIF(ed.deduct_from, ''), IFNULL(dd.deduct_from, 'bonus')), '\"', '')";
        } elseif ($hasEdDeduct) {
            $edDeductFromSql = "REPLACE(IFNULL(ed.deduct_from, 'bonus'), '\"', '')";
        } elseif ($hasDdDeduct) {
            $edDeductFromSql = "REPLACE(IFNULL(dd.deduct_from, 'bonus'), '\"', '')";
        } else {
            $edDeductFromSql = "'bonus'";
        }
        // deduct_from is only ever stored for salary advances (assignment or deduction master).
        $edAdvanceChecks = array_filter([
            $hasEdDeduct ? "NULLIF(ed.deduct_from, '') IS NOT NULL" : null,
            $hasDdDeduct ? "NULLIF(dd.deduct_from, '') IS NOT NULL" : null,
        ]);
        $edIsAdvanceSql = $edAdvanceChecks ? 'IF(' . implode(' OR ', $edAdvanceChecks) . ', 1, 0)' : '0';

        $totalDaysInMonth = (int)$lastDay;

        $location_id = $request->query('location_id');
        $hasLocations = app(CompanyLocationService::class)->ready();
        $locationSelectSql = $hasLocations
            ? 'oa.location_id, cl.name AS location_name,'
            : 'NULL AS location_id, NULL AS location_name,';
        $locationJoinSql = $hasLocations ? 'LEFT JOIN company_locations cl ON oa.location_id = cl.id' : '';
        $locationGroupSql = $hasLocations ? ', oa.location_id, cl.name' : '';

        $query = "
            SELECT
                e.id,
                e.attendance_employee_no AS emp_no,
                e.full_name,
                e.nic,
                c.name AS company_name,
                c.id AS company_id,
                d.name AS department_name,
                sd.name AS sub_department_name,
                {$locationSelectSql}
                comp.basic_salary,
                comp.monthly_bonus,
                comp.sports_fund_percentage,
                comp.staff_fund_amount,
                c.default_sports_fund_percentage,
                COALESCE(c.nopay_working_days, 30) AS nopay_working_days,
                oa.probationary_period,
                oa.date_of_joining,
                e.epf,
                cd.permanent_address AS address,
                cd.mobile_line,
                cd.emg_name,
                cd.emg_relationship,
                cd.emg_tel,
                comp.increment_active,
                CASE WHEN comp.increment_active = 1 THEN comp.increment_value ELSE NULL END AS increment_value,
                CASE WHEN comp.increment_active = 1 THEN comp.increment_effected_date ELSE NULL END AS increment_effected_date,
                comp.ot_morning,
                comp.ot_evening,
                comp.enable_epf_etf,
                comp.br1,
                comp.br2,
                comp.stamp,
                comp.bank_name,
                comp.branch_name,
                comp.bank_account_no,
                COALESCE(SUM(lo.loan_amount), 0) AS total_loan_amount,
                MAX(lo.installment_count) AS installment_count,
                MAX(lo.installment_amount) AS installment_amount,
                MAX(lo.status) AS loan_status,
                MAX(lo.schedule) AS loan_schedule,
                MAX(lo.deduct_from) AS loan_deduct_from,
                {$loanInstallmentDeductSql}
                {$loanInterestDeductSql}
                MAX(lo.deduct_basic_amount) AS loan_deduct_basic_amount,
                MAX(lo.deduct_bonus_amount) AS loan_deduct_bonus_amount,
                MAX(lo.start_from) AS loan_start_from,
                MAX(lo.with_interest) AS with_interest,
                MAX(lo.interest_rate_per_annum) AS interest_rate_per_annum,

                -- No Pay Types Split (Approved records only)
                -- Leave-form shortfall NoPay (split across basic + monthly bonus in PHP)
                COALESCE(SUM(CASE
                    WHEN npr.type = 'LEAVE_SHORTFALL' THEN COALESCE(npr.no_pay_count, 0)
                    WHEN npr.type IN ('FULL_DAY', 'PARTIAL_ABSENT')
                         AND npr.description LIKE '%Auto NoPay from leave shortfall%'
                    THEN COALESCE(npr.no_pay_count, 0)
                    ELSE 0 END), 0) AS leave_shortfall_nopays,
                -- Other weekday/saturday absences (exclude leave shortfall so it is not double-counted)
                COALESCE(SUM(CASE
                    WHEN npr.type IN ('FULL_DAY', 'PARTIAL_ABSENT')
                         AND DAYNAME(npr.date) != 'Saturday'
                         AND (npr.description IS NULL OR npr.description NOT LIKE '%Auto NoPay from leave shortfall%')
                         AND npr.type != 'LEAVE_SHORTFALL'
                    THEN COALESCE(npr.no_pay_count, 0) ELSE 0 END), 0) AS weekday_nopays,
                COALESCE(SUM(CASE
                    WHEN npr.type IN ('FULL_DAY', 'PARTIAL_ABSENT')
                         AND DAYNAME(npr.date) = 'Saturday'
                         AND (npr.description IS NULL OR npr.description NOT LIKE '%Auto NoPay from leave shortfall%')
                    THEN COALESCE(npr.no_pay_count, 0) ELSE 0 END), 0) AS saturday_nopays,
                COALESCE(SUM(CASE WHEN npr.type = 'EARLY_OUT' THEN COALESCE(npr.no_pay_count, 0) ELSE 0 END), 0) AS early_out_nopays,
                COALESCE(SUM(CASE WHEN npr.type = 'LATE_IN' THEN COALESCE(npr.no_pay_count, 0) ELSE 0 END), 0) AS major_late_nopays,
                COALESCE(SUM(CASE WHEN npr.type = 'LATE_MONTHLY' THEN COALESCE(npr.no_pay_count, 0) ELSE 0 END), 0) AS monthly_late_nopays,

                -- Employee-wise allowances (company/month assignments)
                (
                    SELECT COALESCE(CONCAT('[', GROUP_CONCAT(
                        CONCAT('{\"id\":', a.id, ',\"name\":\"', REPLACE(IFNULL(a.allowance_name, ''), '\"', '\\\\\"'), '\",\"amount\":', COALESCE(ea.custom_amount, a.amount, 0), ',\"is_custom\":1,\"code\":\"', REPLACE(IFNULL(a.allowance_code, ''), '\"', '\\\\\"'), '\",\"category\":\"', REPLACE(IFNULL(a.category, ''), '\"', '\\\\\"'), '\"}')
                    SEPARATOR ','), ']'), '[]')
                    FROM employee_allowances ea JOIN allowances a ON a.id = ea.allowance_id
                    WHERE ea.employee_id = e.id AND ea.is_active = 1 AND a.status = 'active'
                      AND LOWER(IFNULL(a.category, '')) NOT IN ('bonus', 'monthly_bonus')
                      AND (
                          (ea.month = ? AND ea.year = ?)
                          OR (ea.month IS NULL AND ea.year IS NULL)
                      )
                ) AS allowances,

                -- Employee-wise allowance records (dated entries)
                (
                    SELECT COALESCE(CONCAT('[', GROUP_CONCAT(
                        CONCAT('{\"id\":', ewa.id, ',\"name\":\"', REPLACE(IFNULL(ewa.allowance_name, ''), '\"', '\\\\\"'), '\",\"amount\":', COALESCE(ewa.amount, 0), ',\"is_custom\":1,\"code\":\"', REPLACE(IFNULL(ewa.allowance_code, ''), '\"', '\\\\\"'), '\",\"category\":\"other\"}')
                    SEPARATOR ','), ']'), '[]')
                    FROM employee_wise_allowances ewa
                    WHERE ewa.employee_id = e.id AND ewa.status = 'active'
                      AND MONTH(ewa.date) = ? AND YEAR(ewa.date) = ?
                ) AS employee_wise_allowances,

                (
                    SELECT COALESCE(SUM(da.amount), 0)
                    FROM dinner_allowances da
                    WHERE da.employee_id = e.id AND da.status = 'Approved' AND MONTH(da.date) = ? AND YEAR(da.date) = ?
                ) AS total_dinner_allowance,

                -- Employee-wise deductions (assigned per employee per month)
                (
                    SELECT COALESCE(CONCAT('[', GROUP_CONCAT(
                        CONCAT('{\"id\":', dd.id, ',\"name\":\"', REPLACE(IFNULL(dd.deduction_name, ''), '\"', '\\\\\"'), '\",\"amount\":', COALESCE(ed.custom_amount, dd.amount, 0), ',\"is_custom\":1,\"code\":\"', REPLACE(IFNULL(dd.deduction_code, ''), '\"', '\\\\\"'), '\",\"category\":\"', REPLACE(IFNULL(dd.category, ''), '\"', '\\\\\"'), '\",\"is_advance\":', {$edIsAdvanceSql}, ',\"deduct_from\":\"', {$edDeductFromSql}, '\"}')
                    SEPARATOR ','), ']'), '[]')
                    FROM employee_deductions ed JOIN deductions dd ON dd.id = ed.deduction_id
                    WHERE ed.employee_id = e.id AND ed.is_active = 1 AND dd.status = 'active'
                      AND (
                          (ed.month = ? AND ed.year = ?)
                          OR (ed.month IS NULL AND ed.year IS NULL)
                      )
                ) AS deductions,

                -- Employee-wise deduction records (dated entries)
                (
                    SELECT COALESCE(CONCAT('[', GROUP_CONCAT(
                        CONCAT('{\"id\":', ewd.id, ',\"name\":\"', REPLACE(IFNULL(ewd.deduction_name, ''), '\"', '\\\\\"'), '\",\"amount\":', COALESCE(ewd.amount, 0), ',\"is_custom\":1,\"code\":\"', REPLACE(IFNULL(ewd.deduction_code, ''), '\"', '\\\\\"'), '\",\"category\":\"other\"}')
                    SEPARATOR ','), ']'), '[]')
                    FROM employee_wise_deductions ewd
                    WHERE ewd.employee_id = e.id AND ewd.status = 'active'
                      AND MONTH(ewd.date) = ? AND YEAR(ewd.date) = ?
                ) AS employee_wise_deductions,

                -- Employee-wise bonuses (company master + month assignments)
                (
                    SELECT COALESCE(CONCAT('[', GROUP_CONCAT(
                        CONCAT('{\"id\":', b.id, ',\"name\":\"', REPLACE(IFNULL(b.bonus_name, ''), '\"', '\\\\\"'), '\",\"amount\":', COALESCE(eb.custom_amount, b.amount, 0), ',\"is_custom\":1,\"code\":\"', REPLACE(IFNULL(b.bonus_code, ''), '\"', '\\\\\"'), '\",\"category\":\"', REPLACE(IFNULL(b.bonus_type, ''), '\"', '\\\\\"'), '\",\"is_annual\":', COALESCE(b.is_annual, 0), ',\"payment_months\":', COALESCE(b.payment_months, '[]'), '}')
                    SEPARATOR ','), ']'), '[]')
                    FROM employee_bonuses eb JOIN bonuses b ON b.id = eb.bonus_id
                    WHERE eb.employee_id = e.id AND eb.is_active = 1 AND b.status = 'active'
                      AND (
                          b.is_annual = 1
                          OR (COALESCE(b.is_annual, 0) = 0 AND eb.month = ? AND eb.year = ?)
                      )
                ) AS bonuses,

                -- Employee-wise bonus records (dated entries)
                (
                    SELECT COALESCE(CONCAT('[', GROUP_CONCAT(
                        CONCAT('{\"id\":', ewb.id, ',\"name\":\"', REPLACE(IFNULL(ewb.bonus_name, ''), '\"', '\\\\\"'), '\",\"amount\":', COALESCE(ewb.amount, 0), ',\"is_custom\":1,\"code\":\"', REPLACE(IFNULL(ewb.bonus_code, ''), '\"', '\\\\\"'), '\",\"category\":\"other\",\"is_annual\":', COALESCE(ewb.is_annual, 0), ',\"payment_months\":', COALESCE(ewb.payment_months, '[]'), '}')
                    SEPARATOR ','), ']'), '[]')
                    FROM employee_wise_bonuses ewb
                    WHERE ewb.employee_id = e.id AND ewb.status = 'active'
                      AND (
                          ewb.is_annual = 1
                          OR (COALESCE(ewb.is_annual, 0) = 0 AND MONTH(ewb.date) = ? AND YEAR(ewb.date) = ?)
                      )
                ) AS employee_wise_bonuses

            FROM employees e
            JOIN organization_assignments oa ON e.organization_assignment_id = oa.id
            JOIN companies c ON oa.company_id = c.id
            LEFT JOIN departments d ON oa.department_id = d.id
            LEFT JOIN sub_departments sd ON oa.sub_department_id = sd.id
            {$locationJoinSql}
            LEFT JOIN compensation comp ON e.id = comp.employee_id
            LEFT JOIN contact_details cd ON e.id = cd.employee_id
            LEFT JOIN loans lo ON e.id = lo.employee_id AND lo.status = 'active'
            LEFT JOIN no_pay_records npr ON e.id = npr.employee_id AND LOWER(npr.status) = 'approved' AND npr.date BETWEEN ? AND ?

            WHERE e.is_active = '1'
            " . ContractEmployeeScope::sqlExclude('e') . "
        ";

        $params = [
            $month,
            $year, // employee_allowances
            $month,
            $year, // employee_wise_allowances
            $month,
            $year, // Dinner Allowance
            $month,
            $year, // employee_deductions
            $month,
            $year, // employee_wise_deductions
            $month,
            $year, // Non-annual Bonuses (master)
            $month,
            $year, // employee_wise_bonuses (non-annual date match)
            $startDate,
            $endDate, // No Pay Records
        ];

        if ($company_id) {
            $query .= " AND oa.company_id = ? ";
            $params[] = $company_id;
        }
        if ($department_id) {
            $query .= " AND oa.department_id = ? ";
            $params[] = $department_id;
        }
        if ($location_id && $hasLocations) {
            $query .= " AND oa.location_id = ? ";
            $params[] = $location_id;
        }
        if ($search) {
            $query .= " AND (e.attendance_employee_no LIKE ? OR e.full_name LIKE ?) ";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
        }

        $query .= " GROUP BY e.id, e.attendance_employee_no, e.full_name, e.nic, c.name, d.name, sd.name, comp.basic_salary, comp.monthly_bonus, comp.sports_fund_percentage, comp.staff_fund_amount, c.default_sports_fund_percentage, c.nopay_working_days, comp.br1, comp.br2, comp.increment_active, comp.increment_value, comp.increment_effected_date, comp.ot_morning, comp.ot_evening, comp.enable_epf_etf, comp.stamp, comp.bank_name, comp.branch_name, comp.bank_account_no, c.id, oa.department_id, oa.probationary_period, oa.date_of_joining, e.epf, cd.permanent_address, cd.mobile_line, cd.emg_name, cd.emg_relationship, cd.emg_tel{$locationGroupSql}";

        $results = DB::select($query, $params);
        $data = [];
        $currentMonthInt = (int) $month;
        $lateGraceConfigs = [];

        foreach ($results as $result) {
            $employeeData = (array)$result;

            $employeeData['compensation'] = [
                'bank_name' => $result->bank_name ?? null,
                'branch_name' => $result->branch_name ?? null,
                'bank_account_no' => $result->bank_account_no ?? null,
                'enable_epf_etf' => $result->enable_epf_etf ?? false,
                'monthly_bonus' => (float) ($result->monthly_bonus ?? 0),
                'sports_fund_percentage' => $result->sports_fund_percentage,
                'staff_fund_amount' => (float) ($result->staff_fund_amount ?? 0),
            ];

            $allowancesArr = json_decode($result->allowances ?? '[]', true) ?: [];
            $employeeWiseAllowances = json_decode($result->employee_wise_allowances ?? '[]', true) ?: [];
            $deductionsArr = json_decode($result->deductions ?? '[]', true) ?: [];
            $employeeWiseDeductions = json_decode($result->employee_wise_deductions ?? '[]', true) ?: [];
            $bonusesArr = json_decode($result->bonuses ?? '[]', true) ?: [];
            $employeeWiseBonuses = json_decode($result->employee_wise_bonuses ?? '[]', true) ?: [];

            $allowancesArr = array_merge($allowancesArr, $employeeWiseAllowances);
            $deductionsArr = array_merge($deductionsArr, $employeeWiseDeductions);
            $bonusesArr = array_merge($bonusesArr, $employeeWiseBonuses);

            // Annual bonuses: only include when current month is a payment month
            $bonusesArr = array_values(array_filter($bonusesArr, function ($bonus) use ($currentMonthInt) {
                if (empty($bonus['is_annual'])) {
                    return true;
                }
                $paymentMonths = $bonus['payment_months'] ?? [];
                if (is_string($paymentMonths)) {
                    $paymentMonths = json_decode($paymentMonths, true) ?: [];
                }
                return in_array($currentMonthInt, array_map('intval', $paymentMonths), true);
            }));

            $dinnerAllowanceValue = (float)($employeeData['total_dinner_allowance'] ?? 0);
            if ($dinnerAllowanceValue > 0) {
                $allowancesArr[] = [
                    'id' => 'dinner_allowance',
                    'name' => 'Dinner Allowance',
                    'amount' => $dinnerAllowanceValue,
                    'is_custom' => 1,
                    'code' => 'DINNER_ALW',
                    'category' => 'dinner_allowance'
                ];
            }

            $stampValue = ((int)($result->stamp ?? 0) === 1) ? 25 : 0;
            $employeeData['stamp'] = $stampValue;

            $basicSalary = (float)($employeeData['basic_salary'] ?? 0);
            $brAllowance = 0;

            if ((int)$result->br1 === 1 && (int)$result->br2 === 1) {
                $brAllowance = 3500;
            } elseif ((int)$result->br1 === 1) {
                $brAllowance = 1000;
            } elseif ((int)$result->br2 === 1) {
                $brAllowance = 2500;
            }

            $basicSalary += $brAllowance;

            if (!empty($employeeData['increment_active']) && !empty($employeeData['increment_effected_date']) && strtotime($employeeData['increment_effected_date']) <= strtotime($endDate)) {
                $basicSalary += (float)($employeeData['increment_value'] ?? 0);
            }

            // LOAN CALCULATION (reducing-balance schedule; skip if approved)
            $installmentAmount = 0.0;
            $loanInterest = 0.0;
            $loanPrincipal = 0.0;
            $loanDeductFrom = strtolower((string) ($employeeData['loan_deduct_from'] ?? 'bonus'));
            $loanResolved = $this->resolveLoanInstallmentForMonth($employeeData, $selectedMonthYear);
            $installmentAmount = $loanResolved['installment'];
            $loanPrincipal = $loanResolved['principal'];
            $loanInterest = $loanResolved['interest'];
            $loanSplit = $this->splitLoanDeductionAcrossPayslips(
                $loanPrincipal,
                $loanInterest,
                $loanDeductFrom,
                (float) ($employeeData['loan_deduct_basic_amount'] ?? 0),
                (float) ($employeeData['loan_deduct_bonus_amount'] ?? 0),
                $employeeData['loan_installment_deduct_from'] ?? null,
                $employeeData['loan_interest_deduct_from'] ?? null
            );
            $loanBasicPrincipal = $loanSplit['basic_principal'];
            $loanBasicInterest = $loanSplit['basic_interest'];
            $loanBonusPrincipal = $loanSplit['bonus_principal'];
            $loanBonusInterest = $loanSplit['bonus_interest'];

            // Working Days & No Pay Deductions
            $companyLeavesCount = DB::table('leave_calendars')->where('company_id', $company_id ?? 0)
                ->where(function ($q) use ($startDate, $endDate) {
                    $q->whereBetween('start_date', [$startDate, $endDate])->orWhereBetween('end_date', [$startDate, $endDate]);
                })->count();

            $workingDaysInMonth = max(1, $totalDaysInMonth - $companyLeavesCount);
            $perDaySalary = $basicSalary / $workingDaysInMonth;

            // Full Day, Partial Absent, Early Out (attendance) — still valued from basic/working calendar days
            $weekdayNoPays = (float)($employeeData['weekday_nopays'] ?? 0);
            $saturdayNoPays = (float)($employeeData['saturday_nopays'] ?? 0);
            $earlyOutNoPays = (float)($employeeData['early_out_nopays'] ?? 0);
            $majorLateNoPaysSql = (float)($employeeData['major_late_nopays'] ?? 0);
            $monthlyLateNoPays = (float)($employeeData['monthly_late_nopays'] ?? 0);

            $fullDayNoPayDeduction = round($weekdayNoPays * $perDaySalary, 2);
            $saturdayNoPayBonusDeduction = round($saturdayNoPays * $perDaySalary, 2);

            $earlyOutNoPayDeduction = round($earlyOutNoPays * $perDaySalary, 2);

            // Late NoPay amount is computed after monthly bonus + company nopay_working_days below
            $majorLateDeduction = 0.0;
            $lateBonusNoPay = [
                'days' => 0.0,
                'amount' => 0.0,
                'source' => 'pending',
                'per_day_from_bonus' => 0.0,
                'per_day_from_basic' => 0.0,
                'working_days' => 30.0,
                'deduct_from' => 'bonus',
            ];
            $majorLateNoPays = 0.0;

            // Probation Deduction
            $probationDeduction = 0.0;
            if ($employeeData['probationary_period']) {
                $probationLeaves = \App\Models\leave_master::where('employee_id', $employeeData['id'])
                    ->whereRaw('LOWER(status) = ?', ['approved'])
                    ->whereBetween('leave_date', [$startDate, $endDate])
                    ->get();

                $totalProbationOverLimit = 0;
                foreach ($probationLeaves as $pl) {
                    $totalProbationOverLimit += (float)($pl->over_limit ?? 0);
                }

                $probationDeduction = round($totalProbationOverLimit * $perDaySalary, 2);
            }

            // Minor Late Deductions (<= 30 mins)
            $minorLateData = $this->calculateLateDeductionData((int)$employeeData['id'], $startDate, $endDate, $perDaySalary);
            $shortLeaveDeduction = $minorLateData['short_leave_deduction'] ?? 0;
            $halfDayDeduction = $minorLateData['half_day_deduction'] ?? 0;

            // KPI
            $kpiAllowance = 0.0;
            $kpiBonusAllowance = 0.0;
            if ($kpiType === 'monthly') {
                $percentage = DB::table('performance_evaluations')->where('employee_id', $employeeData['id'])->whereBetween('start_date', [$startDate, $endDate])->avg('percentage');
                if (!is_null($percentage)) {
                    $kpiAllowance = round(($basicSalary * ((float)$percentage)) / 100.0, 2);
                    $allowancesArr[] = ['name' => 'KPI Allowance', 'amount' => $kpiAllowance, 'category' => 'kpi'];
                }
            } elseif ($kpiType === '6month') {
                $sixMonthStart = \Carbon\Carbon::parse($startDate)->subMonths(5)->startOfMonth()->toDateString();
                $percentage = DB::table('performance_appraisals')->where('employee_id', $employeeData['id'])->where('start_date', '>=', $sixMonthStart)->where('end_date', '<=', $endDate)->avg('percentage');
                if (!is_null($percentage)) {
                    $kpiBonusAllowance = round(($basicSalary * ((float)$percentage)) / 100.0, 2);
                    $bonusesArr[] = ['name' => 'KPI Bonus (6M)', 'amount' => $kpiBonusAllowance, 'category' => 'kpi_bonus'];
                }
            }

            // Monthly bonus is part of salary split — from compensation only (set at employee creation)
            $compMonthlyBonus = (float) ($employeeData['monthly_bonus'] ?? 0);
            $monthlyBonusTotal = $compMonthlyBonus;

            if ($compMonthlyBonus > 0) {
                $bonusesArr[] = [
                    'id' => 'comp_monthly_bonus',
                    'name' => 'Monthly Bonus',
                    'amount' => $compMonthlyBonus,
                    'category' => 'monthly_bonus',
                ];
            }

            // Leave-form shortfall NoPay: (basic + monthly bonus)/company_days × days, split to basic + bonus
            // Late coming NoPay: monthly_bonus/company_days × days — entire amount under monthly bonus
            $leaveShortfallDays = (float) ($employeeData['leave_shortfall_nopays'] ?? 0);
            $nopayWorkingDays = (float) ($employeeData['nopay_working_days'] ?? 30);
            if ($nopayWorkingDays < 1) {
                $nopayWorkingDays = 30;
            }
            $leaveShortfallNoPay = $this->resolveLeaveShortfallNoPay(
                $basicSalary,
                $monthlyBonusTotal,
                $leaveShortfallDays,
                $nopayWorkingDays
            );
            $leaveShortfallBasicDeduction = $leaveShortfallNoPay['basic_amount'];
            $leaveShortfallBonusDeduction = $leaveShortfallNoPay['bonus_amount'];

            $policyOn = false;
            try {
                if (Schema::hasColumn('companies', 'late_attendance_policy_enabled') && !empty($employeeData['company_id'])) {
                    $policyOn = (bool) DB::table('companies')
                        ->where('id', $employeeData['company_id'])
                        ->value('late_attendance_policy_enabled');
                }
            } catch (\Throwable $e) {
                $policyOn = false;
            }

            $lateCompanyId = (int) ($employeeData['company_id'] ?? 0);
            if ($lateCompanyId > 0 && !array_key_exists($lateCompanyId, $lateGraceConfigs)) {
                $lateGraceConfigs[$lateCompanyId] = CompanyProcessSettings::usesLateGraceNoPay($lateCompanyId)
                    ? LateGraceNoPayService::config($lateCompanyId)
                    : null;
            }
            $lateGraceConfig = $lateGraceConfigs[$lateCompanyId] ?? null;
            $lateGrace = null;
            if ($lateGraceConfig) {
                try {
                    $lateGrace = app(LateGraceNoPayService::class)->monthForEmployee(
                        (int) $employeeData['id'],
                        (int) $year,
                        (int) $month,
                        $lateGraceConfig,
                        (float) $basicSalary,
                        (float) $monthlyBonusTotal,
                        $nopayWorkingDays
                    );
                } catch (\Throwable $e) {
                    $lateGrace = null;
                }
            }
            $lateGraceBasic = ($lateGrace && $lateGrace['deduct_from'] === 'basic') ? (float) $lateGrace['amount'] : 0.0;
            $lateGraceBonus = ($lateGrace && $lateGrace['deduct_from'] === 'bonus') ? (float) $lateGrace['amount'] : 0.0;

            if ($lateGrace) {
                // Late grace rule replaces the other late NoPay sources for this company
                $lateBonusNoPay = [
                    'days' => 0.0,
                    'amount' => 0.0,
                    'source' => 'late_grace_rule',
                    'per_day_from_bonus' => $lateGrace['per_day'],
                    'per_day_from_basic' => $lateGrace['per_day'],
                    'per_day_from_total' => $lateGrace['per_day'],
                    'rate_base' => 'basic_plus_bonus',
                    'working_days' => $nopayWorkingDays,
                    'deduct_from' => $lateGrace['deduct_from'],
                ];
            } else {
                $lateBonusNoPay = $this->resolveLateComingBonusNoPay(
                    (int) $employeeData['id'],
                    (int) $year,
                    (int) $month,
                    $monthlyLateNoPays,
                    $policyOn ? 0.0 : $majorLateNoPaysSql,
                    $monthlyBonusTotal,
                    $nopayWorkingDays,
                    $basicSalary
                );
            }
            $majorLateDeduction = $lateBonusNoPay['amount'];
            $majorLateNoPays = $lateBonusNoPay['days'];

            $excessLate = ['basic_amount' => 0.0, 'bonus_amount' => 0.0, 'days' => 0.0, 'minutes' => 0];
            if ($policyOn && !$lateGrace) {
                try {
                    $excessLate = app(ExcessLateService::class)->monthTotalsForSalary(
                        (int) $employeeData['id'],
                        (int) $year,
                        (int) $month
                    );
                } catch (\Throwable $e) {
                    $excessLate = ['basic_amount' => 0.0, 'bonus_amount' => 0.0, 'days' => 0.0, 'minutes' => 0];
                }
            }

            // Combined NoPay taken from monthly bonus (leave shortfall bonus portion + late NoPay)
            $bonusNopayTotal = round($leaveShortfallBonusDeduction + $majorLateDeduction + $lateGraceBonus, 2);

            $employeeData['allowances'] = $allowancesArr;
            $employeeData['deductions'] = $deductionsArr;
            $employeeData['bonuses'] = $bonusesArr;

            $totalAllowances = array_reduce($allowancesArr, fn($c, $i) => $c + (float)($i['amount'] ?? 0), 0);
            $totalBonuses = array_reduce($bonusesArr, fn($c, $i) => $c + (float)($i['amount'] ?? 0), 0);

            $epfEtfDeductions = 0.0;
            $bonusFixedDeductions = 0.0;
            $advanceFromAssignedBasic = 0.0;
            $advanceFromAssignedBonus = 0.0;
            $advancePayroll = SalaryAdvanceService::monthPayrollDeductions(
                (int) $employeeData['id'],
                (int) $year,
                (int) $month
            );
            foreach ($deductionsArr as $deduction) {
                $cat = strtoupper($deduction['category'] ?? '');
                $amount = (float) ($deduction['amount'] ?? 0);
                if (in_array($cat, ['EPF', 'ETF'], true)) {
                    $epfEtfDeductions += $amount;
                } elseif (
                    !empty($deduction['is_advance'])
                    || SalaryAdvanceService::isNamedAdvanceDeduction($deduction['name'] ?? '')
                    || SalaryAdvanceService::isNamedAdvanceDeduction($deduction['code'] ?? '')
                ) {
                    if ($advancePayroll['total'] > 0) {
                        continue;
                    }
                    if (SalaryAdvanceService::normalizeDeductFrom($deduction['deduct_from'] ?? null) === 'basic') {
                        $advanceFromAssignedBasic += $amount;
                    } else {
                        $advanceFromAssignedBonus += $amount;
                    }
                } else {
                    $bonusFixedDeductions += $amount;
                }
            }

            // EPF 8% + employer EPF 12% + ETF 3% all use (basic − basic NoPay)
            $basicNoPayForEpf = (float) $fullDayNoPayDeduction
                + (float) $leaveShortfallBasicDeduction
                + (float) ($excessLate['basic_amount'] ?? 0)
                + $lateGraceBasic;
            $epfEtfBase = max(0, (float) $basicSalary - $basicNoPayForEpf);
            $epfEnabled = !empty($employeeData['enable_epf_etf']);
            $epfEmployeeDeduction = $epfEnabled ? round($epfEtfBase * 0.08, 2) : 0;
            $epfEmployerContribution = $epfEnabled ? round($epfEtfBase * 0.12, 2) : 0;
            $etfEmployerContribution = $epfEnabled ? round($epfEtfBase * 0.03, 2) : 0;

            // Sports fund: percentage of total salary (basic + monthly bonus)
            $sportsFundPct = $employeeData['sports_fund_percentage'] ?? $employeeData['default_sports_fund_percentage'] ?? 0;
            $compensationTotal = $basicSalary + $monthlyBonusTotal;
            $sportsFundDeduction = round($compensationTotal * ((float) $sportsFundPct / 100), 2);

            // Staff fund: fixed amount entered by user
            $staffFundDeduction = round((float) ($employeeData['staff_fund_amount'] ?? 0), 2);

            // Overtime
            $otRows = \App\Models\over_time::with('timeCard:id,date,time,actual_date')->where('employee_id', $employeeData['id'])->whereRaw('LOWER(status) = ?', ['approved'])->whereHas('timeCard', function ($q) use ($startDate, $endDate) {
                $q->whereBetween('date', [$startDate, $endDate]);
            })->get();
            $morning_ot_fees = ((int)($employeeData['ot_morning'] ?? 0) === 1) ? ((float)$otRows->sum('morning_ot_amount') + (float)$otRows->sum('morning_ot_special_amount')) : 0.0;
            $night_ot_fees = ((int)($employeeData['ot_evening'] ?? 0) === 1) ? ((float)$otRows->sum('evening_ot_amount') + (float)$otRows->sum('evening_ot_special_amount')) : 0.0;
            $holiday_ot_fees = (float)$otRows->sum('holiday_ot_amount');

            // --- DEDUCTION SPLIT ---
            // Basic side: EPF, weekday full-day NoPay, leave shortfall (basic portion), probation
            // Bonus side: late coming NoPay, leave shortfall (bonus portion), Saturday NoPay, early out, funds, custom
            $basicGross = $basicSalary + $totalAllowances;
            $bonusGross = $totalBonuses;

            $basicDeductionsTotal = $epfEmployeeDeduction
                + $epfEtfDeductions
                + $fullDayNoPayDeduction
                + $leaveShortfallBasicDeduction
                + $probationDeduction
                + (float) ($excessLate['basic_amount'] ?? 0)
                + $lateGraceBasic;
            $bonusDeductionsTotal = $saturdayNoPayBonusDeduction
                + $earlyOutNoPayDeduction
                + $shortLeaveDeduction
                + $halfDayDeduction
                + $majorLateDeduction // late NoPay — valued from monthly bonus, taken from bonus
                + $leaveShortfallBonusDeduction
                + (float) ($excessLate['bonus_amount'] ?? 0)
                + $lateGraceBonus
                + $bonusFixedDeductions
                + $sportsFundDeduction
                + $staffFundDeduction;

            $basicDeductionsTotal += $loanBasicPrincipal + $loanBasicInterest;
            $bonusDeductionsTotal += $loanBonusPrincipal + $loanBonusInterest;
            $salaryAdvanceBasic = (float) ($advancePayroll['basic'] ?? 0) + $advanceFromAssignedBasic;
            $salaryAdvanceBonus = (float) ($advancePayroll['bonus'] ?? 0) + $advanceFromAssignedBonus;
            $basicDeductionsTotal += $salaryAdvanceBasic;
            $bonusDeductionsTotal += $salaryAdvanceBonus;

            // Totals
            $grossSalary = $basicGross + $bonusGross + $morning_ot_fees + $night_ot_fees + $holiday_ot_fees;
            $totalDeductions = $basicDeductionsTotal + $bonusDeductionsTotal + $stampValue;
            $netSalary = $grossSalary - $totalDeductions;

            $employeeData['salary_breakdown'] = [
                'basic_salary' => round($basicSalary, 2),
                'br_allowance' => round($brAllowance, 2),
                'monthly_bonus' => round($monthlyBonusTotal, 2),
                'total_allowances' => round($totalAllowances, 2),
                'total_bonuses' => round($totalBonuses, 2),
                'total_dinner_allowance' => round($dinnerAllowanceValue, 2),
                'per_day_salary' => round($perDaySalary, 3),
                'ot_morning_fees' => round($morning_ot_fees, 2),
                'ot_night_fees' => round($night_ot_fees, 2),
                'holiday_ot_fees' => round($holiday_ot_fees, 2),
                'ot_morning_hours' => round((float)$otRows->sum('morning_ot') + (float)$otRows->sum('morning_ot_special'), 2),
                'ot_night_hours' => round((float)$otRows->sum('afternoon_ot') + (float)$otRows->sum('evening_ot_special'), 2),
                'holiday_ot_hours' => round((float)$otRows->sum('holiday_ot_hours'), 2),

                'full_day_nopay_deduction' => round($fullDayNoPayDeduction, 2),
                'saturday_nopay_deduction' => round($saturdayNoPayBonusDeduction, 2),
                'early_out_nopay_deduction' => round($earlyOutNoPayDeduction, 2),
                'short_leave_deduction' => round($shortLeaveDeduction, 2),
                'half_day_deduction' => round($halfDayDeduction, 2),
                // Leave-form shortfall NoPay: split basic + monthly bonus
                'leave_shortfall_nopay_days' => $leaveShortfallNoPay['days'],
                'leave_shortfall_nopay_basic_deduction' => $leaveShortfallBasicDeduction,
                'leave_shortfall_nopay_bonus_deduction' => $leaveShortfallBonusDeduction,
                'leave_shortfall_nopay_total' => $leaveShortfallNoPay['total_amount'],
                'leave_shortfall_nopay_basic_per_day' => $leaveShortfallNoPay['basic_per_day'],
                'leave_shortfall_nopay_bonus_per_day' => $leaveShortfallNoPay['bonus_per_day'],
                'leave_shortfall_nopay_combined_per_day' => $leaveShortfallNoPay['combined_per_day'],
                'nopay_working_days' => $leaveShortfallNoPay['divisor'],
                // Late deduction NoPay: (basic + monthly bonus) / company_days × days — deducted from monthly bonus
                'major_late_deduction' => round($majorLateDeduction, 2),
                'monthly_late_nopay_deduction' => round($majorLateDeduction, 2),
                'monthly_late_nopay_days' => $lateBonusNoPay['days'],
                'late_nopay_deduct_from' => 'bonus',
                'late_nopay_source' => $lateBonusNoPay['source'],
                'late_nopay_rate_base' => $lateBonusNoPay['rate_base'] ?? 'basic_plus_bonus',
                'late_nopay_per_day_from_bonus' => $lateBonusNoPay['per_day_from_bonus'],
                'late_nopay_per_day_from_basic' => $lateBonusNoPay['per_day_from_basic'],
                'late_nopay_per_day_from_total' => $lateBonusNoPay['per_day_from_total'] ?? $lateBonusNoPay['per_day_from_bonus'],
                'excess_late_nopay_basic' => round((float) ($excessLate['basic_amount'] ?? 0), 2),
                'excess_late_nopay_bonus' => round((float) ($excessLate['bonus_amount'] ?? 0), 2),
                'excess_late_nopay_days' => $excessLate['days'] ?? 0,
                'excess_late_minutes' => $excessLate['minutes'] ?? 0,
                // Late grace rule (add-on): month excess over start + grace, 1 NoPay day per block
                'late_grace_enabled' => (bool) $lateGrace,
                'late_grace_nopay_days' => $lateGrace['days'] ?? 0,
                'late_grace_excess_minutes' => $lateGrace['excess_minutes'] ?? 0,
                'late_grace_late_days' => $lateGrace['late_days'] ?? 0,
                'late_grace_nopay_amount' => round((float) ($lateGrace['amount'] ?? 0), 2),
                'late_grace_nopay_basic' => round($lateGraceBasic, 2),
                'late_grace_nopay_bonus' => round($lateGraceBonus, 2),
                'late_grace_deduct_from' => $lateGrace['deduct_from'] ?? null,
                'late_grace_start_time' => $lateGrace['config']['start_time'] ?? null,
                'late_grace_grace_minutes' => $lateGrace['config']['grace_minutes'] ?? null,
                'late_grace_block_minutes' => $lateGrace['config']['block_minutes'] ?? null,
                'late_grace_days_per_block' => $lateGrace['config']['days_per_block'] ?? null,
                'late_grace_detail' => $lateGrace['days_detail'] ?? [],
                // Total NoPay amount deducted from monthly bonus
                'bonus_nopay_total' => $bonusNopayTotal,
                'bonus_nopay_from_leave_shortfall' => $leaveShortfallBonusDeduction,
                'bonus_nopay_from_late' => round($majorLateDeduction + $lateGraceBonus, 2),
                'epf_employee_deduction' => round($epfEmployeeDeduction, 2),
                'epf_employer_contribution' => round($epfEmployerContribution, 2),
                'etf_employer_contribution' => round($etfEmployerContribution, 2),
                'epf_etf_base' => round($epfEtfBase, 2),
                'epf_basic_nopay' => round($basicNoPayForEpf, 2),
                'epf_etf_fixed_deductions' => round($epfEtfDeductions, 2),
                'sports_fund_deduction' => $sportsFundDeduction,
                'staff_fund_deduction' => $staffFundDeduction,
                'probation_deduction' => round($probationDeduction, 2),
                'stamp_duty' => $stampValue,

                'loan_principal' => round($loanPrincipal, 2),
                'loan_interest' => round($loanInterest, 2),
                'loan_deduct_from' => $loanDeductFrom,
                'loan_installment_deduct_from' => strtolower((string) ($employeeData['loan_installment_deduct_from'] ?? $loanDeductFrom)),
                'loan_interest_deduct_from' => strtolower((string) ($employeeData['loan_interest_deduct_from'] ?? $loanDeductFrom)),
                'loan_basic_principal' => round($loanBasicPrincipal, 2),
                'loan_basic_interest' => round($loanBasicInterest, 2),
                'loan_bonus_principal' => round($loanBonusPrincipal, 2),
                'loan_bonus_interest' => round($loanBonusInterest, 2),

                'salary_advance_basic' => round($salaryAdvanceBasic, 2),
                'salary_advance_bonus' => round($salaryAdvanceBonus, 2),
                'salary_advance' => round($salaryAdvanceBasic + $salaryAdvanceBonus, 2),

                'basic_deductions_total' => round($basicDeductionsTotal, 2),
                'bonus_deductions_total' => round($bonusDeductionsTotal, 2),
                'total_fixed_deductions' => round($bonusFixedDeductions + $epfEtfDeductions, 2),

                'net_salary' => round($netSalary, 2),
                'gross_salary' => round($grossSalary, 2),
                'total_deductions' => round($totalDeductions, 2),
            ];

            // Payload cleanup: Remove intermediate database layer attributes
            unset(
                $employeeData['bank_name'],
                $employeeData['branch_name'],
                $employeeData['bank_account_no'],
                $employeeData['total_dinner_allowance'],
                $employeeData['br1'],
                $employeeData['br2'],
                $employeeData['increment_active'],
                $employeeData['increment_value'],
                $employeeData['increment_effected_date'],
                $employeeData['ot_morning'],
                $employeeData['ot_evening'],
                $employeeData['enable_epf_etf'],
                $employeeData['total_loan_amount'],
                $employeeData['installment_count'],
                $employeeData['installment_amount'],
                $employeeData['loan_status'],
                $employeeData['loan_schedule'],
                $employeeData['loan_deduct_from'],
                $employeeData['loan_installment_deduct_from'],
                $employeeData['loan_interest_deduct_from'],
                $employeeData['loan_deduct_basic_amount'],
                $employeeData['loan_deduct_bonus_amount'],
                $employeeData['with_interest'],
                $employeeData['interest_rate_per_annum'],
                $employeeData['weekday_nopays'],
                $employeeData['saturday_nopays'],
                $employeeData['early_out_nopays'],
                $employeeData['major_late_nopays'],
                $employeeData['monthly_late_nopays'],
                $employeeData['leave_shortfall_nopays'],
                $employeeData['nopay_working_days']
            );

            $data[] = $employeeData;
        }

        // Attach existing salary process status for this month/year (for revise UI)
        $empNos = collect($data)->pluck('emp_no')->filter()->values()->all();
        $monthKeys = [str_pad((string)(int)$month, 2, '0', STR_PAD_LEFT), (string)(int)$month, (int)$month];
        $existingByNo = salary_process::whereIn('employee_no', $empNos)
            ->whereIn('month', $monthKeys)
            ->where('year', $year)
            ->get()
            ->keyBy('employee_no');

        foreach ($data as &$row) {
            $existing = $existingByNo->get($row['emp_no'] ?? null);
            $row['process_status'] = $existing?->status ?? 'unprocessed';
            $row['salary_process_id'] = $existing?->id;
        }
        unset($row);

        return response()->json(['data' => $data, 'meta' => ['count' => count($data)]]);
    }

    /**
     * Split a month's loan principal+interest across basic and monthly bonus payslips.
     */
    private function splitLoanDeductionAcrossPayslips(
        float $principal,
        float $interest,
        string $deductFrom,
        float $configuredBasicAmount,
        float $configuredBonusAmount,
        $installmentDeductFrom = null,
        $interestDeductFrom = null
    ): array {
        $principal = max(0, $principal);
        $interest = max(0, $interest);
        $empty = [
            'basic_principal' => 0.0,
            'basic_interest' => 0.0,
            'bonus_principal' => 0.0,
            'bonus_interest' => 0.0,
        ];
        if ($principal <= 0 && $interest <= 0) {
            return $empty;
        }

        $instFrom = strtolower(trim((string) $installmentDeductFrom));
        $intFrom = strtolower(trim((string) $interestDeductFrom));
        if (in_array($instFrom, ['basic', 'bonus'], true) && in_array($intFrom, ['basic', 'bonus'], true)) {
            return [
                'basic_principal' => $instFrom === 'basic' ? round($principal, 2) : 0.0,
                'bonus_principal' => $instFrom === 'bonus' ? round($principal, 2) : 0.0,
                'basic_interest' => $intFrom === 'basic' ? round($interest, 2) : 0.0,
                'bonus_interest' => $intFrom === 'bonus' ? round($interest, 2) : 0.0,
            ];
        }

        $total = $principal + $interest;
        $mode = strtolower(trim($deductFrom));
        if ($mode === 'basic') {
            return [
                'basic_principal' => round($principal, 2),
                'basic_interest' => round($interest, 2),
                'bonus_principal' => 0.0,
                'bonus_interest' => 0.0,
            ];
        }
        if ($mode !== 'split') {
            return [
                'basic_principal' => 0.0,
                'basic_interest' => 0.0,
                'bonus_principal' => round($principal, 2),
                'bonus_interest' => round($interest, 2),
            ];
        }

        $basicShare = max(0, $configuredBasicAmount);
        $bonusShare = max(0, $configuredBonusAmount);
        $shareTotal = $basicShare + $bonusShare;
        if ($shareTotal <= 0 || $total <= 0) {
            return [
                'basic_principal' => 0.0,
                'basic_interest' => 0.0,
                'bonus_principal' => round($principal, 2),
                'bonus_interest' => round($interest, 2),
            ];
        }

        $basicRatio = $basicShare / $shareTotal;
        $basicTotal = round($total * $basicRatio, 2);
        $bonusTotal = round($total - $basicTotal, 2);
        $principalRatio = $total > 0 ? $principal / $total : 0;

        $basicPrincipal = round($basicTotal * $principalRatio, 2);
        $basicInterest = round($basicTotal - $basicPrincipal, 2);
        $bonusPrincipal = round($bonusTotal * $principalRatio, 2);
        $bonusInterest = round($bonusTotal - $bonusPrincipal, 2);

        return [
            'basic_principal' => $basicPrincipal,
            'basic_interest' => $basicInterest,
            'bonus_principal' => $bonusPrincipal,
            'bonus_interest' => $bonusInterest,
        ];
    }

    /**
     * Resolve loan principal/interest for a payroll month from schedule (reducing balance).
     * Skipped (approved) installments return zeros. Prefer schedule row amounts over recalculation.
     *
     * @return array{installment: float, principal: float, interest: float, skipped: bool}
     */
    private function resolveLoanInstallmentForMonth(array $employeeData, string $selectedMonthYear): array
    {
        $empty = ['installment' => 0.0, 'principal' => 0.0, 'interest' => 0.0, 'skipped' => false];

        $loanStatus = strtolower((string) ($employeeData['loan_status'] ?? ''));
        if ($loanStatus !== 'active') {
            return $empty;
        }

        $startFrom = $employeeData['loan_start_from'] ?? $employeeData['start_from'] ?? null;
        if ($startFrom) {
            $startYm = date('Y-m', strtotime((string) $startFrom));
            if ($startYm && $selectedMonthYear < $startYm) {
                return $empty;
            }
        }

        $schedule = $employeeData['loan_schedule'] ?? null;
        if (is_string($schedule)) {
            $decoded = json_decode($schedule, true);
            $schedule = is_array($decoded) ? $decoded : null;
        }

        if (is_array($schedule) && count($schedule) > 0) {
            foreach ($schedule as $r) {
                $due = $r['dueDateIso'] ?? $r['due_date_iso'] ?? $r['due_date'] ?? $r['dueDate'] ?? null;
                if (!$due) {
                    continue;
                }
                $dueYm = date('Y-m', strtotime((string) $due));
                if ($dueYm !== $selectedMonthYear) {
                    continue;
                }

                $skipStatus = strtolower((string) ($r['skip_status'] ?? ''));
                $skipped = !empty($r['skipped']) || $skipStatus === 'approved';
                if ($skipped) {
                    return ['installment' => 0.0, 'principal' => 0.0, 'interest' => 0.0, 'skipped' => true];
                }

                $principal = (float) ($r['capitalRepayment'] ?? $r['capital_repayment'] ?? $r['principal_amount'] ?? 0);
                $interest = (float) ($r['interestPayment'] ?? $r['interest_payment'] ?? $r['interest_amount'] ?? 0);
                $installment = (float) ($r['installmentAmount'] ?? $r['installment_amount'] ?? ($principal + $interest));

                if ($installment <= 0 && ($principal > 0 || $interest > 0)) {
                    $installment = $principal + $interest;
                }

                return [
                    'installment' => round($installment, 2),
                    'principal' => round($principal, 2),
                    'interest' => round($interest, 2),
                    'skipped' => false,
                ];
            }

            // Schedule exists but no row for this month → no deduction
            return $empty;
        }

        // Legacy fallback without schedule
        $installmentAmount = ((int) ($employeeData['installment_count'] ?? 0) > 0)
            ? (float) ($employeeData['installment_amount'] ?? 0)
            : 0.0;

        if ($installmentAmount <= 0) {
            return $empty;
        }

        $interest = 0.0;
        if (!empty($employeeData['with_interest']) && (float) ($employeeData['interest_rate_per_annum'] ?? 0) > 0) {
            $loanAmountTotal = (float) ($employeeData['total_loan_amount'] ?? 0);
            $interest = round($loanAmountTotal * ((float) $employeeData['interest_rate_per_annum'] / 100), 2);
            if ($interest > $installmentAmount) {
                $interest = $installmentAmount;
            }
        }
        $principal = round($installmentAmount - $interest, 2);

        return [
            'installment' => round($installmentAmount, 2),
            'principal' => $principal,
            'interest' => $interest,
            'skipped' => false,
        ];
    }

    /**
     * Build the payload fields used for create/update of a salary_process row.
     */
    private function buildSalaryProcessPayload(array $employeeData, $month, $year): array
    {
        return [
            'employee_id' => $employeeData['id'] ?? $employeeData['employee_id'] ?? null,
            'employee_no' => $employeeData['emp_no'],
            'full_name' => $employeeData['full_name'],
            'company_name' => $employeeData['company_name'] ?? null,
            'department_name' => $employeeData['department_name'] ?? null,
            'sub_department_name' => $employeeData['sub_department_name'] ?? null,
            'basic_salary' => $employeeData['basic_salary'] ?? 0,
            'increment_active' => $employeeData['increment_active'] ?? false,
            'increment_value' => $employeeData['increment_value'] ?? null,
            'increment_effected_date' => $employeeData['increment_effected_date'] ?? null,
            'ot_morning' => $employeeData['ot_morning'] ?? 0,
            'ot_evening' => $employeeData['ot_evening'] ?? 0,
            'enable_epf_etf' => $employeeData['enable_epf_etf'] ?? false,
            'br1' => $employeeData['br1'] ?? false,
            'br2' => $employeeData['br2'] ?? false,
            'br_status' => $employeeData['br_status'] ?? '',
            'stamp' => $employeeData['stamp'] ?? false,
            'total_loan_amount' => $employeeData['total_loan_amount'] ?? 0,
            'installment_count' => $employeeData['installment_count'] ?? null,
            'installment_amount' => $employeeData['installment_amount'] ?? null,
            'approved_no_pay_days' => $employeeData['approved_no_pay_days'] ?? 0,
            'allowances' => $employeeData['allowances'] ?? [],
            'deductions' => $employeeData['deductions'] ?? [],
            'bonuses' => $employeeData['bonuses'] ?? [],
            'salary_breakdown' => $employeeData['salary_breakdown'] ?? [],
            'month' => $month,
            'year' => $year,
            'status' => 'processed',
        ];
    }

    private function findExistingSalaryProcess(string $empNo, $month, $year): ?salary_process
    {
        $monthKeys = [str_pad((string)(int)$month, 2, '0', STR_PAD_LEFT), (string)(int)$month, (int)$month];
        return salary_process::where('employee_no', $empNo)
            ->whereIn('month', $monthKeys)
            ->where('year', $year)
            ->first();
    }

    private function writeSalaryAudit(?salary_process $record, string $action, array $changes = []): void
    {
        if (!$record) {
            return;
        }
        try {
            SalaryProcessAudit::create([
                'salary_process_id' => $record->id,
                'user_id' => Auth::id(),
                'user_name' => Auth::user()?->name ?? 'System',
                'action' => $action,
                'changes' => $changes,
            ]);
        } catch (\Throwable $e) {
            // audit must not block payroll
        }
    }

    public function storeSalaryData(Request $request)
    {
        $validated = $request->validate([
            'data' => 'required|array',
            'data.*.emp_no' => 'required|string',
            'data.*.full_name' => 'required|string',
            'month' => 'sometimes|integer|between:1,12',
            'year' => 'sometimes|integer',
            'reprocess' => 'sometimes|boolean',
        ]);

        try {
            $month = (int) ($request->month ?? ($request->data[0]['month'] ?? date('n')));
            $year = (int) ($request->year ?? date('Y'));
            $reprocess = filter_var($request->input('reprocess', false), FILTER_VALIDATE_BOOLEAN);

            $created = [];
            $updated = [];
            $skipped = [];
            $blockedIssued = [];

            DB::beginTransaction();

            foreach ($request->data as $employeeData) {
                $empNo = (string) $employeeData['emp_no'];
                $existingRecord = $this->findExistingSalaryProcess($empNo, $month, $year);
                $payload = $this->buildSalaryProcessPayload($employeeData, $month, $year);

                if (!$existingRecord) {
                    $row = salary_process::create($payload);
                    $this->writeSalaryAudit($row, 'process', ['month' => $month, 'year' => $year]);
                    $created[] = $empNo;
                    continue;
                }

                $status = strtolower((string) $existingRecord->status);

                if ($status === 'issued') {
                    $blockedIssued[] = $empNo;
                    continue;
                }

                if (!$reprocess && in_array($status, ['processed', 'pending', 'hold'], true)) {
                    $skipped[] = $empNo;
                    continue;
                }

                // Revise / reprocess: overwrite calculated fields and set processed
                $before = [
                    'basic_salary' => $existingRecord->basic_salary,
                    'status' => $existingRecord->status,
                    'net_salary' => data_get(
                        is_string($existingRecord->salary_breakdown)
                            ? json_decode($existingRecord->salary_breakdown, true)
                            : ($existingRecord->salary_breakdown ?? []),
                        'net_salary'
                    ),
                ];

                $existingRecord->fill($payload);
                $existingRecord->status = 'processed';
                $existingRecord->save();

                $this->writeSalaryAudit($existingRecord, 'reprocess', [
                    'before' => $before,
                    'month' => $month,
                    'year' => $year,
                ]);
                $updated[] = $empNo;
            }

            DB::commit();

            $message = 'Salary data saved successfully';
            if ($reprocess) {
                $message = 'Salary revised and reprocessed successfully';
            }

            return response()->json([
                'message' => $message,
                'created' => $created,
                'updated' => $updated,
                'skipped' => $skipped,
                'blocked_issued' => $blockedIssued,
                'summary' => [
                    'created' => count($created),
                    'updated' => count($updated),
                    'skipped' => count($skipped),
                    'blocked_issued' => count($blockedIssued),
                ],
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Error saving salary data: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Unlock processed/hold salaries for a month so admin can revise & reprocess.
     * Issued records are blocked unless force_unissue=true (admin privileged).
     */
    public function unlockForRevision(Request $request)
    {
        $validated = $request->validate([
            'month' => 'required|integer|between:1,12',
            'year' => 'required|integer',
            'employee_nos' => 'sometimes|array',
            'employee_nos.*' => 'string',
            'force_unissue' => 'sometimes|boolean',
        ]);

        $month = (int) $validated['month'];
        $year = (int) $validated['year'];
        $forceUnissue = filter_var($request->input('force_unissue', false), FILTER_VALIDATE_BOOLEAN);
        $monthKeys = [str_pad((string)$month, 2, '0', STR_PAD_LEFT), (string)$month, $month];

        $query = salary_process::whereIn('month', $monthKeys)->where('year', $year);
        if (!empty($validated['employee_nos'])) {
            $query->whereIn('employee_no', $validated['employee_nos']);
        }

        $rows = $query->get();
        if ($rows->isEmpty()) {
            return response()->json(['message' => 'No salary records found for this period.', 'unlocked' => []], 404);
        }

        $unlocked = [];
        $blocked = [];

        DB::beginTransaction();
        try {
            foreach ($rows as $row) {
                $status = strtolower((string) $row->status);
                if ($status === 'issued' && !$forceUnissue) {
                    $blocked[] = $row->employee_no;
                    continue;
                }
                if (!in_array($status, ['processed', 'hold', 'pending', 'issued'], true)) {
                    continue;
                }

                $prev = $row->status;
                $row->status = 'pending';
                $row->save();
                $this->writeSalaryAudit($row, 'unlock', [
                    'from' => $prev,
                    'to' => 'pending',
                    'month' => $month,
                    'year' => $year,
                ]);
                $unlocked[] = $row->employee_no;
            }
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Unlock failed: ' . $e->getMessage()], 500);
        }

        return response()->json([
            'message' => count($unlocked)
                ? 'Salary records unlocked for revision. Recalculate and process again.'
                : 'No records were unlocked.',
            'unlocked' => $unlocked,
            'blocked_issued' => $blocked,
        ]);
    }

    public function markAsIssued(Request $request)
    {
        $validated = $request->validate([
            'month' => 'required|integer|between:1,12',
            'year' => 'required|integer',
            'employee_ids' => 'sometimes|array',
            'employee_nos' => 'sometimes|array',
        ]);

        $month = (int) $validated['month'];
        $year = (int) $validated['year'];
        $monthKeys = [str_pad((string)$month, 2, '0', STR_PAD_LEFT), (string)$month, $month];

        $query = salary_process::whereIn('month', $monthKeys)
            ->where('year', $year)
            ->where('status', 'processed');

        if (!empty($validated['employee_nos'])) {
            $query->whereIn('employee_no', $validated['employee_nos']);
        }
        if (!empty($validated['employee_ids'])) {
            $query->whereIn('employee_id', $validated['employee_ids']);
        }

        $salaryProcesses = $query->get();
        if ($salaryProcesses->isEmpty()) {
            return response()->json(['message' => 'No processed salaries found for this period.'], 404);
        }

        DB::beginTransaction();
        try {
            foreach ($salaryProcesses as $process) {
                $installmentCount = $process->installment_count;
                if ($installmentCount !== null) {
                    $loan = loans::where('employee_id', $process->employee_id)
                        ->where('status', 'active')
                        ->first();

                    if ($loan) {
                        $prevCount = (int) ($loan->installment_count ?? 0);
                        $newInstallmentCount = max(0, $prevCount - 1);
                        $loan->installment_count = $newInstallmentCount;
                        $loan->status = $newInstallmentCount == 0 ? 'completed' : 'active';
                        $loan->save();

                        if ($newInstallmentCount == 0) {
                            DB::table('completed_loans')->insert([
                                'employee_id' => $loan->employee_id,
                                'loan_id' => $loan->id,
                                'loan_amount' => $loan->loan_amount,
                                'interest_rate_per_annum' => $loan->interest_rate_per_annum,
                                'with_interest' => $loan->with_interest,
                                'installment_count' => $prevCount,
                                'end_date' => now()->toDateString(),
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);
                        }
                    }
                }

                $process->status = 'issued';
                $process->save();
                $this->writeSalaryAudit($process, 'issue', ['month' => $month, 'year' => $year]);
            }
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Error marking issued: ' . $e->getMessage()], 500);
        }

        return response()->json([
            'message' => 'Salaries marked as issued for the selected period.',
            'count' => $salaryProcesses->count(),
        ]);
    }

    // LEGACY helpers above; CSV download follows

    public function downloadSalaryCSV(Request $request)
    {
        $month = $request->query('month');
        $year = $request->query('year');

        $salaries = salary_process::where('month', $month)
            ->where('year', $year)
            ->get();

        $filename = "salary_process_{$month}_{$year}.csv";

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"$filename\"",
        ];

        $callback = function () use ($salaries) {
            $file = fopen('php://output', 'w');

            fputcsv($file, ['Employee No', 'Full Name', 'Company', 'Department', 'Basic Salary', 'Net Salary', 'Status', 'Month', 'Year']);

            foreach ($salaries as $salary) {
                fputcsv($file, [
                    $salary->employee_no,
                    $salary->full_name,
                    $salary->company_name,
                    $salary->department_name,
                    $salary->basic_salary,
                    data_get(json_decode($salary->salary_breakdown, true), 'net_salary', 0),
                    $salary->status,
                    $salary->month,
                    $salary->year,
                ]);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }


    public function updateSlaryStatus(Request $request)
    {
        DB::beginTransaction();

        try {
            // 1. Processed තත්ත්වයට පත් කිරීම
            if ($request->has('status') && $request->status == 'processed') {
                $salaryData = salary_process::where('status', 'pending')
                    ->orWhere('status', 'Unprocessed')
                    ->update([
                        'status' => 'processed',
                    ]);
                DB::commit();
                return response()->json(['message' => 'Salary status updated to processed', 'data' => $salaryData], 200);
            }

            // 2. Issued තත්ත්වයට පත් කිරීම සහ Loan Installments අඩු කිරීම
            if ($request->has('status') && $request->status == 'issued') {
                $salaryProcesses = salary_process::where('status', 'processed')->get();

                foreach ($salaryProcesses as $process) {
                    $installmentCount = $process->installment_count;

                    if ($installmentCount !== null) {
                        $loan = loans::where('employee_id', $process->employee_id)
                            ->where('status', 'active')
                            ->first();

                        if ($loan) {
                            $prevCount = (int)($loan->installment_count ?? 0);
                            $newInstallmentCount = max(0, $prevCount - 1);

                            $loan->installment_count = $newInstallmentCount;
                            $loan->status = $newInstallmentCount == 0 ? 'completed' : 'active';
                            $loan->save();

                            // if the loan end put the Loan Completed Loans
                            if ($newInstallmentCount == 0) {
                                DB::table('completed_loans')->insert([
                                    'employee_id' => $loan->employee_id,
                                    'loan_id' => $loan->id,
                                    'loan_amount' => $loan->loan_amount,
                                    'interest_rate_per_annum' => $loan->interest_rate_per_annum,
                                    'with_interest' => $loan->with_interest,
                                    'installment_count' => $prevCount,
                                    'end_date' => now()->toDateString(),
                                    'created_at' => now(),
                                    'updated_at' => now(),
                                ]);
                            }
                        }
                    }
                }

                // all  Processed are Issued
                salary_process::where('status', 'processed')->update([
                    'status' => 'issued',
                ]);

                DB::commit();
                return response()->json(['message' => 'Salaries marked as issued and loan installments updated'], 200);
            }

            DB::rollBack();
            return response()->json(['message' => 'Invalid status'], 400);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Error updating status: ' . $e->getMessage()], 500);
        }
    }
}
