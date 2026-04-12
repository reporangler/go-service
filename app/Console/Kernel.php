<?php

namespace App\Console;

use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected function schedule(\Illuminate\Console\Scheduling\Schedule $schedule): void
    {
    }

    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');
    }
}
