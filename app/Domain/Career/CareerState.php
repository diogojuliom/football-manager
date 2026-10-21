<?php

namespace App\Domain\Career;

enum CareerState: string
{
    case AwaitingDecision = 'awaiting_decision';
    case Processing = 'processing';
    case PreMatch = 'pre_match';
    case AwaitingMatch = 'awaiting_match';
    case AwaitingSeason = 'awaiting_season';
}
