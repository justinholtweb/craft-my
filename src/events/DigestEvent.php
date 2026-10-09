<?php

namespace justinholtweb\my\events;

use craft\events\CancelableEvent;

/**
 * Fired just before the MYOB sync summary is sent — scheduled or test.
 *
 * Change who gets it, retitle it, add to what the templates see, or set `isValid` to false to
 * stop it. A cancelled scheduled summary leaves the "last sent" marker where it was, so the next
 * run asks again.
 */
class DigestEvent extends CancelableEvent
{
    /** @var string[] */
    public array $recipients = [];

    public string $subject = '';

    /** @var array<string, mixed> What `_emails/digest.twig` and `_emails/digest.txt.twig` receive. */
    public array $variables = [];

    /** True for "Send a test summary now". */
    public bool $isTest = false;
}
