<?php

declare(strict_types=1);

// Every setting of this section, at its default. Delete a line to go back to the default.
return [
    // How many processes the "actors" server has; the keys are shared out among them. Every process that sends to actors reads it.
    'workers' => 2,

    // Seconds an actor's inbox may be empty before the actor ends; its next message starts it again.
    'idle' => 60,

    // The most messages waiting in one actor's inbox; more are dropped, and asks for them refused.
    'max_inbox' => 1000,
];
