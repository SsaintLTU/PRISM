<?php
class ServerModes_ModeCruise extends ServerModes_Mode
{
    private ?ServerModes_CruiseSystems $systems;

    public function __construct(serverModes $plugin, ?ServerModes_CruiseSystems $systems, array $config = array())
    {
        parent::__construct($plugin, 'cruise', $config);
        $this->systems = $systems;
    }

    public function onActivate(): void
    {
        parent::onActivate();

        if ($this->systems) {
            $this->systems->applyConfig($this->config);
            $this->systems->onActivate();
        }
    }

    public function onDeactivate(): void
    {
        parent::onDeactivate();

        if ($this->systems) {
            $this->systems->onDeactivate();
        }
    }

    public function onPlayerConnected(array &$player): void
    {
        if ($this->systems) {
            $this->systems->onPlayerConnected($player);
        }
    }

    public function onClientInfo(array &$player, IS_NCI $packet): void
    {
        if ($this->systems) {
            $this->systems->onClientInfo($player, $packet);
        }
    }

    public function onPlayerDisconnected(array &$player): void
    {
        if ($this->systems) {
            $this->systems->onPlayerDisconnected($player);
        }
    }

    public function onCarStateChange(array &$player, IS_CSC $packet): void
    {
        if ($this->systems) {
            $this->systems->onCarStateChange($player, $packet);
        }
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

        $earnedMoney = $deltaKm * $rate * $multiplier;
        $earnedXp = $deltaKm * $xpRate;

        $player['session']['money'] += $earnedMoney;
        $player['session']['xp'] += $earnedXp;
        $this->markDirty($player);

        if ($this->systems) {
            $this->systems->onMovementReward($player, $deltaKm, $speedKph, $earnedMoney, $earnedXp, $info);
        }
    }

    public function onLapCompleted(array &$player, IS_LAP $lap): void
    {
        if ($this->systems) {
            $this->systems->onLapCompleted($player, $lap);
        }
    }

    public function tick(array &$players): void
    {
        if ($this->systems) {
            $this->systems->tick($players);
        }
    }
}
