<?php
abstract class ServerModes_Mode
{
    protected $plugin;
    protected $config = array();
    protected $key;

    public function __construct(serverModes $plugin, $key)
    {
        $this->plugin = $plugin;
        $this->key = $key;
    }

    public function getKey()
    {
        return $this->key;
    }

    public function activate(array $config, $hostName)
    {
        $this->config = $config;
        $this->onActivated($hostName);
    }

    protected function onActivated($hostName)
    {
        // Optional override by child classes
    }

    public function onPlayerConnected(array &$player)
    {
        // Optional override
    }

    public function onPlayerDisconnected(array &$player)
    {
        // Optional override
    }

    public function onCompCar(array &$player, $compCar, $deltaSeconds, $deltaDistance)
    {
        // Optional override
    }

    public function onLap(array &$player, IS_LAP $lap)
    {
        // Optional override
    }

    public function onPeriodic(array &$player, $now)
    {
        // Optional override
    }
}
