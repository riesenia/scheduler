<?php
/**
 * This file is part of riesenia/scheduler package.
 *
 * Licensed under the MIT License
 * (c) RIESENIA.com
 */
declare(strict_types=1);

namespace Riesenia\Scheduler\Tests;

use PHPUnit\Framework\TestCase;
use Riesenia\Scheduler\Scheduler;
use Riesenia\Scheduler\SchedulerException;
use Riesenia\Scheduler\TermInterface;
use Riesenia\Scheduler\TermInvalidItemException;
use Riesenia\Scheduler\TermTrait;

class SchedulerTest extends TestCase
{
    public function testChecksMoreOverlapingTermsThanItems(): void
    {
        $term1 = new Term(new \DateTimeImmutable('2019-01-01 07:00:00'), new \DateTimeImmutable('2019-01-01 12:00:00'));
        $term2 = new Term(new \DateTimeImmutable('2019-01-01 13:00:00'), new \DateTimeImmutable('2019-01-01 16:00:00'), 1);
        $term3 = new Term(new \DateTimeImmutable('2019-01-01 08:00:00'), new \DateTimeImmutable('2019-01-01 14:00:00'));
        $term4 = new Term(new \DateTimeImmutable('2019-01-01 17:00:00'), new \DateTimeImmutable('2019-01-01 20:00:00'), 2);
        $term5 = new Term(new \DateTimeImmutable('2019-01-01 09:00:00'), new \DateTimeImmutable('2019-01-01 15:00:00'));

        $scheduler = new Scheduler([1, 2], [$term1, $term2, $term3, $term4]);
        $scheduler->addTerm($term5);

        $this->expectException(SchedulerException::class);
        $scheduler->schedule();
    }

    public function testChecksTwoOverlapingTermsLockedToSameItem(): void
    {
        $term1 = new Term(new \DateTimeImmutable('2019-01-01 07:00:00'), new \DateTimeImmutable('2019-01-01 12:00:00'));
        $term2 = new Term(new \DateTimeImmutable('2019-01-01 13:00:00'), new \DateTimeImmutable('2019-01-01 16:00:00'), 1);
        $term3 = new Term(new \DateTimeImmutable('2019-01-01 08:00:00'), new \DateTimeImmutable('2019-01-01 14:00:00'));
        $term4 = new Term(new \DateTimeImmutable('2019-01-01 17:00:00'), new \DateTimeImmutable('2019-01-01 20:00:00'), 2);
        $term5 = new Term(new \DateTimeImmutable('2019-01-01 19:00:00'), new \DateTimeImmutable('2019-01-01 21:00:00'), 2);

        $scheduler = new Scheduler([1, 2], [$term1, $term2, $term3, $term4]);
        $scheduler->addTerm($term5);

        $this->expectException(SchedulerException::class);
        $scheduler->schedule();
    }

    public function testChecksSolvableSchedule(): void
    {
        $term1 = new Term(new \DateTimeImmutable('2019-01-01 07:00:00'), new \DateTimeImmutable('2019-01-01 12:00:00'));
        $term2 = new Term(new \DateTimeImmutable('2019-01-01 13:00:00'), new \DateTimeImmutable('2019-01-01 16:00:00'), 1);
        $term3 = new Term(new \DateTimeImmutable('2019-01-01 08:00:00'), new \DateTimeImmutable('2019-01-01 14:00:00'));
        $term4 = new Term(new \DateTimeImmutable('2019-01-01 17:00:00'), new \DateTimeImmutable('2019-01-01 20:00:00'), 2);
        $term5 = new Term(new \DateTimeImmutable('2019-01-01 21:00:00'), new \DateTimeImmutable('2019-01-01 23:00:00'));

        $scheduler = new Scheduler([1, 2], [$term1, $term2, $term3, $term4]);
        $scheduler->addTerm($term5);
        $scheduler->schedule();

        $terms = $scheduler->getTerms();
        $this->assertCount(5, $terms);

        // Verify all terms have been assigned to an item
        foreach ($terms as $term) {
            $this->assertNotNull($term->getItemId(), 'Term should be assigned to an item');
            $this->assertContains($term->getItemId(), [1, 2], 'Term should be assigned to item 1 or 2');
        }

        // Verify locked terms are assigned correctly
        $this->assertEquals(1, $term2->getItemId());
        $this->assertEquals(2, $term4->getItemId());
    }

    public function testChecksComplexSolvableSchedule(): void
    {
        $term1 = new Term(new \DateTimeImmutable('2019-01-01 01:00:00'), new \DateTimeImmutable('2019-01-01 03:00:00'));
        $term2 = new Term(new \DateTimeImmutable('2019-01-01 01:00:00'), new \DateTimeImmutable('2019-01-01 03:00:00'));
        $term3 = new Term(new \DateTimeImmutable('2019-01-01 01:00:00'), new \DateTimeImmutable('2019-01-01 04:00:00'));
        $term4 = new Term(new \DateTimeImmutable('2019-01-01 01:00:00'), new \DateTimeImmutable('2019-01-01 05:00:00'));
        $term5 = new Term(new \DateTimeImmutable('2019-01-01 07:00:00'), new \DateTimeImmutable('2019-01-01 12:00:00'));
        $term6 = new Term(new \DateTimeImmutable('2019-01-01 04:00:00'), new \DateTimeImmutable('2019-01-01 06:00:00'));
        $term7 = new Term(new \DateTimeImmutable('2019-01-01 04:00:00'), new \DateTimeImmutable('2019-01-01 11:00:00'));
        $term8 = new Term(new \DateTimeImmutable('2019-01-01 13:00:00'), new \DateTimeImmutable('2019-01-01 18:00:00'));
        $term9 = new Term(new \DateTimeImmutable('2019-01-01 13:00:00'), new \DateTimeImmutable('2019-01-01 14:00:00'));
        $term10 = new Term(new \DateTimeImmutable('2019-01-01 08:00:00'), new \DateTimeImmutable('2019-01-01 10:00:00'));
        $term11 = new Term(new \DateTimeImmutable('2019-01-01 11:00:00'), new \DateTimeImmutable('2019-01-01 18:00:00'));
        $term12 = new Term(new \DateTimeImmutable('2019-01-01 06:00:00'), new \DateTimeImmutable('2019-01-01 08:00:00'));
        $term13 = new Term(new \DateTimeImmutable('2019-01-01 05:00:00'), new \DateTimeImmutable('2019-01-01 07:00:00'));
        $term14 = new Term(new \DateTimeImmutable('2019-01-01 16:00:00'), new \DateTimeImmutable('2019-01-01 23:00:00'));
        $term15 = new Term(new \DateTimeImmutable('2019-01-01 16:00:00'), new \DateTimeImmutable('2019-01-01 18:00:00'));
        $term16 = new Term(new \DateTimeImmutable('2019-01-01 20:00:00'), new \DateTimeImmutable('2019-01-01 23:00:00'));
        $term17 = new Term(new \DateTimeImmutable('2019-01-01 19:00:00'), new \DateTimeImmutable('2019-01-01 21:00:00'));
        $term18 = new Term(new \DateTimeImmutable('2019-01-01 22:00:00'), new \DateTimeImmutable('2019-01-01 23:00:00'));
        $term19 = new Term(new \DateTimeImmutable('2019-01-01 21:00:00'), new \DateTimeImmutable('2019-01-01 22:00:00'));

        $scheduler = new Scheduler([1, 2], [$term1, $term2, $term3, $term4]);
        $scheduler->addTerm($term5);
        $scheduler->addTerm($term6);
        $scheduler->addTerm($term7);
        $scheduler->addTerm($term8);
        $scheduler->addTerm($term9);
        $scheduler->addTerm($term10);
        $scheduler->addTerm($term11);
        $scheduler->addTerm($term12);
        $scheduler->addTerm($term13);
        $scheduler->addTerm($term14);
        $scheduler->addTerm($term15);
        $scheduler->addTerm($term16);
        $scheduler->addTerm($term17);
        $scheduler->addTerm($term18);
        $scheduler->addTerm($term19);
        $scheduler->addItem(3);
        $scheduler->addItem(4);

        $scheduler->schedule();

        $terms = $scheduler->getTerms();
        $this->assertCount(19, $terms);

        // Verify all terms have been assigned to an item
        foreach ($terms as $term) {
            $this->assertNotNull($term->getItemId(), 'Term should be assigned to an item');
            $this->assertContains($term->getItemId(), [1, 2, 3, 4], 'Term should be assigned to one of the 4 items');
        }
    }

    public function testThrowsExceptionForInvalidLockedItem(): void
    {
        $term1 = new Term(new \DateTimeImmutable('2019-01-01 07:00:00'), new \DateTimeImmutable('2019-01-01 12:00:00'));
        $term2 = new Term(new \DateTimeImmutable('2019-01-01 13:00:00'), new \DateTimeImmutable('2019-01-01 16:00:00'), 999);

        $scheduler = new Scheduler([1, 2], [$term1, $term2]);

        $this->expectException(TermInvalidItemException::class);
        $scheduler->schedule();
    }

    public function testThrowsExceptionForEmptyInputs(): void
    {
        $scheduler = new Scheduler([], []);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Set at least one item and term');
        $scheduler->schedule();
    }

    /**
     * Test case where greedy FAILS but backtracking SUCCEEDS.
     *
     * Scenario:
     * - Items: [1, 2]
     * - Term1: 13:00-14:00 (unlocked)
     * - Term2: 10:00-15:00 (unlocked)
     * - Term3: 10:00-12:00 (LOCKED to Item2)
     * - Term4: 15:00-16:00 (LOCKED to Item2)
     *
     * Greedy behavior:
     * 1. Processes locked terms first: Term3 → Item2, Term4 → Item2 ✓
     * 2. Then Term1 → Item1 (first available)
     * 3. Finally Term2 cannot be assigned
     *
     * Backtracking solution:
     * TODO?
     */
    public function testBacktrackingSolvesGreedyFailure(): void
    {
        $term1 = new Term(new \DateTimeImmutable('2019-01-01 13:00:00'), new \DateTimeImmutable('2019-01-01 14:00:00'));
        $term2 = new Term(new \DateTimeImmutable('2019-01-01 10:00:00'), new \DateTimeImmutable('2019-01-01 15:00:00'));
        $term3 = new Term(new \DateTimeImmutable('2019-01-01 10:00:00'), new \DateTimeImmutable('2019-01-01 12:00:00'), 2); // locked to Item2
        $term4 = new Term(new \DateTimeImmutable('2019-01-01 15:00:00'), new \DateTimeImmutable('2019-01-01 16:00:00'), 2); // locked to Item2

        $scheduler = new Scheduler([1, 2], [$term1, $term2, $term3, $term4]);
        $scheduler->schedule();

        $terms = $scheduler->getTerms();
        $this->assertCount(4, $terms);

        // Verify all terms have been assigned to an item
        foreach ($terms as $term) {
            $this->assertNotNull($term->getItemId(), 'Term should be assigned to an item');
            $this->assertContains($term->getItemId(), [1, 2], 'Term should be assigned to item 1 or 2');
        }

        // Verify locked terms are assigned correctly
        $this->assertEquals(2, $term3->getItemId());
        $this->assertEquals(2, $term4->getItemId());
    }
}

/**
 * Simple Term implementation for testing.
 */
class Term implements TermInterface
{
    use TermTrait;

    private \DateTimeInterface $from;
    private \DateTimeInterface $to;
    private ?int $lockedId;

    public function __construct(\DateTimeInterface $from, \DateTimeInterface $to, ?int $lockedId = null)
    {
        $this->from = $from;
        $this->to = $to;
        $this->lockedId = $lockedId;
    }

    public function getFrom(): \DateTimeInterface
    {
        return $this->from;
    }

    public function getTo(): \DateTimeInterface
    {
        return $this->to;
    }

    public function getLockedId(): ?int
    {
        return $this->lockedId;
    }
}
