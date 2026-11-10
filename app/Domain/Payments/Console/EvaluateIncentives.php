<?php

namespace App\Domain\Payments\Console;

use App\Domain\Earnings\Incentives\IncentiveEngine;
use App\Domain\Earnings\Models\IncentiveProgram;
use Illuminate\Console\Command;

class EvaluateIncentives extends Command
{
    protected $signature = 'money:evaluate-incentives';

    protected $description = 'Evaluate active incentive programmes against completed trips and award what was earned';

    public function handle(IncentiveEngine $engine): int
    {
        $awarded = 0;

        IncentiveProgram::running()->get()->each(function (IncentiveProgram $program) use ($engine, &$awarded) {
            if ($program->ends_at !== null && $program->ends_at->lt(now()->subWeeks(5))) {
                $program->forceFill(['status' => 'ended'])->save();

                return;
            }

            $awarded += $engine->evaluate($program)['awarded'];
        });

        $this->line("awarded {$awarded}");

        return self::SUCCESS;
    }
}
