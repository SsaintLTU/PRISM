<?php
abstract class ServerModes_Mode
{
    protected serverModes $plugin;
    protected string $key;
    protected array $config;

    public function __construct(serverModes $plugin, string $key, array $config = array())
    {
        $this->plugin = $plugin;
        $this->key = $key;
        $this->config = $config;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function applyConfig(array $config): void
    {
        $this->config = array_replace($this->config, $config);
    }

    public function onActivate(): void
    {
    }

    public function onDeactivate(): void
    {
    }

    public function onPlayerConnected(array &$player): void
    {
    }

    public function onPlayerDisconnected(array &$player): void
    {
    }

    public function onPlayerJoinRace(array &$player, IS_NPL $packet): void
    {
    }

    public function processMovement(array &$player, float $deltaKm, float $speedKph, CompCar $info): void
    {
    }

    public function onLapCompleted(array &$player, IS_LAP $lap): void
    {
    }

    public function onRaceResult(array &$player, IS_RES $result): void
    {
    }

    public function tick(array &$players): void
    {
    }

    protected function getNumber(string $key, float $default): float
    {
        if (!isset($this->config[$key])) {
            return $default;
        }

        return (float)$this->config[$key];
    }

    protected function markDirty(array &$player): void
    {
        $player['dirty'] = true;
    }
}
