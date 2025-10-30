<?php
class ServerModes_Database
{
    private array $config;
    private ?PDO $pdo = null;
    private bool $available;

    public function __construct(array $config = array())
    {
        $this->config = $config;
        $this->available = class_exists('PDO');
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function ensureConnection(): bool
    {
        if (!$this->available) {
            return false;
        }

        if ($this->pdo instanceof PDO) {
            return true;
        }

        $dsn = $this->config['dsn'] ?? ($this->config['DSN'] ?? null);
        if (!$dsn) {
            console('serverModes: database DSN is not configured.');
            return false;
        }

        $username = $this->config['username'] ?? $this->config['user'] ?? '';
        $password = $this->config['password'] ?? '';
        $options = $this->buildOptions($this->config['options'] ?? array());

        try {
            $this->pdo = new PDO($dsn, $username, $password, $options);
        } catch (PDOException $e) {
            console('serverModes: PDO connection failed - ' . $e->getMessage());
            return false;
        }

        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $this->ensureSchema();

        return true;
    }

    public function loadPlayer(int $userId): ?array
    {
        if (!$this->ensureConnection()) {
            return null;
        }

        $stmt = $this->pdo->prepare('SELECT user_id, username, nickname, total_distance, total_earnings, total_xp, lap_count, last_mode FROM prism_players WHERE user_id = :id');
        $stmt->execute(array(':id' => $userId));
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function savePlayer(array $payload): void
    {
        if (!$this->ensureConnection()) {
            return;
        }

        $sql = 'INSERT INTO prism_players (user_id, username, nickname, total_distance, total_earnings, total_xp, lap_count, last_mode, last_seen)
                VALUES (:user_id, :username, :nickname, :distance, :earnings, :xp, :lap_count, :mode, FROM_UNIXTIME(:last_seen))
                ON DUPLICATE KEY UPDATE
                    username = VALUES(username),
                    nickname = VALUES(nickname),
                    total_distance = VALUES(total_distance),
                    total_earnings = VALUES(total_earnings),
                    total_xp = VALUES(total_xp),
                    lap_count = VALUES(lap_count),
                    last_mode = VALUES(last_mode),
                    last_seen = VALUES(last_seen)';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array(
            ':user_id' => $payload['user_id'],
            ':username' => $payload['username'] ?? '',
            ':nickname' => $payload['nickname'] ?? '',
            ':distance' => $payload['distance'] ?? 0.0,
            ':earnings' => $payload['earnings'] ?? 0.0,
            ':xp' => $payload['xp'] ?? 0.0,
            ':lap_count' => $payload['lap_count'] ?? 0,
            ':mode' => $payload['mode'] ?? '',
            ':last_seen' => $payload['last_seen'] ?? time(),
        ));
    }

    public function recordSnapshot(array $payload): void
    {
        if (!$this->ensureConnection()) {
            return;
        }

        $sql = 'INSERT INTO prism_player_snapshots (user_id, mode, distance, earnings, xp, lap_count, created_at)
                VALUES (:user_id, :mode, :distance, :earnings, :xp, :lap_count, NOW())';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array(
            ':user_id' => $payload['user_id'],
            ':mode' => $payload['mode'] ?? '',
            ':distance' => $payload['distance'] ?? 0.0,
            ':earnings' => $payload['earnings'] ?? 0.0,
            ':xp' => $payload['xp'] ?? 0.0,
            ':lap_count' => $payload['lap_count'] ?? 0,
        ));
    }

    private function ensureSchema(): void
    {
        try {
            $this->pdo->exec('CREATE TABLE IF NOT EXISTS prism_players (
            user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            username VARCHAR(32) NOT NULL DEFAULT "",
            nickname VARCHAR(32) NOT NULL DEFAULT "",
            total_distance DOUBLE NOT NULL DEFAULT 0,
            total_earnings DOUBLE NOT NULL DEFAULT 0,
            total_xp DOUBLE NOT NULL DEFAULT 0,
            lap_count INT UNSIGNED NOT NULL DEFAULT 0,
            last_mode VARCHAR(16) NOT NULL DEFAULT "",
            last_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

            $this->pdo->exec('CREATE TABLE IF NOT EXISTS prism_player_snapshots (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            mode VARCHAR(16) NOT NULL,
            distance DOUBLE NOT NULL DEFAULT 0,
            earnings DOUBLE NOT NULL DEFAULT 0,
            xp DOUBLE NOT NULL DEFAULT 0,
            lap_count INT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_id (user_id),
            CONSTRAINT fk_snapshots_player FOREIGN KEY (user_id) REFERENCES prism_players(user_id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        } catch (PDOException $e) {
            console('serverModes: failed to ensure schema - ' . $e->getMessage());
        }
    }

    private function buildOptions($raw): array
    {
        $options = array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        );

        if (is_string($raw)) {
            $raw = array($raw);
        }

        if (!is_array($raw)) {
            return $options;
        }

        foreach ($raw as $key => $value) {
            if (is_int($key)) {
                $entry = $value;
                if (!is_string($entry) || strpos($entry, '=') === false) {
                    continue;
                }
                list($optKey, $optValue) = array_map('trim', explode('=', $entry, 2));
            } else {
                $optKey = trim((string)$key);
                $optValue = $value;
            }

            $optionId = $this->parseConstant($optKey);
            if ($optionId === null) {
                continue;
            }

            if (is_string($optValue)) {
                $optValue = trim($optValue);
                $constantValue = $this->parseConstant($optValue);
                if ($constantValue !== null) {
                    $optValue = $constantValue;
                } elseif (is_numeric($optValue)) {
                    $optValue = $optValue + 0;
                } elseif (strcasecmp($optValue, 'true') === 0) {
                    $optValue = true;
                } elseif (strcasecmp($optValue, 'false') === 0) {
                    $optValue = false;
                }
            }

            $options[$optionId] = $optValue;
        }

        return $options;
    }

    private function parseConstant(string $value)
    {
        if (defined($value)) {
            return constant($value);
        }

        if (is_numeric($value)) {
            return $value + 0;
        }

        return null;
    }
}
