<?php

declare(strict_types=1);

namespace Payments;

/**
 * Форматирование денег. Внутри — целые копейки; наружу (в текст уведомления) —
 * рубли с двумя знаками. Без float, чтобы не терять точность.
 */
final class Money
{
    /**
     * 100000 (копейки) → "1000.00" (рубли).
     */
    public static function kopecksToRubles(int $kopecks): string
    {
        $rubles = intdiv($kopecks, 100);
        $remainder = $kopecks % 100;

        return sprintf('%d.%02d', $rubles, $remainder);
    }
}
