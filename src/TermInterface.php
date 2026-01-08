<?php
/**
 * This file is part of riesenia/scheduler package.
 *
 * Licensed under the MIT License
 * (c) RIESENIA.com
 */
declare(strict_types=1);

namespace Riesenia\Scheduler;

interface TermInterface
{
    /**
     * Get event starting date.
     */
    public function getFrom(): \DateTimeInterface;

    /**
     * Get event ending date.
     */
    public function getTo(): \DateTimeInterface;

    /**
     * Check if event is locked for specific item.
     */
    public function getLockedId(): ?int;

    /**
     * Set event to item.
     */
    public function setItemId(?int $id);

    /**
     * Get event item.
     */
    public function getItemId(): ?int;
}
