<?php
class ServerModes_DriftSystems
{
    private const HUD_GROUP = 'DriftHUD';
    private const BOARD_GROUP = 'DriftBoard';

    private serverModes $plugin;
    private bool $active = false;
    private array $config = array();
    private array $playerFlags = array();
    private array $leaderboardCache = array();
    private int $leaderboardTtl = 20;
    private array $layoutList = array('*');
    private array $periods = array('day', 'week', 'month', 'year');

    public function __construct(serverModes $plugin, array $config = array())
    {
        $this->plugin = $plugin;
        $this->applyConfig($config);
    }

    public function applyConfig(array $config): void
    {
        $this->config = array_replace($this->config, $config);
        $this->leaderboardTtl = max(5, (int)($this->config['leaderboard_cache_ttl'] ?? 20));
        $this->refreshLayouts();
    }

    public function onActivate(): void
    {
        $this->active = true;
        $this->leaderboardCache = array();
        $this->refreshLayouts();
        $this->markAllDirty();
    }

    public function onDeactivate(): void
    {
        $this->active = false;
        $this->leaderboardCache = array();
        $this->clearButtons();
    }

    public function onTrackChanged(string $track): void
    {
        if ($track !== '') {
            $this->refreshLayouts($track);
            $this->markAllDirty();
        }
    }

    public function onPlayerConnected(array &$player): void
    {
        $this->initialisePlayer($player);
        $ucid = $player['ucid'] ?? 0;
        if ($ucid > 0) {
            $this->playerFlags[$ucid] = array('dirty' => true);
        }
    }

    public function onPlayerDisconnected(array &$player): void
    {
        $ucid = $player['ucid'] ?? 0;
        if ($ucid > 0) {
            $this->finaliseCombo($player, true);
            ButtonManager::removeButtonsByGroup($ucid, self::HUD_GROUP);
            ButtonManager::removeButtonsByGroup($ucid, self::BOARD_GROUP);
            unset($this->playerFlags[$ucid]);
        }
    }

    public function onPlayerJoinRace(array &$player, IS_NPL $packet): void
    {
        if (!$this->active) {
            return;
        }
        $this->initialisePlayer($player);
        $player['state']['drift']['layout'] = $this->plugin->getCurrentTrack() ?: ($player['state']['drift']['layout'] ?? '');
        $this->markStateDirty($player);
    }

    public function onDriftSample(array &$player, float $deltaKm, float $speedKph, float $slipAngle, CompCar $info): void
    {
        if (!$this->active) {
            return;
        }

        $this->initialisePlayer($player);
        $ucid = $player['ucid'] ?? 0;
        if ($ucid === 0) {
            return;
        }

        $minAngle = $this->getConfigNumber('min_angle', 10.0);
        $angleThreshold = max($minAngle + 1.0, $this->getConfigNumber('angle_threshold', 35.0));
        $minSpeed = $this->getConfigNumber('min_speed_kph', 25.0);
        $comboTimeout = $this->getConfigNumber('combo_timeout', 2.5);
        $pointsScale = $this->getConfigNumber('points_scale', 100000.0);
        $speedScale = max(1.0, $this->getConfigNumber('speed_scale', 120.0));

        $drift =& $player['state']['drift'];
        $now = microtime(true);

        if ($slipAngle >= $minAngle && $speedKph >= $minSpeed) {
            $intensity = min(1.0, ($slipAngle - $minAngle) / max(1.0, $angleThreshold - $minAngle));
            $speedFactor = 1.0 + (min($speedKph, $speedScale) / $speedScale);
            $points = $deltaKm * $pointsScale * $intensity * $speedFactor;

            if ($points > 0) {
                $drift['current_combo'] += $points;
                $drift['session_points'] += $points;
                $drift['max_angle'] = max($drift['max_angle'], $slipAngle);
                $drift['last_activity'] = $now;
                $drift['layout'] = $this->plugin->getCurrentTrack() ?: ($drift['layout'] ?? '');
                $drift['last_angle'] = $slipAngle;
                $drift['last_speed'] = $speedKph;
                $drift['last_sample'] = $now;
                $this->playerFlags[$ucid]['dirty'] = true;
                $this->markStateDirty($player);
            }
        } elseif (($drift['current_combo'] ?? 0.0) > 0.0) {
            $lastActivity = $drift['last_activity'] ?? 0.0;
            if ($lastActivity > 0 && ($now - $lastActivity) >= $comboTimeout) {
                $this->finaliseCombo($player);
            }
        } else {
            $previousSpeed = (float)($drift['last_speed'] ?? -1.0);
            if ($previousSpeed >= 0.0 && abs($previousSpeed - $speedKph) >= 1.0) {
                $drift['last_speed'] = $speedKph;
                $this->playerFlags[$ucid]['dirty'] = true;
            }
        }
    }

    public function tick(array &$players): void
    {
        if (!$this->active) {
            return;
        }

        $now = microtime(true);
        foreach ($players as &$player) {
            $ucid = $player['ucid'] ?? 0;
            if ($ucid === 0) {
                continue;
            }

            $this->initialisePlayer($player);
            $drift =& $player['state']['drift'];
            $comboTimeout = $this->getConfigNumber('combo_timeout', 2.5);
            $lastActivity = $drift['last_activity'] ?? 0.0;
            if (($drift['current_combo'] ?? 0.0) > 0.0 && $lastActivity > 0 && ($now - $lastActivity) >= $comboTimeout) {
                $this->finaliseCombo($player);
            }

            $lastSample = $drift['last_sample'] ?? 0.0;
            if ($lastSample > 0.0) {
                $elapsed = $now - $lastSample;
                if ($elapsed >= 0.8) {
                    $decay = ($elapsed - 0.5) * 45.0;
                    $currentAngle = (float)($drift['last_angle'] ?? 0.0);
                    $newAngle = max(0.0, $currentAngle - $decay);

                    if ($newAngle <= 0.01) {
                        if ($currentAngle > 0.0 || ($drift['last_speed'] ?? 0.0) > 0.0) {
                            $drift['last_angle'] = 0.0;
                            $drift['last_speed'] = 0.0;
                            $drift['last_sample'] = 0.0;
                            $this->playerFlags[$ucid]['dirty'] = true;
                            $this->markStateDirty($player);
                        }
                    } elseif ($newAngle !== $currentAngle) {
                        $drift['last_angle'] = $newAngle;
                        $this->playerFlags[$ucid]['dirty'] = true;
                    }
                }
            }

            $layout = $this->getSelectedLayout($player);
            $period = $this->getSelectedPeriod($player);
            if ($this->isLeaderboardExpired($layout, $period)) {
                $this->playerFlags[$ucid]['dirty'] = true;
            }

            if (!empty($this->playerFlags[$ucid]['dirty'])) {
                $this->renderHud($player);
                $this->playerFlags[$ucid]['dirty'] = false;
            }
        }
    }

    public function handleButton(int $ucid, string $action, $extra = null): void
    {
        if (!$this->active || $ucid === 0) {
            return;
        }

        $player =& $this->plugin->getPlayerRecord($ucid);
        $this->initialisePlayer($player);

        switch ($action) {
            case 'layout_prev':
                $this->adjustLayoutIndex($player, -1);
                break;
            case 'layout_next':
                $this->adjustLayoutIndex($player, 1);
                break;
            case 'period_next':
                $this->cyclePeriod($player);
                break;
            case 'panel_toggle':
                $player['state']['drift']['ui']['visible'] = !$player['state']['drift']['ui']['visible'];
                if (!$player['state']['drift']['ui']['visible']) {
                    ButtonManager::removeButtonsByGroup($ucid, self::BOARD_GROUP);
                }
                $this->markStateDirty($player);
                break;
            case 'panel_up':
                $this->offsetPanel($player, 0, -3);
                break;
            case 'panel_down':
                $this->offsetPanel($player, 0, 3);
                break;
            case 'panel_left':
                $this->offsetPanel($player, -3, 0);
                break;
            case 'panel_right':
                $this->offsetPanel($player, 3, 0);
                break;
        }

        $this->playerFlags[$ucid]['dirty'] = true;
    }

    private function renderHud(array &$player): void
    {
        $ucid = $player['ucid'];
        $drift =& $player['state']['drift'];

        $points = number_format((float)($drift['session_points'] ?? 0.0), 0, '.', ' ');
        $combo = number_format((float)($drift['current_combo'] ?? 0.0), 0, '.', ' ');
        $best = number_format((float)($drift['best_combo'] ?? 0.0), 0, '.', ' ');

        $line = sprintf('^7Drift ^3%s ^8| ^7Combo ^3%s ^8| ^7Best ^3%s', $points, $combo, $best);
        $button = new Button($ucid, 'DriftHudMain', self::HUD_GROUP);
        $button->L(0)->T(0)->W(200)->H(4)->BStyle(ISB_DARK | ISB_LEFT)->Text($line)->Send();

        $angle = (float)($drift['last_angle'] ?? 0.0);
        $speed = (float)($drift['last_speed'] ?? 0.0);
        $threshold = max(1.0, $this->getConfigNumber('angle_threshold', 35.0));
        $gauge = $this->buildGauge($angle, $threshold, 22);
        $angleLine = sprintf('^7Angle ^3%02.0f° ^8%s ^7Speed ^3%02.0f ^8km/h', $angle, $gauge, $speed);

        $gaugeButton = new Button($ucid, 'DriftHudGauge', self::HUD_GROUP);
        $gaugeButton->L(0)->T(4)->W(200)->H(4)->BStyle(ISB_DARK | ISB_LEFT)->Text($angleLine)->Send();

        if (!empty($drift['ui']['visible'])) {
            $this->renderLeaderboard($player, $ucid);
        }
    }

    private function renderLeaderboard(array &$player, int $ucid): void
    {
        $layoutKey = $this->getSelectedLayout($player);
        $periodKey = $this->getSelectedPeriod($player);
        $rows = $this->getLeaderboardRows($layoutKey, $periodKey);

        $displayLayout = $layoutKey === '*' ? 'All Layouts' : strtoupper($layoutKey);
        $periodLabel = ucfirst($periodKey);

        $ui =& $player['state']['drift']['ui'];
        $left = max(0, min(IS_X_MAX - 80, (int)($ui['left'] ?? 120)));
        $top = max(10, min(IS_Y_MAX - 50, (int)($ui['top'] ?? 30)));
        $height = 14 + (max(1, count($rows)) * 6) + 8;

        $background = new Button($ucid, 'DriftBoardBG', self::BOARD_GROUP);
        $background->L($left)->T($top)->W(80)->H($height)->BStyle(ISB_DARK)->Text('')->Send();

        $title = new Button($ucid, 'DriftBoardTitle', self::BOARD_GROUP);
        $title->L($left + 2)->T($top + 2)->W(76)->H(6)->BStyle(ISB_DARK | ISB_YELLOW)->Text(sprintf('^7Top %s - %s', $periodLabel, $displayLayout))->Send();

        $layoutPrev = new Button($ucid, 'DriftLayoutPrev', self::BOARD_GROUP);
        $layoutPrev->L($left + 2)->T($top + 9)->W(6)->H(5)->BStyle(ISB_DARK | ISB_CLICK)->Text('<')->registerOnClick($this->plugin, 'handleDriftButton', array($ucid, 'layout_prev'));
        $layoutPrev->Send();

        $layoutNext = new Button($ucid, 'DriftLayoutNext', self::BOARD_GROUP);
        $layoutNext->L($left + 72)->T($top + 9)->W(6)->H(5)->BStyle(ISB_DARK | ISB_CLICK)->Text('>')->registerOnClick($this->plugin, 'handleDriftButton', array($ucid, 'layout_next'));
        $layoutNext->Send();

        $layoutLabel = new Button($ucid, 'DriftLayoutLabel', self::BOARD_GROUP);
        $layoutLabel->L($left + 10)->T($top + 9)->W(60)->H(5)->BStyle(ISB_DARK | ISB_LEFT)->Text('^7Layout: ^3' . $displayLayout)->Send();

        $periodButton = new Button($ucid, 'DriftPeriod', self::BOARD_GROUP);
        $periodButton->L($left + 2)->T($top + 15)->W(40)->H(5)->BStyle(ISB_DARK | ISB_CLICK | ISB_LEFT)->Text('^7Period: ^3' . $periodLabel)->registerOnClick($this->plugin, 'handleDriftButton', array($ucid, 'period_next'));
        $periodButton->Send();

        $toggleButton = new Button($ucid, 'DriftPanelToggle', self::BOARD_GROUP);
        $toggleButton->L($left + 44)->T($top + 15)->W(34)->H(5)->BStyle(ISB_DARK | ISB_CLICK | ISB_LEFT)->Text('^7Hide')->registerOnClick($this->plugin, 'handleDriftButton', array($ucid, 'panel_toggle'));
        $toggleButton->Send();

        if (empty($rows)) {
            $rowButton = new Button($ucid, 'DriftRowEmpty', self::BOARD_GROUP);
            $rowButton->L($left + 2)->T($top + 22)->W(76)->H(5)->BStyle(ISB_DARK | ISB_LEFT)->Text('^8No runs recorded yet.')->Send();
        } else {
            $rowIndex = 0;
            foreach ($rows as $row) {
                $rank = $rowIndex + 1;
                $name = $row['nickname'] !== '' ? $row['nickname'] : $row['username'];
                $points = number_format((float)$row['points'], 0, '.', ' ');
                $text = sprintf('^3%2d.^7 %s ^8%s pts', $rank, $name, $points);

                $rowButton = new Button($ucid, 'DriftRow' . $rank, self::BOARD_GROUP);
                $rowButton->L($left + 2)->T($top + 22 + ($rowIndex * 6))->W(76)->H(5)->BStyle(ISB_DARK | ISB_LEFT)->Text($text)->Send();
                $rowIndex++;
            }
        }

        $moveUp = new Button($ucid, 'DriftMoveUp', self::BOARD_GROUP);
        $moveUp->L($left + 30)->T($top + $height - 8)->W(6)->H(5)->BStyle(ISB_DARK | ISB_CLICK)->Text('^')->registerOnClick($this->plugin, 'handleDriftButton', array($ucid, 'panel_up'));
        $moveUp->Send();

        $moveLeft = new Button($ucid, 'DriftMoveLeft', self::BOARD_GROUP);
        $moveLeft->L($left + 22)->T($top + $height - 3)->W(6)->H(5)->BStyle(ISB_DARK | ISB_CLICK)->Text('<')->registerOnClick($this->plugin, 'handleDriftButton', array($ucid, 'panel_left'));
        $moveLeft->Send();

        $moveDown = new Button($ucid, 'DriftMoveDown', self::BOARD_GROUP);
        $moveDown->L($left + 30)->T($top + $height - 3)->W(6)->H(5)->BStyle(ISB_DARK | ISB_CLICK)->Text('v')->registerOnClick($this->plugin, 'handleDriftButton', array($ucid, 'panel_down'));
        $moveDown->Send();

        $moveRight = new Button($ucid, 'DriftMoveRight', self::BOARD_GROUP);
        $moveRight->L($left + 38)->T($top + $height - 3)->W(6)->H(5)->BStyle(ISB_DARK | ISB_CLICK)->Text('>')->registerOnClick($this->plugin, 'handleDriftButton', array($ucid, 'panel_right'));
        $moveRight->Send();
    }

    private function initialisePlayer(array &$player): void
    {
        if (!isset($player['state']['drift']) || !is_array($player['state']['drift'])) {
            $player['state']['drift'] = $this->defaultState();
            $this->markStateDirty($player);
        } else {
            $player['state']['drift'] = array_replace_recursive($this->defaultState(), $player['state']['drift']);
        }

        $ui =& $player['state']['drift']['ui'];
        if (!isset($ui['layout_index']) || !is_int($ui['layout_index'])) {
            $ui['layout_index'] = 0;
        }
        if (!isset($ui['period_index']) || !is_int($ui['period_index'])) {
            $ui['period_index'] = 0;
        }

        if ($ui['layout_index'] < 0 || $ui['layout_index'] >= count($this->layoutList)) {
            $ui['layout_index'] = 0;
        }

        $player['state']['drift']['ui'] = $ui;
    }

    private function defaultState(): array
    {
        return array(
            'session_points' => 0.0,
            'current_combo' => 0.0,
            'best_combo' => 0.0,
            'max_angle' => 0.0,
            'last_activity' => 0.0,
            'layout' => '',
            'last_angle' => 0.0,
            'last_speed' => 0.0,
            'last_sample' => 0.0,
            'ui' => array(
                'visible' => true,
                'layout_index' => 0,
                'period_index' => 0,
                'left' => 120,
                'top' => 30,
            ),
        );
    }

    private function markStateDirty(array &$player): void
    {
        $player['state_dirty'] = true;
    }

    private function finaliseCombo(array &$player, bool $force = false): void
    {
        $drift =& $player['state']['drift'];
        $combo = $drift['current_combo'] ?? 0.0;
        if ($combo <= 0.0) {
            return;
        }

        $minCombo = $this->getConfigNumber('min_combo_points', 50.0);
        if ($combo >= $minCombo) {
            $drift['best_combo'] = max($drift['best_combo'], $combo);
            $userId = $player['user_id'] ?? 0;
            if ($userId > 0) {
                $this->plugin->getDatabase()->recordDriftScore(array(
                    'user_id' => $userId,
                    'username' => $player['username'] ?? '',
                    'nickname' => $player['nickname'] ?? '',
                    'layout' => $drift['layout'] ?? $this->plugin->getCurrentTrack(),
                    'host_id' => $this->plugin->getActiveHostId(),
                    'points' => $combo,
                    'max_angle' => $drift['max_angle'] ?? 0.0,
                ));
                $this->invalidateLeaderboard('*');
                if (!empty($drift['layout'])) {
                    $this->invalidateLeaderboard($drift['layout']);
                }
            }
        }

        $drift['current_combo'] = 0.0;
        $drift['max_angle'] = 0.0;
        $drift['last_activity'] = 0.0;
        $drift['last_sample'] = 0.0;
        $drift['last_angle'] = 0.0;
        $drift['last_speed'] = 0.0;
        $this->markStateDirty($player);
        $ucid = $player['ucid'] ?? 0;
        if ($ucid > 0) {
            $this->playerFlags[$ucid]['dirty'] = true;
        }
    }

    private function adjustLayoutIndex(array &$player, int $delta): void
    {
        $count = count($this->layoutList);
        if ($count === 0) {
            return;
        }

        $ui =& $player['state']['drift']['ui'];
        $index = ($ui['layout_index'] ?? 0) + $delta;
        while ($index < 0) {
            $index += $count;
        }
        $index = $index % $count;
        $ui['layout_index'] = $index;
        $player['state']['drift']['ui'] = $ui;
        $this->markStateDirty($player);
    }

    private function cyclePeriod(array &$player): void
    {
        $count = count($this->periods);
        $ui =& $player['state']['drift']['ui'];
        $index = ($ui['period_index'] ?? 0) + 1;
        $ui['period_index'] = $index % $count;
        $player['state']['drift']['ui'] = $ui;
        $this->markStateDirty($player);
    }

    private function offsetPanel(array &$player, int $dx, int $dy): void
    {
        $ui =& $player['state']['drift']['ui'];
        $ui['left'] = ($ui['left'] ?? 120) + $dx;
        $ui['top'] = ($ui['top'] ?? 30) + $dy;
        $player['state']['drift']['ui'] = $ui;
        $this->markStateDirty($player);
    }

    private function getSelectedLayout(array &$player): string
    {
        $index = $player['state']['drift']['ui']['layout_index'] ?? 0;
        if ($index < 0 || $index >= count($this->layoutList)) {
            $index = 0;
        }
        return $this->layoutList[$index] ?? '*';
    }

    private function getSelectedPeriod(array &$player): string
    {
        $index = $player['state']['drift']['ui']['period_index'] ?? 0;
        if ($index < 0 || $index >= count($this->periods)) {
            $index = 0;
        }
        return $this->periods[$index];
    }

    private function getLeaderboardRows(string $layout, string $period): array
    {
        $key = $layout . '|' . $period;
        if (!$this->isLeaderboardExpired($layout, $period) && isset($this->leaderboardCache[$key])) {
            return $this->leaderboardCache[$key]['data'];
        }

        $rows = $this->plugin->getDatabase()->fetchTopDriftScores($layout, $period, (int)($this->config['leaderboard_limit'] ?? 5));
        $this->leaderboardCache[$key] = array(
            'data' => $rows,
            'expires' => time() + $this->leaderboardTtl,
        );
        return $rows;
    }

    private function isLeaderboardExpired(string $layout, string $period): bool
    {
        $key = $layout . '|' . $period;
        if (!isset($this->leaderboardCache[$key])) {
            return true;
        }
        return $this->leaderboardCache[$key]['expires'] <= time();
    }

    private function invalidateLeaderboard(string $layout = '*'): void
    {
        if ($layout === '*' || $layout === '') {
            $this->leaderboardCache = array();
            return;
        }

        foreach (array_keys($this->leaderboardCache) as $key) {
            if (strpos($key, $layout . '|') === 0) {
                unset($this->leaderboardCache[$key]);
            }
        }
    }

    private function refreshLayouts(?string $extra = null): void
    {
        $layouts = array('*');

        $configLayouts = $this->config['layouts'] ?? array();
        if (is_string($configLayouts)) {
            $configLayouts = preg_split('/[;,\s]+/', $configLayouts, -1, PREG_SPLIT_NO_EMPTY);
        }
        if (is_array($configLayouts)) {
            foreach ($configLayouts as $layout) {
                $layout = strtoupper(trim((string)$layout));
                if ($layout !== '') {
                    $layouts[] = $layout;
                }
            }
        }

        $current = strtoupper($this->plugin->getCurrentTrack());
        if ($current !== '') {
            $layouts[] = $current;
        }

        if ($extra) {
            $layouts[] = strtoupper($extra);
        }

        foreach ($this->plugin->getDatabase()->fetchKnownDriftLayouts() as $known) {
            $known = strtoupper($known);
            if ($known !== '') {
                $layouts[] = $known;
            }
        }

        $layouts = array_values(array_unique($layouts));
        $this->layoutList = $layouts;

        foreach ($this->plugin->getPlayerMap() as &$player) {
            $this->initialisePlayer($player);
            $ui =& $player['state']['drift']['ui'];
            if ($ui['layout_index'] >= count($this->layoutList)) {
                $ui['layout_index'] = 0;
                $this->markStateDirty($player);
            }
        }
    }

    private function markAllDirty(): void
    {
        foreach (array_keys($this->plugin->getPlayerMap()) as $ucid) {
            $this->playerFlags[$ucid]['dirty'] = true;
        }
    }

    private function clearButtons(): void
    {
        foreach (array_keys($this->playerFlags) as $ucid) {
            ButtonManager::removeButtonsByGroup($ucid, self::HUD_GROUP);
            ButtonManager::removeButtonsByGroup($ucid, self::BOARD_GROUP);
        }
        $this->playerFlags = array();
    }

    private function getConfigNumber(string $key, float $default): float
    {
        if (!isset($this->config[$key])) {
            return $default;
        }
        return (float)$this->config[$key];
    }

    private function buildGauge(float $value, float $max, int $slots = 20): string
    {
        if ($max <= 0.0) {
            $max = 1.0;
        }

        $ratio = max(0.0, min(1.0, $value / $max));
        $filled = (int)round($ratio * $slots);
        $filled = max(0, min($slots, $filled));

        $filledPart = $filled > 0 ? '^2' . str_repeat('|', $filled) : '';
        $emptyPart = ($slots - $filled) > 0 ? '^8' . str_repeat('.', $slots - $filled) : '';

        return sprintf('[%s%s^8]', $filledPart, $emptyPart);
    }
}
