<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Employee;
use App\Models\Payroll;
use Illuminate\Support\Facades\DB;

class TestBankAccount extends Command
{
    protected $signature = 'test:bank-account';
    protected $description = 'Test bank account relationship';

    public function handle()
    {
        $this->info('Testing bank account relationship...');
        
        // Test 1: Payrolls data
        $this->line("\n--- Payrolls Data ---");
        $totalPayrolls = Payroll::count();
        $this->line("Total Payrolls: {$totalPayrolls}");
        
        $samplePayrolls = Payroll::take(5)->get(['id', 'citizen_id', 'bank_account']);
        foreach ($samplePayrolls as $p) {
            $this->line("  {$p->citizen_id} => {$p->bank_account}");
        }
        
        // Test 2: Employees data
        $this->line("\n--- Employees Data ---");
        $totalEmployees = Employee::count();
        $this->line("Total Employees: {$totalEmployees}");
        
        $sampleEmployees = Employee::take(5)->get(['id', 'citizen_id', 'first_name', 'last_name']);
        foreach ($sampleEmployees as $e) {
            $this->line("  {$e->citizen_id} => {$e->first_name} {$e->last_name}");
        }
        
        // Test 3: Check matching
        $this->line("\n--- Matching Check ---");
        $employeeWithPayroll = Employee::whereHas('latestPayroll')->first();
        if ($employeeWithPayroll) {
            $employeeWithPayroll->load('latestPayroll');
            $this->info("✓ Found matching: {$employeeWithPayroll->full_name}");
            $this->line("  Citizen ID: {$employeeWithPayroll->citizen_id}");
            $this->line("  Bank Account: {$employeeWithPayroll->bank_account_number}");
        } else {
            $this->warn("✗ No employees with matching payroll found");
        }
        
        // Test 4: Count matches
        $matchCount = Employee::whereHas('latestPayroll')->count();
        $this->line("\n--- Summary ---");
        $this->line("Employees with payroll data: {$matchCount} / {$totalEmployees}");
        
        return 0;
    }
}
