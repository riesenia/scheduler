<?php
/**
 * This file is part of riesenia/scheduler package.
 *
 * Licensed under the MIT License
 * (c) RIESENIA.com
 */
declare(strict_types=1);

namespace Riesenia\Scheduler;

class Scheduler
{
    /** @var array<int,TermInterface[]> */
    protected $items = [];

    /** @var TermInterface[] */
    protected $terms = [];

    /** @var string|null */
    protected $solverBinary;

    /**
     * Create scheduler.
     *
     * @param int[]           $items
     * @param TermInterface[] $terms
     */
    public function __construct(iterable $items, iterable $terms)
    {
        foreach ($items as $item) {
            $this->addItem($item);
        }

        foreach ($terms as $term) {
            $this->addTerm($term);
        }
    }

    /**
     * Set path to the external Rust solver binary.
     *
     * @return $this
     */
    public function setSolverBinary(string $path): self
    {
        $this->solverBinary = $path;

        return $this;
    }

    /**
     * Schedule terms on items.
     */
    public function schedule()
    {
        if ($this->solverBinary !== null) {
            $this->solveExternal();

            return;
        }

        if (!$this->items || !$this->terms) {
            throw new \InvalidArgumentException('Set at least one item and term');
        }

        // set locked terms first
        foreach ($this->terms as $term) {
            if ($term->getLockedId() === null) {
                continue;
            }

            $id = $term->getLockedId();

            // locked to not existing item
            if (!isset($this->items[$id])) {
                $e = new TermInvalidItemException('Term locked to unknown item: ' . $id);
                $e->setTerm($term);
                $e->setItem($id);

                throw $e;
            }

            // check terms already added to this item
            foreach ($this->items[$id] as $occupied) {
                if ($this->checkConflict($term, $occupied)) {
                    $e = new SchedulerException('Conflict in terms for item ' . $id);
                    $e->setConflictingTerms([$term, $occupied]);

                    throw $e;
                }
            }

            $this->setTermItem($term, $id);
        }

        // remaining terms - use backtracking
        $unlockedTerms = [];

        foreach ($this->terms as $term) {
            if ($term->getItemId() === null) {
                $unlockedTerms[] = $term;
            }
        }

        // Sort by duration (shortest first) - shorter terms are typically more constrained
        \usort($unlockedTerms, function (TermInterface $a, TermInterface $b) {
            return ($a->getTo()->getTimestamp() - $a->getFrom()->getTimestamp()) <=> ($b->getTo()->getTimestamp() - $b->getFrom()->getTimestamp());
        });

        if (!empty($unlockedTerms) && !$this->backtrackSchedule($unlockedTerms, 0)) {
            // collect all conflicting terms for error message
            $conflicts = $this->collectConflicts($unlockedTerms);
            $e = new SchedulerException('Conflict in terms');
            $e->setConflictingTerms($conflicts);

            throw $e;
        }
    }

    /**
     * Add item.
     */
    public function addItem(int $id)
    {
        if (!isset($this->items[$id])) {
            $this->items[$id] = [];
        }
    }

    /**
     * Add term.
     */
    public function addTerm(TermInterface $term)
    {
        // reset
        $term->setItemId(null);
        $this->terms[] = $term;
    }

    /**
     * Get terms.
     *
     * @return TermInterface[]
     */
    public function getTerms(): array
    {
        return $this->terms;
    }

    /**
     * Check if two terms overlap.
     */
    private function checkConflict(TermInterface $term1, TermInterface $term2): bool
    {
        return $term1->getFrom() <= $term2->getTo() && $term2->getFrom() <= $term1->getTo();
    }

    /**
     * Assign a term to an item.
     */
    private function setTermItem(TermInterface $term, int $id)
    {
        $this->items[$id][] = $term;
        $term->setItemId($id);
    }

    /**
     * Remove a term assignment (for backtracking).
     */
    private function unsetTermItem(TermInterface $term, int $id)
    {
        $this->items[$id] = \array_filter($this->items[$id], function ($t) use ($term) {
            return $t !== $term;
        });

        // clear term's item assignment
        $term->setItemId(null);
    }

    /**
     * Recursively assign terms to items using backtracking.
     *
     * @param TermInterface[] $unlockedTerms
     */
    private function backtrackSchedule(array $unlockedTerms, int $termIndex): bool
    {
        // all terms assigned
        if ($termIndex >= \count($unlockedTerms)) {
            return true;
        }

        $term = $unlockedTerms[$termIndex];

        // try assigning to each item
        foreach ($this->items as $itemId => $occupiedTerms) {
            // check if term conflicts with any term on this item
            $hasConflict = false;

            foreach ($occupiedTerms as $occupied) {
                if ($this->checkConflict($term, $occupied)) {
                    $hasConflict = true;

                    break;
                }
            }

            if (!$hasConflict) {
                // try this assignment
                $this->setTermItem($term, $itemId);

                // forward checking: verify remaining terms still have valid options
                if ($this->hasValidOptionsForRemaining($unlockedTerms, $termIndex + 1)) {
                    // recursively assign remaining terms
                    if ($this->backtrackSchedule($unlockedTerms, $termIndex + 1)) {
                        return true;
                    }
                }

                // backtrack: undo assignment
                $this->unsetTermItem($term, $itemId);
            }
        }

        // no valid assignment found
        return false;
    }

    /**
     * Check if a term has at least one valid item.
     */
    private function hasValidItem(TermInterface $term): bool
    {
        foreach ($this->items as $occupiedTerms) {
            $hasConflict = false;

            foreach ($occupiedTerms as $occupied) {
                if ($this->checkConflict($term, $occupied)) {
                    $hasConflict = true;

                    break;
                }
            }

            if (!$hasConflict) {
                return true;
            }
        }

        return false;
    }

    /**
     * Forward checking: verify all remaining terms have at least one valid option.
     *
     * @param TermInterface[] $terms
     */
    private function hasValidOptionsForRemaining(array $terms, int $fromIndex): bool
    {
        $count = \count($terms);

        for ($i = $fromIndex; $i < $count; ++$i) {
            if (!$this->hasValidItem($terms[$i])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Collect conflicting terms when no solution found.
     *
     * @param TermInterface[] $unlockedTerms
     *
     * @return TermInterface[]
     */
    private function collectConflicts(array $unlockedTerms): array
    {
        // find the first term that couldn't be assigned
        foreach ($unlockedTerms as $term) {
            if ($term->getItemId() !== null) {
                continue;
            }

            $conflicts = [$term];

            // collect all terms this conflicts with
            foreach ($this->items as $itemId => $occupiedTerms) {
                foreach ($occupiedTerms as $occupied) {
                    if ($this->checkConflict($term, $occupied)) {
                        $conflicts[] = $occupied;
                    }
                }
            }

            return $conflicts;
        }

        // fallback: return first term
        return isset($unlockedTerms[0]) ? [$unlockedTerms[0]] : [];
    }

    /**
     * Delegate scheduling to the external Rust solver binary.
     */
    private function solveExternal()
    {
        if (!$this->items || !$this->terms) {
            throw new \InvalidArgumentException('Set at least one item and term');
        }

        $input = [
            'items' => \array_map('intval', \array_keys($this->items)),
            'terms' => []
        ];

        foreach ($this->terms as $index => $term) {
            $input['terms'][] = [
                'id' => $index,
                'from' => $term->getFrom()->getTimestamp(),
                'to' => $term->getTo()->getTimestamp(),
                'locked_id' => $term->getLockedId()
            ];
        }

        $process = \proc_open(
            $this->solverBinary,
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes
        );

        if (!\is_resource($process)) {
            throw new \RuntimeException('Failed to start solver: ' . $this->solverBinary);
        }

        \fwrite($pipes[0], \json_encode($input));
        \fclose($pipes[0]);

        $stdout = \stream_get_contents($pipes[1]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);

        $exitCode = \proc_close($process);

        if ($exitCode !== 0 || $stdout === false || $stdout === '') {
            throw new \RuntimeException('Solver failed with exit code ' . $exitCode);
        }

        $result = \json_decode($stdout, true);

        if ($result === null) {
            throw new \RuntimeException('Solver returned invalid JSON');
        }

        switch ($result['status']) {
            case 'ok':
                foreach ($result['assignments'] as $assignment) {
                    $term = $this->terms[$assignment['term_id']];
                    $itemId = $assignment['item_id'];
                    $this->setTermItem($term, $itemId);
                }

                break;

            case 'invalid_item':
                $e = new TermInvalidItemException($result['message']);
                $e->setTerm($this->terms[$result['term_id']]);
                $e->setItem($result['item_id']);

                throw $e;

            case 'conflict':
                $e = new SchedulerException($result['message']);
                $conflicting = \array_map(function ($id) {
                    return $this->terms[$id];
                }, $result['conflicts']);
                $e->setConflictingTerms($conflicting);

                throw $e;

            default:
                throw new \RuntimeException('Unknown solver status: ' . $result['status']);
        }
    }
}
