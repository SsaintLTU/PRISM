<?php
class ServerModes_Database
{
    private $config;
    private $pdo;
    private $connected = false;
    private $autoCreate;

    public function __construct(array $config, $autoCreate = true)
    {
        $this->config = $config;
        $this->autoCreate = $autoCreate;
    }

    public function isAvailable()
    {
        return extension_loaded('pdo_mysql');
    }

    public function ensureConnection()
    {
        if ($this->connected) {
            return true;
        }

        if (!$this->isAvailable()) {
            return false;
        }

        $host = $this->config['host'] ?? '127.0.0.1';
        $port = (int)($this->config['port'] ?? 3306);
        $dbname = $this->config['database'] ?? '';
        $charset = $this->config['charset'] ?? 'utf8mb4';

        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $dbname, $charset);

        try {
            $this->pdo = new PDO(
                $dsn,
                $this->config['username'] ?? '',
                $this->config['password'] ?? '',
                array(
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                )
            );
        } catch (PDOException $e) {
            console('serverModes: failed to connect to database - ' . $e->getMessage());
            return false;
        }

        $this->connected = true;

        if ($this->autoCreate) {
            $this->initialiseSchema();
        }

        return true;
    }

    private function initialiseSchema()
    {
        try {
            $this->pdo->exec('CREATE TABLE IF NOT EXISTS server_modes_players (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id BIGINT UNSIGNED NULL,
                username VARCHAR(24) NOT NULL,
                nickname VARCHAR(24) NOT NULL,
                mode VARCHAR(32) NOT NULL,
                last_host VARCHAR(64) NOT NULL,
                total_distance DOUBLE NOT NULL DEFAULT 0,
                total_time INT UNSIGNED NOT NULL DEFAULT 0,
                currency DOUBLE NOT NULL DEFAULT 0,
                best_lap INT UNSIGNED NULL,
                drift_score DOUBLE NOT NULL DEFAULT 0,
                updated_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL,
                UNIQUE KEY uniq_user (user_id),
                UNIQUE KEY uniq_username (username)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

            $this->pdo->exec('CREATE TABLE IF NOT EXISTS server_modes_snapshots (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id BIGINT UNSIGNED NULL,
                username VARCHAR(24) NOT NULL,
                mode VARCHAR(32) NOT NULL,
                distance DOUBLE NOT NULL DEFAULT 0,
                session_time INT UNSIGNED NOT NULL DEFAULT 0,
                currency DOUBLE NOT NULL DEFAULT 0,
                drift_score DOUBLE NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        } catch (PDOException $e) {
            console('serverModes: failed to initialise schema - ' . $e->getMessage());
        }
    }

    public function fetchPlayer($userId, $username)
    {
        if (!$this->ensureConnection()) {
            return null;
        }

        try {
            if ($userId) {
                $stmt = $this->pdo->prepare('SELECT * FROM server_modes_players WHERE user_id = :user_id');
                $stmt->execute(array(':user_id' => $userId));
                $row = $stmt->fetch();
                if ($row) {
                    return $row;
                }
            }

            if ($username !== '') {
                $stmt = $this->pdo->prepare('SELECT * FROM server_modes_players WHERE username = :username');
                $stmt->execute(array(':username' => $username));
                return $stmt->fetch();
            }
        } catch (PDOException $e) {
            console('serverModes: failed to fetch player - ' . $e->getMessage());
        }

        return null;
    }

    public function savePlayer(array $data)
    {
        if (!$this->ensureConnection()) {
            return false;
        }

        $now = date('Y-m-d H:i:s');
        $params = array(
            ':user_id' => $data['user_id'] ?? null,
            ':username' => $data['username'] ?? '',
            ':nickname' => $data['nickname'] ?? '',
            ':mode' => $data['mode'] ?? 'unknown',
            ':last_host' => $data['host'] ?? '',
            ':total_distance' => $data['total_distance'] ?? 0,
            ':total_time' => (int)($data['total_time'] ?? 0),
            ':currency' => $data['currency'] ?? 0,
            ':best_lap' => isset($data['best_lap']) ? (int)$data['best_lap'] : null,
            ':drift_score' => $data['drift_score'] ?? 0,
            ':updated_at' => $now,
            ':created_at' => $now,
        );

        try {
            $sql = 'INSERT INTO server_modes_players
                (user_id, username, nickname, mode, last_host, total_distance, total_time, currency, best_lap, drift_score, updated_at, created_at)
                VALUES (:user_id, :username, :nickname, :mode, :last_host, :total_distance, :total_time, :currency, :best_lap, :drift_score, :updated_at, :created_at)
                ON DUPLICATE KEY UPDATE
                    nickname = VALUES(nickname),
                    mode = VALUES(mode),
                    last_host = VALUES(last_host),
                    total_distance = VALUES(total_distance),
                    total_time = VALUES(total_time),
                    currency = VALUES(currency),
                    best_lap = VALUES(best_lap),
                    drift_score = VALUES(drift_score),
                    updated_at = VALUES(updated_at)';

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return true;
        } catch (PDOException $e) {
            console('serverModes: failed to save player - ' . $e->getMessage());
        }

        return false;
    }

    public function recordSnapshot(array $data)
    {
        if (!$this->ensureConnection()) {
            return false;
        }

        $params = array(
            ':user_id' => $data['user_id'] ?? null,
            ':username' => $data['username'] ?? '',
            ':mode' => $data['mode'] ?? 'unknown',
            ':distance' => $data['session_distance'] ?? 0,
            ':session_time' => (int)($data['session_time'] ?? 0),
            ':currency' => $data['session_currency'] ?? 0,
            ':drift_score' => $data['session_drift'] ?? 0,
            ':created_at' => date('Y-m-d H:i:s'),
        );

        try {
            $sql = 'INSERT INTO server_modes_snapshots (user_id, username, mode, distance, session_time, currency, drift_score, created_at)
                    VALUES (:user_id, :username, :mode, :distance, :session_time, :currency, :drift_score, :created_at)';
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return true;
        } catch (PDOException $e) {
            console('serverModes: failed to record snapshot - ' . $e->getMessage());
        }

        return false;
    }
}
