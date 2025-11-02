<?php
class ServerModes_CruiseSystems
{
    private const HUD_GROUP = 'CruiseHUD';
    private const BANK_GROUP = 'CruiseBank';
    private const TELEPORT_GROUP = 'CruiseTeleport';
    private const REGITRA_GROUP = 'CruiseRegitra';
    private const AFK_GROUP = 'CruiseAfk';
    private const DAILY_GROUP = 'CruiseDaily';
    public const POLICE_GROUP = 'CruisePolice';
    private const PRICE_GROUP = 'CruisePrice';

    private serverModes $plugin;
    private ServerModes_VehicleMods $vehicleMods;
    private bool $active = false;
    private array $config = array();
    private array $teleports = array();
    private array $trafficChecks = array();
    private array $jobs = array();
    private array $jobTriggers = array();
    private array $officers = array();
    private array $hudRendered = array();
    private array $afkQueue = array();
    private ?int $afkQueueLeader = null;
    private int $maxPlayers = 20;
    private array $dailyLeaderboardCache = array();
    private int $dailyLeaderboardLimit = 5;
    private int $dailyLeaderboardTtl = 30;

    public function __construct(serverModes $plugin, ServerModes_VehicleMods $vehicleMods, array $config = array())
    {
        $this->plugin = $plugin;
        $this->vehicleMods = $vehicleMods;
        $this->applyConfig($config);
    }

    public function applyConfig(array $config): void
    {
        $this->config = array_replace_recursive($this->config, $config);
        $this->teleports = $this->parseTeleports($this->config['teleports'] ?? array());
        $this->trafficChecks = $this->parseTrafficChecks($this->config['traffic_checks'] ?? array());
        $this->jobs = $this->parseJobs($this->config['jobs'] ?? array());
        $this->jobTriggers = $this->indexJobTriggers($this->jobs);
        $this->officers = $this->parseList($this->config['police_officers'] ?? '');

        $limit = $this->config['daily_leaderboard_limit'] ?? ($this->config['ui']['daily_leaderboard_limit'] ?? 5);
        $this->dailyLeaderboardLimit = max(3, (int)$limit);
        $ttl = $this->config['daily_leaderboard_ttl'] ?? 30;
        $this->dailyLeaderboardTtl = max(5, (int)$ttl);
        $this->dailyLeaderboardCache = array();

        $configuredMax = $this->config['max_players'] ?? ($this->config['limits']['max_players'] ?? $this->maxPlayers);
        $this->maxPlayers = max(1, (int)$configuredMax);
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function onActivate(): void
    {
        $this->active = true;
        $this->dailyLeaderboardCache = array();
    }

    public function onDeactivate(): void
    {
        $this->active = false;
        $this->clearAllButtons();
        $this->dailyLeaderboardCache = array();
    }

    public function onPlayerConnected(array &$player): void
    {
        $this->initialisePlayerState($player);
        $this->hudRendered[$player['ucid']] = false;
    }

    public function onPlayerDisconnected(array &$player): void
    {
        $ucid = $player['ucid'] ?? null;
        if ($ucid === null) {
            return;
        }

        ButtonManager::removeButtonsByGroup($ucid, self::HUD_GROUP);
        ButtonManager::removeButtonsByGroup($ucid, self::BANK_GROUP);
        ButtonManager::removeButtonsByGroup($ucid, self::TELEPORT_GROUP);
        ButtonManager::removeButtonsByGroup($ucid, self::REGITRA_GROUP);
        ButtonManager::removeButtonsByGroup($ucid, self::POLICE_GROUP);
        ButtonManager::removeButtonsByGroup($ucid, self::AFK_GROUP);
        ButtonManager::removeButtonsByGroup($ucid, self::DAILY_GROUP);
        ButtonManager::removeButtonsByGroup($ucid, self::PRICE_GROUP);
        unset($this->hudRendered[$ucid]);
        $this->removeAfkQueueEntry($ucid);
    }

    public function handleJoinAttempt(array &$player, IS_NPL $packet, ?string &$errorMessage = null): bool
    {
        if (!$this->active) {
            $errorMessage = null;
            return true;
        }

        $this->initialisePlayerState($player);

        $ucid = $player['ucid'] ?? 0;
        if ($ucid <= 0) {
            $errorMessage = null;
            return true;
        }

        $carCode = $this->vehicleMods->normaliseVehicleCode($packet->CName ?? '');
        if ($carCode === '') {
            $this->hideVehiclePriceButton($ucid);
            $errorMessage = null;
            return true;
        }

        $mod = $this->vehicleMods->getMod($carCode);
        $price = (float)($mod['price'] ?? 0.0);

        if ($price <= 0.0) {
            $this->hideVehiclePriceButton($ucid);

            $codeLabel = $this->vehicleMods->formatVehicleCode($carCode);
            if ($codeLabel === '') {
                $codeLabel = $this->vehicleMods->formatVehicleCode((string)($mod['hex_code'] ?? ''));
            }
            if ($codeLabel === '') {
                $codeLabel = strtoupper($carCode);
            }

            $nameCandidates = array(
                (string)($mod['display_name'] ?? ''),
                (string)($mod['short_name'] ?? ''),
                $this->vehicleMods->describeVehicle($carCode),
            );

            $displayName = '';
            foreach ($nameCandidates as $candidate) {
                $candidate = trim($candidate);
                if ($candidate !== '') {
                    $displayName = $candidate;
                    break;
                }
            }

            $plainName = preg_replace('/\^[0-9A-Z]/i', '', $displayName);
            if ($plainName === null) {
                $plainName = '';
            }
            $plainName = trim($plainName);

            if ($plainName !== '') {
                $errorMessage = sprintf('^1Price for ^3%s ^7[%s^7] ^1is not set. You cannot leave the pits.', $codeLabel, $plainName);
            } else {
                $errorMessage = sprintf('^1Price for ^3%s ^1is not set. You cannot leave the pits.', $codeLabel);
            }
            return false;
        }

        $this->showVehiclePriceButton($ucid, $price);
        $errorMessage = null;
        return true;
    }

    public function onPlayerJoinRace(array &$player, IS_NPL $packet): void
    {
        if (!$this->active) {
            return;
        }

        $this->initialisePlayerState($player);
        $state =& $player['state'];

        $carCode = $this->vehicleMods->normaliseVehicleCode($packet->CName ?? '');
        $skin = trim($packet->SName);
        $changed = false;

        if ($carCode !== '') {
            if (($state['garage']['active_car'] ?? '') !== $carCode) {
                $state['garage']['active_car'] = $carCode;
                $changed = true;
            }

            if (!isset($state['garage']['vehicles'][$carCode])) {
                $state['garage']['vehicles'][$carCode] = $this->createVehicleRecord();
                $changed = true;
            }

            if ($skin !== '') {
                if (($state['garage']['vehicles'][$carCode]['last_skin'] ?? '') !== $skin) {
                    $state['garage']['vehicles'][$carCode]['last_skin'] = $skin;
                    $changed = true;
                }
            }
        }

        if (($state['garage']['active_skin'] ?? '') !== $skin) {
            $state['garage']['active_skin'] = $skin;
            $changed = true;
        }

        if ($changed) {
            $this->markStateDirty($player);
        }

        if ($carCode !== '') {
            $price = (float)($state['garage']['vehicles'][$carCode]['mod']['price'] ?? 0.0);
            if ($price > 0.0) {
                $this->showVehiclePriceButton($player['ucid'], $price);
            } else {
                $this->hideVehiclePriceButton($player['ucid']);
            }
        } else {
            $this->hideVehiclePriceButton($player['ucid']);
        }

        $this->evaluateJobEligibility($player);
    }

    public function onVehicleSelected(array &$player, string $carCode): void
    {
        if (!$this->active) {
            return;
        }

        $ucid = (int)($player['ucid'] ?? 0);
        $carCode = $this->vehicleMods->normaliseVehicleCode($carCode);

        $this->initialisePlayerState($player);
        $state =& $player['state'];

        if ($carCode === '') {
            if (($state['garage']['active_car'] ?? '') !== '') {
                $state['garage']['active_car'] = '';
                $this->markStateDirty($player);
            }
            if ($ucid > 0) {
                $this->hideVehiclePriceButton($ucid);
            }
            return;
        }

        $previous = $state['garage']['active_car'] ?? '';
        if ($previous !== $carCode) {
            $state['garage']['active_car'] = $carCode;
            $this->markStateDirty($player);
        }

        $isNew = !isset($state['garage']['vehicles'][$carCode]);
        $this->vehicleMods->handleVehicleActivation($player, $carCode, $isNew);

        $state =& $player['state'];
        $vehicle = $state['garage']['vehicles'][$carCode] ?? null;
        $price = (float)($vehicle['mod']['price'] ?? 0.0);

        if ($ucid > 0) {
            if ($price > 0.0) {
                $this->showVehiclePriceButton($ucid, $price);
            } else {
                $this->hideVehiclePriceButton($ucid);
            }
        }
    }

    public function onPlayerLeaveRace(array &$player): void
    {
        if (!$this->active) {
            return;
        }

        $this->initialisePlayerState($player);

        $ucid = $player['ucid'] ?? null;
        if ($ucid !== null) {
            $this->hideVehiclePriceButton($ucid);
        }

        if ($this->isJobActive($player)) {
            $this->cancelJob($player, '^1Job cancelled: you left the track.');
        }

        $state =& $player['state'];
        $state['garage']['active_car'] = '';
        $state['garage']['active_skin'] = '';
        $this->markStateDirty($player);
    }

    public function onPlayerPits(array &$player): void
    {
        if (!$this->active) {
            return;
        }

        $ucid = $player['ucid'] ?? null;
        if ($ucid === null) {
            return;
        }

        $this->hideVehiclePriceButton($ucid);
    }

    public function onMovementReward(array &$player, float $deltaKm, float $speedKph, float $earnedMoney, float $earnedXp, CompCar $info): void
    {
        $this->initialisePlayerState($player);

        $state =& $player['state'];
        $state['economy']['cash'] += $earnedMoney;
        $state['stats']['xp'] = ($state['stats']['xp'] ?? 0.0) + $earnedXp;

        if ($deltaKm > 0.0) {
            $salaryRate = $this->getConfigNumber('salary_per_km', 10.0);
            if ($salaryRate > 0.0) {
                $state['economy']['salary_pool'] = ($state['economy']['salary_pool'] ?? 0.0) + ($deltaKm * $salaryRate);
            }

            $bonusKm = $this->getConfigNumber('salary_bonus_km', 100.0);
            $bonusAmount = $this->getConfigNumber('salary_bonus_amount', 1000.0);
            if ($bonusKm > 0.0) {
                $state['economy']['bonus_progress_km'] = ($state['economy']['bonus_progress_km'] ?? 0.0) + $deltaKm;

                while ($state['economy']['bonus_progress_km'] >= $bonusKm) {
                    $state['economy']['bonus_progress_km'] -= $bonusKm;
                    $state['economy']['bonus_tokens'] = ($state['economy']['bonus_tokens'] ?? 0) + 1;

                    $message = sprintf('^2Bonus earned ^3%s^2 for driving ^3%d ^7km.', $this->formatCurrency(max(0.0, $bonusAmount)), (int)$bonusKm);
                    $this->sendMessage($player['ucid'], $message);
                }
            }

            $this->updateJobProgress($player, $deltaKm);
        }

        if ($deltaKm > 0.0 || $speedKph > 1.0) {
            $state['telemetry']['last_move'] = time();
        }

        $state['telemetry']['speed'] = $speedKph;
        $state['telemetry']['position'] = array(
            'x' => $info->X / 65536.0,
            'y' => $info->Y / 65536.0,
        );

        $sampleTime = microtime(true);
        if ($this->updateAcceleration($player, $speedKph, $sampleTime)) {
            $this->markStateDirty($player);
        }

        $activeCar = $state['garage']['active_car'] ?? '';
        if ($activeCar !== '') {
            if (!isset($state['garage']['vehicles'][$activeCar])) {
                $state['garage']['vehicles'][$activeCar] = $this->createVehicleRecord();
            }
            $state['garage']['vehicles'][$activeCar]['distance'] += $deltaKm;
        }

        $totalDistance = ($player['lifetime']['distance_km'] ?? 0.0) + ($player['session']['distance_km'] ?? 0.0);
        $state['stats']['licenses'] = max($state['stats']['licenses'], (int)floor($totalDistance / max(1.0, $this->getConfigNumber('license_km', 44.0))));

        $this->markStateDirty($player);
    }

    public function onCarStateChange(array &$player, IS_CSC $packet): void
    {
        if (!$this->active) {
            return;
        }

        $this->initialisePlayerState($player);

        if (!isset($player['state']['telemetry']) || !is_array($player['state']['telemetry'])) {
            return;
        }

        $telemetry =& $player['state']['telemetry'];
        if (!isset($telemetry['accel']) || !is_array($telemetry['accel'])) {
            $telemetry['accel'] = $this->defaultAccelerationState();
        } else {
            $telemetry['accel'] = array_merge($this->defaultAccelerationState(), $telemetry['accel']);
        }

        $accel =& $telemetry['accel'];
        $changed = false;
        $now = microtime(true);
        $speed = (float)($telemetry['speed'] ?? 0.0);

        if ($packet->CSCAction === CSC_STOP) {
            if (!empty($accel['active']) || empty($accel['ready']) || ($accel['start'] ?? 0.0) > 0.0) {
                $accel['active'] = false;
                $accel['ready'] = true;
                $accel['start'] = 0.0;
                $accel['start_speed'] = 0.0;
                $accel['armed_at'] = $now;
                $accel['last_event'] = 'stop';
                $changed = true;
            }
        } elseif ($packet->CSCAction === CSC_START) {
            if (empty($accel['active']) && !empty($accel['ready']) && $speed <= 5.0) {
                $accel['active'] = true;
                $accel['ready'] = false;
                $accel['start'] = $now;
                $accel['start_speed'] = $speed;
                $accel['armed_at'] = 0.0;
                $accel['last_event'] = 'start';
                $changed = true;
            }
        }

        if ($changed) {
            $this->markStateDirty($player);
        }
    }

    public function onLapCompleted(array &$player, IS_LAP $lap): void
    {
        $this->initialisePlayerState($player);
        $player['state']['stats']['lap_count'] = ($player['state']['stats']['lap_count'] ?? 0) + 1;
        $this->markStateDirty($player);
    }

    public function tick(array &$players): void
    {
        if (!$this->active) {
            return;
        }

        foreach ($players as &$player) {
            $ucid = $player['ucid'] ?? null;
            if ($ucid === null || $ucid === 0) {
                continue;
            }

            $this->initialisePlayerState($player);
            $this->updateHud($player);
            $this->tickAfk($player);
            $this->tickSalary($player);
        }

        unset($player);
        $this->renderAfkQueue($players);
    }

    public function showBankUi(int $ucid): void
    {
        if (!$this->active) {
            return;
        }

        $player =& $this->plugin->getPlayerRecord($ucid);
        $this->initialisePlayerState($player);
        $state =& $player['state'];

        ButtonManager::removeButtonsByGroup($ucid, self::BANK_GROUP);

        $width = 90;
        $height = 48;
        $left = (int)((IS_X_MAX - $width) / 2);
        $top = 65;

        $this->drawButton($ucid, 'BankBG', self::BANK_GROUP, $left, $top, $width, $height, '', ISB_DARK);
        $this->drawButton($ucid, 'BankTitle', self::BANK_GROUP, $left + 2, $top + 3, $width - 4, 6, 'Bank', ISB_DARK | ISB_YELLOW);

        $balance = $this->formatCurrency($state['economy']['cash']);
        $bank = $this->formatCurrency($state['economy']['bank']);
        $interestRate = $this->getDynamicInterestRatePercent($player);
        $interest = number_format($interestRate, 3);
        $identityTag = $this->isIdentityVerified($player) ? '^2ID' : '^8ID';

        $salaryPool = $this->formatCurrency($state['economy']['salary_pool'] ?? 0.0);
        $bonusTokens = (int)($state['economy']['bonus_tokens'] ?? 0);
        $bonusProgressKm = $state['economy']['bonus_progress_km'] ?? 0.0;
        $bonusKm = $this->getConfigNumber('salary_bonus_km', 100.0);
        $bonusAmount = $this->getConfigNumber('salary_bonus_amount', 1000.0);
        $bonusValue = $this->formatCurrency($bonusTokens * max(0.0, $bonusAmount));
        $bonusPercent = $bonusKm > 0.0 ? min(100.0, ($bonusProgressKm / $bonusKm) * 100.0) : 0.0;

        $salaryReadyAt = (int)($state['economy']['salary_ready_at'] ?? time());
        $now = time();
        $salaryReady = $salaryReadyAt <= $now;
        $remaining = max(0, $salaryReadyAt - $now);
        $salaryLabel = $salaryReady ? '^2Ready' : '^8' . $this->formatSeconds($remaining);

        $lastSalary = $this->formatCurrency($state['economy']['last_salary'] ?? 0.0);
        $lastInterest = $this->formatCurrency($state['economy']['last_interest'] ?? 0.0);

        $lineTop = $top + 8;
        $lineStep = 7;

        $this->drawButton(
            $ucid,
            'BankBalance',
            self::BANK_GROUP,
            $left + 4,
            $lineTop,
            $width - 8,
            5,
            "^7Cash: ^2{$balance} ^7Bank: ^2{$bank} ^7Interest: ^3{$interest}% ^7{$identityTag}",
            ISB_DARK | ISB_LEFT
        );

        $lineTop += $lineStep;
        $this->drawButton(
            $ucid,
            'BankSalary',
            self::BANK_GROUP,
            $left + 4,
            $lineTop,
            $width - 8,
            5,
            "^7Salary pool:^2{$salaryPool} ^7Pending bonus:^2{$bonusValue} ^7Last:^2{$lastSalary}",
            ISB_DARK | ISB_LEFT
        );

        $lineTop += $lineStep;
        $bonusText = ($bonusKm > 0.0)
            ? sprintf('^7Bonuses:^2%d ^7Progress:^3%.1f%% (^3%.1f^7 km)', $bonusTokens, $bonusPercent, $bonusProgressKm)
            : '^7Bonuses:^8Disabled';
        $this->drawButton(
            $ucid,
            'BankBonus',
            self::BANK_GROUP,
            $left + 4,
            $lineTop,
            $width - 8,
            5,
            $bonusText,
            ISB_DARK | ISB_LEFT
        );

        $lineTop += $lineStep;
        $nextText = "^7Next payout: {$salaryLabel} ^7Last interest:^2{$lastInterest}";
        $this->drawButton(
            $ucid,
            'BankNext',
            self::BANK_GROUP,
            $left + 4,
            $lineTop,
            $width - 8,
            5,
            $nextText,
            ISB_DARK | ISB_LEFT
        );

        $buttons = array(
            array('key' => 'Deposit', 'label' => 'Deposit cash', 'style' => ISB_DARK | ISB_GREEN, 'action' => 'deposit'),
            array('key' => 'Withdraw', 'label' => 'Withdraw', 'style' => ISB_DARK | ISB_RED, 'action' => 'withdraw'),
            array('key' => 'Salary', 'label' => "Collect salary ({$salaryLabel})", 'style' => ISB_DARK | ISB_BLUE, 'action' => 'salary'),
        );

        $btnWidth = 26;
        $gap = 3;
        $startLeft = $left + (int)(($width - ((count($buttons) * $btnWidth) + ((count($buttons) - 1) * $gap))) / 2);
        $rowTop = $top + 34;

        foreach ($buttons as $index => $meta) {
            $this->drawButton(
                $ucid,
                'Bank' . $meta['key'],
                self::BANK_GROUP,
                $startLeft + ($index * ($btnWidth + $gap)),
                $rowTop,
                $btnWidth,
                8,
                $meta['label'],
                $meta['style'],
                array($ucid, 'bank', $meta['action'])
            );
        }

        $this->drawButton(
            $ucid,
            'BankClose',
            self::BANK_GROUP,
            $left + ($width / 2) - 12,
            $top + $height - 6,
            24,
            5,
            '^1Close',
            ISB_DARK | ISB_RED,
            array($ucid, 'bank', 'close')
        );
    }

    public function handleDailyAction(int $ucid, string $action): void
    {
        $player =& $this->plugin->getPlayerRecord($ucid);
        $this->initialisePlayerState($player);

        $state =& $player['state']['ui']['daily'];
        if (!is_array($state)) {
            $state = array(
                'visible' => true,
                'left' => 4,
                'top' => 120,
                'row_keys' => array(),
            );
            $this->markStateDirty($player);
        }

        switch ($action) {
            case 'toggle':
                $state['visible'] = !empty($state['visible']) ? false : true;
                if (!$state['visible']) {
                    ButtonManager::removeButtonsByGroup($ucid, self::DAILY_GROUP);
                }
                $this->markStateDirty($player);
                break;
            case 'up':
                $this->offsetDailyPanel($player, 0, -3);
                break;
            case 'down':
                $this->offsetDailyPanel($player, 0, 3);
                break;
            case 'left':
                $this->offsetDailyPanel($player, -3, 0);
                break;
            case 'right':
                $this->offsetDailyPanel($player, 3, 0);
                break;
        }
    }

    public function handleBankAction(int $ucid, string $action): void
    {
        $player =& $this->plugin->getPlayerRecord($ucid);
        $this->initialisePlayerState($player);
        $state =& $player['state'];

        switch ($action) {
            case 'deposit':
                $amount = min($state['economy']['cash'], $this->getConfigNumber('bank_deposit_step', 500.0));
                if ($amount <= 0.0) {
                    $this->sendMessage($ucid, '^1No cash available to deposit.');
                    break;
                }
                $state['economy']['cash'] -= $amount;
                $state['economy']['bank'] += $amount;
                $this->sendMessage($ucid, '^2Deposited ^3' . $this->formatCurrency($amount) . '^2 to bank.');
                $this->markStateDirty($player);
                break;
            case 'withdraw':
                $amount = min($state['economy']['bank'], $this->getConfigNumber('bank_withdraw_step', 500.0));
                if ($amount <= 0.0) {
                    $this->sendMessage($ucid, '^1No bank funds available to withdraw.');
                    break;
                }
                $state['economy']['bank'] -= $amount;
                $state['economy']['cash'] += $amount;
                $this->sendMessage($ucid, '^2Withdrew ^3' . $this->formatCurrency($amount) . '^2 from bank.');
                $this->markStateDirty($player);
                break;
            case 'salary':
                $now = time();
                $readyAt = (int)($state['economy']['salary_ready_at'] ?? $now);
                if ($readyAt > $now) {
                    $remaining = $readyAt - $now;
                    $this->sendMessage($ucid, '^8Salary not ready yet. Try again in ' . $this->formatSeconds($remaining) . '.');
                    break;
                }

                $salaryPool = $state['economy']['salary_pool'] ?? 0.0;
                $bonusTokens = (int)($state['economy']['bonus_tokens'] ?? 0);
                $bonusAmount = $this->getConfigNumber('salary_bonus_amount', 1000.0);
                $bonusTotal = $bonusTokens * max(0.0, $bonusAmount);
                $totalSalary = $salaryPool + $bonusTotal;

                $bankBalance = $state['economy']['bank'];
                $interestRate = $this->getDynamicInterestRatePercent($player) / 100.0;
                $interest = ($bankBalance > 0.0 && $interestRate > 0.0) ? $bankBalance * $interestRate : 0.0;

                $messages = array();

                if ($totalSalary > 0.0) {
                    $state['economy']['cash'] += $totalSalary;
                    $state['economy']['last_salary'] = $totalSalary;
                    $bonusNote = ($bonusTokens > 0) ? sprintf(' ^7(%d bonus%s)', $bonusTokens, $bonusTokens === 1 ? '' : 'es') : '';
                    $messages[] = sprintf('^2Salary ^3%s%s', $this->formatCurrency($totalSalary), $bonusNote);
                } else {
                    $state['economy']['last_salary'] = 0.0;
                }

                if ($interest > 0.0) {
                    $state['economy']['bank'] += $interest;
                    $state['economy']['last_interest'] = $interest;
                    $messages[] = '^2Interest ^3' . $this->formatCurrency($interest);
                } else {
                    $state['economy']['last_interest'] = 0.0;
                }

                if (empty($messages)) {
                    $messages[] = '^8No salary or interest available yet. Keep driving!';
                }

                $state['economy']['salary_pool'] = 0.0;
                $state['economy']['bonus_tokens'] = 0;
                $state['economy']['salary_ready_at'] = $now + (int)$this->getConfigNumber('salary_interval', 2700);
                $state['economy']['salary_notified'] = false;

                $this->sendMessage($ucid, implode(' ^7| ', $messages));
                $this->markStateDirty($player);
                break;
            case 'close':
                ButtonManager::removeButtonsByGroup($ucid, self::BANK_GROUP);
                return;
        }

        $this->showBankUi($ucid);
    }

    public function showTeleportMenu(int $ucid): void
    {
        if (!$this->active) {
            return;
        }

        if (empty($this->teleports)) {
            $this->sendMessage($ucid, '^1No teleport locations configured.');
            return;
        }

        ButtonManager::removeButtonsByGroup($ucid, self::TELEPORT_GROUP);

        $width = 60;
        $left = (int)((IS_X_MAX - $width) / 2);
        $top = 80;

        $this->drawButton($ucid, 'TeleportBG', self::TELEPORT_GROUP, $left, $top, $width, 8 + (count($this->teleports) * 7), '', ISB_DARK);
        $this->drawButton($ucid, 'TeleportTitle', self::TELEPORT_GROUP, $left + 2, $top + 2, $width - 4, 6, '^7Select teleport location', ISB_DARK | ISB_YELLOW);

        $row = 0;
        foreach ($this->teleports as $key => $teleport) {
            $text = sprintf('^3> ^7%s', $teleport['name']);
            $this->drawButton(
                $ucid,
                'Teleport' . $key,
                self::TELEPORT_GROUP,
                $left + 2,
                $top + 10 + ($row * 7),
                $width - 4,
                6,
                $text,
                ISB_DARK | ISB_GREEN,
                array($ucid, 'teleport', $key)
            );
            $row++;
        }

        $this->drawButton(
            $ucid,
            'TeleportClose',
            self::TELEPORT_GROUP,
            $left + ($width / 2) - 12,
            $top + 10 + ($row * 7),
            24,
            5,
            '^1Close',
            ISB_DARK | ISB_RED,
            array($ucid, 'teleport', 'close')
        );
    }

    public function handleTeleport(int $ucid, string $key): void
    {
        if ($key === 'close') {
            ButtonManager::removeButtonsByGroup($ucid, self::TELEPORT_GROUP);
            return;
        }

        if (!isset($this->teleports[$key])) {
            $this->sendMessage($ucid, '^1Unknown teleport destination.');
            return;
        }

        $info = $this->teleports[$key];
        $client = $this->plugin->getClientInfo($ucid);
        if (!$client) {
            return;
        }

        $command = $info['command'] ?? '/ujoin %s';
        $cmd = sprintf($command, $client->UName);
        IS_MST()->Msg($cmd)->Send();
        $this->sendMessage($ucid, '^2Teleport request sent to ^3' . $info['name'] . '^2.');
        ButtonManager::removeButtonsByGroup($ucid, self::TELEPORT_GROUP);
    }

    public function showRegitraMenu(int $ucid): void
    {
        if (!$this->active) {
            return;
        }

        $player =& $this->plugin->getPlayerRecord($ucid);
        $this->initialisePlayerState($player);
        $state =& $player['state'];
        $car = $state['garage']['active_car'] ?? '';

        if ($car === '') {
            $this->sendMessage($ucid, '^1No active car detected. Enter the track to register.');
            return;
        }

        if (!isset($state['garage']['vehicles'][$car])) {
            $state['garage']['vehicles'][$car] = $this->createVehicleRecord();
        }

        $vehicle =& $state['garage']['vehicles'][$car];
        if (empty($vehicle['mod'])) {
            $this->vehicleMods->handleVehicleActivation($player, $car, false);
            $vehicle =& $state['garage']['vehicles'][$car];
        }

        $codeLabel = $this->vehicleMods->formatVehicleCode($car);
        if ($codeLabel === '') {
            $codeLabel = $car;
        }
        $carLabel = $this->vehicleMods->describeVehicle($car);
        if ($carLabel === '') {
            $carLabel = $codeLabel;
        }

        ButtonManager::removeButtonsByGroup($ucid, self::REGITRA_GROUP);

        $width = 70;
        $height = 60;
        $left = (int)((IS_X_MAX - $width) / 2);
        $top = 40;

        $this->drawButton($ucid, 'RegitraBG', self::REGITRA_GROUP, $left, $top, $width, $height, '', ISB_DARK);
        $this->drawButton($ucid, 'RegitraTitle', self::REGITRA_GROUP, $left + 2, $top + 3, $width - 4, 6, '^7REGITRA', ISB_DARK | ISB_YELLOW);

        $plate = $vehicle['plate'] ?? '---:---';
        $insuranceUntil = $vehicle['insurance_until'] ?? 0;
        $insuranceLabel = $insuranceUntil > time() ? '^2Valid' : '^1Expired';
        $modLabel = trim((string)($vehicle['mod']['name'] ?? ''));
        if ($modLabel === '') {
            $modLabel = $carLabel;
        }

        $carText = sprintf('^7Car: ^3%s', $carLabel);
        if ($codeLabel !== '' && strcasecmp($carLabel, $codeLabel) !== 0) {
            $carText .= sprintf(' ^8(%s)', $codeLabel);
        }

        $this->drawButton($ucid, 'RegitraCar', self::REGITRA_GROUP, $left + 2, $top + 11, $width - 4, 6, $carText, ISB_DARK | ISB_LEFT);
        $this->drawButton($ucid, 'RegitraMod', self::REGITRA_GROUP, $left + 2, $top + 19, $width - 4, 6, '^7Vehicle: ^3' . $modLabel, ISB_DARK | ISB_LEFT);
        $this->drawButton($ucid, 'RegitraPlate', self::REGITRA_GROUP, $left + 2, $top + 27, $width - 4, 6, '^7Plate: ^3' . $plate, ISB_DARK | ISB_LEFT);
        $this->drawButton($ucid, 'RegitraInsurance', self::REGITRA_GROUP, $left + 2, $top + 35, $width - 4, 6, '^7Insurance: ' . $insuranceLabel, ISB_DARK | ISB_LEFT);

        $buttonRow = $top + 43;

        if ($plate === '---:---') {
            $this->drawButton(
                $ucid,
                'RegitraRegister',
                self::REGITRA_GROUP,
                $left + 2,
                $buttonRow,
                $width - 4,
                8,
                '^7Register vehicle (^3150)',
                ISB_DARK | ISB_GREEN,
                array($ucid, 'regitra', 'register')
            );
        } else {
            $this->drawButton(
                $ucid,
                'RegitraChange',
                self::REGITRA_GROUP,
                $left + 2,
                $buttonRow,
                $width - 4,
                8,
                '^7Change plate (^3250)',
                ISB_DARK | ISB_BLUE,
                array($ucid, 'regitra', 'change')
            );
            $this->drawButton(
                $ucid,
                'RegitraInsuranceAction',
                self::REGITRA_GROUP,
                $left + 2,
                $buttonRow + 10,
                $width - 4,
                8,
                '^7Renew insurance (^3500)',
                ISB_DARK | ISB_GREEN,
                array($ucid, 'regitra', 'insurance')
            );
        }

        $this->drawButton(
            $ucid,
            'RegitraClose',
            self::REGITRA_GROUP,
            $left + ($width / 2) - 12,
            $top + $height - 6,
            24,
            5,
            '^1Close',
            ISB_DARK | ISB_RED,
            array($ucid, 'regitra', 'close')
        );
    }

    public function handleRegitraAction(int $ucid, string $action): void
    {
        if ($action === 'close') {
            ButtonManager::removeButtonsByGroup($ucid, self::REGITRA_GROUP);
            return;
        }

        $player =& $this->plugin->getPlayerRecord($ucid);
        $this->initialisePlayerState($player);
        $state =& $player['state'];
        $car = $state['garage']['active_car'] ?? '';
        if ($car === '') {
            $this->sendMessage($ucid, '^1No active car detected.');
            return;
        }

        if (!isset($state['garage']['vehicles'][$car])) {
            $state['garage']['vehicles'][$car] = $this->createVehicleRecord();
        }

        $vehicle =& $state['garage']['vehicles'][$car];

        switch ($action) {
            case 'register':
                if ($vehicle['plate'] !== '---:---') {
                    $this->sendMessage($ucid, '^8Vehicle already registered.');
                    break;
                }
                if (!$this->deductCash($state, 150.0)) {
                    $this->sendMessage($ucid, '^1Not enough cash to register vehicle.');
                    break;
                }
                $vehicle['plate'] = $this->generatePlate();
                $this->sendMessage($ucid, '^2Vehicle registered with plate ^3' . $vehicle['plate']);
                $this->markStateDirty($player);
                break;
            case 'change':
                if (!$this->deductCash($state, 250.0)) {
                    $this->sendMessage($ucid, '^1Not enough cash to change plate.');
                    break;
                }
                $vehicle['plate'] = $this->generatePlate();
                $this->sendMessage($ucid, '^2New plate issued: ^3' . $vehicle['plate']);
                $this->markStateDirty($player);
                break;
            case 'insurance':
                if (!$this->deductCash($state, 500.0)) {
                    $this->sendMessage($ucid, '^1Not enough cash to renew insurance.');
                    break;
                }
                $vehicle['insurance_until'] = time() + (7 * 24 * 3600);
                $this->sendMessage($ucid, '^2Insurance renewed for 7 days.');
                $this->markStateDirty($player);
                break;
        }

        $this->showRegitraMenu($ucid);
    }

    public function showPoliceMenu(int $ucid): void
    {
        if (!$this->active) {
            return;
        }

        $player =& $this->plugin->getPlayerRecord($ucid);
        if (!$this->isOfficer($player)) {
            $this->sendMessage($ucid, '^1Only officers can access the police panel.');
            return;
        }

        $targets = $this->collectNearbyPlayers($player, 200.0);
        ButtonManager::removeButtonsByGroup($ucid, self::POLICE_GROUP);

        $width = 90;
        $left = (int)((IS_X_MAX - $width) / 2);
        $top = 40;

        $this->drawButton($ucid, 'PoliceBG', self::POLICE_GROUP, $left, $top, $width, 10 + (count($targets) * 7), '', ISB_DARK);
        $this->drawButton($ucid, 'PoliceTitle', self::POLICE_GROUP, $left + 2, $top + 2, $width - 4, 6, '^7Nearest players', ISB_DARK | ISB_YELLOW);

        if (empty($targets)) {
            $this->drawButton($ucid, 'PoliceNone', self::POLICE_GROUP, $left + 2, $top + 10, $width - 4, 6, '^8No nearby players.', ISB_DARK | ISB_LEFT);
        }

        $row = 0;
        foreach ($targets as $targetUcid => $info) {
            $label = sprintf('^7%s ^3%.0f m ^2%.0f km/h', $info['name'], $info['distance'], $info['speed']);
            $this->drawButton(
                $ucid,
                'PoliceTarget' . $targetUcid,
                self::POLICE_GROUP,
                $left + 2,
                $top + 10 + ($row * 7),
                $width - 4,
                6,
                $label,
                ISB_DARK | ISB_GREEN,
                array($ucid, 'police_inspect', $targetUcid)
            );
            $row++;
        }

        $this->drawButton(
            $ucid,
            'PoliceClose',
            self::POLICE_GROUP,
            $left + ($width / 2) - 12,
            $top + 10 + ($row * 7),
            24,
            5,
            '^1Close',
            ISB_DARK | ISB_RED,
            array($ucid, 'police', 'close')
        );
    }

    public function showPolicePanel(int $officerUcid, int $targetUcid): void
    {
        $officer =& $this->plugin->getPlayerRecord($officerUcid);
        if (!$this->isOfficer($officer)) {
            $this->sendMessage($officerUcid, '^1Only officers can inspect players.');
            return;
        }

        $targetClient = $this->plugin->getClientInfo($targetUcid);
        if (!$targetClient) {
            $this->sendMessage($officerUcid, '^1Target player left.');
            return;
        }

        $target =& $this->plugin->getPlayerRecord($targetUcid);
        $this->initialisePlayerState($target);

        ButtonManager::removeButtonsByGroup($officerUcid, self::POLICE_GROUP);

        $width = 90;
        $left = (int)((IS_X_MAX - $width) / 2);
        $top = 40;

        $this->drawButton($officerUcid, 'PolicePanelBG', self::POLICE_GROUP, $left, $top, $width, 52, '', ISB_DARK);
        $this->drawButton($officerUcid, 'PolicePanelTitle', self::POLICE_GROUP, $left + 2, $top + 2, $width - 4, 6, '^7Officer panel', ISB_DARK | ISB_YELLOW);

        $cash = $this->formatCurrency($target['state']['economy']['cash']);
        $safety = (int)($target['state']['police']['safety_points'] ?? 500);
        $wanted = (int)($target['state']['police']['wanted_level'] ?? 0);

        $activeCode = trim((string)($target['state']['garage']['active_car'] ?? ''));
        $activeLabel = 'N/A';
        if ($activeCode !== '') {
            $codeLabel = $this->vehicleMods->formatVehicleCode($activeCode);
            if ($codeLabel === '') {
                $codeLabel = $activeCode;
            }
            $carLabel = $this->vehicleMods->describeVehicle($activeCode);
            if ($carLabel === '') {
                $carLabel = $codeLabel;
            }

            $activeLabel = $carLabel;
            if ($codeLabel !== '' && strcasecmp($carLabel, $codeLabel) !== 0) {
                $activeLabel .= sprintf(' ^8(%s)', $codeLabel);
            }
        }

        $infoLines = array(
            '^7Name: ^3' . $targetClient->PName,
            '^7Car: ^3' . $activeLabel,
            '^7Cash: ^3' . $cash,
            '^7Safety: ^3' . $safety,
            '^7Wanted level: ^3' . $wanted,
        );

        foreach ($infoLines as $index => $line) {
            $this->drawButton($officerUcid, 'PoliceInfo' . $index, self::POLICE_GROUP, $left + 2, $top + 10 + ($index * 6), $width - 4, 5, $line, ISB_DARK | ISB_LEFT);
        }

        $actions = array(
            array('key' => 'fine_small', 'label' => '^7Fine ^3Small (150)', 'amount' => 150.0),
            array('key' => 'fine_medium', 'label' => '^7Fine ^3Medium (350)', 'amount' => 350.0),
            array('key' => 'fine_large', 'label' => '^7Fine ^3Large (600)', 'amount' => 600.0),
        );

        $rowTop = $top + 10 + (count($infoLines) * 6) + 4;
        foreach ($actions as $index => $meta) {
            $this->drawButton(
                $officerUcid,
                'PoliceAction' . $meta['key'],
                self::POLICE_GROUP,
                $left + 2,
                $rowTop + ($index * 7),
                $width - 4,
                6,
                $meta['label'],
                ISB_DARK | ISB_GREEN,
                array($officerUcid, 'police_fine', $targetUcid, $meta['amount'])
            );
        }

        $this->drawButton(
            $officerUcid,
            'PoliceRelease',
            self::POLICE_GROUP,
            $left + 2,
            $rowTop + (count($actions) * 7),
            $width - 4,
            6,
            '^7Release without fine',
            ISB_DARK | ISB_BLUE,
            array($officerUcid, 'police_release', $targetUcid)
        );

        $this->drawButton(
            $officerUcid,
            'PoliceBack',
            self::POLICE_GROUP,
            $left + ($width / 2) - 12,
            $top + 52 - 6,
            24,
            5,
            '^1Back',
            ISB_DARK | ISB_RED,
            array($officerUcid, 'police', 'back')
        );
    }

    public function handlePoliceAction(int $officerUcid, string $mode, int $targetUcid, float $amount = 0.0): void
    {
        switch ($mode) {
            case 'police':
                ButtonManager::removeButtonsByGroup($officerUcid, self::POLICE_GROUP);
                break;
            case 'police_inspect':
                $this->showPolicePanel($officerUcid, $targetUcid);
                return;
            case 'police_fine':
                $this->issueFine($officerUcid, $targetUcid, $amount);
                ButtonManager::removeButtonsByGroup($officerUcid, self::POLICE_GROUP);
                return;
            case 'police_release':
                $targetClient = $this->plugin->getClientInfo($targetUcid);
                if ($targetClient) {
                    $this->sendMessage($targetUcid, '^2You have been released by the officer.');
                }
                $this->sendMessage($officerUcid, '^2Player released.');
                ButtonManager::removeButtonsByGroup($officerUcid, self::POLICE_GROUP);
                return;
            case 'police':
            default:
                break;
        }
    }

    public function handleUserControlObject(IS_UCO $packet): void
    {
        if (!$this->active) {
            return;
        }

        if ($packet->UCOAction !== UCO_CIRCLE_ENTER) {
            return;
        }

        $ucid = $this->plugin->getUcidByPlid($packet->PLID);
        if ($ucid === null) {
            return;
        }

        $player =& $this->plugin->getPlayerRecord($ucid);
        $this->initialisePlayerState($player);

        if (!empty($this->trafficChecks)) {
            $this->processTrafficCircle($player, $packet);
        }

        if (!empty($this->jobTriggers)) {
            $this->processJobCircle($player, $packet);
        }
    }

    public function showGarage(int $ucid): void
    {
        if (!$this->active) {
            return;
        }

        $player =& $this->plugin->getPlayerRecord($ucid);
        $this->initialisePlayerState($player);
        $vehicles =& $player['state']['garage']['vehicles'];

        foreach ($vehicles as $code => &$vehicle) {
            if (empty($vehicle['mod'])) {
                $this->vehicleMods->handleVehicleActivation($player, $code, false);
                $vehicle = $player['state']['garage']['vehicles'][$code];
            }
        }
        unset($vehicle);

        ButtonManager::removeButtonsByGroup($ucid, self::REGITRA_GROUP);

        $width = 110;
        $height = 20 + (count($vehicles) * 6);
        $left = (int)((IS_X_MAX - $width) / 2);
        $top = 60;

        $this->drawButton($ucid, 'GarageBG', self::REGITRA_GROUP, $left, $top, $width, max(20, $height), '', ISB_DARK);
        $this->drawButton($ucid, 'GarageTitle', self::REGITRA_GROUP, $left + 2, $top + 2, $width - 4, 6, '^7Garage vehicles', ISB_DARK | ISB_YELLOW);

        if (empty($vehicles)) {
            $this->drawButton($ucid, 'GarageEmpty', self::REGITRA_GROUP, $left + 2, $top + 10, $width - 4, 6, '^8No owned vehicles stored.', ISB_DARK | ISB_LEFT);
        }

        $row = 0;
        foreach ($vehicles as $code => $vehicle) {
            $plate = $vehicle['plate'] ?? '---:---';
            $distance = $vehicle['distance'] ?? 0.0;
            $label = trim((string)($vehicle['mod']['name'] ?? ''));
            if ($label === '') {
                $label = $this->vehicleMods->describeVehicle($code);
            }
            if ($label === '') {
                $label = $code;
            }
            $codeLabel = $this->vehicleMods->formatVehicleCode($code);
            if ($codeLabel === '') {
                $codeLabel = $code;
            }
            $text = sprintf('^3%s ^7(%s) ^8| ^7Plate:^3 %s ^8| ^7%.2f km', $label, $codeLabel, $plate, $distance);
            $this->drawButton($ucid, 'GarageItem' . $row, self::REGITRA_GROUP, $left + 2, $top + 10 + ($row * 6), $width - 4, 5, $text, ISB_DARK | ISB_LEFT);
            $row++;
        }

        $this->drawButton(
            $ucid,
            'GarageClose',
            self::REGITRA_GROUP,
            $left + ($width / 2) - 12,
            $top + max(20, $height) - 6,
            24,
            5,
            '^1Close',
            ISB_DARK | ISB_RED,
            array($ucid, 'regitra', 'close')
        );
    }

    public function handleVehiclePriceCommand(int $ucid, string $identifier, float $price): void
    {
        if (!$this->active) {
            $this->sendMessage($ucid, '^1Cruise mode is not active.');
            return;
        }

        $identifier = trim($identifier);
        if ($identifier === '') {
            $this->sendMessage($ucid, '^1Usage:^7 !carprice <code> <price>');
            return;
        }

        $player =& $this->plugin->getPlayerRecord($ucid);
        $this->initialisePlayerState($player);

        if (!$this->isOfficer($player)) {
            $this->sendMessage($ucid, '^1Only officers can change vehicle prices.');
            return;
        }

        $updatedMod = $this->vehicleMods->updateModPrice($identifier, $price);
        if ($updatedMod === null) {
            $this->sendMessage($ucid, '^1Unknown vehicle identifier.');
            return;
        }

        $this->applyVehiclePriceToGarage($updatedMod);

        $codeSource = (string)($updatedMod['hex_code'] ?? $updatedMod['car_code'] ?? $identifier);
        $codeLabel = $this->vehicleMods->formatVehicleCode($codeSource);
        if ($codeLabel === '') {
            $codeLabel = $this->vehicleMods->formatVehicleCode($identifier);
        }
        if ($codeLabel === '') {
            $codeLabel = strtoupper($identifier);
        }

        $displayName = trim((string)($updatedMod['display_name'] ?? $updatedMod['short_name'] ?? ''));
        if ($displayName === '') {
            $displayName = $this->vehicleMods->describeVehicle($codeSource);
        }
        $displayName = preg_replace('/\^[0-9A-Z]/i', '', $displayName ?? '');
        if ($displayName === null) {
            $displayName = '';
        }

        $priceLabel = $this->formatCurrency((float)$updatedMod['price']);

        if ($displayName !== '') {
            $this->sendMessage(
                $ucid,
                sprintf('^2Set price for ^3%s ^7[%s^7] ^2to ^3%s', $codeLabel, $displayName, $priceLabel)
            );
        } else {
            $this->sendMessage(
                $ucid,
                sprintf('^2Set price for ^3%s ^2to ^3%s', $codeLabel, $priceLabel)
            );
        }
    }

    private function updateHud(array &$player): void
    {
        $ucid = $player['ucid'];
        $state =& $player['state'];

        $cash = $this->formatCurrency($state['economy']['cash']);
        $bank = $this->formatCurrency($state['economy']['bank']);
        $xpTotal = $player['lifetime']['xp'] + $player['session']['xp'];
        $distanceTotal = $player['lifetime']['distance_km'] + $player['session']['distance_km'];
        $speed = number_format($state['telemetry']['speed'], 0);
        $licenses = $state['stats']['licenses'] ?? 0;

        $lineOne = sprintf('^7Cash:^2%s  ^7Bank:^2%s  ^7XP:^3%s  ^7Dist:^3%.1f km  ^7Speed:^3%s km/h  ^7Lic:^3%d', $cash, $bank, number_format($xpTotal, 1), $distanceTotal, $speed, $licenses);

        $sessionMoney = $player['session']['money'];
        $sessionDistance = $player['session']['distance_km'];
        $safety = $this->formatStars($state['police']['safety_points'] ?? 500);

        $lineTwo = sprintf('^7Session:^3%s ^7Dist:^3%.2f km ^7Safety:%s', $this->formatSignedCurrency($sessionMoney), $sessionDistance, $safety);

        $accel = $state['telemetry']['accel'] ?? array();
        $lastAccel = $this->formatInterval((float)($accel['last'] ?? 0.0));
        $bestAccel = $this->formatInterval((float)($accel['best'] ?? 0.0));
        if (!empty($accel['active'])) {
            $status = '^3Measuring';
        } elseif (!empty($accel['ready'])) {
            $status = '^2Ready';
        } else {
            $lastEvent = $accel['last_event'] ?? '';
            if ($lastEvent === 'complete') {
                $status = '^2Complete';
            } else {
                $status = '^8Idle';
            }
        }
        $accelLine = sprintf('^70-100:^3%s ^8| ^7Best:^3%s ^8| ^7Status:%s', $lastAccel, $bestAccel, $status);

        $jobLine = null;
        if (is_array($state['jobs']['active'] ?? null)) {
            $job = $state['jobs']['active'];
            $distance = $job['distance'] ?? 0.0;
            $earned = $job['earned'] ?? 0.0;
            $jobName = $job['name'] ?? $job['id'] ?? 'Job';
            $jobLine = sprintf('^7Job:^3%s ^7Dist:^3%.1f km ^7Pay:^3%s', $jobName, $distance, $this->formatCurrency($earned));
        }

        $this->drawButton($ucid, 'HudMain', self::HUD_GROUP, 0, 0, 200, 4, $lineOne, ISB_DARK | ISB_LEFT | ISB_CLICK);
        $this->drawButton($ucid, 'HudAccel', self::HUD_GROUP, 0, 188, 200, 4, $accelLine, ISB_DARK | ISB_LEFT);
        if ($jobLine !== null) {
            $this->drawButton($ucid, 'HudJob', self::HUD_GROUP, 0, 192, 200, 4, $jobLine, ISB_DARK | ISB_LEFT);
            $this->drawButton($ucid, 'HudSession', self::HUD_GROUP, 0, 196, 200, 4, $lineTwo, ISB_DARK | ISB_LEFT);
        } else {
            ButtonManager::removeButtonByKey($ucid, 'HudJob');
            $this->drawButton($ucid, 'HudSession', self::HUD_GROUP, 0, 196, 200, 4, $lineTwo, ISB_DARK | ISB_LEFT);
        }

        $this->renderDailyLeaderboard($player);

        $this->hudRendered[$ucid] = true;
    }

    private function renderDailyLeaderboard(array &$player): void
    {
        $ucid = $player['ucid'] ?? 0;
        if ($ucid === 0) {
            return;
        }

        $state =& $player['state']['ui']['daily'];
        if (!is_array($state)) {
            $state = array(
                'visible' => true,
                'left' => 4,
                'top' => 120,
                'row_keys' => array(),
            );
            $this->markStateDirty($player);
        } else {
            $defaults = array(
                'visible' => true,
                'left' => 4,
                'top' => 120,
                'row_keys' => array(),
            );
            $merged = array_replace($defaults, $state);
            if ($merged !== $state) {
                $state = $merged;
                $this->markStateDirty($player);
            }
        }

        if (empty($state['visible'])) {
            $left = max(0, min(IS_X_MAX - 60, (int)$state['left']));
            $top = max(20, min(IS_Y_MAX - 6, (int)$state['top']));

            ButtonManager::removeButtonsByGroup($ucid, self::DAILY_GROUP);
            $this->drawButton(
                $ucid,
                'DailyCollapsed',
                self::DAILY_GROUP,
                $left,
                $top,
                60,
                5,
                '^2Show daily top',
                ISB_DARK | ISB_CLICK | ISB_LEFT,
                array($ucid, 'daily', 'toggle')
            );

            if (($state['row_keys'] ?? null) !== array('DailyCollapsed')) {
                $state['row_keys'] = array('DailyCollapsed');
                $this->markStateDirty($player);
            }

            return;
        }

        $focusUserId = $player['user_id'] ?? 0;
        $leaderboard = $this->buildDailyLeaderboard($focusUserId, $player);
        $rows = $leaderboard['rows'];

        $left = max(0, min(IS_X_MAX - 74, (int)$state['left']));
        $top = max(20, min(IS_Y_MAX - 60, (int)$state['top']));
        $rowCount = max(1, count($rows));
        $height = 16 + ($rowCount * 6) + 8;

        $this->drawButton($ucid, 'DailyBG', self::DAILY_GROUP, $left, $top, 74, $height, '', ISB_DARK);
        $this->drawButton($ucid, 'DailyTitle', self::DAILY_GROUP, $left + 2, $top + 2, 70, 6, '^7Top drivers today', ISB_DARK | ISB_YELLOW);

        $this->drawButton(
            $ucid,
            'DailyToggle',
            self::DAILY_GROUP,
            $left + 44,
            $top + 2,
            28,
            6,
            '^1Hide',
            ISB_DARK | ISB_CLICK | ISB_LEFT,
            array($ucid, 'daily', 'toggle')
        );

        $activeKeys = array('DailyBG', 'DailyTitle', 'DailyToggle');
        $rowTop = $top + 10;

        if (empty($rows)) {
            $this->drawButton($ucid, 'DailyRowEmpty', self::DAILY_GROUP, $left + 2, $rowTop, 70, 5, '^8No distance recorded yet.', ISB_DARK | ISB_LEFT);
            $activeKeys[] = 'DailyRowEmpty';
        } else {
            foreach ($rows as $index => $row) {
                $rank = (int)($row['rank'] ?? ($index + 1));
                $name = $row['nickname'] !== '' ? $row['nickname'] : $row['username'];
                $distance = $this->formatDistance((float)($row['distance'] ?? 0.0));
                $highlight = ($focusUserId > 0 && $row['user_id'] === $focusUserId);
                $color = $highlight ? '^2' : '^3';
                $text = sprintf('%s%2d.^7 %s ^8%s km', $color, $rank, $name, $distance);

                $rowKey = 'DailyRow' . $rank;
                $this->drawButton($ucid, $rowKey, self::DAILY_GROUP, $left + 2, $rowTop + ($index * 6), 70, 5, $text, ISB_DARK | ISB_LEFT);
                $activeKeys[] = $rowKey;
            }
        }

        $controlsTop = $top + $height - 8;
        $this->drawButton($ucid, 'DailyMoveUp', self::DAILY_GROUP, $left + 24, $controlsTop, 6, 5, '^', ISB_DARK | ISB_CLICK, array($ucid, 'daily', 'up'));
        $this->drawButton($ucid, 'DailyMoveLeft', self::DAILY_GROUP, $left + 16, $controlsTop + 5, 6, 5, '<', ISB_DARK | ISB_CLICK, array($ucid, 'daily', 'left'));
        $this->drawButton($ucid, 'DailyMoveDown', self::DAILY_GROUP, $left + 24, $controlsTop + 5, 6, 5, 'v', ISB_DARK | ISB_CLICK, array($ucid, 'daily', 'down'));
        $this->drawButton($ucid, 'DailyMoveRight', self::DAILY_GROUP, $left + 32, $controlsTop + 5, 6, 5, '>', ISB_DARK | ISB_CLICK, array($ucid, 'daily', 'right'));
        $activeKeys = array_merge($activeKeys, array('DailyMoveUp', 'DailyMoveLeft', 'DailyMoveDown', 'DailyMoveRight'));

        $previousKeys = is_array($state['row_keys']) ? $state['row_keys'] : array();
        $rowKeys = array();
        foreach ($activeKeys as $key) {
            if (strpos($key, 'DailyRow') === 0 || $key === 'DailyRowEmpty') {
                $rowKeys[] = $key;
            }
        }

        foreach ($previousKeys as $key) {
            if (!in_array($key, $rowKeys, true)) {
                ButtonManager::removeButtonByKey($ucid, $key);
            }
        }

        if ($rowKeys !== $previousKeys) {
            $state['row_keys'] = $rowKeys;
            $this->markStateDirty($player);
        }
    }

    private function offsetDailyPanel(array &$player, int $dx, int $dy): void
    {
        $state =& $player['state']['ui']['daily'];
        if (!is_array($state)) {
            $state = array(
                'visible' => true,
                'left' => 4,
                'top' => 120,
                'row_keys' => array(),
            );
        }

        $state['left'] = max(0, min(IS_X_MAX - 74, (int)($state['left'] ?? 4) + $dx));
        $state['top'] = max(20, min(IS_Y_MAX - 60, (int)($state['top'] ?? 120) + $dy));
        $this->markStateDirty($player);
    }

    private function buildDailyLeaderboard(int $focusUserId, array $focusPlayer): array
    {
        $leaders = array();
        foreach ($this->getBaseDailyLeaderboard() as $row) {
            $userId = (int)($row['user_id'] ?? 0);
            if ($userId <= 0) {
                continue;
            }

            $leaders[$userId] = array(
                'user_id' => $userId,
                'username' => (string)($row['username'] ?? ''),
                'nickname' => (string)($row['nickname'] ?? ''),
                'distance' => max(0.0, (float)($row['distance'] ?? 0.0)),
            );
        }

        foreach ($this->plugin->getPlayerMap() as $other) {
            $userId = (int)($other['user_id'] ?? 0);
            if ($userId <= 0) {
                continue;
            }

            $sessionDistance = (float)($other['session']['distance_km'] ?? 0.0);
            if ($sessionDistance <= 0.0) {
                continue;
            }

            if (!isset($leaders[$userId])) {
                $leaders[$userId] = array(
                    'user_id' => $userId,
                    'username' => (string)($other['username'] ?? ''),
                    'nickname' => (string)($other['nickname'] ?? ''),
                    'distance' => 0.0,
                );
            }

            $leaders[$userId]['distance'] += $sessionDistance;

            if (!empty($other['nickname'])) {
                $leaders[$userId]['nickname'] = $other['nickname'];
            }
            if (!empty($other['username'])) {
                $leaders[$userId]['username'] = $other['username'];
            }
        }

        if ($focusUserId > 0 && !isset($leaders[$focusUserId])) {
            $session = (float)($focusPlayer['session']['distance_km'] ?? 0.0);
            if ($session > 0.0) {
                $leaders[$focusUserId] = array(
                    'user_id' => $focusUserId,
                    'username' => (string)($focusPlayer['username'] ?? ''),
                    'nickname' => (string)($focusPlayer['nickname'] ?? ''),
                    'distance' => $session,
                );
            }
        }

        $leaders = array_filter($leaders, function ($row) {
            return ($row['distance'] ?? 0.0) > 0.0;
        });

        if (empty($leaders)) {
            return array('rows' => array(), 'map' => array());
        }

        usort($leaders, function ($a, $b) {
            $compare = $b['distance'] <=> $a['distance'];
            if ($compare !== 0) {
                return $compare;
            }

            $nameA = $this->leaderboardName($a);
            $nameB = $this->leaderboardName($b);
            return strcmp($nameA, $nameB);
        });

        $map = array();
        foreach ($leaders as $index => &$row) {
            $row['rank'] = $index + 1;
            $map[$row['user_id']] = $row;
        }
        unset($row);

        $rows = array_slice($leaders, 0, $this->dailyLeaderboardLimit);
        if ($focusUserId > 0 && isset($map[$focusUserId]) && $map[$focusUserId]['rank'] > $this->dailyLeaderboardLimit) {
            $rows[] = $map[$focusUserId];
        }

        return array('rows' => $rows, 'map' => $map);
    }

    private function getBaseDailyLeaderboard(): array
    {
        $expires = $this->dailyLeaderboardCache['expires'] ?? 0;
        if ($expires <= time()) {
            $rows = array();
            $database = $this->plugin->getDatabase();
            if ($database) {
                $limit = max($this->dailyLeaderboardLimit + 5, 10);
                foreach ($database->fetchDailyDistanceLeaders($limit) as $row) {
                    $userId = (int)($row['user_id'] ?? 0);
                    if ($userId <= 0) {
                        continue;
                    }
                    $rows[] = array(
                        'user_id' => $userId,
                        'username' => (string)($row['username'] ?? ''),
                        'nickname' => (string)($row['nickname'] ?? ''),
                        'distance' => max(0.0, (float)($row['distance'] ?? 0.0)),
                    );
                }
            }

            $this->dailyLeaderboardCache = array(
                'rows' => $rows,
                'expires' => time() + $this->dailyLeaderboardTtl,
            );
        }

        return $this->dailyLeaderboardCache['rows'] ?? array();
    }

    private function updateAcceleration(array &$player, float $speedKph, ?float $timestamp = null): bool
    {
        if (!isset($player['state']['telemetry']) || !is_array($player['state']['telemetry'])) {
            return false;
        }

        $telemetry =& $player['state']['telemetry'];
        if (!isset($telemetry['accel']) || !is_array($telemetry['accel'])) {
            $telemetry['accel'] = $this->defaultAccelerationState();
        } else {
            $telemetry['accel'] = array_merge($this->defaultAccelerationState(), $telemetry['accel']);
        }

        $accel =& $telemetry['accel'];
        $now = $timestamp ?? microtime(true);
        $changed = false;

        if (!empty($accel['active']) && ($accel['start'] ?? 0.0) > 0.0) {
            if ($speedKph >= 100.0) {
                $elapsed = max(0.0, $now - (float)$accel['start']);
                $accel['last'] = $elapsed;
                $best = (float)($accel['best'] ?? 0.0);
                if ($best <= 0.0 || $elapsed < $best) {
                    $accel['best'] = $elapsed;
                }
                $accel['active'] = false;
                $accel['start'] = 0.0;
                $accel['start_speed'] = 0.0;
                $accel['last_event'] = 'complete';
                $accel['armed_at'] = 0.0;
                $changed = true;
            }
        }

        return $changed;
    }

    private function tickAfk(array &$player): void
    {
        $ucid = $player['ucid'];
        $state =& $player['state'];
        $lastMove = $state['telemetry']['last_move'] ?? time();
        $now = time();
        $warnAfter = (int)$this->getConfigNumber('afk_warn_seconds', 720);
        $kickAfter = (int)$this->getConfigNumber('afk_kick_seconds', 900);

        $elapsed = $now - $lastMove;
        $status = 'active';
        if ($elapsed > $kickAfter) {
            $status = 'kick';
        } elseif ($elapsed > $warnAfter) {
            $status = 'warn';
        }

        $previousStatus = $state['afk']['status'] ?? 'active';
        if ($status !== $previousStatus) {
            $state['afk']['status'] = $status;
            $state['afk']['since'] = $now;
            if ($status === 'active') {
                unset($state['afk']['queued_at']);
            } elseif ($previousStatus === 'active') {
                $state['afk']['queued_at'] = $now;
            }
            $this->markStateDirty($player);
        }
        $state['afk']['last_move'] = $lastMove;

        if ($status === 'kick') {
            if (($state['ui']['afk_notified'] ?? false) !== 'kick') {
                $this->sendMessage($ucid, '^1You have been idle for too long. Please move your car.');
                $state['ui']['afk_notified'] = 'kick';
            }
        } elseif ($status === 'warn') {
            if (($state['ui']['afk_notified'] ?? false) !== 'warn') {
                $remaining = $kickAfter - $elapsed;
                $this->sendMessage($ucid, '^3AFK warning. Move within ' . $this->formatSeconds(max(0, $remaining)) . '.');
                $state['ui']['afk_notified'] = 'warn';
            }
        } else {
            $state['ui']['afk_notified'] = null;
        }

        $this->updateAfkQueueState($player, $status !== 'active');
    }

    private function processTrafficCircle(array &$player, IS_UCO $packet): void
    {
        $circleId = $packet->Info->Index;
        if (!isset($this->trafficChecks[$circleId])) {
            return;
        }

        $lightId = $this->trafficChecks[$circleId]['light'];
        $expectedHeading = $this->trafficChecks[$circleId]['heading'];
        $currentHeading = $player['state']['telemetry']['heading'] ?? 0.0;
        $angleDiff = $this->normaliseAngle($currentHeading - $expectedHeading);

        $lightController = $this->plugin->getTrafficLights();
        $state = $lightController ? $lightController->getState($lightId) : TL_GREEN;

        if ($state === TL_RED && abs($angleDiff) < 45) {
            $this->adjustSafetyPoints($player, -25);
            $this->sendMessage($player['ucid'], '^1Red light violation! Safety points reduced.');
        }
    }

    private function processJobCircle(array &$player, IS_UCO $packet): void
    {
        $circleId = $packet->Info->Index;
        if (!isset($this->jobTriggers[$circleId])) {
            return;
        }

        foreach ($this->jobTriggers[$circleId] as $trigger) {
            $jobId = $trigger['job'];
            if (!isset($this->jobs[$jobId])) {
                continue;
            }

            if (!$this->isTriggerHeadingMatch($player, $trigger)) {
                continue;
            }

            $job = $this->jobs[$jobId];
            $action = $trigger['action'];

            if ($action === 'start') {
                if ($this->canStartJob($player, $job)) {
                    $this->startJob($player, $job);
                }
            } elseif ($action === 'finish') {
                if ($this->isJobActive($player, $jobId)) {
                    $this->completeJob($player, $job);
                }
            }
        }
    }

    private function isTriggerHeadingMatch(array &$player, array $trigger): bool
    {
        if (!isset($trigger['heading'])) {
            return true;
        }

        $heading = $player['state']['telemetry']['heading'] ?? 0.0;
        $target = (float)$trigger['heading'];
        $tolerance = isset($trigger['tolerance']) ? (float)$trigger['tolerance'] : 45.0;
        $diff = abs($this->normaliseAngle($heading - $target));

        return $diff <= max(0.0, $tolerance);
    }

    private function updateJobProgress(array &$player, float $deltaKm): void
    {
        $state =& $player['state'];
        if (!is_array($state['jobs']['active'] ?? null)) {
            return;
        }

        $active =& $state['jobs']['active'];
        $jobId = $active['id'] ?? '';
        if ($jobId === '' || !isset($this->jobs[$jobId])) {
            return;
        }

        $job = $this->jobs[$jobId];
        $active['distance'] = ($active['distance'] ?? 0.0) + $deltaKm;

        $rate = (float)($job['payout_per_km'] ?? 0.0);
        $earned = 0.0;
        if ($rate > 0.0) {
            $earned = $deltaKm * $rate;
            if ($earned > 0.0) {
                $state['economy']['cash'] += $earned;
                $player['session']['money'] += $earned;
            }
        }

        $active['earned'] = ($active['earned'] ?? 0.0) + $earned;
        $this->markStateDirty($player);
    }

    private function startJob(array &$player, array $job): void
    {
        $ucid = $player['ucid'];
        $state =& $player['state'];

        $state['jobs']['active'] = array(
            'id' => $job['id'],
            'name' => $job['name'],
            'category' => $job['category'],
            'started_at' => time(),
            'distance' => 0.0,
            'earned' => 0.0,
            'start_circle' => $job['start_circle'] ?? 0,
        );

        $message = $job['start_message'] ?? '';
        if ($message === '') {
            $message = sprintf('^2Job started:^3 %s', $job['name']);
        }

        $this->sendMessage($ucid, $message);
        $this->markStateDirty($player);
    }

    private function completeJob(array &$player, array $job): void
    {
        $bonus = max(0.0, (float)($job['finish_bonus'] ?? 0.0));
        $message = $job['finish_message'] ?? '';
        if ($message === '') {
            $message = sprintf('^2Job complete:^3 %s', $job['name']);
        }

        $this->endJob($player, $job, 'complete', $message, $bonus);
    }

    private function cancelJob(array &$player, string $reason): void
    {
        if (!$this->isJobActive($player)) {
            return;
        }

        $jobId = $player['state']['jobs']['active']['id'] ?? '';
        $job = $jobId !== '' && isset($this->jobs[$jobId]) ? $this->jobs[$jobId] : array('name' => $jobId, 'id' => $jobId);

        $this->endJob($player, $job, 'cancelled', $reason, 0.0, true);
    }

    private function endJob(array &$player, array $job, string $status, string $message, float $bonus = 0.0, bool $notify = true): void
    {
        $state =& $player['state'];
        $active = $state['jobs']['active'] ?? null;
        if (!is_array($active)) {
            return;
        }

        $earned = $active['earned'] ?? 0.0;
        if ($bonus > 0.0) {
            $state['economy']['cash'] += $bonus;
            $player['session']['money'] += $bonus;
            $earned += $bonus;
        }

        $history = array(
            'id' => $job['id'] ?? ($active['id'] ?? ''),
            'name' => $job['name'] ?? ($active['name'] ?? ''),
            'category' => $job['category'] ?? ($active['category'] ?? ''),
            'distance' => $active['distance'] ?? 0.0,
            'earned' => $earned,
            'finished_at' => time(),
            'status' => $status,
        );

        $this->addJobHistory($player, $history);

        $state['jobs']['active'] = null;
        if ($notify && $message !== '') {
            $this->sendMessage($player['ucid'], $message);
        }

        $this->markStateDirty($player);
    }

    private function addJobHistory(array &$player, array $entry): void
    {
        $state =& $player['state'];
        if (!isset($state['jobs']['history']) || !is_array($state['jobs']['history'])) {
            $state['jobs']['history'] = array();
        }

        $state['jobs']['history'][] = $entry;
        if (count($state['jobs']['history']) > 10) {
            $state['jobs']['history'] = array_slice($state['jobs']['history'], -10);
        }
    }

    private function isJobActive(array &$player, string $jobId = ''): bool
    {
        $active = $player['state']['jobs']['active'] ?? null;
        if (!is_array($active)) {
            return false;
        }

        if ($jobId === '') {
            return true;
        }

        return strcasecmp((string)($active['id'] ?? ''), $jobId) === 0;
    }

    private function canStartJob(array &$player, array $job): bool
    {
        if ($this->isJobActive($player)) {
            return false;
        }

        return $this->meetsJobRequirements($player, $job);
    }

    private function evaluateJobEligibility(array &$player): void
    {
        if (!$this->isJobActive($player)) {
            return;
        }

        $jobId = $player['state']['jobs']['active']['id'] ?? '';
        if ($jobId === '' || !isset($this->jobs[$jobId])) {
            $player['state']['jobs']['active'] = null;
            $this->markStateDirty($player);
            return;
        }

        if (!$this->meetsJobRequirements($player, $this->jobs[$jobId])) {
            $this->cancelJob($player, '^1Job cancelled: requirements no longer met.');
        }
    }

    private function meetsJobRequirements(array &$player, array $job): bool
    {
        $state =& $player['state'];
        $car = $state['garage']['active_car'] ?? '';
        if ($car === '') {
            return false;
        }

        $vehicle = $state['garage']['vehicles'][$car] ?? null;
        if (!is_array($vehicle)) {
            return false;
        }

        $requiredMods = $job['required_mod_ids'] ?? array();
        if (!empty($requiredMods)) {
            $modId = strtoupper((string)($vehicle['mod']['id'] ?? ''));
            $modMatches = false;
            foreach ($requiredMods as $requirement) {
                if ($modId !== '' && strcasecmp($modId, $requirement) === 0) {
                    $modMatches = true;
                    break;
                }
            }
            if (!$modMatches) {
                return false;
            }
        }

        $requiredSkins = $job['required_skin_ids'] ?? array();
        if (!empty($requiredSkins)) {
            $activeSkin = strtoupper((string)($state['garage']['active_skin'] ?? ''));
            $vehicleSkin = strtoupper((string)($vehicle['last_skin'] ?? ''));
            $skinMatches = false;
            foreach ($requiredSkins as $skin) {
                if (($activeSkin !== '' && strcasecmp($activeSkin, $skin) === 0)
                    || ($vehicleSkin !== '' && strcasecmp($vehicleSkin, $skin) === 0)) {
                    $skinMatches = true;
                    break;
                }
            }
            if (!$skinMatches) {
                return false;
            }
        }

        return true;
    }

    private function tickSalary(array &$player): void
    {
        $ucid = $player['ucid'];
        $state =& $player['state'];
        if (!empty($state['economy']['salary_notified'])) {
            return;
        }

        if ($state['economy']['salary_ready_at'] > 0 && $state['economy']['salary_ready_at'] <= time()) {
            $this->sendMessage($ucid, '^2Salary available. Open the bank to collect.');
            $state['economy']['salary_notified'] = true;
            $this->markStateDirty($player);
        }
    }

    private function clearAllButtons(): void
    {
        foreach (array_keys($this->hudRendered) as $ucid) {
            ButtonManager::removeButtonsByGroup($ucid, self::HUD_GROUP);
            ButtonManager::removeButtonsByGroup($ucid, self::BANK_GROUP);
            ButtonManager::removeButtonsByGroup($ucid, self::TELEPORT_GROUP);
            ButtonManager::removeButtonsByGroup($ucid, self::REGITRA_GROUP);
            ButtonManager::removeButtonsByGroup($ucid, self::POLICE_GROUP);
            ButtonManager::removeButtonsByGroup($ucid, self::AFK_GROUP);
            ButtonManager::removeButtonsByGroup($ucid, self::DAILY_GROUP);
            ButtonManager::removeButtonsByGroup($ucid, self::PRICE_GROUP);
        }
        $this->hudRendered = array();
        $this->afkQueue = array();
        $this->afkQueueLeader = null;
    }

    private function drawButton(int $ucid, string $key, string $group, int $left, int $top, int $width, int $height, string $text, int $style, ?array $callback = null): void
    {
        $button = ButtonManager::getButtonForKey($ucid, $key);
        if ($button === null || $button->group() !== $group) {
            $button = new Button($ucid, $key, $group);
        }

        $button->L($left)
            ->T($top)
            ->W($width)
            ->H($height)
            ->BStyle($style)
            ;

        $inst = $this->resolveButtonInst($group);
        if ($inst !== null) {
            $button->Inst($inst);
        }

        $button->Text($text);

        if ($callback !== null) {
            $button->registerOnClick($this->plugin, 'handleCruiseButton', $callback);
        }

        $button->Send();
    }

    private function resolveButtonInst(string $group): ?int
    {
        if ($group === self::REGITRA_GROUP) {
            return INST_ALWAYS_ON;
        }

        return null;
    }

    private function initialisePlayerState(array &$player): void
    {
        if (!isset($player['state']) || !is_array($player['state'])) {
            $player['state'] = $this->defaultState();
            $this->markStateDirty($player);
        } else {
            $player['state'] = array_replace_recursive($this->defaultState(), $player['state']);
        }

        $player['state']['police']['officer'] = $this->isOfficer($player);

        $this->normaliseGarageState($player);
    }

    private function defaultAccelerationState(): array
    {
        return array(
            'start' => 0.0,
            'last' => 0.0,
            'best' => 0.0,
            'active' => false,
            'ready' => true,
            'armed_at' => 0.0,
            'start_speed' => 0.0,
            'last_event' => '',
        );
    }

    private function defaultState(): array
    {
        $now = time();
        return array(
            'economy' => array(
                'cash' => 0.0,
                'bank' => 0.0,
                'salary_pool' => 0.0,
                'bonus_progress_km' => 0.0,
                'bonus_tokens' => 0,
                'salary_ready_at' => $now,
                'salary_notified' => false,
                'last_salary' => 0.0,
                'last_interest' => 0.0,
            ),
            'garage' => array(
                'active_car' => '',
                'active_skin' => '',
                'vehicles' => array(),
            ),
            'stats' => array(
                'xp' => 0.0,
                'licenses' => 0,
                'lap_count' => 0,
            ),
            'jobs' => array(
                'active' => null,
                'history' => array(),
            ),
            'police' => array(
                'safety_points' => 500,
                'wanted_level' => 0,
                'warnings' => 0,
            ),
            'telemetry' => array(
                'speed' => 0.0,
                'position' => array('x' => 0.0, 'y' => 0.0),
                'last_move' => $now,
                'heading' => 0.0,
                'accel' => $this->defaultAccelerationState(),
            ),
            'ui' => array(
                'daily' => array(
                    'visible' => true,
                    'left' => 4,
                    'top' => 120,
                    'row_keys' => array(),
                ),
            ),
            'afk' => array(
                'status' => 'active',
                'since' => $now,
                'last_move' => $now,
                'queued_at' => $now,
            ),
            'identity' => array(
                'verified' => false,
            ),
        );
    }

    private function createVehicleRecord(): array
    {
        return array(
            'plate' => '---:---',
            'distance' => 0.0,
            'insurance_until' => 0,
            'mod' => array(),
            'acquired_at' => 0,
            'discord_announced' => false,
            'last_skin' => '',
        );
    }

    private function mergeVehicleRecords(array $existing, array $incoming): array
    {
        $existing = array_replace($this->createVehicleRecord(), $existing);
        $incoming = array_replace($this->createVehicleRecord(), $incoming);

        if (($existing['plate'] ?? '---:---') === '---:---' && $incoming['plate'] !== '---:---' && $incoming['plate'] !== '') {
            $existing['plate'] = $incoming['plate'];
        }

        $existing['distance'] = max((float)$existing['distance'], (float)$incoming['distance']);
        $existing['insurance_until'] = max((int)$existing['insurance_until'], (int)$incoming['insurance_until']);

        if (!empty($incoming['mod']) && is_array($incoming['mod'])) {
            $existing['mod'] = $incoming['mod'];
        }

        if ((int)$incoming['acquired_at'] > (int)$existing['acquired_at']) {
            $existing['acquired_at'] = (int)$incoming['acquired_at'];
        }

        if (!empty($incoming['discord_announced'])) {
            $existing['discord_announced'] = true;
        }

        if (!empty($incoming['last_skin'])) {
            $existing['last_skin'] = $incoming['last_skin'];
        }

        return $existing;
    }

    private function normaliseGarageState(array &$player): void
    {
        if (!isset($player['state']['garage']) || !is_array($player['state']['garage'])) {
            $player['state']['garage'] = array(
                'active_car' => '',
                'active_skin' => '',
                'vehicles' => array(),
            );
            $this->markStateDirty($player);
            return;
        }

        $garage =& $player['state']['garage'];
        if (!isset($garage['vehicles']) || !is_array($garage['vehicles'])) {
            $garage['vehicles'] = array();
        }

        $vehicles = $garage['vehicles'];
        $normalized = array();
        $changed = false;

        foreach ($vehicles as $code => $vehicle) {
            $canonical = $this->vehicleMods->normaliseVehicleCode((string)$code);
            if ($canonical === '') {
                $changed = true;
                continue;
            }

            if (!is_array($vehicle)) {
                $vehicle = array();
                $changed = true;
            }

            $vehicle = array_replace($this->createVehicleRecord(), $vehicle);

            if ($canonical !== (string)$code) {
                $changed = true;
            }

            if (isset($normalized[$canonical])) {
                $vehicle = $this->mergeVehicleRecords($normalized[$canonical], $vehicle);
                $changed = true;
            }

            $vehicle['distance'] = (float)$vehicle['distance'];
            $vehicle['insurance_until'] = (int)$vehicle['insurance_until'];
            $vehicle['acquired_at'] = (int)$vehicle['acquired_at'];
            $vehicle['discord_announced'] = !empty($vehicle['discord_announced']);

            if (!is_array($vehicle['mod'])) {
                $vehicle['mod'] = array();
            }

            $normalized[$canonical] = $vehicle;
        }

        if ($normalized !== $vehicles) {
            $changed = true;
        }

        $garage['vehicles'] = $normalized;

        $active = (string)($garage['active_car'] ?? '');
        if ($active !== '') {
            $canonicalActive = $this->vehicleMods->normaliseVehicleCode($active);
            if ($canonicalActive === '') {
                $garage['active_car'] = '';
                $changed = true;
            } elseif ($canonicalActive !== $active) {
                if (isset($garage['vehicles'][$canonicalActive])) {
                    $garage['active_car'] = $canonicalActive;
                } else {
                    $garage['active_car'] = '';
                }
                $changed = true;
            } elseif (!isset($garage['vehicles'][$active])) {
                $garage['active_car'] = '';
                $changed = true;
            }
        } elseif (!empty($garage['active_car'])) {
            $garage['active_car'] = '';
            $changed = true;
        }

        if ($changed) {
            $this->markStateDirty($player);
        }
    }

    private function showVehiclePriceButton(int $ucid, float $price): void
    {
        $button = ButtonManager::getButtonForKey($ucid, 'VehiclePrice');
        if ($button === null || $button->group() !== self::PRICE_GROUP) {
            $button = new Button($ucid, 'VehiclePrice', self::PRICE_GROUP);
        }

        $text = sprintf('^7Price:^3 %s', $this->formatCurrency($price));

        $button->L(IS_X_MAX - 36)
            ->T(IS_Y_MIN + 2)
            ->W(34)
            ->H(6)
            ->BStyle(ISB_DARK | ISB_LEFT)
            ->Inst(INST_ALWAYS_ON)
            ->Text($text)
            ->Send();
    }

    private function hideVehiclePriceButton(int $ucid): void
    {
        ButtonManager::removeButtonsByGroup($ucid, self::PRICE_GROUP);
    }

    private function applyVehiclePriceToGarage(array $mod): void
    {
        $price = (float)($mod['price'] ?? 0.0);

        $candidates = array();
        $id = strtoupper(trim((string)($mod['id'] ?? '')));
        $carCode = strtoupper(trim((string)($mod['car_code'] ?? '')));
        $hexCode = strtoupper(trim((string)($mod['hex_code'] ?? '')));

        if ($id !== '') {
            $candidates[] = $id;
        }
        if ($carCode !== '') {
            $candidates[] = $carCode;
            $candidates[] = $this->vehicleMods->normaliseVehicleCode($carCode);
            $candidates[] = $this->vehicleMods->formatVehicleCode($carCode);
        }
        if ($hexCode !== '') {
            $candidates[] = $hexCode;
            $candidates[] = ltrim($hexCode, '0');
            $candidates[] = $this->vehicleMods->normaliseVehicleCode($hexCode);
            $candidates[] = $this->vehicleMods->formatVehicleCode($hexCode);
        }

        $candidates = array_values(array_filter(array_unique(array_map(function ($value) {
            return strtoupper((string)$value);
        }, $candidates))));

        if (empty($candidates)) {
            return;
        }

        $players =& $this->plugin->getPlayerMap();
        foreach ($players as $ucid => &$player) {
            if (empty($player['state']) || empty($player['state']['garage']['vehicles'])) {
                continue;
            }

            $updated = false;
            foreach ($player['state']['garage']['vehicles'] as $code => &$vehicle) {
                if (!is_array($vehicle)) {
                    continue;
                }

                if (!is_array($vehicle['mod'] ?? null)) {
                    continue;
                }

                $vehicleCandidates = array(
                    strtoupper((string)($vehicle['mod']['id'] ?? '')),
                    strtoupper((string)($vehicle['mod']['car_code'] ?? '')),
                    strtoupper((string)($vehicle['mod']['hex_code'] ?? '')),
                    strtoupper((string)$code),
                    ltrim(strtoupper((string)$code), '0'),
                    $this->vehicleMods->normaliseVehicleCode((string)$code),
                    $this->vehicleMods->formatVehicleCode((string)$code),
                    $this->vehicleMods->formatVehicleCode((string)($vehicle['mod']['hex_code'] ?? '')),
                );

                $vehicleCandidates = array_values(array_filter(array_unique(array_map(function ($value) {
                    return strtoupper((string)$value);
                }, $vehicleCandidates))));

                $match = false;
                foreach ($candidates as $target) {
                    if ($target === '') {
                        continue;
                    }
                    if (in_array($target, $vehicleCandidates, true)) {
                        $match = true;
                        break;
                    }
                }

                if ($match) {
                    $vehicle['mod']['price'] = $price;
                    $updated = true;
                }
            }
            unset($vehicle);

            if (!$updated) {
                continue;
            }

            $this->markStateDirty($player);

            $activeCode = (string)($player['state']['garage']['active_car'] ?? '');
            if ($activeCode === '') {
                continue;
            }

            $activeCandidates = array(
                strtoupper($activeCode),
                ltrim(strtoupper($activeCode), '0'),
                $this->vehicleMods->normaliseVehicleCode($activeCode),
                $this->vehicleMods->formatVehicleCode($activeCode),
            );

            $activeCandidates = array_values(array_filter(array_unique(array_map(function ($value) {
                return strtoupper((string)$value);
            }, $activeCandidates))));

            $shouldDisplay = false;
            foreach ($candidates as $target) {
                if ($target === '') {
                    continue;
                }
                if (in_array($target, $activeCandidates, true)) {
                    $shouldDisplay = true;
                    break;
                }
            }

            if ($shouldDisplay) {
                if ($price > 0.0) {
                    $this->showVehiclePriceButton($ucid, $price);
                } else {
                    $this->hideVehiclePriceButton($ucid);
                }
            }
        }
        unset($player);
    }

    private function markStateDirty(array &$player): void
    {
        $player['state_dirty'] = true;
        $player['dirty'] = true;
    }

    private function formatCurrency(float $amount): string
    {
        return number_format($amount, 0, '.', '');
    }

    private function formatSignedCurrency(float $amount): string
    {
        $formatted = $this->formatCurrency(abs($amount));
        return ($amount >= 0 ? '^2+' : '^1-') . $formatted;
    }

    private function formatDistance(float $distance): string
    {
        if ($distance >= 1000.0) {
            return number_format($distance, 0, '.', '');
        }
        if ($distance >= 100.0) {
            return number_format($distance, 1, '.', '');
        }
        return number_format($distance, 2, '.', '');
    }

    private function formatInterval(float $seconds): string
    {
        if ($seconds <= 0.0) {
            return '--';
        }

        if ($seconds >= 60.0) {
            $minutes = (int)floor($seconds / 60.0);
            $remaining = $seconds - ($minutes * 60.0);
            return sprintf('%dm %04.1fs', $minutes, $remaining);
        }

        return sprintf('%.2fs', $seconds);
    }

    private function formatStars(float $safetyPoints): string
    {
        $maxStars = 5;
        $normalized = max(0.0, min(1.0, $safetyPoints / 500.0));
        $filled = (int)round($normalized * $maxStars);
        $stars = '';
        for ($i = 0; $i < $maxStars; $i++) {
            $stars .= ($i < $filled) ? '^2★' : '^7☆';
        }
        return $stars;
    }

    private function formatSeconds(int $seconds): string
    {
        if ($seconds <= 0) {
            return '0s';
        }
        $minutes = intdiv($seconds, 60);
        $remaining = $seconds % 60;
        if ($minutes > 0) {
            return sprintf('%dm %02ds', $minutes, $remaining);
        }
        return sprintf('%ds', $seconds);
    }

    private function leaderboardName(array $row): string
    {
        $name = (string)($row['nickname'] ?? '');
        if ($name === '') {
            $name = (string)($row['username'] ?? '');
        }
        return strtolower(trim(preg_replace('/\^./', '', $name)));
    }

    private function parseList($raw): array
    {
        if (is_array($raw)) {
            $values = $raw;
        } else {
            $values = array_map('trim', explode(',', (string)$raw));
        }

        $list = array();
        foreach ($values as $value) {
            if ($value !== '') {
                $list[] = strtolower($value);
            }
        }
        return $list;
    }

    private function parseTeleports($raw): array
    {
        $teleports = array();
        if (is_array($raw)) {
            foreach ($raw as $key => $value) {
                if (is_array($value)) {
                    $teleports[$key] = array(
                        'name' => $value['name'] ?? ucfirst((string)$key),
                        'command' => $value['command'] ?? '/ujoin %s',
                    );
                } else {
                    $parts = array_map('trim', explode('|', (string)$value));
                    $teleports[$key] = array(
                        'name' => $parts[1] ?? ucfirst((string)$key),
                        'command' => $parts[2] ?? '/ujoin %s',
                    );
                }
            }
        } else {
            $entries = array_map('trim', explode(';', (string)$raw));
            foreach ($entries as $entry) {
                if ($entry === '') {
                    continue;
                }
                $parts = array_map('trim', explode('|', $entry));
                $key = $parts[0] ?? ('loc' . count($teleports));
                $teleports[$key] = array(
                    'name' => $parts[1] ?? ucfirst($key),
                    'command' => $parts[2] ?? '/ujoin %s',
                );
            }
        }

        if (empty($teleports)) {
            $teleports = array(
                'pits' => array('name' => 'Pitlane', 'command' => '/ujoin %s'),
                'garage' => array('name' => 'Garage', 'command' => '/pitlane %s'),
            );
        }

        return $teleports;
    }

    private function parseTrafficChecks($raw): array
    {
        $checks = array();
        if (is_array($raw)) {
            foreach ($raw as $key => $value) {
                if (is_array($value)) {
                    $checks[(int)$key] = array(
                        'light' => (int)($value['light'] ?? 1),
                        'heading' => (float)($value['heading'] ?? 0),
                    );
                } else {
                    $parts = array_map('trim', explode('|', (string)$value));
                    $checks[(int)$key] = array(
                        'light' => (int)($parts[0] ?? 1),
                        'heading' => (float)($parts[1] ?? 0),
                    );
                }
            }
        }
        return $checks;
    }

    private function parseJobs($raw): array
    {
        if (!is_array($raw)) {
            return array();
        }

        $jobs = array();
        foreach ($raw as $key => $value) {
            $id = strtolower(trim((string)$key));
            if ($id === '') {
                continue;
            }

            if (is_array($value)) {
                $job = $this->normaliseJobDefinition($id, $value);
            } else {
                $parts = array_map('trim', explode('|', (string)$value));
                $job = $this->normaliseJobDefinition($id, array(
                    'name' => $parts[1] ?? ucfirst($id),
                    'category' => $parts[2] ?? 'general',
                    'start_circle' => isset($parts[3]) ? (int)$parts[3] : 0,
                ));
            }

            if (($job['start_circle'] ?? 0) > 0) {
                $jobs[$job['id']] = $job;
            }
        }

        return $jobs;
    }

    private function indexJobTriggers(array $jobs): array
    {
        $triggers = array();
        foreach ($jobs as $job) {
            if (($job['start_circle'] ?? 0) > 0) {
                $circle = (int)$job['start_circle'];
                $triggers[$circle][] = array(
                    'job' => $job['id'],
                    'action' => 'start',
                    'heading' => $job['start_heading'],
                    'tolerance' => $job['start_tolerance'],
                );
            }

            if (($job['finish_circle'] ?? 0) > 0) {
                $circle = (int)$job['finish_circle'];
                $triggers[$circle][] = array(
                    'job' => $job['id'],
                    'action' => 'finish',
                    'heading' => $job['finish_heading'],
                    'tolerance' => $job['finish_tolerance'],
                );
            }
        }

        return $triggers;
    }

    private function normaliseJobDefinition(string $id, array $data): array
    {
        $job = array(
            'id' => $id,
            'name' => $data['name'] ?? ucfirst($id),
            'category' => $data['category'] ?? 'general',
            'start_circle' => isset($data['start_circle']) ? (int)$data['start_circle'] : (isset($data['circle']) ? (int)$data['circle'] : 0),
            'finish_circle' => isset($data['finish_circle']) ? (int)$data['finish_circle'] : 0,
            'start_heading' => isset($data['start_heading']) ? (float)$data['start_heading'] : null,
            'finish_heading' => isset($data['finish_heading']) ? (float)$data['finish_heading'] : null,
            'start_tolerance' => isset($data['start_tolerance']) ? (float)$data['start_tolerance'] : 45.0,
            'finish_tolerance' => isset($data['finish_tolerance']) ? (float)$data['finish_tolerance'] : 45.0,
            'payout_per_km' => isset($data['payout_per_km']) ? (float)$data['payout_per_km'] : 0.0,
            'finish_bonus' => isset($data['finish_bonus']) ? (float)$data['finish_bonus'] : 0.0,
            'start_message' => $data['start_message'] ?? '',
            'finish_message' => $data['finish_message'] ?? '',
            'required_mod_ids' => $this->parseIdList($data['required_mod_ids'] ?? ($data['mods'] ?? array())),
            'required_skin_ids' => $this->parseIdList($data['required_skin_ids'] ?? ($data['skins'] ?? array())),
        );

        if ($job['start_circle'] <= 0) {
            $job['start_circle'] = 0;
        }

        if ($job['finish_circle'] <= 0) {
            $job['finish_circle'] = 0;
        }

        return $job;
    }

    private function parseIdList($raw): array
    {
        if (is_array($raw)) {
            $values = $raw;
        } else {
            $values = array_map('trim', explode(',', (string)$raw));
        }

        $list = array();
        foreach ($values as $value) {
            if ($value === '') {
                continue;
            }
            $list[] = strtoupper($value);
        }

        return array_values(array_unique($list));
    }

    private function getDynamicInterestRatePercent(?array $player = null): float
    {
        $baseRate = $this->getConfigNumber('bank_interest_rate', 0.024);
        $perPlayer = $this->getConfigNumber('bank_interest_rate_per_player', 0.02);
        $verifiedBonus = $this->getConfigNumber('bank_interest_verified_bonus', 0.02);
        $playerCount = max(0, $this->getEffectivePlayerCount());

        $rate = $baseRate + ($perPlayer * $playerCount);

        if ($player !== null && $this->isIdentityVerified($player)) {
            $rate += $verifiedBonus;
        }

        return max(0.0, $rate);
    }

    private function isIdentityVerified(array $player): bool
    {
        if (!empty($player['state']['identity']['verified'])) {
            return true;
        }

        if (!empty($player['identity']['verified'])) {
            return true;
        }

        if (array_key_exists('identity_verified', $player)) {
            return (bool)$player['identity_verified'];
        }

        return false;
    }

    private function updateAfkQueueState(array &$player, bool $isAfk): void
    {
        $ucid = $player['ucid'];

        if ($isAfk) {
            $state = $player['state'];
            $lastMove = $state['telemetry']['last_move'] ?? time();
            $queuedAt = $state['afk']['queued_at'] ?? $lastMove;

            $this->afkQueue[$ucid] = array(
                'last_move' => $lastMove,
                'queued_at' => $queuedAt,
            );
            return;
        }

        $this->removeAfkQueueEntry($ucid);
    }

    private function removeAfkQueueEntry(int $ucid): void
    {
        if (!isset($this->afkQueue[$ucid])) {
            ButtonManager::removeButtonsByGroup($ucid, self::AFK_GROUP);
            return;
        }

        unset($this->afkQueue[$ucid]);
        ButtonManager::removeButtonsByGroup($ucid, self::AFK_GROUP);
        if ($this->afkQueueLeader === $ucid) {
            $this->afkQueueLeader = null;
        }
    }

    private function renderAfkQueue(array &$players): void
    {
        $serverFull = $this->plugin->getActivePlayerCount() >= $this->maxPlayers;
        if (!$serverFull) {
            $this->clearAfkButtons();
            return;
        }

        $this->cleanupAfkQueueEntries($players);
        if (empty($this->afkQueue)) {
            $this->clearAfkButtons();
            return;
        }

        uasort($this->afkQueue, function (array $a, array $b) {
            return ($a['last_move'] ?? 0) <=> ($b['last_move'] ?? 0);
        });
        $leaderUcid = array_key_first($this->afkQueue);
        if ($leaderUcid === null) {
            $this->clearAfkButtons();
            return;
        }

        $kickAfter = (int)$this->getConfigNumber('afk_kick_seconds', 900);
        $now = time();
        $this->afkQueueLeader = $leaderUcid;

        foreach (array_keys($this->afkQueue) as $viewerUcid) {
            $lines = $this->buildAfkQueueLines($viewerUcid, $leaderUcid, $kickAfter, $now, $players);
            $height = max(5, 5 + ((count($lines) - 1) * 5));
            $style = ISB_DARK | ($viewerUcid === $leaderUcid ? ISB_RED : ISB_YELLOW);
            $this->drawButton(
                $viewerUcid,
                'AfkQueueNotice',
                self::AFK_GROUP,
                0,
                8,
                200,
                $height,
                implode("\n", $lines),
                $style
            );
        }
    }

    private function clearAfkButtons(): void
    {
        foreach (array_keys($this->afkQueue) as $ucid) {
            ButtonManager::removeButtonsByGroup($ucid, self::AFK_GROUP);
        }
        $this->afkQueueLeader = null;
    }

    private function cleanupAfkQueueEntries(?array $players = null): void
    {
        if ($players === null) {
            $players = $this->plugin->getPlayerMap();
        }

        foreach (array_keys($this->afkQueue) as $ucid) {
            if (!isset($players[$ucid])) {
                $this->removeAfkQueueEntry($ucid);
                continue;
            }

            $status = $players[$ucid]['state']['afk']['status'] ?? 'active';
            if ($status === 'active') {
                $this->removeAfkQueueEntry($ucid);
            } else {
                $lastMove = $players[$ucid]['state']['telemetry']['last_move'] ?? ($this->afkQueue[$ucid]['last_move'] ?? time());
                $queuedAt = $players[$ucid]['state']['afk']['queued_at'] ?? ($this->afkQueue[$ucid]['queued_at'] ?? $lastMove);
                $this->afkQueue[$ucid] = array(
                    'last_move' => $lastMove,
                    'queued_at' => $queuedAt,
                );
            }
        }
    }

    private function getEffectivePlayerCount(): int
    {
        $this->cleanupAfkQueueEntries();

        $count = max(0, $this->plugin->getActivePlayerCount());
        if ($count >= $this->maxPlayers && !empty($this->afkQueue)) {
            $count = max(0, $count - count($this->afkQueue));
        }

        return $count;
    }

    private function buildAfkQueueLines(int $viewerUcid, int $leaderUcid, int $kickAfter, int $now, array $players): array
    {
        $lines = array();
        $leaderEntry = $this->afkQueue[$leaderUcid] ?? array();
        $leaderLastMove = $leaderEntry['last_move'] ?? $now;
        $leaderRemaining = max(0, $kickAfter - ($now - $leaderLastMove));

        if ($viewerUcid === $leaderUcid) {
            $lines[] = '^1Server full: move within ^3' . $this->formatSeconds($leaderRemaining) . '^1!';
        } else {
            $position = $this->getAfkQueuePosition($viewerUcid);
            $lines[] = '^7Server full - waiting for slot (pos ^3' . $position . '^7)';
        }

        $lines[] = '^7AFK queue:';

        $position = 1;
        foreach ($this->afkQueue as $ucid => $entry) {
            $name = $this->getPlayerDisplayName($ucid, $players);
            $queuedAt = $entry['queued_at'] ?? $entry['last_move'] ?? $now;
            $waitTime = max(0, $now - $queuedAt);

            if ($ucid === $leaderUcid) {
                $line = sprintf('^1#%d %s ^8- kick in ^3%s', $position, $name, $this->formatSeconds($leaderRemaining));
            } elseif ($ucid === $viewerUcid) {
                $line = sprintf('^3#%d You ^8(%s) - waiting --', $position, $name);
            } else {
                $line = sprintf('^7#%d %s ^8- waiting ^3%s', $position, $name, $this->formatSeconds($waitTime));
            }

            $lines[] = $line;
            $position++;
        }

        return $lines;
    }

    private function getAfkQueuePosition(int $ucid): int
    {
        $position = 1;
        foreach (array_keys($this->afkQueue) as $entryUcid) {
            if ($entryUcid === $ucid) {
                return $position;
            }
            $position++;
        }

        return $position;
    }

    private function getPlayerDisplayName(int $ucid, array $players): string
    {
        $client = $this->plugin->getClientInfo($ucid);
        if ($client && !empty($client->PName)) {
            return $client->PName;
        }

        if (isset($players[$ucid]['state']['identity']['name']) && $players[$ucid]['state']['identity']['name'] !== '') {
            return $players[$ucid]['state']['identity']['name'];
        }

        if (isset($players[$ucid]['pname']) && $players[$ucid]['pname'] !== '') {
            return $players[$ucid]['pname'];
        }

        if (isset($players[$ucid]['uname']) && $players[$ucid]['uname'] !== '') {
            return $players[$ucid]['uname'];
        }

        return 'UCID ' . $ucid;
    }

    private function getConfigNumber(string $key, float $default): float
    {
        if (isset($this->config[$key]) && is_numeric($this->config[$key])) {
            return (float)$this->config[$key];
        }
        return $default;
    }

    private function sendMessage(int $ucid, string $text): void
    {
        IS_MTC()->UCID($ucid)->Text($text)->Send();
    }

    private function deductCash(array &$state, float $amount): bool
    {
        if ($state['economy']['cash'] >= $amount) {
            $state['economy']['cash'] -= $amount;
            return true;
        }
        return false;
    }

    private function generatePlate(): string
    {
        $letters = substr(str_shuffle('ABCDEFGHIJKLMNOPQRSTUVWXYZ'), 0, 3);
        $numbers = str_pad((string)rand(0, 999), 3, '0', STR_PAD_LEFT);
        return $letters . '-' . $numbers;
    }

    private function isOfficer(array $player): bool
    {
        $username = strtolower($player['username'] ?? '');
        if ($username === '') {
            return false;
        }
        if (empty($this->officers)) {
            return false;
        }
        return in_array($username, $this->officers, true);
    }

    private function collectNearbyPlayers(array $officer, float $radius): array
    {
        $results = array();
        $officerPos = $officer['state']['telemetry']['position'];
        foreach ($this->plugin->getPlayerMap() as $ucid => $player) {
            if ($ucid == $officer['ucid'] || empty($player['state']['telemetry']['position'])) {
                continue;
            }
            if (($player['state']['afk']['status'] ?? 'active') !== 'active') {
                continue;
            }
            if (($player['state']['telemetry']['speed'] ?? 0.0) <= 1.0) {
                continue;
            }
            $pos = $player['state']['telemetry']['position'];
            $dx = $pos['x'] - $officerPos['x'];
            $dy = $pos['y'] - $officerPos['y'];
            $distance = sqrt(($dx * $dx) + ($dy * $dy));
            if ($distance <= $radius) {
                $client = $this->plugin->getClientInfo($ucid);
                if ($client) {
                    $results[$ucid] = array(
                        'name' => $client->PName,
                        'distance' => $distance,
                        'speed' => $player['state']['telemetry']['speed'],
                    );
                }
            }
        }
        uasort($results, function ($a, $b) {
            return $a['distance'] <=> $b['distance'];
        });
        return $results;
    }

    private function issueFine(int $officerUcid, int $targetUcid, float $amount): void
    {
        $officer =& $this->plugin->getPlayerRecord($officerUcid);
        if (!$this->isOfficer($officer)) {
            $this->sendMessage($officerUcid, '^1Only officers can issue fines.');
            return;
        }

        $targetClient = $this->plugin->getClientInfo($targetUcid);
        if (!$targetClient) {
            $this->sendMessage($officerUcid, '^1Target player left.');
            return;
        }

        $target =& $this->plugin->getPlayerRecord($targetUcid);
        $this->initialisePlayerState($target);

        if (!$this->deductCash($target['state'], $amount)) {
            $available = $target['state']['economy']['cash'];
            $this->sendMessage($officerUcid, '^1Player has insufficient cash for fine (available ' . $this->formatCurrency($available) . ').');
            return;
        }

        $officer['state']['economy']['cash'] += $amount * 0.5;
        $this->adjustSafetyPoints($target, -$amount / 10.0);

        $this->sendMessage($targetUcid, '^1Fine issued: ^3' . $this->formatCurrency($amount));
        $this->sendMessage($officerUcid, '^2Collected fine of ^3' . $this->formatCurrency($amount) . '^2.');

        $this->markStateDirty($target);
        $this->markStateDirty($officer);
    }

    private function adjustSafetyPoints(array &$player, float $delta): void
    {
        $player['state']['police']['safety_points'] = max(0, min(500, ($player['state']['police']['safety_points'] ?? 500) + $delta));
        $this->markStateDirty($player);
    }

    private function normaliseAngle(float $angle): float
    {
        while ($angle > 180.0) {
            $angle -= 360.0;
        }
        while ($angle < -180.0) {
            $angle += 360.0;
        }
        return $angle;
    }
}
