<?php

declare(strict_types=1);

namespace App\Ai\Guard;

enum ToolVerdict
{
    case Allow;
    case Ask;
    case Deny;
}
