<?php
require_once __DIR__ . '/serverModes/modules/DB.php';
require_once __DIR__ . '/serverModes/modules/ModeBase.php';
require_once __DIR__ . '/serverModes/modules/ModeCruise.php';
require_once __DIR__ . '/serverModes/modules/ModeDrift.php';
require_once __DIR__ . '/serverModes/modules/ModeRace.php';
require_once __DIR__ . '/serverModes/modules/RaceSystems.php';
require_once __DIR__ . '/serverModes/modules/TrafficLightController.php';
require_once __DIR__ . '/serverModes/modules/CruiseSystems.php';
require_once __DIR__ . '/serverModes/modules/DriftSystems.php';
require_once __DIR__ . '/serverModes/modules/Friends.php';
require_once __DIR__ . '/serverModes/modules/VehicleMods.php';

class serverModes extends Plugins
{
    const NAME = 'Server Modes';
    const AUTHOR = 'OpenAI';
    const VERSION = '2.0.0';
    const DESCRIPTION = 'Cruise / drift / race lifecycle manager with MySQL persistence and traffic light control.';

    private array $config = array();
    private ?ServerModes_Database $database = null;
    private bool $enabled = false;

    private array $players = array();
    private array $plidMap = array();

    /** @var array<string, ServerModes_Mode> */
    private array $modes = array();
    private ?ServerModes_Mode $activeMode = null;

    private string $activeHost = '';
    private array $hostModeMap = array();
    private string $currentTrack = '';

    private int $snapshotInterval = 60;
    private int $autosaveInterval = 15;

    private ?ServerModes_TrafficLightController $trafficLights = null;
    private ServerModes_VehicleMods $vehicleMods;
    private ServerModes_CruiseSystems $cruiseSystems;
    private ServerModes_DriftSystems $driftSystems;
    private ServerModes_RaceSystems $raceSystems;
    private ServerModes_Friends $friendManager;

    public function __construct()
    {
        $this->config = $this->loadConfig();
        $this->snapshotInterval = max(10, (int)($this->config['general']['snapshot_interval'] ?? 60));
        $this->autosaveInterval = max(5, (int)($this->config['general']['autosave_interval'] ?? 15));

        $this->database = new ServerModes_Database($this->config['database'] ?? array());
        if (!$this->database->isAvailable()) {
            console('serverModes: PDO extension is not available, plugin disabled.');
            return;
        }

        if (!$this->database->ensureConnection()) {
            console('serverModes: database connection failed, plugin disabled.');
            return;
        }

        $this->vehicleMods = new ServerModes_VehicleMods($this, $this->database, $this->config['mods_api'] ?? array());
        $this->vehicleMods->bootstrap();

        $this->cruiseSystems = new ServerModes_CruiseSystems($this, $this->vehicleMods, $this->config['mode_cruise'] ?? array());
        $this->driftSystems = new ServerModes_DriftSystems($this, $this->config['mode_drift'] ?? array());
        $this->raceSystems = new ServerModes_RaceSystems($this, $this->config['mode_race'] ?? array());
        $this->friendManager = new ServerModes_Friends($this, $this->config['social'] ?? array());

        $this->modes = array(
            'cruise' => new ServerModes_ModeCruise($this, $this->cruiseSystems, $this->config['mode_cruise'] ?? array()),
            'drift'  => new ServerModes_ModeDrift($this, $this->driftSystems, $this->config['mode_drift'] ?? array()),
            'race'   => new ServerModes_ModeRace($this, $this->raceSystems, $this->config['mode_race'] ?? array()),
        );

        $this->hostModeMap = $this->config['hosts'] ?? array();

        $this->trafficLights = new ServerModes_TrafficLightController($this, $this->config['traffic_lights'] ?? array());

        $this->enabled = true;

        $this->registerPacket('onPrismConnect', ISP_VER);
        $this->registerPacket('onClientConnect', ISP_NCN);
        $this->registerPacket('onClientInfo', ISP_NCI);
        $this->registerPacket('onClientDisconnect', ISP_CNL);
        $this->registerPacket('onStateInfo', ISP_STA);
        $this->registerPacket('onPlayerJoinRace', ISP_NPL);
        $this->registerPacket('onPlayerLeaveRace', ISP_PLL);
        $this->registerPacket('onCarInfo', ISP_MCI);
        $this->registerPacket('onLapCompleted', ISP_LAP);
        $this->registerPacket('onRaceResult', ISP_RES);
        $this->registerPacket('onButtonClick', ISP_BTC);
        $this->registerPacket('onButtonText', ISP_BTT);
        $this->registerPacket('onButtonClear', ISP_BFN);
        $this->registerPacket('onUserControlObject', ISP_UCO);

        $this->registerSayCommand('bank', 'commandCruiseBank', 'Open the cruise bank.');
        $this->registerSayCommand('teleport', 'commandCruiseTeleport', 'Open teleport menu.');
        $this->registerSayCommand('regitra', 'commandCruiseRegitra', 'Open vehicle registry.');
        $this->registerSayCommand('garage', 'commandCruiseGarage', 'Show owned vehicles.');
        $this->registerSayCommand('chase', 'commandCruiseChase', 'Open the police menu.');
        $this->registerSayCommand('add', 'commandAddFriend', 'Add a player to your friends list.');
        $this->registerSayCommand('friends', 'commandFriends', 'Manage your friends list display.');
        $this->registerSayCommand('pmto', 'commandPmTo', 'Select a player for private messaging.');
        $this->registerSayCommand('pm', 'commandPm', 'Send a private message to the selected player.');

        $this->createNamedTimer('serverModes.tick', 'handleTickTimer', 1.0, Timer::REPEAT);
        $this->createNamedTimer('serverModes.autosave', 'handleAutosaveTimer', $this->autosaveInterval, Timer::REPEAT);
    }

    public function __destruct()
    {
        if (!$this->enabled) {
            return;
        }

        foreach (array_keys($this->players) as $ucid) {
            $this->flushPlayer($ucid, true);
        }
    }

    public function onPrismConnect(IS_VER $packet)
    {
        if (!$this->enabled) {
            return PLUGIN_CONTINUE;
        }

        $this->activateModeForHost($this->getCurrentHostId());

        if ($this->activeMode) {
            console(sprintf('serverModes: host "%s" using %s mode.', $this->activeHost, $this->activeMode->getKey()));
        } else {
            console(sprintf('serverModes: host "%s" does not map to a managed mode.', $this->activeHost));
        }

        return PLUGIN_CONTINUE;
    }

    public function commandCruiseBank($cmd, $ucid, $packet = null)
    {
        if (!$this->enabled || !$this->cruiseSystems->isActive()) {
            return PLUGIN_HANDLED;
        }

        $this->cruiseSystems->showBankUi($ucid);
        return PLUGIN_HANDLED;
    }

    public function commandCruiseTeleport($cmd, $ucid, $packet = null)
    {
        if (!$this->enabled || !$this->cruiseSystems->isActive()) {
            return PLUGIN_HANDLED;
        }

        $this->cruiseSystems->showTeleportMenu($ucid);
        return PLUGIN_HANDLED;
    }

    public function commandCruiseRegitra($cmd, $ucid, $packet = null)
    {
        if (!$this->enabled || !$this->cruiseSystems->isActive()) {
            return PLUGIN_HANDLED;
        }

        $this->cruiseSystems->showRegitraMenu($ucid);
        return PLUGIN_HANDLED;
    }

    public function commandCruiseGarage($cmd, $ucid, $packet = null)
    {
        if (!$this->enabled || !$this->cruiseSystems->isActive()) {
            return PLUGIN_HANDLED;
        }

        $this->cruiseSystems->showGarage($ucid);
        return PLUGIN_HANDLED;
    }

    public function commandCruiseChase($cmd, $ucid, $packet = null)
    {
        if (!$this->enabled || !$this->cruiseSystems->isActive()) {
            return PLUGIN_HANDLED;
        }

        $this->cruiseSystems->showPoliceMenu($ucid);
        return PLUGIN_HANDLED;
    }

    public function commandAddFriend($cmd, $ucid, $packet = null)
    {
        if (!$this->enabled) {
            return PLUGIN_HANDLED;
        }

        $target = trim(substr($cmd, strlen('add')));
        if ($target === '') {
            $this->MsgToUCID($ucid, '^1Usage:^7 !add <player name>');
            return PLUGIN_HANDLED;
        }

        $this->friendManager->handleAddCommand($ucid, $target);
        return PLUGIN_HANDLED;
    }

    public function commandFriends($cmd, $ucid, $packet = null)
    {
        if (!$this->enabled) {
            return PLUGIN_HANDLED;
        }

        $argument = trim(substr($cmd, strlen('friends')));
        $this->friendManager->handleFriendsCommand($ucid, $argument);

        return PLUGIN_HANDLED;
    }

    public function commandPmTo($cmd, $ucid, $packet = null)
    {
        if (!$this->enabled) {
            return PLUGIN_HANDLED;
        }

        $argument = trim(substr($cmd, strlen('pmto')));
        if ($argument === '') {
            $this->MsgToUCID($ucid, '^1Usage:^7 !pmto <player name>');
            return PLUGIN_HANDLED;
        }

        $match = $this->findPlayerAcrossHosts($argument);
        if ($match === null) {
            $this->MsgToUCID($ucid, '^1Player not found on any connected host.');
            return PLUGIN_HANDLED;
        }

        $this->setPmTarget($ucid, $match);
        $hostLabel = $match['host_name'] !== '' ? $match['host_name'] : ('Host ' . $match['host_id']);
        $this->MsgToUCID($ucid, sprintf('^5[PM]^7 Target set to ^3%s ^8(^7%s^8).', $match['display'], $hostLabel));

        return PLUGIN_HANDLED;
    }

    public function commandPm($cmd, $ucid, $packet = null)
    {
        if (!$this->enabled) {
            return PLUGIN_HANDLED;
        }

        $message = trim(substr($cmd, strlen('pm')));
        if ($message === '') {
            $this->MsgToUCID($ucid, '^1Usage:^7 !pm <message>');
            return PLUGIN_HANDLED;
        }

        $target = $this->getPmTarget($ucid);
        if ($target === null) {
            $this->MsgToUCID($ucid, '^1Use ^7!pmto <name> ^1to select a player first.');
            return PLUGIN_HANDLED;
        }

        $resolved = $this->resolvePmTarget($target);
        if ($resolved === null) {
            $this->MsgToUCID($ucid, '^1That player is no longer online.');
            $this->setPmTarget($ucid, null);
            return PLUGIN_HANDLED;
        }

        $senderName = $this->players[$ucid]['nickname'] ?? $this->players[$ucid]['username'] ?? 'Player';
        $outbound = sprintf('^5[PM]^7 %s: ^3%s', $senderName, $message);
        Msg2Lfs()->UCID($resolved['ucid'])->Text($outbound)->send($resolved['host_id']);

        $this->MsgToUCID($ucid, '^5[PM]^7 to ^3' . $resolved['display'] . '^7: ^3' . $message);

        return PLUGIN_HANDLED;
    }

    public function onClientConnect(IS_NCN $NCN)
    {
        if (!$this->enabled || $NCN->UCID == 0) {
            return PLUGIN_CONTINUE;
        }

        $player =& $this->ensurePlayer($NCN->UCID);
        $player['username'] = $NCN->UName;
        $player['nickname'] = $NCN->PName;
        $player['connected_at'] = time();
        $player['last_seen'] = time();
        $player['host'] = $this->activeHost;
        $player['mode_key'] = $this->activeMode ? $this->activeMode->getKey() : '';
        $player['dirty'] = true;

        $this->friendManager->onPlayerConnected($player);

        if ($this->activeMode) {
            $this->activeMode->onPlayerConnected($player);
        }

        return PLUGIN_CONTINUE;
    }

    public function onClientInfo(IS_NCI $NCI)
    {
        if (!$this->enabled || $NCI->UCID == 0) {
            return PLUGIN_CONTINUE;
        }

        $player =& $this->ensurePlayer($NCI->UCID);
        $player['user_id'] = (int)$NCI->UserID;
        $player['dirty'] = true;

        $this->bootstrapPlayerFromDatabase($NCI->UCID);
        $this->friendManager->onClientInfo($player);

        return PLUGIN_CONTINUE;
    }

    public function onStateInfo(IS_STA $STA)
    {
        if (!$this->enabled) {
            return PLUGIN_CONTINUE;
        }

        $track = trim($STA->Track);
        if ($track !== $this->currentTrack) {
            $this->currentTrack = $track;
            $this->driftSystems->onTrackChanged($track);
        }

        return PLUGIN_CONTINUE;
    }

    public function onClientDisconnect(IS_CNL $CNL)
    {
        if (!$this->enabled || $CNL->UCID == 0) {
            return PLUGIN_CONTINUE;
        }

        if (isset($this->players[$CNL->UCID])) {
            $player =& $this->players[$CNL->UCID];
            $this->friendManager->onPlayerDisconnected($player);

            if ($this->activeMode) {
                $this->activeMode->onPlayerDisconnected($player);
            }
        }

        $this->flushPlayer($CNL->UCID, true);
        unset($this->players[$CNL->UCID]);

        foreach ($this->plidMap as $plid => $ucid) {
            if ($ucid === $CNL->UCID) {
                unset($this->plidMap[$plid]);
            }
        }

        return PLUGIN_CONTINUE;
    }

    public function onPlayerJoinRace(IS_NPL $NPL)
    {
        if (!$this->enabled) {
            return PLUGIN_CONTINUE;
        }

        $this->plidMap[$NPL->PLID] = $NPL->UCID;
        $player =& $this->ensurePlayer($NPL->UCID);
        $player['positions'][$NPL->PLID] = null;

        if ($this->cruiseSystems->isActive()) {
            $carCode = trim($NPL->CName);
            $skinName = trim($NPL->SName);
            $player['state']['garage']['active_car'] = $carCode;
            $player['state']['garage']['active_skin'] = $skinName;
            $isNewVehicle = false;
            if (!isset($player['state']['garage']['vehicles'][$carCode])) {
                $player['state']['garage']['vehicles'][$carCode] = array(
                    'plate' => '---:---',
                    'distance' => 0.0,
                    'insurance_until' => 0,
                    'mod' => array(),
                    'acquired_at' => 0,
                    'discord_announced' => false,
                    'last_skin' => '',
                );
                $isNewVehicle = true;
            }
            if ($skinName !== '') {
                $player['state']['garage']['vehicles'][$carCode]['last_skin'] = $skinName;
            }
            $player['state_dirty'] = true;
            $this->vehicleMods->handleVehicleActivation($player, $carCode, $isNewVehicle);
            $this->cruiseSystems->onPlayerJoinRace($player, $NPL);
        }

        $this->driftSystems->onPlayerJoinRace($player, $NPL);

        return PLUGIN_CONTINUE;
    }

    public function onPlayerLeaveRace(IS_PLL $PLL)
    {
        if (!$this->enabled) {
            return PLUGIN_CONTINUE;
        }

        if (isset($this->plidMap[$PLL->PLID])) {
            $ucid = $this->plidMap[$PLL->PLID];
            unset($this->plidMap[$PLL->PLID]);
            if ($this->cruiseSystems->isActive() && isset($this->players[$ucid])) {
                $player =& $this->players[$ucid];
                $this->cruiseSystems->onPlayerLeaveRace($player);
            }
            if (isset($this->players[$ucid]['positions'][$PLL->PLID])) {
                unset($this->players[$ucid]['positions'][$PLL->PLID]);
            }
        }

        return PLUGIN_CONTINUE;
    }

    public function onCarInfo(IS_MCI $MCI)
    {
        if (!$this->enabled) {
            return PLUGIN_CONTINUE;
        }

        foreach ($MCI->Info as $carInfo) {
            $plid = $carInfo->PLID;
            if ($plid == 0 || !isset($this->plidMap[$plid])) {
                continue;
            }

            $ucid = $this->plidMap[$plid];
            if (!isset($this->players[$ucid])) {
                continue;
            }

            $player =& $this->players[$ucid];
            $this->processMovement($player, $plid, $carInfo);
        }

        return PLUGIN_CONTINUE;
    }

    public function onLapCompleted(IS_LAP $lap)
    {
        if (!$this->enabled) {
            return PLUGIN_CONTINUE;
        }

        $plid = $lap->PLID;
        if ($plid == 0 || !isset($this->plidMap[$plid])) {
            return PLUGIN_CONTINUE;
        }

        $ucid = $this->plidMap[$plid];
        if (!isset($this->players[$ucid])) {
            return PLUGIN_CONTINUE;
        }

        $player =& $this->players[$ucid];
        $player['session']['lap_count'] += 1;
        $player['dirty'] = true;

        if ($this->activeMode) {
            $this->activeMode->onLapCompleted($player, $lap);
        }

        return PLUGIN_CONTINUE;
    }

    public function onRaceResult(IS_RES $RES)
    {
        if (!$this->enabled) {
            return PLUGIN_CONTINUE;
        }

        $plid = $RES->PLID;
        $ucid = $plid ? ($this->plidMap[$plid] ?? null) : null;
        $player = null;
        if ($ucid !== null && isset($this->players[$ucid])) {
            $player =& $this->players[$ucid];
        }

        if ($this->activeMode && $player !== null) {
            $this->activeMode->onRaceResult($player, $RES);
        } elseif ($this->activeMode && $player === null) {
            $dummy = array('ucid' => $ucid);
            $this->activeMode->onRaceResult($dummy, $RES);
        }

        return PLUGIN_CONTINUE;
    }

    public function onButtonClick(IS_BTC $BTC)
    {
        ButtonManager::onButtonClick($BTC);
        return PLUGIN_CONTINUE;
    }

    public function onButtonText(IS_BTT $BTT)
    {
        ButtonManager::onButtonText($BTT);
        return PLUGIN_CONTINUE;
    }

    public function onButtonClear(IS_BFN $BFN)
    {
        if ($BFN->SubT == BFN_CLEAR) {
            ButtonManager::clearButtonsForConn($BFN->UCID);
        }

        return PLUGIN_CONTINUE;
    }

    public function onUserControlObject(IS_UCO $UCO)
    {
        if ($this->enabled && $this->cruiseSystems->isActive()) {
            $this->cruiseSystems->handleUserControlObject($UCO);
        }

        return PLUGIN_CONTINUE;
    }

    public function handleCruiseButton(...$args): void
    {
        if (!$this->enabled || !$this->cruiseSystems->isActive()) {
            return;
        }

        if (count($args) < 2) {
            return;
        }

        $ucid = (int)$args[0];
        $type = (string)$args[1];
        $action = $args[2] ?? null;
        $extra = $args[3] ?? null;

        switch ($type) {
            case 'bank':
                $this->cruiseSystems->handleBankAction($ucid, (string)$action);
                break;
            case 'teleport':
                $this->cruiseSystems->handleTeleport($ucid, (string)$action);
                break;
            case 'regitra':
                $this->cruiseSystems->handleRegitraAction($ucid, (string)$action);
                break;
            case 'police':
                if ($action === 'close') {
                    ButtonManager::removeButtonsByGroup($ucid, ServerModes_CruiseSystems::POLICE_GROUP);
                } elseif ($action === 'back') {
                    $this->cruiseSystems->showPoliceMenu($ucid);
                }
                break;
            case 'police_inspect':
                $this->cruiseSystems->showPolicePanel($ucid, (int)$action);
                break;
            case 'police_fine':
                $this->cruiseSystems->handlePoliceAction($ucid, 'police_fine', (int)$action, (float)$extra);
                break;
            case 'police_release':
                $this->cruiseSystems->handlePoliceAction($ucid, 'police_release', (int)$action);
                break;
        }
    }

    public function handleDriftButton($ucid, $action, $extra = null): void
    {
        if (!$this->enabled) {
            return;
        }

        $this->driftSystems->handleButton((int)$ucid, (string)$action, $extra);
    }

    public function handleRaceButton($ucid, $action, $extra = null): void
    {
        if (!$this->enabled) {
            return;
        }

        $this->raceSystems->handleButton((int)$ucid, (string)$action, $extra);
    }

    public function handleFriendsButton($ucid, $action, $extra = null): void
    {
        if (!$this->enabled) {
            return;
        }

        $this->friendManager->handleButton((int)$ucid, (string)$action, $extra);
    }

    public function handleTickTimer()
    {
        if (!$this->enabled) {
            return PLUGIN_CONTINUE;
        }

        if ($this->trafficLights) {
            $this->trafficLights->update();
        }

        if (isset($this->vehicleMods)) {
            $this->vehicleMods->tick();
        }

        if ($this->activeMode) {
            $this->activeMode->tick($this->players);
        }

        $this->friendManager->tick();

        return PLUGIN_CONTINUE;
    }

    public function handleAutosaveTimer()
    {
        if (!$this->enabled) {
            return PLUGIN_CONTINUE;
        }

        foreach (array_keys($this->players) as $ucid) {
            $this->flushPlayer($ucid, false);
        }

        return PLUGIN_CONTINUE;
    }

    private function findPlayerAcrossHosts(string $query): ?array
    {
        global $PRISM;

        $normalized = strtolower($query);
        foreach ($PRISM->hosts->getHostsInfo() as $host) {
            if (($host['connStatus'] ?? 0) < CONN_VERIFIED) {
                continue;
            }

            $state = $this->getHostState($host['id']);
            if (!$state || empty($state->clients)) {
                continue;
            }

            foreach ($state->clients as $client) {
                $ucid = $client->UCID ?? null;
                if ($ucid === null) {
                    continue;
                }

                $username = $client->UName ?? '';
                $nickname = $client->PName ?? '';
                if ($normalized === strtolower($username) || ($nickname !== '' && $normalized === strtolower($nickname))) {
                    return array(
                        'host_id' => $host['id'],
                        'host_name' => $host['hostname'] ?? '',
                        'ucid' => $ucid,
                        'username' => $username,
                        'nickname' => $nickname,
                        'display' => $nickname !== '' ? $nickname : $username,
                    );
                }
            }
        }

        return null;
    }

    private function resolvePmTarget(array $target): ?array
    {
        $hostId = $target['host_id'] ?? null;
        $ucid = $target['ucid'] ?? null;
        if ($hostId === null || $ucid === null) {
            return null;
        }

        $state = $this->getHostState($hostId);
        if (!$state || empty($state->clients)) {
            return null;
        }

        foreach ($state->clients as $client) {
            if (($client->UCID ?? null) === $ucid) {
                $username = $client->UName ?? '';
                $nickname = $client->PName ?? '';
                return array(
                    'host_id' => $hostId,
                    'host_name' => $target['host_name'] ?? '',
                    'ucid' => $ucid,
                    'username' => $username,
                    'nickname' => $nickname,
                    'display' => $nickname !== '' ? $nickname : $username,
                );
            }
        }

        return null;
    }

    private function setPmTarget(int $ucid, ?array $target): void
    {
        $player =& $this->ensurePlayer($ucid);
        if (!isset($player['social']) || !is_array($player['social'])) {
            $player['social'] = array();
        }

        if ($target === null) {
            $player['social']['pm_target'] = null;
        } else {
            $player['social']['pm_target'] = array(
                'host_id' => $target['host_id'],
                'host_name' => $target['host_name'] ?? '',
                'ucid' => $target['ucid'],
                'display' => $target['display'],
            );
        }
    }

    private function getPmTarget(int $ucid): ?array
    {
        if (!isset($this->players[$ucid])) {
            return null;
        }

        $target = $this->players[$ucid]['social']['pm_target'] ?? null;
        return is_array($target) ? $target : null;
    }

    public function applyModeConfig(string $modeKey, array $config): void
    {
        if (isset($this->modes[$modeKey])) {
            $this->modes[$modeKey]->applyConfig($config);
        }
    }

    public function getMode(string $modeKey): ?ServerModes_Mode
    {
        return $this->modes[$modeKey] ?? null;
    }

    public function getDatabase(): ?ServerModes_Database
    {
        return $this->database;
    }

    public function getVehicleMods(): ServerModes_VehicleMods
    {
        return $this->vehicleMods;
    }

    public function getSnapshotInterval(): int
    {
        return $this->snapshotInterval;
    }

    public function getAutosaveInterval(): int
    {
        return $this->autosaveInterval;
    }

    public function getTrafficLights(): ?ServerModes_TrafficLightController
    {
        return $this->trafficLights;
    }

    public function getActiveHostId(): string
    {
        return $this->activeHost;
    }

    public function getCurrentTrack(): string
    {
        return $this->currentTrack;
    }

    private function processMovement(array &$player, int $plid, CompCar $info): void
    {
        $current = array(
            'x' => (int)$info->X,
            'y' => (int)$info->Y,
            'z' => (int)$info->Z,
            'ts' => microtime(true),
        );

        $previous = $player['positions'][$plid] ?? null;
        $player['positions'][$plid] = $current;

        if (!$previous) {
            return;
        }

        $dx = ($current['x'] - $previous['x']) / 65536.0;
        $dy = ($current['y'] - $previous['y']) / 65536.0;
        $dz = ($current['z'] - $previous['z']) / 65536.0;
        $distanceMetres = sqrt(($dx * $dx) + ($dy * $dy) + ($dz * $dz));

        if ($distanceMetres <= 0.01 || $distanceMetres > 500) {
            // Ignore tiny jitter and large teleports.
            return;
        }

        $distanceKm = $distanceMetres / 1000.0;
        $player['session']['distance_km'] += $distanceKm;
        $player['dirty'] = true;
        $player['last_seen'] = time();

        $speedMs = ($info->Speed * (100.0 / 32768.0));
        $speedKph = $speedMs * 3.6;

        if ($this->activeMode) {
            $this->activeMode->processMovement($player, $distanceKm, $speedKph, $info);
        }

        if (isset($player['state'])) {
            $player['state']['telemetry']['heading'] = ($info->Heading * 360.0) / 65536.0;
        }
    }

    private function flushPlayer(int $ucid, bool $force): void
    {
        if (!isset($this->players[$ucid])) {
            return;
        }

        $player =& $this->players[$ucid];
        if (empty($player['user_id'])) {
            return;
        }

        $now = time();
        $hasProgress = ($player['session']['distance_km'] > 0.0001)
            || ($player['session']['money'] > 0.0001)
            || ($player['session']['xp'] > 0.0001)
            || ($player['session']['lap_count'] > 0);

        if (!$force && isset($player['last_saved']) && ($now - $player['last_saved']) < $this->autosaveInterval && !$hasProgress) {
            return;
        }

        if (!$force && !$hasProgress && !$player['dirty']) {
            return;
        }

        $totals = array(
            'distance_km' => $player['lifetime']['distance_km'] + $player['session']['distance_km'],
            'money' => $player['lifetime']['money'] + $player['session']['money'],
            'xp' => $player['lifetime']['xp'] + $player['session']['xp'],
            'lap_count' => $player['lifetime']['lap_count'] + $player['session']['lap_count'],
        );

        $this->database->savePlayer(array(
            'user_id' => $player['user_id'],
            'username' => $player['username'],
            'nickname' => $player['nickname'],
            'distance' => $totals['distance_km'],
            'earnings' => $totals['money'],
            'xp' => $totals['xp'],
            'lap_count' => $totals['lap_count'],
            'mode' => $player['mode_key'],
            'last_seen' => $now,
        ));

        $shouldSnapshot = $hasProgress && ($force || ($now - $player['last_snapshot']) >= $this->snapshotInterval);
        if ($shouldSnapshot) {
            $this->database->recordSnapshot(array(
                'user_id' => $player['user_id'],
                'mode' => $player['mode_key'],
                'distance' => $player['session']['distance_km'],
                'earnings' => $player['session']['money'],
                'xp' => $player['session']['xp'],
                'lap_count' => $player['session']['lap_count'],
            ));
            $player['last_snapshot'] = $now;
        }

        $player['lifetime']['distance_km'] = $totals['distance_km'];
        $player['lifetime']['money'] = $totals['money'];
        $player['lifetime']['xp'] = $totals['xp'];
        $player['lifetime']['lap_count'] = $totals['lap_count'];

        $player['session'] = array(
            'distance_km' => 0.0,
            'money' => 0.0,
            'xp' => 0.0,
            'lap_count' => 0,
        );

        if (($force || !empty($player['state_dirty'])) && !empty($player['state'])) {
            $this->database->savePlayerState($player['user_id'], $player['state']);
            $player['state_dirty'] = false;
        }

        $player['dirty'] = false;
        $player['last_saved'] = $now;
    }

    private function &ensurePlayer(int $ucid): array
    {
        if (!isset($this->players[$ucid])) {
            $this->players[$ucid] = array(
                'ucid' => $ucid,
                'user_id' => null,
                'username' => '',
                'nickname' => '',
                'host' => $this->activeHost,
                'mode_key' => $this->activeMode ? $this->activeMode->getKey() : '',
                'connected_at' => time(),
                'last_seen' => time(),
                'last_saved' => time(),
                'last_snapshot' => time(),
                'lifetime' => array(
                    'distance_km' => 0.0,
                    'money' => 0.0,
                    'xp' => 0.0,
                    'lap_count' => 0,
                ),
                'session' => array(
                    'distance_km' => 0.0,
                    'money' => 0.0,
                    'xp' => 0.0,
                    'lap_count' => 0,
                ),
                'positions' => array(),
                'state' => array(),
                'state_dirty' => false,
                'dirty' => false,
                'social' => array(
                    'pm_target' => null,
                ),
            );
        }

        return $this->players[$ucid];
    }

    public function &getPlayerRecord(int $ucid): array
    {
        return $this->ensurePlayer($ucid);
    }

    public function &getPlayerMap(): array
    {
        return $this->players;
    }

    public function getActivePlayerCount(): int
    {
        $count = 0;
        foreach ($this->players as $player) {
            if (($player['ucid'] ?? 0) > 0) {
                $count++;
            }
        }

        return $count;
    }

    public function getUcidByPlid(int $plid): ?int
    {
        return $this->plidMap[$plid] ?? null;
    }

    private function bootstrapPlayerFromDatabase(int $ucid): void
    {
        if (empty($this->players[$ucid]['user_id'])) {
            return;
        }

        $userId = $this->players[$ucid]['user_id'];
        $record = $this->database->loadPlayer($userId);
        if (!$record) {
            return;
        }

        $player =& $this->players[$ucid];
        $player['lifetime']['distance_km'] = (float)$record['total_distance'];
        $player['lifetime']['money'] = (float)$record['total_earnings'];
        $player['lifetime']['xp'] = (float)$record['total_xp'];
        $player['lifetime']['lap_count'] = (int)$record['lap_count'];
        $player['mode_key'] = $record['last_mode'] ?: ($this->activeMode ? $this->activeMode->getKey() : '');

        $state = $this->database->loadPlayerState($userId);
        if (is_array($state) && !empty($state)) {
            $player['state'] = array_replace_recursive($player['state'], $state);
            $player['state_dirty'] = false;
        }
    }

    private function activateModeForHost(?string $hostId): void
    {
        $hostId = $hostId ?? '';
        $previous = $this->activeMode;
        if ($previous) {
            $previous->onDeactivate();
        }

        $this->activeHost = $hostId;
        $modeKey = $this->hostModeMap[$hostId] ?? null;
        if ($modeKey && isset($this->modes[$modeKey])) {
            $this->activeMode = $this->modes[$modeKey];
        } else {
            $this->activeMode = null;
        }

        $trafficConfig = $this->config['traffic_lights'] ?? array();
        $hostSection = 'traffic_lights.host_' . $hostId;
        if (isset($this->config[$hostSection]) && is_array($this->config[$hostSection])) {
            $trafficConfig = array_replace($trafficConfig, $this->config[$hostSection]);
        }

        if ($this->activeMode) {
            $modeKey = $this->activeMode->getKey();
            $modeConfig = $this->config['mode_' . $modeKey] ?? array();
            $hostModeSection = 'mode_' . $modeKey . '.host_' . $hostId;
            if (isset($this->config[$hostModeSection]) && is_array($this->config[$hostModeSection])) {
                $modeConfig = array_replace($modeConfig, $this->config[$hostModeSection]);
            }
            $this->activeMode->applyConfig($modeConfig);

            $modeSection = 'traffic_lights.' . $this->activeMode->getKey();
            if (isset($this->config[$modeSection]) && is_array($this->config[$modeSection])) {
                $trafficConfig = array_replace($trafficConfig, $this->config[$modeSection]);
            }

            if ($this->trafficLights) {
                $this->trafficLights->applyConfig($trafficConfig);
            }

            $this->activeMode->onActivate();
        } elseif ($this->trafficLights) {
            $this->trafficLights->applyConfig($trafficConfig);
        }

        foreach ($this->players as &$player) {
            $player['mode_key'] = $this->activeMode ? $this->activeMode->getKey() : '';
        }
        unset($player);
    }

    private function loadConfig(): array
    {
        $default = $this->getDefaultConfig();

        $config = array();
        $path = ROOTPATH . '/configs/serverModes.ini';
        if (is_file($path)) {
            $config = parse_ini_file($path, true, INI_SCANNER_TYPED);
        } else {
            $sample = ROOTPATH . '/configs/serverModes-sample.ini';
            if (is_file($sample)) {
                $config = parse_ini_file($sample, true, INI_SCANNER_TYPED);
            }
        }

        if (!is_array($config)) {
            $config = array();
        }

        return array_replace_recursive($default, $config);
    }

    private function getDefaultConfig(): array
    {
        return array(
            'database' => array(
                'dsn' => 'mysql:host=127.0.0.1;dbname=lfs;charset=utf8mb4',
                'username' => 'lfsuser',
                'password' => 'changeme',
            ),
            'general' => array(
                'snapshot_interval' => 60,
                'autosave_interval' => 15,
            ),
            'hosts' => array(
                'cruise' => 'cruise',
                'drift' => 'drift',
                'race' => 'race',
            ),
            'mode_cruise' => array(
                'money_per_km' => 7.5,
                'xp_per_km' => 0.25,
                'speed_limit' => 0,
                'penalty_multiplier' => 0.5,
            ),
            'mode_drift' => array(
                'money_per_km' => 5.0,
                'xp_per_km' => 0.4,
                'angle_bonus' => 1.4,
                'angle_threshold' => 35.0,
            ),
            'mode_race' => array(
                'money_per_km' => 9.0,
                'xp_per_km' => 0.3,
                'lap_bonus' => 20.0,
                'lap_xp' => 1.5,
            ),
            'traffic_lights' => array(
                'enabled' => false,
                'index' => 149,
                'sequence' => 'red:25,red_yellow:3,green:25,yellow:3',
                'lights' => '1@0,2@15',
            ),
        );
    }
}
