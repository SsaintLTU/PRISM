<?php
require_once __DIR__ . '/serverModes/Database.php';
require_once __DIR__ . '/serverModes/Modes/BaseMode.php';
require_once __DIR__ . '/serverModes/Modes/CruiseMode.php';
require_once __DIR__ . '/serverModes/Modes/RaceMode.php';
require_once __DIR__ . '/serverModes/Modes/DriftMode.php';

class serverModes extends Plugins
{
    const URL = '';
    const NAME = 'Server Mode Toolkit';
    const AUTHOR = 'OpenAI';
    const VERSION = '1.0.0';
    const DESCRIPTION = 'Shared cruise / drift / race plugin with MySQL persistence and periodic snapshots.';

    private $config = array();
    private $db;
    private $enabled = false;
    private $players = array();
    private $plidMap = array();
    private $modes = array();
    private $activeMode = null;
    private $activeHost = '';
    private $snapshotInterval = 120;
    private $flushInterval = 15;

    public function __construct()
    {
        $this->config = $this->loadConfig();
        $this->snapshotInterval = max(30, (int)($this->config['general']['snapshot_interval'] ?? 120));
        $this->flushInterval = max(5, (int)($this->config['general']['flush_interval'] ?? 15));

        $autoCreate = ($this->config['general']['auto_create_tables'] ?? true) ? true : false;
        $this->db = new ServerModes_Database($this->config['database'], $autoCreate);

        if (!$this->db->isAvailable()) {
            console('serverModes: PDO MySQL extension is not available; plugin disabled.');
            return;
        }

        if (!$this->db->ensureConnection()) {
            console('serverModes: database connection failed; plugin disabled.');
            return;
        }

        $this->modes = array(
            'cruise' => new ServerModes_CruiseMode($this, 'cruise'),
            'race' => new ServerModes_RaceMode($this, 'race'),
            'drift' => new ServerModes_DriftMode($this, 'drift'),
        );

        $this->enabled = true;

        $initialHost = $this->resolveCurrentHost();
        $this->activateModeForHost($initialHost);

        $this->registerPacket('onPrismConnect', ISP_VER);
        $this->registerPacket('onClientConnect', ISP_NCN);
        $this->registerPacket('onClientInfo', ISP_NCI);
        $this->registerPacket('onClientDisconnect', ISP_CNL);
        $this->registerPacket('onPlayerJoinRace', ISP_NPL);
        $this->registerPacket('onPlayerLeaveRace', ISP_PLL);
        $this->registerPacket('onCarInfo', ISP_MCI);
        $this->registerPacket('onLap', ISP_LAP);

        $this->createNamedTimer('serverModes.flush', 'handleFlushTimer', $this->flushInterval, Timer::REPEAT);
    }

    public function __destruct()
    {
        $this->flushAll(true);
    }

    public function onPrismConnect(IS_VER $packet)
    {
        if (!$this->enabled) {
            return;
        }

        $hostName = $this->resolveCurrentHost();
        $this->activateModeForHost($hostName);
        foreach ($this->players as $ucid => &$player) {
            $player['mode'] = $this->activeMode ? $this->activeMode->getKey() : ($player['mode'] ?? 'general');
        }
        unset($player);
        console(sprintf('serverModes: tracking players for host "%s" in %s mode.', $hostName, $this->activeMode->getKey()));
    }

    public function onClientConnect(IS_NCN $NCN)
    {
        if (!$this->enabled || $NCN->UCID == 0) {
            return;
        }

        $player = $this->ensurePlayer($NCN->UCID);
        $player['username'] = $NCN->UName;
        $player['nickname'] = $NCN->PName;
        $player['join_time'] = time();
        $player['last_update'] = microtime(true);
        $player['host'] = $this->activeHost;
        $player['mode'] = $this->activeMode ? $this->activeMode->getKey() : 'general';
        $player['dirty'] = true;
        $this->players[$NCN->UCID] = $player;

        if ($this->activeMode) {
            $playerRef =& $this->players[$NCN->UCID];
            $this->activeMode->onPlayerConnected($playerRef);
        }
    }

    public function onClientInfo(IS_NCI $NCI)
    {
        if (!$this->enabled || $NCI->UCID == 0) {
            return;
        }

        $player = $this->ensurePlayer($NCI->UCID);
        $player['user_id'] = $NCI->UserID;
        $player['dirty'] = true;
        $this->players[$NCI->UCID] = $player;

        $this->bootstrapPlayerFromDatabase($NCI->UCID);
    }

    public function onClientDisconnect(IS_CNL $CNL)
    {
        if (!$this->enabled || $CNL->UCID == 0) {
            return;
        }

        $this->flushPlayer($CNL->UCID, true);
        unset($this->players[$CNL->UCID]);

        foreach ($this->plidMap as $plid => $ucid) {
            if ($ucid == $CNL->UCID) {
                unset($this->plidMap[$plid]);
            }
        }
    }

    public function onPlayerJoinRace(IS_NPL $NPL)
    {
        if (!$this->enabled) {
            return;
        }

        $this->plidMap[$NPL->PLID] = $NPL->UCID;
        if (isset($this->players[$NPL->UCID])) {
            $this->players[$NPL->UCID]['last_compcar'][$NPL->PLID] = null;
        }
    }

    public function onPlayerLeaveRace(IS_PLL $PLL)
    {
        if (!$this->enabled) {
            return;
        }

        if (isset($this->plidMap[$PLL->PLID])) {
            $ucid = $this->plidMap[$PLL->PLID];
            unset($this->plidMap[$PLL->PLID]);
            if (isset($this->players[$ucid]['last_compcar'][$PLL->PLID])) {
                unset($this->players[$ucid]['last_compcar'][$PLL->PLID]);
            }
        }
    }

    public function onCarInfo(IS_MCI $MCI)
    {
        if (!$this->enabled) {
            return;
        }

        $now = microtime(true);
        foreach ($MCI->Info as $compCar) {
            $plid = $compCar->PLID;
            if (!isset($this->plidMap[$plid])) {
                continue;
            }

            $ucid = $this->plidMap[$plid];
            if (!isset($this->players[$ucid])) {
                continue;
            }

            $player =& $this->players[$ucid];
            $previous = $player['last_compcar'][$plid] ?? null;
            $player['last_compcar'][$plid] = array(
                'time' => $now,
                'x' => $compCar->X,
                'y' => $compCar->Y,
                'z' => $compCar->Z,
            );

            if ($previous === null) {
                continue;
            }

            $deltaSeconds = max(0.0, $now - $previous['time']);
            $dx = $compCar->X - $previous['x'];
            $dy = $compCar->Y - $previous['y'];
            $dz = $compCar->Z - $previous['z'];
            $distance = sqrt(($dx * $dx) + ($dy * $dy) + ($dz * $dz)) / 65536.0;

            if ($distance > 0) {
                $player['session_stats']['distance'] += $distance;
                $player['last_activity'] = time();
                if (isset($player['flags']['afk_notified']) && $player['flags']['afk_notified']) {
                    $player['flags']['afk_notified'] = false;
                }
            }

            $player['session_stats']['time'] += $deltaSeconds;

            if ($this->activeMode) {
                $this->activeMode->onCompCar($player, $compCar, $deltaSeconds, $distance);
            }

            $player['dirty'] = true;
        }
    }

    public function onLap(IS_LAP $lap)
    {
        if (!$this->enabled) {
            return;
        }

        $plid = $lap->PLID;
        if (!isset($this->plidMap[$plid])) {
            return;
        }

        $ucid = $this->plidMap[$plid];
        if (!isset($this->players[$ucid])) {
            return;
        }

        $player =& $this->players[$ucid];
        if ($lap->LTime > 0 && (!isset($player['best_lap_ms']) || $player['best_lap_ms'] === null || $lap->LTime < $player['best_lap_ms'])) {
            $player['best_lap_ms'] = $lap->LTime;
            $player['dirty'] = true;
        }

        if ($this->activeMode) {
            $this->activeMode->onLap($player, $lap);
        }
    }

    public function handleFlushTimer()
    {
        if (!$this->enabled) {
            return;
        }

        $this->flushAll(false);
    }

    private function flushAll($force)
    {
        if (!$this->enabled) {
            return;
        }

        $now = time();
        foreach (array_keys($this->players) as $ucid) {
            $this->flushPlayer($ucid, $force, $now);
        }
    }

    private function flushPlayer($ucid, $force = false, $now = null)
    {
        if (!$this->enabled || !isset($this->players[$ucid])) {
            return;
        }

        if ($now === null) {
            $now = time();
        }

        $player =& $this->players[$ucid];

        if ($this->activeMode) {
            $this->activeMode->onPeriodic($player, $now);
        }

        $shouldSnapshot = $force;
        if (!$shouldSnapshot) {
            $lastSnapshot = $player['last_snapshot'] ?? 0;
            if (($now - $lastSnapshot) >= $this->snapshotInterval) {
                $shouldSnapshot = true;
            }
        }

        if (!$force && empty($player['dirty']) && !$shouldSnapshot) {
            return;
        }

        $aggregate = $this->buildAggregate($player);
        if ($aggregate === null) {
            return;
        }

        $this->db->savePlayer($aggregate);

        if ($shouldSnapshot) {
            $snapshot = $this->buildSnapshot($player);
            if ($snapshot !== null) {
                $this->db->recordSnapshot($snapshot);
                $player['snapshot_baseline'] = $player['session_stats'];
                $player['last_snapshot'] = $now;
            }
        }

        $player['dirty'] = false;
    }

    private function buildAggregate(array $player)
    {
        if (($player['username'] ?? '') === '') {
            return null;
        }

        $base = $player['base_totals'] ?? array();
        $session = $player['session_stats'] ?? array();

        return array(
            'user_id' => $player['user_id'] ?? null,
            'username' => $player['username'],
            'nickname' => $player['nickname'] ?? '',
            'mode' => $player['mode'] ?? 'general',
            'host' => $player['host'] ?? $this->activeHost,
            'total_distance' => ($base['distance'] ?? 0) + ($session['distance'] ?? 0),
            'total_time' => (int)(($base['time'] ?? 0) + ($session['time'] ?? 0)),
            'currency' => ($base['currency'] ?? 0) + ($session['currency'] ?? 0),
            'best_lap' => $player['best_lap_ms'] ?? null,
            'drift_score' => ($base['drift'] ?? 0) + ($session['drift'] ?? 0),
            'session_distance' => $session['distance'] ?? 0,
            'session_time' => (int)($session['time'] ?? 0),
            'session_currency' => $session['currency'] ?? 0,
            'session_drift' => $session['drift'] ?? 0,
        );
    }

    private function buildSnapshot(array $player)
    {
        $baseline = $player['snapshot_baseline'] ?? array();
        $session = $player['session_stats'] ?? array();

        $distanceDelta = ($session['distance'] ?? 0) - ($baseline['distance'] ?? 0);
        $timeDelta = ($session['time'] ?? 0) - ($baseline['time'] ?? 0);
        $currencyDelta = ($session['currency'] ?? 0) - ($baseline['currency'] ?? 0);
        $driftDelta = ($session['drift'] ?? 0) - ($baseline['drift'] ?? 0);

        if ($distanceDelta <= 0.01 && $timeDelta <= 1 && $currencyDelta <= 0.01 && $driftDelta <= 0.01) {
            return null;
        }

        return array(
            'user_id' => $player['user_id'] ?? null,
            'username' => $player['username'] ?? '',
            'mode' => $player['mode'] ?? 'general',
            'session_distance' => max(0, $distanceDelta),
            'session_time' => max(0, (int)$timeDelta),
            'session_currency' => max(0, $currencyDelta),
            'session_drift' => max(0, $driftDelta),
        );
    }

    private function ensurePlayer($ucid)
    {
        if (isset($this->players[$ucid])) {
            return $this->players[$ucid];
        }

        return array(
            'ucid' => $ucid,
            'user_id' => null,
            'username' => '',
            'nickname' => '',
            'mode' => $this->activeMode ? $this->activeMode->getKey() : 'general',
            'host' => $this->activeHost,
            'join_time' => time(),
            'last_update' => microtime(true),
            'last_activity' => time(),
            'session_stats' => array(
                'distance' => 0.0,
                'time' => 0.0,
                'currency' => 0.0,
                'drift' => 0.0,
            ),
            'snapshot_baseline' => array(
                'distance' => 0.0,
                'time' => 0.0,
                'currency' => 0.0,
                'drift' => 0.0,
            ),
            'base_totals' => array(
                'distance' => 0.0,
                'time' => 0.0,
                'currency' => 0.0,
                'drift' => 0.0,
            ),
            'best_lap_ms' => null,
            'last_compcar' => array(),
            'last_snapshot' => 0,
            'dirty' => false,
            'flags' => array(),
        );
    }

    private function bootstrapPlayerFromDatabase($ucid)
    {
        if (!isset($this->players[$ucid])) {
            return;
        }

        $player =& $this->players[$ucid];
        $userId = $player['user_id'] ?? null;
        $username = $player['username'] ?? '';

        if (!$userId && $username === '') {
            return;
        }

        $record = $this->db->fetchPlayer($userId, $username);
        if (!$record) {
            return;
        }

        $player['base_totals'] = array(
            'distance' => (float)($record['total_distance'] ?? 0),
            'time' => (float)($record['total_time'] ?? 0),
            'currency' => (float)($record['currency'] ?? 0),
            'drift' => (float)($record['drift_score'] ?? 0),
        );

        if (isset($record['best_lap']) && $record['best_lap'] !== null) {
            $player['best_lap_ms'] = (int)$record['best_lap'];
        }

        $player['mode'] = $record['mode'] ?? ($player['mode'] ?? 'general');
        $player['dirty'] = true;
    }

    private function loadConfig()
    {
        $defaults = array(
            'database' => array(
                'host' => '127.0.0.1',
                'port' => 3306,
                'database' => 'lfs',
                'username' => 'lfs',
                'password' => 'secret',
                'charset' => 'utf8mb4',
            ),
            'general' => array(
                'snapshot_interval' => 120,
                'flush_interval' => 15,
                'auto_create_tables' => true,
            ),
            'modes' => array(
                '*' => 'cruise',
            ),
            'mode:cruise' => array(
                'credits_per_km' => 6.5,
                'idle_timeout' => 180,
            ),
            'mode:drift' => array(
                'minimum_angle' => 10,
            ),
            'mode:race' => array(),
        );

        $configPath = ROOTPATH . 'configs/serverModes.ini';
        if (!file_exists($configPath)) {
            return $defaults;
        }

        $parsed = parse_ini_file($configPath, true, INI_SCANNER_TYPED);
        if ($parsed === false) {
            console('serverModes: failed to parse serverModes.ini, using defaults.');
            return $defaults;
        }

        foreach ($parsed as $section => $values) {
            if (!is_array($values)) {
                continue;
            }
            if (isset($defaults[$section]) && is_array($defaults[$section])) {
                $defaults[$section] = array_merge($defaults[$section], $values);
            } else {
                $defaults[$section] = $values;
            }
        }

        return $defaults;
    }

    private function resolveCurrentHost()
    {
        global $PRISM;
        if (isset($PRISM) && isset($PRISM->hosts)) {
            $host = $PRISM->hosts->getCurrentHost();
            if ($host !== null) {
                return strtolower($host);
            }
        }

        return '';
    }

    private function activateModeForHost($hostName)
    {
        $this->activeHost = $hostName;
        $modeKey = $this->determineMode($hostName);

        if (!isset($this->modes[$modeKey])) {
            $modeKey = 'cruise';
        }

        $this->activeMode = $this->modes[$modeKey];
        $configKey = 'mode:' . $modeKey;
        $modeConfig = $this->config[$configKey] ?? array();
        $this->activeMode->activate($modeConfig, $hostName);
    }

    private function determineMode($hostName)
    {
        $mapping = $this->config['modes'] ?? array('*' => 'cruise');
        $hostName = (string)$hostName;
        $default = $mapping['*'] ?? 'cruise';

        foreach ($mapping as $pattern => $mode) {
            if ($pattern === '*') {
                continue;
            }

            if (function_exists('fnmatch')) {
                if (fnmatch($pattern, $hostName, FNM_CASEFOLD)) {
                    return strtolower($mode);
                }
            } else {
                if (strcasecmp($pattern, $hostName) === 0) {
                    return strtolower($mode);
                }
            }
        }

        return strtolower($default);
    }
}
