<?php

namespace App\Enums;

enum GameStatus: string
{
    case Playing = 'playing';
    case Planned = 'planned';
    case Completed = 'completed';
    case Tried = 'tried';
    case Dropped = 'dropped';
}
