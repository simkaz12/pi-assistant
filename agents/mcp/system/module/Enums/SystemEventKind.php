<?php

namespace App\Modules\System\Enums;

enum SystemEventKind: string
{
    case ProblemOpened = 'problem_opened';
    case ProblemResolved = 'problem_resolved';
    case Notice = 'notice';
    case PcOn = 'pc_on';
    case PcOff = 'pc_off';
}
