<?php
class ServerModes_ModeCruise extends ServerModes_Mode
{
    public function __construct(serverModes $plugin, array $config = array())
    {
        parent::__construct($plugin, 'cruise', $config);
    }

    public function processMovement(array &$player, float $deltaKm, float $speedKph, CompCar $info): void
    {
        $rate = $this->getNumber('money_per_km', 7.5);
        $xpRate = $this->getNumber('xp_per_km', 0.25);
        $speedLimit = $this->getNumber('speed_limit', 0.0);
        $penaltyMultiplier = $this->getNumber('penalty_multiplier', 0.5);

        $multiplier = 1.0;
        if ($speedLimit > 0 && $speedKph > $speedLimit) {
            $multiplier = max(0.0, min(1.0, $penaltyMultiplier));
        }

        $player['session']['money'] += $deltaKm * $rate * $multiplier;
        $player['session']['xp'] += $deltaKm * $xpRate;
        $this->markDirty($player);
    }
}
