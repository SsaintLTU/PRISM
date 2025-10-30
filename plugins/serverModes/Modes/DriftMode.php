<?php
class ServerModes_DriftMode extends ServerModes_Mode
{
    protected function onActivated($hostName)
    {
        console(sprintf('serverModes: Drift mode activated for host "%s"', $hostName));
    }

    public function onCompCar(array &$player, $compCar, $deltaSeconds, $deltaDistance)
    {
        if ($deltaSeconds <= 0) {
            return;
        }

        $heading = $this->normaliseAngle(($compCar->Heading / 65536.0) * 360.0);
        $direction = $this->normaliseAngle(($compCar->Direction / 65536.0) * 360.0);
        $angleDiff = abs($heading - $direction);
        if ($angleDiff > 180.0) {
            $angleDiff = 360.0 - $angleDiff;
        }

        $speed = ($compCar->Speed / 32768.0) * 100.0; // metres per second

        if ($angleDiff < 10.0 || $speed < 5.0) {
            return;
        }

        $scoreGain = ($angleDiff * $speed) / 50.0;
        if (!isset($player['session_stats']['drift'])) {
            $player['session_stats']['drift'] = 0.0;
        }

        $player['session_stats']['drift'] += $scoreGain;
        $player['dirty'] = true;

        if (!isset($player['drift_peak']) || $scoreGain > $player['drift_peak']) {
            $player['drift_peak'] = $scoreGain;
            if (isset($player['ucid'])) {
                IS_MTC()->UCID($player['ucid'])->Text(sprintf('^3Drift! ^7Score +%.1f', $scoreGain))->Send();
            }
        }
    }

    public function onPeriodic(array &$player, $now)
    {
        if (!isset($player['session_stats']['drift']) || $player['session_stats']['drift'] <= 0) {
            return;
        }

        if (!isset($player['drift_notify']) || ($now - $player['drift_notify']) > 120) {
            if (isset($player['ucid'])) {
                IS_MTC()->UCID($player['ucid'])->Text(sprintf('^7Session drift score:^3 %.1f', $player['session_stats']['drift']))->Send();
            }
            $player['drift_notify'] = $now;
        }
    }

    private function normaliseAngle($angle)
    {
        while ($angle < 0) {
            $angle += 360.0;
        }
        while ($angle >= 360.0) {
            $angle -= 360.0;
        }
        return $angle;
    }
}
