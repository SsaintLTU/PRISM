<?php
class ServerModes_ModeRace extends ServerModes_Mode
{
    private ?ServerModes_RaceSystems $systems;

    public function __construct(serverModes $plugin, ?ServerModes_RaceSystems $systems, array $config = array())
    {
        parent::__construct($plugin, 'race', $config);
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

    public function onPlayerDisconnected(array &$player): void
    {
        if ($this->systems) {
            $this->systems->onPlayerDisconnected($player);
        }
    }

    public function onPlayerJoinRace(array &$player, IS_NPL $packet): void
    {
        if ($this->systems) {
            $this->systems->onPlayerJoinRace($player, $packet);
        }
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

        if ($this->systems) {
            $this->systems->onLapCompleted($player, $lap);
        }
    }

    public function onRaceResult(array &$player, IS_RES $result): void
    {
        if ($this->systems) {
            $this->systems->onRaceResult($result, $player['ucid'] ?? null);
        }
    }

    public function tick(array &$players): void
    {
        if ($this->systems && $this->systems->isActive()) {
            $this->systems->tick();
        }
    }
}
