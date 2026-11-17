<?php

namespace App\Enums;

enum GenerationStatus: string
{
    case Pending = 'pending';
    case Queued = 'queued';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Submitting',
            self::Queued => 'Queued',
            self::Processing => 'Processing',
            self::Succeeded => 'Succeeded',
            self::Failed => 'Failed',
        };
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Succeeded, self::Failed], true);
    }

    public function isInProgress(): bool
    {
        return ! $this->isFinal();
    }

    /**
     * Position in the lifecycle pending → queued → processing → succeeded|failed.
     * A generation may only move to a status with a higher rank.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Pending => 0,
            self::Queued => 1,
            self::Processing => 2,
            self::Succeeded, self::Failed => 3,
        };
    }

}
