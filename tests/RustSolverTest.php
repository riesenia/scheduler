<?php
declare(strict_types=1);

namespace Riesenia\Scheduler\Tests;

use Riesenia\Scheduler\Scheduler;

class RustSolverTest extends SchedulerTest
{
    private static string $solverBinary = __DIR__ . '/../solver/target/release/scheduler-solver';

    public static function setUpBeforeClass(): void
    {
        if (!\file_exists(self::$solverBinary)) {
            self::markTestSkipped('Rust solver binary not found. Run: cd solver && cargo build --release');
        }
    }

    protected function createScheduler(array $items, array $terms): Scheduler
    {
        $scheduler = new Scheduler($items, $terms);
        $scheduler->setSolverBinary(self::$solverBinary);

        return $scheduler;
    }
}
