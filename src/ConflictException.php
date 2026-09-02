<?php

declare(strict_types=1);

namespace Payments;

/**
 * Конфликт ключа идемпотентности → HTTP 409 {"error": "<сообщение>"}.
 *
 * Платёж с парой (from, client_oid) уже существует, но запрос пришёл
 * с другими to и/или amount.
 */
final class ConflictException extends \RuntimeException
{
}
