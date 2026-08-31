<?php

declare(strict_types=1);

namespace Payments;

/**
 * Валидация входных данных согласно спецификации.
 *
 * Ошибка валидации выбрасывается как ValidationException,
 * которую роутер превращает в ответ 400 {"error": "..."}.
 */
final class Validator
{
    private const ACCOUNT_PATTERN = '/^acc_[a-z0-9_]+$/';

    /**
     * Проверить идентификатор счёта.
     */
    public static function account(mixed $value, string $field): string
    {
        if (!is_string($value) || preg_match(self::ACCOUNT_PATTERN, $value) !== 1) {
            throw new ValidationException(
                sprintf('%s must match ^acc_[a-z0-9_]+$', $field)
            );
        }

        return $value;
    }

    /**
     * Проверить сумму: целое число строго больше нуля (в копейках).
     */
    public static function amount(mixed $value): int
    {
        // json_decode отдаёт целые как int; float (например 100.5) отклоняем.
        if (!is_int($value)) {
            throw new ValidationException('amount must be an integer number of kopecks');
        }
        if ($value <= 0) {
            throw new ValidationException('amount must be greater than zero');
        }

        return $value;
    }
}
