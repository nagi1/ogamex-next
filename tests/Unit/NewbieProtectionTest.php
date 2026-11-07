<?php

namespace Tests\Unit;

use OGame\Services\PlayerService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Newbie protection follows the live servers' bands (serverData.xml newbieProtectionHigh 50,000 and
 * newbieProtectionLimit 500,000): 1:5 below the high mark, 1:10 up to the limit, none above.
 */
class NewbieProtectionTest extends TestCase
{
    /**
     * @return array<string, array{float, float, bool}>
     */
    public static function bands(): array
    {
        return [
            'small player, more than 5 times stronger attacker' => [10_000, 50_001, true],
            'small player, exactly 5 times stronger attacker' => [10_000, 50_000, false],
            'mid player, 6 times stronger attacker' => [100_000, 600_000, false],
            'mid player, more than 10 times stronger attacker' => [100_000, 1_000_001, true],
            'player at the limit' => [500_000, 1_000_000_000, false],
            'unknown attacker score' => [10_000, 0, false],
        ];
    }

    #[DataProvider('bands')]
    public function testProtectionBands(float $weak, float $strong, bool $protected): void
    {
        $this->assertSame($protected, PlayerService::protectsNewbie($weak, $strong));
    }
}
