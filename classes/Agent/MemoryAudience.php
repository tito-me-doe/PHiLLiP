<?php
namespace Agent;

/** Explicit restricted audience; null continues to mean trusted application code. */
class MemoryAudience
{
    public $threadId;
    public function __construct($threadId = null) { $this->threadId = $threadId === null ? null : (int)$threadId; }
}
