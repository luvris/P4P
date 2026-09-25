<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('hr:check-duplicates {--delete : Delete duplicate records}')]
#[Description('Check and optionally remove duplicate employment histories')]
class CheckDuplicateEmploymentHistories extends Command
{
    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Checking for duplicate employment histories...');

        // หา duplicate records
        $duplicates = DB::select("
            SELECT employee_id, serial_number, COUNT(*) as count 
            FROM employment_histories 
            GROUP BY employee_id, serial_number 
            HAVING count > 1
        ");

        if (empty($duplicates)) {
            $this->info('No duplicates found!');
            return 0;
        }

        $this->warn('Found ' . count($duplicates) . ' duplicate groups:');
        
        $totalDuplicates = 0;
        foreach ($duplicates as $dup) {
            $this->line("  - employee_id: {$dup->employee_id}, serial_number: {$dup->serial_number}, count: {$dup->count}");
            $totalDuplicates += ($dup->count - 1);
        }

        $this->warn("Total duplicate records to remove: {$totalDuplicates}");

        if ($this->option('delete')) {
            if (!$this->confirm('Do you want to delete these duplicates?')) {
                $this->info('Operation cancelled.');
                return 0;
            }

            $deleted = 0;
            foreach ($duplicates as $dup) {
                // เก็บ record ที่มี id เล็กที่สุด ลบที่เหลือ
                $ids = DB::table('employment_histories')
                    ->where('employee_id', $dup->employee_id)
                    ->where('serial_number', $dup->serial_number)
                    ->orderBy('id')
                    ->pluck('id')
                    ->toArray();

                // ลบทุก id ยกเว้นตัวแรก
                $idsToDelete = array_slice($ids, 1);
                if (!empty($idsToDelete)) {
                    DB::table('employment_histories')->whereIn('id', $idsToDelete)->delete();
                    $deleted += count($idsToDelete);
                }
            }

            $this->info("Deleted {$deleted} duplicate records.");
        }

        return 0;
    }
}
