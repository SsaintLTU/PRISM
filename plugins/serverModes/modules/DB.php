<?php
class ServerModes_Database
{
    private array $config;
    private ?PDO $pdo = null;
    private bool $available;
    /** @var array<string, PDOStatement> */
    private array $statementCache = array();

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
        $this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

        $this->statementCache = array();

        $this->ensureSchema();

        return true;
    }

    public function loadPlayer(int $userId): ?array
    {
        if (!$this->ensureConnection()) {
            return null;
        }

        $stmt = $this->prepareStatement('SELECT user_id, username, nickname, total_distance, total_earnings, total_xp, lap_count, last_mode FROM prism_players WHERE user_id = :id');
        if (!$stmt) {
            return null;
        }

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

        $stmt = $this->prepareStatement($sql);
        if (!$stmt) {
            return;
        }

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

    public function loadPlayerState(int $userId): array
    {
        if (!$this->ensureConnection()) {
            return array();
        }

        $stmt = $this->prepareStatement('SELECT state_json FROM prism_player_state WHERE user_id = :id');
        if (!$stmt) {
            return array();
        }

        $stmt->execute(array(':id' => $userId));
        $row = $stmt->fetch();

        if (!$row || !isset($row['state_json'])) {
            return array();
        }

        $decoded = json_decode($row['state_json'], true);
        return is_array($decoded) ? $decoded : array();
    }

    public function savePlayerState(int $userId, array $state): void
    {
        if (!$this->ensureConnection()) {
            return;
        }

        $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $stmt = $this->prepareStatement('INSERT INTO prism_player_state (user_id, state_json, updated_at)
                VALUES (:id, :json, NOW())
                ON DUPLICATE KEY UPDATE state_json = VALUES(state_json), updated_at = VALUES(updated_at)');
        if (!$stmt) {
            return;
        }

        $stmt->execute(array(':id' => $userId, ':json' => $json));
    }

    public function recordSnapshot(array $payload): void
    {
        if (!$this->ensureConnection()) {
            return;
        }

        $sql = 'INSERT INTO prism_player_snapshots (user_id, mode, distance, earnings, xp, lap_count, created_at)
                VALUES (:user_id, :mode, :distance, :earnings, :xp, :lap_count, NOW())';

        $stmt = $this->prepareStatement($sql);
        if (!$stmt) {
            return;
        }

        $stmt->execute(array(
            ':user_id' => $payload['user_id'],
            ':mode' => $payload['mode'] ?? '',
            ':distance' => $payload['distance'] ?? 0.0,
            ':earnings' => $payload['earnings'] ?? 0.0,
            ':xp' => $payload['xp'] ?? 0.0,
            ':lap_count' => $payload['lap_count'] ?? 0,
        ));
    }

    public function loadFriends(int $userId): array
    {
        if (!$this->ensureConnection()) {
            return array();
        }

        $stmt = $this->prepareStatement('SELECT friend_id, friend_name, created_at FROM prism_player_friends WHERE owner_id = :id ORDER BY friend_name');
        if (!$stmt) {
            return array();
        }

        $stmt->execute(array(':id' => $userId));

        return $stmt->fetchAll() ?: array();
    }

    public function fetchVehicleMods(): array
    {
        if (!$this->ensureConnection()) {
            return array();
        }

        $stmt = $this->prepareStatement('SELECT id, short_name, display_name, car_code, hex_code, category, author, power_kw, weight_kg, price, raw_json, updated_at FROM prism_vehicle_mods');
        if (!$stmt) {
            return array();
        }

        $stmt->execute();
        $rows = $stmt->fetchAll();

        return is_array($rows) ? $rows : array();
    }

    public function replaceVehicleMods(array $mods): void
    {
        if (!$this->ensureConnection()) {
            return;
        }

        $existingPrices = $this->getExistingVehicleModPrices();

        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec('DELETE FROM prism_vehicle_mods');

            $sql = 'INSERT INTO prism_vehicle_mods (id, short_name, display_name, car_code, hex_code, category, author, power_kw, weight_kg, price, raw_json, updated_at)
                VALUES (:id, :short_name, :display_name, :car_code, :hex_code, :category, :author, :power_kw, :weight_kg, :price, :raw_json, NOW())';
            $stmt = $this->prepareStatement($sql);
            if (!$stmt) {
                $this->pdo->rollBack();
                return;
            }

            foreach ($mods as $mod) {
                $id = strtoupper($mod['id']);
                $stmt->execute(array(
                    ':id' => $mod['id'],
                    ':short_name' => $mod['short_name'],
                    ':display_name' => $mod['display_name'],
                    ':car_code' => $mod['car_code'],
                    ':hex_code' => $mod['hex_code'],
                    ':category' => $mod['category'],
                    ':author' => $mod['author'],
                    ':power_kw' => $mod['power_kw'],
                    ':weight_kg' => $mod['weight_kg'],
                    ':price' => $existingPrices[$id] ?? 0.0,
                    ':raw_json' => $mod['raw_json'],
                ));
            }

            $this->pdo->commit();
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            console('serverModes: failed to persist vehicle mod cache - ' . $e->getMessage());
        }
    }

    private function getExistingVehicleModPrices(): array
    {
        $prices = array();

        if (!$this->ensureConnection()) {
            return $prices;
        }

        $stmt = $this->prepareStatement('SELECT id, price FROM prism_vehicle_mods');
        if (!$stmt) {
            return $prices;
        }

        $stmt->execute();
        $rows = $stmt->fetchAll();
        if (!is_array($rows)) {
            return $prices;
        }

        foreach ($rows as $row) {
            $id = strtoupper(trim($row['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $prices[$id] = (float)($row['price'] ?? 0.0);
        }

        return $prices;
    }

    public function getLatestVehicleModTimestamp(): ?int
    {
        if (!$this->ensureConnection()) {
            return null;
        }

        $stmt = $this->prepareStatement('SELECT UNIX_TIMESTAMP(MAX(updated_at)) AS ts FROM prism_vehicle_mods');
        if (!$stmt) {
            return null;
        }

        $stmt->execute();
        $row = $stmt->fetch();

        if (!$row || empty($row['ts'])) {
            return null;
        }

        return (int)$row['ts'];
    }

    public function addFriend(int $ownerId, int $friendId, string $friendName): bool
    {
        if (!$this->ensureConnection()) {
            return false;
        }

        $sql = 'INSERT INTO prism_player_friends (owner_id, friend_id, friend_name, created_at)
                VALUES (:owner, :friend, :name, NOW())
                ON DUPLICATE KEY UPDATE friend_name = VALUES(friend_name)';

        $stmt = $this->prepareStatement($sql);
        if (!$stmt) {
            return false;
        }

        return $stmt->execute(array(
            ':owner' => $ownerId,
            ':friend' => $friendId,
            ':name' => $friendName,
        ));
    }

    public function removeFriend(int $ownerId, int $friendId): void
    {
        if (!$this->ensureConnection()) {
            return;
        }

        $stmt = $this->prepareStatement('DELETE FROM prism_player_friends WHERE owner_id = :owner AND friend_id = :friend');
        if (!$stmt) {
            return;
        }

        $stmt->execute(array(':owner' => $ownerId, ':friend' => $friendId));
    }

    public function recordDriftScore(array $payload): void
    {
        if (!$this->ensureConnection()) {
            return;
        }

        $sql = 'INSERT INTO prism_drift_scores (user_id, username, nickname, layout, host_id, points, max_angle, created_at)
                VALUES (:user_id, :username, :nickname, :layout, :host_id, :points, :max_angle, NOW())';

        $stmt = $this->prepareStatement($sql);
        if (!$stmt) {
            return;
        }

        $stmt->execute(array(
            ':user_id' => $payload['user_id'],
            ':username' => $payload['username'] ?? '',
            ':nickname' => $payload['nickname'] ?? '',
            ':layout' => $payload['layout'] ?? '',
            ':host_id' => $payload['host_id'] ?? '',
            ':points' => $payload['points'] ?? 0.0,
            ':max_angle' => $payload['max_angle'] ?? 0.0,
        ));
    }

    public function recordRaceResult(array $payload): void
    {
        if (!$this->ensureConnection()) {
            return;
        }

        $sql = 'INSERT INTO prism_race_results (user_id, username, nickname, track, car, class, position, points, race_time, created_at)
                VALUES (:user_id, :username, :nickname, :track, :car, :class, :position, :points, :race_time, NOW())';

        $stmt = $this->prepareStatement($sql);
        if (!$stmt) {
            return;
        }

        $stmt->execute(array(
            ':user_id' => $payload['user_id'],
            ':username' => $payload['username'] ?? '',
            ':nickname' => $payload['nickname'] ?? '',
            ':track' => $payload['track'] ?? '',
            ':car' => $payload['car'] ?? '',
            ':class' => $payload['class'] ?? '',
            ':position' => (int)($payload['position'] ?? 0),
            ':points' => (int)($payload['points'] ?? 0),
            ':race_time' => (float)($payload['time'] ?? 0.0),
        ));
    }

    public function fetchTopDriftScores(string $layout, string $period, int $limit = 5): array
    {
        if (!$this->ensureConnection()) {
            return array();
        }

        $period = strtolower($period);
        switch ($period) {
            case 'day':
                $interval = '1 DAY';
                break;
            case 'week':
                $interval = '7 DAY';
                break;
            case 'month':
                $interval = '1 MONTH';
                break;
            case 'year':
                $interval = '1 YEAR';
                break;
            default:
                $interval = '1 DAY';
                break;
        }

        $sql = 'SELECT user_id, username, nickname, layout, points, max_angle, created_at
                FROM prism_drift_scores
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL ' . $interval . ')';

        $params = array();
        if ($layout !== '' && $layout !== '*') {
            $sql .= ' AND layout = :layout';
            $params[':layout'] = $layout;
        }

        $sql .= ' ORDER BY points DESC, created_at ASC LIMIT :limit';

        $stmt = $this->prepareStatement($sql);
        if (!$stmt) {
            return array();
        }

        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll() ?: array();
    }

    public function fetchDailyDistanceLeaders(int $limit = 5): array
    {
        if (!$this->ensureConnection()) {
            return array();
        }

        $sql = 'SELECT s.user_id, p.username, p.nickname, SUM(s.distance) AS distance
                FROM prism_player_snapshots s
                INNER JOIN prism_players p ON p.user_id = s.user_id
                WHERE s.created_at >= CURDATE()
                GROUP BY s.user_id, p.username, p.nickname
                HAVING distance > 0
                ORDER BY distance DESC
                LIMIT :limit';

        $stmt = $this->prepareStatement($sql);
        if (!$stmt) {
            return array();
        }

        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll() ?: array();
    }

    public function fetchKnownDriftLayouts(): array
    {
        if (!$this->ensureConnection()) {
            return array();
        }

        $stmt = $this->prepareStatement('SELECT DISTINCT layout FROM prism_drift_scores WHERE layout <> "" ORDER BY layout ASC');
        if (!$stmt) {
            return array();
        }

        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);

        return is_array($rows) ? $rows : array();
    }

    private function prepareStatement(string $sql): ?PDOStatement
    {
        if (!$this->ensureConnection()) {
            return null;
        }

        $cacheKey = md5($sql);

        if (!isset($this->statementCache[$cacheKey])) {
            try {
                $this->statementCache[$cacheKey] = $this->pdo->prepare($sql);
            } catch (PDOException $e) {
                console('serverModes: failed to prepare statement - ' . $e->getMessage());
                return null;
            }
        }

        return $this->statementCache[$cacheKey];
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

            $this->pdo->exec('CREATE TABLE IF NOT EXISTS prism_player_state (
            user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            state_json LONGTEXT NOT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_state_player FOREIGN KEY (user_id) REFERENCES prism_players(user_id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

            $this->pdo->exec('CREATE TABLE IF NOT EXISTS prism_player_friends (
            owner_id BIGINT UNSIGNED NOT NULL,
            friend_id BIGINT UNSIGNED NOT NULL,
            friend_name VARCHAR(32) NOT NULL DEFAULT "",
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (owner_id, friend_id),
            INDEX idx_friend_id (friend_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

            $this->pdo->exec('CREATE TABLE IF NOT EXISTS prism_drift_scores (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            username VARCHAR(32) NOT NULL DEFAULT "",
            nickname VARCHAR(32) NOT NULL DEFAULT "",
            layout VARCHAR(8) NOT NULL DEFAULT "",
            host_id VARCHAR(32) NOT NULL DEFAULT "",
            points DOUBLE NOT NULL DEFAULT 0,
            max_angle DOUBLE NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_layout_created (layout, created_at),
            INDEX idx_user_layout (user_id, layout)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

            $this->pdo->exec('CREATE TABLE IF NOT EXISTS prism_race_results (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            username VARCHAR(32) NOT NULL DEFAULT "",
            nickname VARCHAR(32) NOT NULL DEFAULT "",
            track VARCHAR(8) NOT NULL DEFAULT "",
            car VARCHAR(8) NOT NULL DEFAULT "",
            class VARCHAR(16) NOT NULL DEFAULT "",
            position TINYINT UNSIGNED NOT NULL DEFAULT 0,
            points INT UNSIGNED NOT NULL DEFAULT 0,
            race_time DOUBLE NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_track (user_id, track),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

            $this->pdo->exec('CREATE TABLE IF NOT EXISTS prism_vehicle_mods (
            id VARCHAR(32) NOT NULL PRIMARY KEY,
            short_name VARCHAR(32) NOT NULL DEFAULT "",
            display_name VARCHAR(128) NOT NULL DEFAULT "",
            car_code VARCHAR(32) NOT NULL DEFAULT "",
            hex_code VARCHAR(64) NOT NULL DEFAULT "",
            category VARCHAR(64) NOT NULL DEFAULT "",
            author VARCHAR(64) NOT NULL DEFAULT "",
            power_kw DOUBLE NOT NULL DEFAULT 0,
            weight_kg DOUBLE NOT NULL DEFAULT 0,
            price DOUBLE NOT NULL DEFAULT 0,
            raw_json LONGTEXT NOT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_car_code (car_code),
            INDEX idx_hex_code (hex_code)
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
            PDO::ATTR_EMULATE_PREPARES => false,
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
