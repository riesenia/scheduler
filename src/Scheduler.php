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
     * Schedule terms on items.
     */
    public function schedule()
    {
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

        // remaining terms
        foreach ($this->terms as $term) {
            if ($term->getItemId() !== null) {
                continue;
            }

            $conflicts = [$term];

            // check already occupied terms for all items
            foreach ($this->items as $id => $items) {
                $isConflict = false;

                foreach ($items as $occupied) {
                    if ($isConflict = $this->checkConflict($term, $occupied)) {
                        $conflicts[] = $occupied;

                        break;
                    }
                }

                if (!$isConflict) {
                    $this->setTermItem($term, $id);

                    break;
                }
            }

            if ($isConflict) {
                $e = new SchedulerException('Conflict in terms');
                $e->setConflictingTerms($conflicts);

                throw $e;
            }
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
}
