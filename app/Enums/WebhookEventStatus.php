<?php

namespace App\Enums;

enum WebhookEventStatus: string
{
    case Pending = 'pending';
    case Processed = 'processed';
    case Ignored = 'ignored';
    case Failed = 'failed';

    public function isHandled(): bool
    {
        return in_array($this, [self::Processed, self::Ignored], true);
    }
}
