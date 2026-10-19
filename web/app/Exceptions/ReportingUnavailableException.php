<?php

namespace App\Exceptions;

use RuntimeException;

class ReportingUnavailableException extends RuntimeException
{
    public function __construct(
        string $reason,
        public readonly ?string $stage = null,
        public readonly ?string $dataset = null,
        public readonly ?string $field = null,
        public readonly ?string $sqlState = null,
    ) {
        parent::__construct($reason);
    }

    public function diagnostics(): array
    {
        return array_filter([
            'reason' => $this->getMessage(), 'stage' => $this->stage,
            'dataset' => $this->dataset, 'field' => $this->field, 'sql_state' => $this->sqlState,
        ], fn ($value) => $value !== null);
    }
}
