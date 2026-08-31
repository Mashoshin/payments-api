<?php

declare(strict_types=1);

namespace Payments;

/**
 * Ledger недоступен или ответил не 201 → HTTP 502 {"error": "ledger unavailable"}.
 * Платёж при этом сохраняется со статусом failed.
 */
final class LedgerUnavailableException extends \RuntimeException
{
}
