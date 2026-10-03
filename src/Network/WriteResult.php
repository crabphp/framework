<?php

declare(strict_types=1);

namespace Crab\Engine\Network;

enum WriteResult
{
    case Accepted;
    case Backpressured;
    case Closed;
}
