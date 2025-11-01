<?php
class ServerModes_ModeDrift extends ServerModes_Mode
{
    private ?ServerModes_DriftSystems $systems;

    public function __construct(serverModes $plugin, ?ServerModes_DriftSystems $systems, array $config = array())
    {
        parent::__construct($plugin, 'drift', $config);
        $this->systems = $systems;
    }

    public function onActivate(): void
    {
        if ($this->systems) {
            $this->systems->applyConfig($this->config);
            $this->systems->onActivate();
        }
    }

    public function onDeactivate(): void
    {
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

    public function onPlayerDisconnected(array &$player): void
    {
        if ($this->systems) {
            $this->systems->onPlayerDisconnected($player);
        }
    }

    public function processMovement(array &$player, float $deltaKm, float $speedKph, CompCar $info): void
    {
        $rate = $this->getNumber('money_per_km', 5.0);
        $xpRate = $this->getNumber('xp_per_km', 0.4);
        $angleBonus = $this->getNumber('angle_bonus', 1.4);
        $threshold = max(1.0, $this->getNumber('angle_threshold', 35.0));

        $heading = ($info->Heading * (360.0 / 65536.0));
        $direction = ($info->Direction * (360.0 / 65536.0));
        $slip = abs($heading - $direction);
        if ($slip > 180.0) {
            $slip = 360.0 - $slip;
        }

        $intensity = min(1.0, $slip / $threshold);
        $bonus = 1.0 + ($angleBonus - 1.0) * $intensity;

        $player['session']['money'] += $deltaKm * $rate * $bonus;
        $player['session']['xp'] += $deltaKm * $xpRate * $bonus;
        $this->markDirty($player);

        if ($this->systems) {
            $this->systems->onDriftSample($player, $deltaKm, $speedKph, $slip, $info);
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
