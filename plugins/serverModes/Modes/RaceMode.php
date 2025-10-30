<?php
class ServerModes_RaceMode extends ServerModes_Mode
{
    protected function onActivated($hostName)
    {
        console(sprintf('serverModes: Race mode activated for host "%s"', $hostName));
    }

    public function onPlayerConnected(array &$player)
    {
        $player['race']['streak'] = 0;
    }

    public function onLap(array &$player, IS_LAP $lap)
    {
        $lapTime = $lap->LTime;
        if ($lapTime <= 0) {
            return;
        }

        if (!isset($player['best_lap_ms']) || $player['best_lap_ms'] === null || $lapTime < $player['best_lap_ms']) {
            $player['best_lap_ms'] = $lapTime;
            $player['dirty'] = true;
            if (isset($player['ucid'])) {
                IS_MTC()->UCID($player['ucid'])->Text(sprintf('^2New PB:^7 %s', $this->formatLap($lapTime)))->Send();
            }
        }

        $player['race']['streak'] = isset($player['race']['streak']) ? ($player['race']['streak'] + 1) : 1;
        if ($player['race']['streak'] > 0 && ($player['race']['streak'] % 5) === 0 && isset($player['ucid'])) {
            IS_MTC()->UCID($player['ucid'])->Text('^7Nice pace! ^3Five clean laps in a row.')->Send();
        }
    }

    public function onPeriodic(array &$player, $now)
    {
        if (!isset($player['race']['last_notice']) || $now - $player['race']['last_notice'] >= 300) {
            if (isset($player['ucid']) && isset($player['best_lap_ms']) && $player['best_lap_ms'] > 0) {
                IS_MTC()->UCID($player['ucid'])->Text(sprintf('^7PB:^3 %s', $this->formatLap($player['best_lap_ms'])))->Send();
            }
            $player['race']['last_notice'] = $now;
        }
    }

    private function formatLap($lapTime)
    {
        $ms = $lapTime % 1000;
        $totalSeconds = ($lapTime - $ms) / 1000;
        $seconds = $totalSeconds % 60;
        $minutes = ($totalSeconds - $seconds) / 60;

        return sprintf('%d:%02d.%03d', $minutes, $seconds, $ms);
    }
}
