<?php

namespace SocialDept\AtpSignals\Enums;

enum SignalEventType: string
{
    case Commit = 'commit';
    case Identity = 'identity';
    case Account = 'account';
    case Sync = 'sync';
}
