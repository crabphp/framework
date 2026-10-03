<?php

declare(strict_types=1);

namespace Crab\Engine\WebSocket;

enum MessageType: int
{
    case Text = 1;
    case Binary = 2;
}
