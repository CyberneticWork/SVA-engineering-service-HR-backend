<?php

namespace App\Services;

use App\Models\PendingPayment;
use App\Models\SalaryAdvanceRequest;
use App\Models\employee;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class SalaryAdvanceService
{
    public static function quota(employee $employee): array
    {
        $employee->loadMissing('compensation');
        $cfg = CompanyProcessSettings::packConfig($employee, 'salary_advance');
        $percent = (float) ($cfg['percent'] ?? 50);
        if ($percent <= 0) {
            $percent = 50;
        }
        $basic = (float) ($employee->compensation->basic_salary ?? 0);
        $cap = round($basic * $percent / 100, 2);

        $used = 0.0;
        if (Schema::hasTable('salary_advance_requests')) {
            $monthStart = now()->startOfMonth()->toDateString();
            $monthEnd = now()->endOfMonth()->toDateString();
            $requests = SalaryAdvanceRequest::where('employee_id', $employee->id)
                ->whereIn('status', ['PENDING', 'APPROVED'])
                ->where(function ($q) use ($monthStart, $monthEnd) {
                    $q->whereBetween('needed_on', [$monthStart, $monthEnd])
                        ->orWhere(function ($q2) use ($monthStart, $monthEnd) {
                            $q2->whereNull('needed_on')
                                ->whereBetween('created_at', [$monthStart . ' 00:00:00', $monthEnd . ' 23:59:59']);
                        });
                })
                ->get();

            $paidIds = [];
            if (Schema::hasTable('pending_payments')) {
                $paidIds = PendingPayment::where('source_type', 'salary_advance')
                    ->where('status', 'PAID')
                    ->whereIn('source_id', $requests->pluck('id'))
                    ->pluck('source_id')
                    ->all();
            }

            $used = (float) $requests->reject(fn ($row) => in_array($row->id, $paidIds, true))->sum('amount');
        }

        return [
            'basic_salary' => round($basic, 2),
            'percent' => $percent,
            'cap' => $cap,
            'used' => round($used, 2),
            'available' => max(0, round($cap - $used, 2)),
        ];
    }

    public static function normalizeDeductFrom(?string $value, string $fallback = 'bonus'): string
    {
        $v = strtolower(trim((string) $value));
        if ($v === 'basic') {
            return 'basic';
        }
        if ($v === 'bonus') {
            return 'bonus';
        }

        return strtolower(trim($fallback)) === 'basic' ? 'basic' : 'bonus';
    }

    private static bool $columnsEnsured = false;

    /**
     * Servers that skipped `php artisan migrate` silently dropped the basic/bonus choice,
     * so add the deduct_from columns on demand. Returns whether salary advances can store it.
     */
    public static function ensureDeductFromColumns(): bool
    {
        if (!self::$columnsEnsured) {
            self::$columnsEnsured = true;
            try {
                if (Schema::hasTable('salary_advance_requests')) {
                    $missingFrom = !Schema::hasColumn('salary_advance_requests', 'deduct_from');
                    $missingSource = !Schema::hasColumn('salary_advance_requests', 'source');
                    if ($missingFrom || $missingSource) {
                        Schema::table('salary_advance_requests', function (Blueprint $table) use ($missingFrom, $missingSource) {
                            if ($missingFrom) {
                                $table->string('deduct_from', 20)->nullable();
                            }
                            if ($missingSource) {
                                $table->string('source', 20)->nullable();
                            }
                        });
                    }
                }
                foreach (['employee_deductions', 'deductions'] as $table) {
                    if (Schema::hasTable($table) && !Schema::hasColumn($table, 'deduct_from')) {
                        Schema::table($table, function (Blueprint $t) {
                            $t->string('deduct_from', 20)->nullable();
                        });
                    }
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return Schema::hasTable('salary_advance_requests')
            && Schema::hasColumn('salary_advance_requests', 'deduct_from');
    }

    public static function applyHrDeductFrom(SalaryAdvanceRequest $row, ?string $choice = null): void
    {
        if (!self::ensureDeductFromColumns()) {
            return;
        }
        $row->loadMissing('employee.organizationAssignment.company');
        $fallback = 'bonus';
        if ($row->employee) {
            $fallback = CompanyProcessSettings::salaryAdvanceHrDeductFrom($row->employee);
        }
        $row->deduct_from = self::normalizeDeductFrom($choice ?? $row->deduct_from, $fallback);
    }

    public static function isNamedAdvanceDeduction(?string $name): bool
    {
        $n = strtolower(trim((string) $name));
        if ($n === '') {
            return false;
        }

        $compact = preg_replace('/[\s_\-]+/', '', $n);
        if (in_array($compact, ['advance', 'salaryadvance', 'advancesalary'], true)) {
            return true;
        }

        return str_contains($n, 'salary advance')
            || str_contains($n, 'salary_advance')
            || str_contains($n, 'advance salary')
            || str_contains($n, 'advance_salary')
            || (bool) preg_match('/\badvance\b/', $n);
    }

    public static function isAdvanceDeduction($deduction): bool
    {
        if (!$deduction) {
            return false;
        }

        $from = strtolower(trim((string) ($deduction->deduct_from ?? '')));
        if ($from === 'basic' || $from === 'bonus') {
            return true;
        }

        return self::isNamedAdvanceDeduction($deduction->deduction_name ?? null)
            || self::isNamedAdvanceDeduction($deduction->deduction_code ?? null)
            || self::isNamedAdvanceDeduction($deduction->category ?? null);
    }

    /**
     * Approved HR-path advances (HR created, or HR approved) for the payroll month.
     *
     * @return array{basic: float, bonus: float, total: float}
     */
    public static function monthPayrollDeductions(int $employeeId, int $year, int $month): array
    {
        $empty = ['basic' => 0.0, 'bonus' => 0.0, 'total' => 0.0];
        if (!Schema::hasTable('salary_advance_requests') || $employeeId < 1) {
            return $empty;
        }

        $start = sprintf('%04d-%02d-01', $year, $month);
        $end = date('Y-m-t', strtotime($start));
        $q = SalaryAdvanceRequest::query()
            ->where('employee_id', $employeeId)
            ->where('status', 'APPROVED')
            ->where(function ($w) use ($start, $end) {
                $w->whereBetween('needed_on', [$start, $end])
                    ->orWhere(function ($w2) use ($start, $end) {
                        $w2->whereNull('needed_on')
                            ->whereBetween('created_at', [$start . ' 00:00:00', $end . ' 23:59:59']);
                    });
            });

        $basic = 0.0;
        $bonus = 0.0;
        $cols = ['amount'];
        if (Schema::hasColumn('salary_advance_requests', 'deduct_from')) {
            $cols[] = 'deduct_from';
        }
        foreach ($q->get($cols) as $row) {
            $amt = (float) $row->amount;
            if (self::normalizeDeductFrom($row->deduct_from ?? null) === 'basic') {
                $basic += $amt;
            } else {
                $bonus += $amt;
            }
        }

        return [
            'basic' => round($basic, 2),
            'bonus' => round($bonus, 2),
            'total' => round($basic + $bonus, 2),
        ];
    }
}
