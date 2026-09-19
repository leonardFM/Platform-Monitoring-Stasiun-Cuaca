<?php

namespace App\Console\Commands;

use App\Services\AggregateRecalculator;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'aggregates:recalculate')]
class RecalculateAggregates extends Command
{
    protected $description = 'Rebuild recent hour/day aggregates from base readings';

    public function handle(AggregateRecalculator $recalculator): int
    {
        $recalculator->recalculate();

        $this->info('station aggregates recalculated');

        return self::SUCCESS;
    }
}