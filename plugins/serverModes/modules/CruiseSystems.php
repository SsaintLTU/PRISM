<?php
class ServerModes_CruiseSystems
{
    private const HUD_GROUP = 'CruiseHUD';
    private const BANK_GROUP = 'CruiseBank';
    private const TELEPORT_GROUP = 'CruiseTeleport';
    private const REGITRA_GROUP = 'CruiseRegitra';
    public const POLICE_GROUP = 'CruisePolice';

    private serverModes $plugin;
    private ServerModes_VehicleMods $vehicleMods;
    private bool $active = false;
    private array $config = array();
    private array $teleports = array();
    private array $trafficChecks = array();
    private array $officers = array();
    private array $hudRendered = array();

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
        $this->officers = $this->parseList($this->config['police_officers'] ?? '');
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function onActivate(): void
    {
        $this->active = true;
    }

    public function onDeactivate(): void
    {
        $this->active = false;
        $this->clearAllButtons();
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
        unset($this->hudRendered[$ucid]);
    }

    public function onMovementReward(array &$player, float $deltaKm, float $speedKph, float $earnedMoney, float $earnedXp, CompCar $info): void
    {
        $this->initialisePlayerState($player);

        $state =& $player['state'];
        $state['economy']['cash'] += $earnedMoney;
        $state['stats']['xp'] = ($state['stats']['xp'] ?? 0.0) + $earnedXp;

        if ($deltaKm > 0.0 || $speedKph > 1.0) {
            $state['telemetry']['last_move'] = time();
        }

        $state['telemetry']['speed'] = $speedKph;
        $state['telemetry']['position'] = array(
            'x' => $info->X / 65536.0,
            'y' => $info->Y / 65536.0,
        );

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
        $height = 30;
        $left = (int)((IS_X_MAX - $width) / 2);
        $top = 65;

        $this->drawButton($ucid, 'BankBG', self::BANK_GROUP, $left, $top, $width, $height, '', ISB_DARK);
        $this->drawButton($ucid, 'BankTitle', self::BANK_GROUP, $left + 2, $top + 3, $width - 4, 6, 'Bank', ISB_DARK | ISB_YELLOW);

        $balance = $this->formatCurrency($state['economy']['cash']);
        $bank = $this->formatCurrency($state['economy']['bank']);
        $interest = number_format($this->getDynamicInterestRatePercent(), 3);
        $salaryReady = $state['economy']['salary_ready_at'] <= time();
        $salaryLabel = $salaryReady ? '^2Ready' : '^8Waiting';

        $this->drawButton(
            $ucid,
            'BankBalance',
            self::BANK_GROUP,
            $left + 4,
            $top + 11,
            $width - 8,
            6,
            "^7Cash: ^2{$balance} ^7Bank: ^2{$bank} ^7Interest: ^3{$interest}%",
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
        $rowTop = $top + 20;

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
                if ($state['economy']['salary_ready_at'] > $now) {
                    $remaining = $state['economy']['salary_ready_at'] - $now;
                    $this->sendMessage($ucid, '^8Salary not ready yet. Try again in ' . $this->formatSeconds($remaining) . '.');
                    break;
                }
                $salary = $this->getConfigNumber('salary_amount', 350.0);
                $state['economy']['cash'] += $salary;
                $state['economy']['salary_ready_at'] = $now + (int)$this->getConfigNumber('salary_interval', 900);
                $state['economy']['last_salary'] = $salary;
                $state['economy']['salary_notified'] = false;
                $bankBalance = $state['economy']['bank'];
                $interestRate = $this->getDynamicInterestRatePercent() / 100.0;
                if ($bankBalance > 0.0 && $interestRate > 0.0) {
                    $interest = $bankBalance * $interestRate;
                    $state['economy']['bank'] += $interest;
                    $this->sendMessage($ucid, '^2Salary collected with ^3' . $this->formatCurrency($interest) . '^2 interest.');
                } else {
                    $this->sendMessage($ucid, '^2Salary collected.');
                }
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
        $client = $this->plugin->getClientByUCID($ucid);
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
        $modLabel = $vehicle['mod']['name'] ?? $car;
        if ($modLabel === '') {
            $modLabel = $car;
        }

        $this->drawButton($ucid, 'RegitraCar', self::REGITRA_GROUP, $left + 2, $top + 11, $width - 4, 6, '^7Car: ^3' . $car, ISB_DARK | ISB_LEFT);
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

        $targetClient = $this->plugin->getClientByUCID($targetUcid);
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

        $infoLines = array(
            '^7Name: ^3' . $targetClient->PName,
            '^7Car: ^3' . ($target['state']['garage']['active_car'] ?? 'N/A'),
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
                $targetClient = $this->plugin->getClientByUCID($targetUcid);
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
        if (!$this->active || empty($this->trafficChecks)) {
            return;
        }

        if ($packet->UCOAction !== UCO_CIRCLE_ENTER) {
            return;
        }

        $circleId = $packet->Info->Index;
        if (!isset($this->trafficChecks[$circleId])) {
            return;
        }

        $ucid = $this->plugin->getUcidByPlid($packet->PLID);
        if ($ucid === null) {
            return;
        }

        $player =& $this->plugin->getPlayerRecord($ucid);
        $this->initialisePlayerState($player);

        $lightId = $this->trafficChecks[$circleId]['light'];
        $expectedHeading = $this->trafficChecks[$circleId]['heading'];
        $currentHeading = $player['state']['telemetry']['heading'] ?? 0.0;
        $angleDiff = $this->normaliseAngle($currentHeading - $expectedHeading);

        $lightController = $this->plugin->getTrafficLights();
        $state = $lightController ? $lightController->getState($lightId) : TL_GREEN;

        if ($state === TL_RED && abs($angleDiff) < 45) {
            $this->adjustSafetyPoints($player, -25);
            $this->sendMessage($ucid, '^1Red light violation! Safety points reduced.');
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
            $label = $vehicle['mod']['name'] ?? $code;
            if ($label === '') {
                $label = $code;
            }
            $text = sprintf('^3%s ^7(%s) ^8| ^7Plate:^3 %s ^8| ^7%.2f km', $label, $code, $plate, $distance);
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

        $this->drawButton($ucid, 'HudMain', self::HUD_GROUP, 0, 0, 200, 4, $lineOne, ISB_DARK | ISB_LEFT | ISB_CLICK);
        $this->drawButton($ucid, 'HudSession', self::HUD_GROUP, 0, 196, 200, 4, $lineTwo, ISB_DARK | ISB_LEFT);

        $this->hudRendered[$ucid] = true;
    }

    private function tickAfk(array &$player): void
    {
        $ucid = $player['ucid'];
        $state =& $player['state'];
        $lastMove = $state['telemetry']['last_move'] ?? time();
        $now = time();
        $warnAfter = (int)$this->getConfigNumber('afk_warn_seconds', 720);
        $kickAfter = (int)$this->getConfigNumber('afk_kick_seconds', 900);

        if (($now - $lastMove) > $kickAfter) {
            if (($state['ui']['afk_notified'] ?? false) !== 'kick') {
                $this->sendMessage($ucid, '^1You have been idle for too long. Please move your car.');
                $state['ui']['afk_notified'] = 'kick';
            }
        } elseif (($now - $lastMove) > $warnAfter) {
            if (($state['ui']['afk_notified'] ?? false) !== 'warn') {
                $remaining = $kickAfter - ($now - $lastMove);
                $this->sendMessage($ucid, '^3AFK warning. Move within ' . $this->formatSeconds(max(0, $remaining)) . '.');
                $state['ui']['afk_notified'] = 'warn';
            }
        } else {
            $state['ui']['afk_notified'] = null;
        }
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
        }
        $this->hudRendered = array();
    }

    private function drawButton(int $ucid, string $key, string $group, int $left, int $top, int $width, int $height, string $text, int $style, ?array $callback = null): void
    {
        $button = new Button($ucid, $key, $group);
        $button->L($left)->T($top)->W($width)->H($height)->BStyle($style)->Text($text);
        if ($callback !== null) {
            $button->registerOnClick($this->plugin, 'handleCruiseButton', $callback);
        }
        $button->Send();
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
    }

    private function defaultState(): array
    {
        $now = time();
        return array(
            'economy' => array(
                'cash' => 0.0,
                'bank' => 0.0,
                'salary_ready_at' => $now,
                'salary_notified' => false,
                'last_salary' => 0.0,
            ),
            'garage' => array(
                'active_car' => '',
                'vehicles' => array(),
            ),
            'stats' => array(
                'xp' => 0.0,
                'licenses' => 0,
                'lap_count' => 0,
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
            ),
            'ui' => array(),
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
        );
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

    private function getDynamicInterestRatePercent(): float
    {
        $baseRate = $this->getConfigNumber('bank_interest_rate', 0.024);
        $perPlayer = $this->getConfigNumber('bank_interest_rate_per_player', 0.02);
        $playerCount = max(0, $this->plugin->getActivePlayerCount());

        $rate = $baseRate + ($perPlayer * $playerCount);

        return max(0.0, $rate);
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
            $pos = $player['state']['telemetry']['position'];
            $dx = $pos['x'] - $officerPos['x'];
            $dy = $pos['y'] - $officerPos['y'];
            $distance = sqrt(($dx * $dx) + ($dy * $dy));
            if ($distance <= $radius) {
                $client = $this->plugin->getClientByUCID($ucid);
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

        $targetClient = $this->plugin->getClientByUCID($targetUcid);
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
