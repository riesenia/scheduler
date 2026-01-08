<?php
/**
 * This file is part of riesenia/scheduler package.
 *
 * Licensed under the MIT License
 * (c) RIESENIA.com
 */
declare(strict_types=1);

namespace Riesenia\Scheduler;

class SchedulerException extends \Exception
{
    /** @var TermInterface[] */
    private $conflictingTerms = [];

    /**
     * Set conflicting terms.
     *
     * @param TermInterface[] $terms
     */
    public function setConflictingTerms(array $terms)
    {
        $this->conflictingTerms = $terms;
    }

    /**
     * Get conflicting terms.
     *
     * @return TermInterface[]
     */
    public function getConflictingTerms(): array
    {
        return $this->conflictingTerms;
    }
}
