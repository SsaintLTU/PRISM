<?php
class ServerModes_RaceSystems
{
    private const HUD_GROUP = 'RaceHUD';
    private const VOTE_GROUP = 'RaceVote';

    private const CAR_MASKS = array(
        'XFG' => PLC_XFG,
        'XRG' => PLC_XRG,
        'XRT' => PLC_XRT,
        'RB4' => PLC_RB4,
        'FXO' => PLC_FXO,
        'LX4' => PLC_LX4,
        'LX6' => PLC_LX6,
        'MRT' => PLC_MRT,
        'UF1' => PLC_UF1,
        'RAC' => PLC_RAC,
        'FZ5' => PLC_FZ5,
        'FOX' => PLC_FOX,
        'FO8' => PLC_FO8,
        'BF1' => PLC_BF1,
        'XFR' => PLC_XFR,
        'UFR' => PLC_UFR,
        'FXR' => PLC_FXR,
        'XRR' => PLC_XRR,
        'FZR' => PLC_FZR,
        'FBM' => PLC_FBM,
    );

    private serverModes $plugin;
    private bool $active = false;
    private array $config = array();

    private array $classOrder = array();
    private array $classCars = array();
    private array $carToClass = array();

    private ?string $firstCar = null;
    private ?int $firstOwner = null;
    private ?string $firstClass = null;
    private ?float $firstLapTime = null;
    private ?string $forcedClass = null;
    private ?string $forcedCar = null;
    private int $qualDeadline = 0;

    private array $pointsTable = array(1 => 15, 2 => 12, 3 => 10);

    private array $playerFlags = array();
    private bool $standingsDirty = false;

    private array $pendingResults = array();

    private array $voteState = array(
        'open' => false,
        'deadline' => 0,
        'options' => array(),
        'votes' => array(),
        'randomPools' => array(),
        'duration' => 20,
    );

    public function __construct(serverModes $plugin, array $config = array())
    {
        $this->plugin = $plugin;
        $this->applyConfig($config);
    }

    public function applyConfig(array $config): void
    {
        $this->config = array_replace($this->config, $config);
        $this->parseClassConfig();
        $this->parsePointsConfig();
        $this->parseVoteConfig();
    }

    public function onActivate(): void
    {
        $this->active = true;
        $this->resetSession();
        $this->markAllDirty();
    }

    public function onDeactivate(): void
    {
        $this->active = false;
        $this->clearButtons();
        $this->resetSession();
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function onPlayerConnected(array &$player): void
    {
        if (!$this->active) {
            return;
        }

        $this->initialisePlayer($player);
        $ucid = $player['ucid'] ?? 0;
        if ($ucid > 0) {
            $this->playerFlags[$ucid] = true;
            $this->standingsDirty = true;
        }
    }

    public function onPlayerDisconnected(array &$player): void
    {
        if (!$this->active) {
            return;
        }

        $ucid = $player['ucid'] ?? 0;
        if ($ucid > 0) {
            ButtonManager::removeButtonsByGroup($ucid, self::HUD_GROUP);
            ButtonManager::removeButtonsByGroup($ucid, self::VOTE_GROUP);
            unset($this->playerFlags[$ucid]);
            $this->standingsDirty = true;
        }
    }

    public function onPlayerJoinRace(array &$player, IS_NPL $packet): void
    {
        if (!$this->active) {
            return;
        }

        $this->initialisePlayer($player);
        $ucid = $player['ucid'] ?? 0;
        $car = trim($packet->CName);
        $class = $this->getClassForCar($car);

        $player['state']['race']['current_car'] = $car;
        $player['state']['race']['class'] = $class;
        $player['state']['race']['last_lap'] = 0.0;

        if ($this->firstCar === null && $car !== '') {
            $this->firstCar = $car;
            $this->firstClass = $class;
            $this->firstOwner = $ucid;
            $this->firstLapTime = null;
            $this->announce(sprintf('^7First qualifier car:^3 %s ^8(class ^3%s^8)', $car, $class ?: 'Unclassified'));
        }

        if ($this->forcedCar !== null) {
            if ($car !== $this->forcedCar) {
                $this->plugin->MsgToUCID($ucid, sprintf('^1Forced car active:^7 switch to ^3%s ^7(class ^3%s^7).', $this->forcedCar, $this->forcedClass ?? '?'));
            }
            $this->applyForcedCarToPlayer($ucid);
        }

        if ($ucid > 0) {
            $this->playerFlags[$ucid] = true;
            $this->standingsDirty = true;
        }
    }

    public function onLapCompleted(array &$player, IS_LAP $lap): void
    {
        if (!$this->active) {
            return;
        }

        $ucid = $player['ucid'] ?? 0;
        if ($ucid === 0) {
            return;
        }

        $lapMs = (int)$lap->LTime;
        if ($lapMs <= 0) {
            return;
        }

        $lapSeconds = $lapMs / 1000.0;
        $race =& $player['state']['race'];
        $race['last_lap'] = $lapSeconds;

        if (($race['best_lap'] ?? 0.0) <= 0.0 || $lapSeconds < $race['best_lap']) {
            $race['best_lap'] = $lapSeconds;
            $this->playerFlags[$ucid] = true;
            $this->standingsDirty = true;
        }

        if ($this->firstOwner === $ucid) {
            if ($this->firstLapTime === null || $lapSeconds < $this->firstLapTime) {
                $this->firstLapTime = $lapSeconds;
            }
            return;
        }

        if ($this->firstClass === null || $this->firstLapTime === null) {
            return;
        }

        if (($race['class'] ?? '') !== $this->firstClass) {
            return;
        }

        if ($lapSeconds + 0.0001 < $this->firstLapTime) {
            $this->triggerClassPromotion($player);
        }
    }

    public function onRaceResult(IS_RES $result, ?int $ucid): void
    {
        if (!$this->active) {
            return;
        }

        if ($this->pendingResults === array() || $this->pendingResults['received'] >= $this->pendingResults['expected']) {
            $this->pendingResults = array(
                'id' => microtime(true),
                'expected' => max(1, (int)$result->NumRes),
                'received' => 0,
                'track' => $this->plugin->getCurrentTrack(),
                'entries' => array(),
                'seen' => array(),
            );
        }

        $key = sprintf('%d:%d', $result->PLID, $result->ResultNum);
        if (isset($this->pendingResults['seen'][$key])) {
            return;
        }

        $this->pendingResults['seen'][$key] = true;
        $position = (int)$result->ResultNum + 1;
        $entry = array(
            'ucid' => $ucid,
            'position' => $position,
            'username' => trim($result->UName ?? ''),
            'nickname' => trim($result->PName ?? ''),
            'car' => trim($result->CName ?? ''),
            'class' => $this->getClassForCar(trim($result->CName ?? '')),
            'time' => $result->TTime > 0 ? $result->TTime / 1000.0 : 0.0,
        );

        $this->pendingResults['entries'][] = $entry;
        $this->pendingResults['received']++;

        if ($this->pendingResults['received'] >= $this->pendingResults['expected']) {
            $this->finaliseRaceResults();
        }
    }

    public function tick(): void
    {
        if (!$this->active) {
            return;
        }

        if ($this->qualDeadline > 0 && time() >= $this->qualDeadline) {
            $this->qualDeadline = 0;
            if ($this->forcedCar) {
                $this->announce(sprintf('^7Qualification locked: all drivers switch to ^3%s ^8(class ^3%s^8).', $this->forcedCar, $this->forcedClass ?? '-'));
            }
        }

        if ($this->voteState['open'] && $this->voteState['deadline'] > 0 && time() >= $this->voteState['deadline']) {
            $this->finaliseVote();
        }

        if ($this->standingsDirty) {
            $this->recalculateStandings();
            $this->standingsDirty = false;
        }

        foreach ($this->plugin->getPlayerMap() as $ucid => &$player) {
            if (!empty($this->playerFlags[$ucid])) {
                $this->renderHud($player);
                $this->playerFlags[$ucid] = false;
            }
        }
        unset($player);
    }

    public function handleButton(int $ucid, string $action, $extra = null): void
    {
        if (!$this->active) {
            return;
        }

        switch ($action) {
            case 'vote_select':
                if ($this->voteState['open']) {
                    $choice = (string)$extra;
                    if (in_array($choice, $this->voteState['options'], true)) {
                        $this->voteState['votes'][$ucid] = $choice;
                        $this->renderVotePanel($ucid);
                    }
                }
                break;
            case 'vote_close':
                ButtonManager::removeButtonsByGroup($ucid, self::VOTE_GROUP);
                break;
        }
    }

    public function openTrackVote(): void
    {
        if (!$this->active || empty($this->voteState['options'])) {
            return;
        }

        $this->voteState['open'] = true;
        $this->voteState['votes'] = array();
        $duration = $this->voteState['duration'] ?? 20;
        $this->voteState['deadline'] = time() + max(5, (int)$duration);

        foreach ($this->plugin->getPlayerMap() as $ucid => $player) {
            if ($ucid > 0) {
                $this->renderVotePanel($ucid);
            }
        }

        $this->announce(sprintf('^7Track vote started. ^3%d ^7seconds to choose!', max(5, (int)$duration)));
    }

    private function renderVotePanel(int $ucid): void
    {
        if (!$this->voteState['open']) {
            return;
        }

        $left = 10;
        $top = 50;
        $height = 10 + (count($this->voteState['options']) * 6);

        $background = new Button($ucid, 'RaceVoteBG', self::VOTE_GROUP);
        $background->L($left)->T($top)->W(60)->H($height)->BStyle(ISB_DARK)->Text('')->Send();

        $title = new Button($ucid, 'RaceVoteTitle', self::VOTE_GROUP);
        $title->L($left + 2)->T($top + 2)->W(56)->H(6)->BStyle(ISB_DARK | ISB_YELLOW)->Text('^7Vote next layout')->Send();

        $index = 0;
        $choice = $this->voteState['votes'][$ucid] ?? '';
        foreach ($this->voteState['options'] as $option) {
            $button = new Button($ucid, 'RaceVoteOpt' . $index, self::VOTE_GROUP);
            $style = ISB_DARK | ISB_CLICK | ISB_LEFT;
            if ($choice === $option) {
                $style |= ISB_GREEN;
            }
            $button->L($left + 2)->T($top + 9 + ($index * 6))->W(56)->H(5)
                ->BStyle($style)
                ->Text('^3' . $option)
                ->registerOnClick($this->plugin, 'handleRaceButton', array($ucid, 'vote_select', $option));
            $button->Send();
            $index++;
        }

        $close = new Button($ucid, 'RaceVoteClose', self::VOTE_GROUP);
        $close->L($left + 20)->T($top + $height - 6)->W(20)->H(5)
            ->BStyle(ISB_DARK | ISB_CLICK)
            ->Text('^7Close')
            ->registerOnClick($this->plugin, 'handleRaceButton', array($ucid, 'vote_close'));
        $close->Send();
    }

    private function finaliseVote(): void
    {
        if (!$this->voteState['open']) {
            return;
        }

        $this->voteState['open'] = false;
        $this->voteState['deadline'] = 0;

        foreach ($this->plugin->getPlayerMap() as $ucid => $_) {
            ButtonManager::removeButtonsByGroup($ucid, self::VOTE_GROUP);
        }

        $votes = $this->voteState['votes'];
        $options = $this->voteState['options'];
        $randomPools = $this->voteState['randomPools'];

        if (empty($votes)) {
            $choice = $options[array_rand($options)];
            $this->announce('^7No votes cast. Random choice: ^3' . $choice);
            return;
        }

        $tally = array();
        foreach ($votes as $opt) {
            if (!in_array($opt, $options, true)) {
                continue;
            }
            if (!isset($tally[$opt])) {
                $tally[$opt] = 0;
            }
            $tally[$opt]++;
        }

        $unique = array_keys($tally);
        $selected = '';
        if (count($unique) >= 3) {
            $selected = $unique[array_rand($unique)];
            $this->announce('^7Three different picks. Random among them: ^3' . $selected);
        } elseif (count($unique) === 2) {
            arsort($tally);
            $selected = array_key_first($tally);
            $this->announce('^7Majority vote selected: ^3' . $selected);
        } else {
            $selected = $unique[0];
            if (!empty($randomPools[$selected])) {
                $pool = $randomPools[$selected];
                $selected = $pool[array_rand($pool)];
                $this->announce('^7All picked the same. Random layout variant: ^3' . $selected);
            } else {
                $this->announce('^7Unanimous choice: ^3' . $selected);
            }
        }
    }

    private function recalculateStandings(): void
    {
        $standings = array();
        $playerMap =& $this->plugin->getPlayerMap();

        foreach ($playerMap as $ucid => &$player) {
            $this->initialisePlayer($player);
            $best = $player['state']['race']['best_lap'] ?? 0.0;
            if ($best > 0.0) {
                $standings[$ucid] = $best;
            }
        }
        unset($player);

        asort($standings, SORT_NUMERIC);

        $position = 1;
        foreach ($standings as $ucid => $lap) {
            $points = $this->pointsTable[$position] ?? 0;
            $playerMap[$ucid]['state']['race']['potential_points'] = $points;
            $this->playerFlags[$ucid] = true;
            $position++;
        }

        foreach ($playerMap as $ucid => &$player) {
            if (!isset($standings[$ucid])) {
                $player['state']['race']['potential_points'] = 0;
            }
        }
        unset($player);
    }

    private function renderHud(array &$player): void
    {
        $ucid = $player['ucid'] ?? 0;
        if ($ucid === 0) {
            return;
        }

        $race =& $player['state']['race'];
        $potential = (int)($race['potential_points'] ?? 0);
        $class = $race['class'] ?? '';
        $car = $race['current_car'] ?? '';
        $best = $race['best_lap'] ?? 0.0;
        $bestText = $best > 0.0 ? sprintf('%0.3f', $best) : '-';
        $qualRemaining = $this->qualDeadline > 0 ? max(0, $this->qualDeadline - time()) : 0;
        $forced = $this->forcedCar ?? '-';

        $line1 = sprintf('^7Potential ^3%d ^8| ^7Class ^3%s ^8| ^7Car ^3%s ^8| ^7Best ^3%s', $potential, $class ?: '-', $car ?: '-', $bestText);
        $line2 = sprintf('^7First ^3%s ^8| ^7Forced ^3%s ^8| ^7Qual %s', $this->firstClass ?: '-', $forced, $qualRemaining > 0 ? sprintf('^3%ds', $qualRemaining) : '^8-');

        $button1 = new Button($ucid, 'RaceHudMain', self::HUD_GROUP);
        $button1->L(0)->T(0)->W(200)->H(4)->BStyle(ISB_DARK | ISB_LEFT)->Text($line1)->Send();

        $button2 = new Button($ucid, 'RaceHudInfo', self::HUD_GROUP);
        $button2->L(0)->T(4)->W(200)->H(4)->BStyle(ISB_DARK | ISB_LEFT)->Text($line2)->Send();
    }

    private function triggerClassPromotion(array $player): void
    {
        if ($this->forcedClass !== null) {
            return;
        }

        $nextClass = $this->getNextClass($this->firstClass ?? '');
        if ($nextClass === null) {
            return;
        }

        $car = $this->getRandomCarForClass($nextClass);
        if ($car === null) {
            return;
        }

        $this->forcedClass = $nextClass;
        $this->forcedCar = $car;
        $duration = max(30, (int)($this->config['qual_short_duration'] ?? 60));
        $this->qualDeadline = time() + $duration;

        $name = $player['nickname'] ?? ($player['username'] ?? 'Driver');
        $this->announce(sprintf('^3%s ^7triggered class change! New class ^3%s ^7with mandatory car ^3%s^7. Qualification ends in ^3%d^7s.', $name, $nextClass, $car, $duration));

        $this->applyForcedCarRestriction();
        $this->broadcastForcedCarNotice();
    }

    private function finaliseRaceResults(): void
    {
        if (empty($this->pendingResults['entries'])) {
            return;
        }

        $entries = $this->pendingResults['entries'];
        usort($entries, function ($a, $b) {
            return $a['position'] <=> $b['position'];
        });

        $playerMap =& $this->plugin->getPlayerMap();
        $database = $this->plugin->getDatabase();

        foreach ($entries as $entry) {
            $position = (int)$entry['position'];
            if ($position > 3) {
                continue;
            }

            $points = $this->pointsTable[$position] ?? 0;
            $ucid = $entry['ucid'];
            if ($ucid && isset($playerMap[$ucid])) {
                $playerMap[$ucid]['session']['race_points'] = ($playerMap[$ucid]['session']['race_points'] ?? 0) + $points;
                $this->playerFlags[$ucid] = true;
                $this->plugin->MsgToUCID($ucid, sprintf('^7You earned ^3%d ^7race points for finishing ^3#%d^7!', $points, $position));
            }

            if ($database && $ucid && isset($playerMap[$ucid])) {
                $userId = $playerMap[$ucid]['user_id'] ?? 0;
                if ($userId > 0) {
                    $database->recordRaceResult(array(
                        'user_id' => $userId,
                        'username' => $playerMap[$ucid]['username'] ?? '',
                        'nickname' => $playerMap[$ucid]['nickname'] ?? '',
                        'track' => $this->pendingResults['track'] ?? '',
                        'car' => $entry['car'],
                        'class' => $entry['class'],
                        'position' => $position,
                        'points' => $points,
                        'time' => $entry['time'],
                    ));
                }
            }
        }

        $this->announce('^7Race finished! Points awarded to the podium.');
        $this->resetQualificationState(true);
        $this->openTrackVote();
        $this->pendingResults = array();
    }

    private function parseClassConfig(): void
    {
        $this->classOrder = array();
        $this->classCars = array();
        $this->carToClass = array();

        $classesRaw = $this->config['classes'] ?? array();
        if (is_string($classesRaw)) {
            $classesRaw = preg_split('/[;,\s]+/', $classesRaw, -1, PREG_SPLIT_NO_EMPTY);
        }

        if (!is_array($classesRaw)) {
            $classesRaw = array();
        }

        foreach ($classesRaw as $classKey) {
            $classKey = trim((string)$classKey);
            if ($classKey === '') {
                continue;
            }
            $lower = strtolower($classKey);
            $carsRaw = $this->config['class.' . $lower] ?? $this->config['class_' . $lower] ?? array();
            if (is_string($carsRaw)) {
                $carsRaw = preg_split('/[;,\s]+/', $carsRaw, -1, PREG_SPLIT_NO_EMPTY);
            }
            if (!is_array($carsRaw)) {
                $carsRaw = array();
            }

            $carList = array();
            foreach ($carsRaw as $car) {
                $car = strtoupper(trim((string)$car));
                if ($car === '') {
                    continue;
                }
                $carList[] = $car;
                $this->carToClass[$car] = $classKey;
            }

            if (!empty($carList)) {
                $this->classOrder[] = $classKey;
                $this->classCars[$classKey] = $carList;
            }
        }
    }

    private function parsePointsConfig(): void
    {
        $pointsRaw = $this->config['points'] ?? $this->config['points_table'] ?? null;
        if ($pointsRaw === null) {
            return;
        }

        $points = array();
        if (is_string($pointsRaw)) {
            $parts = preg_split('/[;,\s]+/', $pointsRaw, -1, PREG_SPLIT_NO_EMPTY);
            $pos = 1;
            foreach ($parts as $value) {
                if (strpos($value, '=') !== false) {
                    list($rank, $pts) = array_map('trim', explode('=', $value, 2));
                    $rank = (int)$rank;
                    $points[$rank] = (int)$pts;
                } else {
                    $points[$pos] = (int)$value;
                    $pos++;
                }
            }
        } elseif (is_array($pointsRaw)) {
            foreach ($pointsRaw as $rank => $value) {
                $points[(int)$rank] = (int)$value;
            }
        }

        if (!empty($points)) {
            ksort($points, SORT_NUMERIC);
            $this->pointsTable = $points;
        }
    }

    private function parseVoteConfig(): void
    {
        $options = $this->config['vote_options'] ?? array();
        if (is_string($options)) {
            $options = preg_split('/[;,\s]+/', $options, -1, PREG_SPLIT_NO_EMPTY);
        }
        if (!is_array($options)) {
            $options = array();
        }

        $clean = array();
        foreach ($options as $option) {
            $option = strtoupper(trim((string)$option));
            if ($option !== '') {
                $clean[] = $option;
            }
        }
        $clean = array_values(array_unique($clean));
        $this->voteState['options'] = $clean;

        $duration = $this->config['vote_duration'] ?? $this->config['vote_seconds'] ?? 20;
        $this->voteState['duration'] = max(5, (int)$duration);

        $randomPools = array();
        foreach ($clean as $option) {
            $key = 'vote_random.' . strtolower($option);
            if (!isset($this->config[$key])) {
                continue;
            }
            $pool = $this->config[$key];
            if (is_string($pool)) {
                $pool = preg_split('/[;,\s]+/', $pool, -1, PREG_SPLIT_NO_EMPTY);
            }
            if (!is_array($pool)) {
                continue;
            }
            $list = array();
            foreach ($pool as $entry) {
                $entry = strtoupper(trim((string)$entry));
                if ($entry !== '') {
                    $list[] = $entry;
                }
            }
            if (!empty($list)) {
                $randomPools[$option] = array_values(array_unique($list));
            }
        }
        $this->voteState['randomPools'] = $randomPools;
    }

    private function getClassForCar(string $car): string
    {
        $car = strtoupper(trim($car));
        if ($car === '') {
            return '';
        }
        return $this->carToClass[$car] ?? '';
    }

    private function getNextClass(string $class): ?string
    {
        if (empty($this->classOrder)) {
            return null;
        }
        $index = array_search($class, $this->classOrder, true);
        if ($index === false) {
            return $this->classOrder[0] ?? null;
        }
        $index = ($index + 1) % count($this->classOrder);
        return $this->classOrder[$index] ?? null;
    }

    private function getRandomCarForClass(string $class): ?string
    {
        if (!isset($this->classCars[$class]) || empty($this->classCars[$class])) {
            return null;
        }
        $cars = $this->classCars[$class];
        return $cars[array_rand($cars)];
    }

    private function initialisePlayer(array &$player): void
    {
        if (!isset($player['state']['race']) || !is_array($player['state']['race'])) {
            $player['state']['race'] = array(
                'current_car' => '',
                'class' => '',
                'best_lap' => 0.0,
                'last_lap' => 0.0,
                'potential_points' => 0,
            );
        } else {
            $player['state']['race'] = array_replace(array(
                'current_car' => '',
                'class' => '',
                'best_lap' => 0.0,
                'last_lap' => 0.0,
                'potential_points' => 0,
            ), $player['state']['race']);
        }
    }

    private function resetSession(): void
    {
        $this->resetQualificationState(false);
        $this->pendingResults = array();
        $this->voteState['open'] = false;
        $this->voteState['votes'] = array();
        $this->voteState['deadline'] = 0;
    }

    private function resetQualificationState(bool $preserveVote): void
    {
        $this->firstCar = null;
        $this->firstOwner = null;
        $this->firstClass = null;
        $this->firstLapTime = null;
        $this->forcedClass = null;
        $this->forcedCar = null;
        $this->qualDeadline = 0;
        $this->playerFlags = array();
        $this->standingsDirty = true;
        $this->markAllDirty();
        $this->clearForcedCarRestriction();

        if (!$preserveVote) {
            $this->voteState['open'] = false;
            $this->voteState['votes'] = array();
            $this->voteState['deadline'] = 0;
        }
    }

    private function announce(string $message): void
    {
        Msg2Lfs()->Text($message)->send();
    }

    private function markAllDirty(): void
    {
        foreach ($this->plugin->getPlayerMap() as $ucid => $_) {
            $this->playerFlags[$ucid] = true;
        }
        $this->standingsDirty = true;
    }

    private function clearButtons(): void
    {
        foreach (array_keys($this->plugin->getPlayerMap()) as $ucid) {
            ButtonManager::removeButtonsByGroup($ucid, self::HUD_GROUP);
            ButtonManager::removeButtonsByGroup($ucid, self::VOTE_GROUP);
        }
    }

    private function broadcastForcedCarNotice(): void
    {
        if ($this->forcedCar === null) {
            return;
        }

        $message = sprintf('^7Forced qualifier car:^3 %s ^7(class ^3%s^7). Switch in pits!', $this->forcedCar, $this->forcedClass ?? '-');
        foreach ($this->plugin->getPlayerMap() as $ucid => $_) {
            if ($ucid > 0) {
                $this->plugin->MsgToUCID($ucid, $message);
            }
        }
    }

    private function applyForcedCarRestriction(): void
    {
        if ($this->forcedCar === null) {
            return;
        }

        $mask = $this->getCarMask($this->forcedCar);
        if ($mask === 0) {
            return;
        }

        foreach ($this->plugin->getPlayerMap() as $ucid => $_) {
            $this->applyForcedCarToPlayer($ucid, $mask);
        }
    }

    private function applyForcedCarToPlayer(int $ucid, ?int $mask = null): void
    {
        if ($ucid <= 0) {
            return;
        }

        if ($this->forcedCar === null) {
            $this->clearForcedCarRestrictionForPlayer($ucid);
            return;
        }

        $mask = $mask ?? $this->getCarMask($this->forcedCar);
        if ($mask === 0) {
            return;
        }

        IS_PLC()->UCID($ucid)->Cars($mask)->Send();
    }

    private function clearForcedCarRestriction(): void
    {
        foreach ($this->plugin->getPlayerMap() as $ucid => $_) {
            $this->clearForcedCarRestrictionForPlayer($ucid);
        }
    }

    private function clearForcedCarRestrictionForPlayer(int $ucid): void
    {
        if ($ucid <= 0) {
            return;
        }

        IS_PLC()->UCID($ucid)->Cars(0xFFFFFFFF)->Send();
    }

    private function getCarMask(string $car): int
    {
        $car = strtoupper(trim($car));
        if ($car === '') {
            return 0;
        }

        return self::CAR_MASKS[$car] ?? 0;
    }
}
