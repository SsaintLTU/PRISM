<?php
class ServerModes_CruiseMode extends ServerModes_Mode
{
    protected function onActivated($hostName)
    {
        console(sprintf('serverModes: Cruise mode activated for host "%s"', $hostName));
    }

    public function onCompCar(array &$player, $compCar, $deltaSeconds, $deltaDistance)
    {
        if ($deltaDistance <= 0) {
            return;
        }

        $rate = isset($this->config['credits_per_km']) ? (float)$this->config['credits_per_km'] : 6.5;
        $distanceKm = $deltaDistance / 1000.0;
        $earned = $distanceKm * $rate;

        if ($earned <= 0) {
            return;
        }

        if (!isset($player['session_stats']['currency'])) {
            $player['session_stats']['currency'] = 0.0;
        }

        $player['session_stats']['currency'] += $earned;
        $player['dirty'] = true;
    }

    public function onPeriodic(array &$player, $now)
    {
        $idleLimit = isset($this->config['idle_timeout']) ? (int)$this->config['idle_timeout'] : 180;
        if ($idleLimit <= 0) {
            return;
        }

        $lastActive = isset($player['last_activity']) ? $player['last_activity'] : $player['join_time'];
        if (($now - $lastActive) > $idleLimit && empty($player['flags']['afk_notified'])) {
            $player['flags']['afk_notified'] = true;
            if (isset($player['ucid'])) {
                IS_MTC()->UCID($player['ucid'])->Text('^3AFK? ^7Your earnings pause after long inactivity.')->Send();
            }
        }
    }
}
