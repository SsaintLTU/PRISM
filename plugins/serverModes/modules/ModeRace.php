<?php
class ServerModes_ModeRace extends ServerModes_Mode
{
    public function __construct(serverModes $plugin, array $config = array())
    {
        parent::__construct($plugin, 'race', $config);
    }

    public function processMovement(array &$player, float $deltaKm, float $speedKph, CompCar $info): void
    {
        $rate = $this->getNumber('money_per_km', 9.0);
        $xpRate = $this->getNumber('xp_per_km', 0.3);

        $player['session']['money'] += $deltaKm * $rate;
        $player['session']['xp'] += $deltaKm * $xpRate;
        $this->markDirty($player);
    }

    public function onLapCompleted(array &$player, IS_LAP $lap): void
    {
        $lapBonus = $this->getNumber('lap_bonus', 20.0);
        $lapXp = $this->getNumber('lap_xp', 1.5);

        $player['session']['money'] += $lapBonus;
        $player['session']['xp'] += $lapXp;
        $this->markDirty($player);
    }
}
