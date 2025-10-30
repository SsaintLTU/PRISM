<?php
class ServerModes_VehicleMods
{
    private serverModes $plugin;
    private ServerModes_Database $database;
    private array $config;

    private array $modsByCode = array();
    private array $modsByHex = array();
    private array $modsById = array();

    private int $lastUpdated = 0;
    private int $nextRefresh = 0;

    public function __construct(serverModes $plugin, ServerModes_Database $database, array $config = array())
    {
        $this->plugin = $plugin;
        $this->database = $database;
        $this->config = $config;
    }

    public function bootstrap(): void
    {
        $this->reloadFromDatabase();

        if (empty($this->modsByCode)) {
            if ($this->refreshFromApi()) {
                $this->reloadFromDatabase();
            }
        }

        $ttl = $this->getCacheTtl();
        if ($ttl > 0) {
            if ($this->lastUpdated > 0 && (time() - $this->lastUpdated) >= $ttl) {
                if ($this->refreshFromApi()) {
                    $this->reloadFromDatabase();
                }
            }
            $this->nextRefresh = time() + $ttl;
        } else {
            $this->nextRefresh = PHP_INT_MAX;
        }
    }

    public function tick(): void
    {
        if ($this->getCacheTtl() <= 0) {
            return;
        }

        if ($this->nextRefresh > 0 && time() >= $this->nextRefresh) {
            if ($this->refreshFromApi()) {
                $this->reloadFromDatabase();
            }
            $this->nextRefresh = time() + $this->getCacheTtl();
        }
    }

    public function handleVehicleActivation(array &$player, string $carCode, bool $isNew): void
    {
        $carCode = trim($carCode);
        if ($carCode === '') {
            return;
        }

        $mod = $this->getMod($carCode);
        $ucid = $player['ucid'] ?? 0;

        $wasMissing = !isset($player['state']['garage']['vehicles'][$carCode]);
        if ($wasMissing) {
            $player['state']['garage']['vehicles'][$carCode] = array(
                'plate' => '---:---',
                'distance' => 0.0,
                'insurance_until' => 0,
                'mod' => array(),
                'acquired_at' => 0,
                'discord_announced' => false,
            );
        }

        $vehicle =& $player['state']['garage']['vehicles'][$carCode];
        $didUpdate = $wasMissing;

        if ($mod !== null) {
            $newMeta = array(
                'id' => $mod['id'],
                'name' => $mod['display_name'],
                'short_name' => $mod['short_name'],
                'category' => $mod['category'],
                'author' => $mod['author'],
                'power_kw' => (float)$mod['power_kw'],
                'weight_kg' => (float)$mod['weight_kg'],
                'price' => (float)$mod['price'],
                'hex_code' => $mod['hex_code'],
            );
            if ($vehicle['mod'] ?? null !== $newMeta) {
                $vehicle['mod'] = $newMeta;
                $didUpdate = true;
            }

            if (empty($vehicle['acquired_at'])) {
                $vehicle['acquired_at'] = time();
                $didUpdate = true;
            }

            if ($isNew && empty($vehicle['discord_announced'])) {
                $this->announcePurchase($player, $mod);
                $vehicle['discord_announced'] = true;
                $didUpdate = true;
            }
        }

        if ($didUpdate && $ucid > 0) {
            $player['state_dirty'] = true;
        }
    }

    public function getMod(string $carCode): ?array
    {
        $carCode = trim($carCode);
        if ($carCode === '') {
            return null;
        }

        $normalized = $this->normaliseCarCode($carCode);
        if ($normalized !== '' && isset($this->modsByCode[$normalized])) {
            return $this->modsByCode[$normalized];
        }

        if ($normalized !== '') {
            $hex = strtoupper(bin2hex($normalized));
            if (isset($this->modsByHex[$hex])) {
                return $this->modsByHex[$hex];
            }
        }

        $upper = strtoupper($carCode);
        if (isset($this->modsById[$upper])) {
            return $this->modsById[$upper];
        }

        if (preg_match('/^[0-9A-F]{4,}$/', $upper)) {
            if (isset($this->modsByHex[$upper])) {
                return $this->modsByHex[$upper];
            }
            $decoded = @hex2bin($upper);
            if ($decoded !== false) {
                $decoded = strtoupper(trim($decoded));
                if ($decoded !== '' && isset($this->modsByCode[$decoded])) {
                    return $this->modsByCode[$decoded];
                }
            }
        }

        return null;
    }

    private function reloadFromDatabase(): void
    {
        $rows = $this->database->fetchVehicleMods();
        $this->modsByCode = array();
        $this->modsByHex = array();
        $this->modsById = array();

        foreach ($rows as $row) {
            $code = strtoupper(trim($row['car_code'] ?? ''));
            $hex = strtoupper(trim($row['hex_code'] ?? ''));
            $id = strtoupper(trim($row['id'] ?? ''));

            if ($code !== '') {
                $this->modsByCode[$code] = $row;
            }
            if ($hex !== '') {
                $this->modsByHex[$hex] = $row;
            }
            if ($id !== '') {
                $this->modsById[$id] = $row;
            }
        }

        $timestamp = $this->database->getLatestVehicleModTimestamp();
        $this->lastUpdated = $timestamp ?? time();
    }

    private function refreshFromApi(): bool
    {
        $token = $this->requestAccessToken();
        if ($token === null) {
            return false;
        }

        $mods = $this->requestMods($token);
        if (empty($mods)) {
            console('serverModes: no vehicle mods returned from LFS API.');
            return false;
        }

        $normalized = array();
        foreach ($mods as $entry) {
            $record = $this->normaliseEntry($entry);
            if ($record === null) {
                continue;
            }
            $normalized[$record['id']] = $record;
        }

        if (empty($normalized)) {
            console('serverModes: unable to normalise vehicle mod data.');
            return false;
        }

        $this->database->replaceVehicleMods(array_values($normalized));
        console(sprintf('serverModes: cached %d vehicle mods from LFS.', count($normalized)));

        return true;
    }

    private function requestAccessToken(): ?string
    {
        $clientId = trim($this->config['client_id'] ?? '');
        $clientSecret = trim($this->config['client_secret'] ?? '');

        if ($clientId === '' || $clientSecret === '') {
            console('serverModes: LFS API credentials not configured; skipping vehicle mod sync.');
            return null;
        }

        $context = stream_context_create(array(
            'http' => array(
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                'timeout' => 10,
                'content' => http_build_query(array(
                    'grant_type' => 'client_credentials',
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                )),
            ),
        ));

        $response = @file_get_contents('https://id.lfs.net/oauth2/access_token', false, $context);
        if ($response === false) {
            console('serverModes: failed to request LFS access token.');
            return null;
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded) || empty($decoded['access_token'])) {
            console('serverModes: invalid token response from LFS.');
            return null;
        }

        return (string)$decoded['access_token'];
    }

    private function requestMods(string $token): array
    {
        $context = stream_context_create(array(
            'http' => array(
                'method' => 'GET',
                'header' => "Authorization: Bearer {$token}\r\n",
                'timeout' => 10,
            ),
        ));

        $response = @file_get_contents('https://api.lfs.net/vehiclemod', false, $context);
        if ($response === false) {
            console('serverModes: failed to download vehicle mod list.');
            return array();
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            console('serverModes: invalid vehicle mod response payload.');
            return array();
        }

        if (isset($decoded['data']) && is_array($decoded['data'])) {
            return $decoded['data'];
        }

        return $decoded;
    }

    private function normaliseEntry(array $entry): ?array
    {
        $id = trim((string)($entry['id'] ?? $entry['uuid'] ?? $entry['carid'] ?? ''));
        $short = $this->normaliseCarCode((string)($entry['short_name'] ?? $entry['code'] ?? $entry['car'] ?? $id));

        if ($id === '') {
            $id = $short !== '' ? $short : strtoupper(bin2hex((string)($entry['name'] ?? uniqid('mod_', true))));
        }

        if ($short === '' && preg_match('/^[0-9A-F]{4,}$/', $id) && strlen($id) % 2 === 0) {
            $decoded = @hex2bin($id);
            if ($decoded !== false && preg_match('/^[\x20-\x7E]+$/', $decoded)) {
                $short = strtoupper(trim($decoded));
            }
        }

        $display = trim((string)($entry['name'] ?? $entry['title'] ?? $short));
        $category = trim((string)($entry['category'] ?? $entry['class'] ?? ''));
        $author = trim((string)($entry['author_name'] ?? $entry['author'] ?? ''));
        $power = (float)($entry['power'] ?? $entry['power_kw'] ?? 0);
        $weight = (float)($entry['weight'] ?? $entry['mass'] ?? 0);
        $price = (float)($entry['price'] ?? 0);

        $hex = $short !== '' ? strtoupper(bin2hex($short)) : strtoupper($id);
        $json = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return array(
            'id' => strtoupper($id),
            'short_name' => $short,
            'display_name' => $display,
            'car_code' => $short,
            'hex_code' => $hex,
            'category' => $category,
            'author' => $author,
            'power_kw' => $power,
            'weight_kg' => $weight,
            'price' => $price,
            'raw_json' => $json ?: '{}',
        );
    }

    private function normaliseCarCode(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (preg_match('/^[0-9A-F]{4,}$/i', $value) && (strlen($value) % 2) === 0) {
            $decoded = @hex2bin($value);
            if ($decoded !== false && preg_match('/^[\x20-\x7E]+$/', $decoded)) {
                $value = $decoded;
            }
        }

        return strtoupper($value);
    }

    private function announcePurchase(array $player, array $mod): void
    {
        $ucid = $player['ucid'] ?? 0;
        $name = $player['nickname'] ?? $player['username'] ?? 'Player';

        if ($ucid > 0) {
            $this->plugin->MsgToUCID($ucid, sprintf('^2Congrats on acquiring ^3%s^2!', $mod['display_name']));
        }

        $this->sendDiscordEmbed($player, $mod);
    }

    private function sendDiscordEmbed(array $player, array $mod): void
    {
        $webhook = trim($this->config['discord_webhook'] ?? '');
        if ($webhook === '') {
            return;
        }

        $username = trim($this->config['discord_username'] ?? 'PRISM');
        $avatar = trim($this->config['discord_avatar'] ?? '');
        $playerName = $player['nickname'] ?? $player['username'] ?? 'Player';
        $code = $mod['short_name'] !== '' ? $mod['short_name'] : $mod['id'];

        $fields = array();
        $fields[] = array(
            'name' => 'Car Code',
            'value' => $code,
            'inline' => true,
        );

        if ($mod['category'] !== '') {
            $fields[] = array('name' => 'Class', 'value' => $mod['category'], 'inline' => true);
        }

        if ($mod['author'] !== '') {
            $fields[] = array('name' => 'Author', 'value' => $mod['author'], 'inline' => true);
        }

        if ($mod['power_kw'] > 0) {
            $hp = round($mod['power_kw'] * 1.341, 1);
            $fields[] = array('name' => 'Power', 'value' => sprintf('%.1f kW (%.1f hp)', $mod['power_kw'], $hp), 'inline' => true);
        }

        if ($mod['weight_kg'] > 0) {
            $fields[] = array('name' => 'Weight', 'value' => sprintf('%.0f kg', $mod['weight_kg']), 'inline' => true);
        }

        if ($mod['price'] > 0) {
            $fields[] = array('name' => 'Price', 'value' => number_format($mod['price'], 0), 'inline' => true);
        }

        $payload = array(
            'username' => $username,
            'embeds' => array(array(
                'title' => sprintf('%s purchased %s', $playerName, $mod['display_name']),
                'description' => sprintf('Say hello to %s\'s new ride!', $playerName),
                'color' => 0x3BA55D,
                'timestamp' => gmdate('c'),
                'fields' => $fields,
            )),
        );

        if ($avatar !== '') {
            $payload['avatar_url'] = $avatar;
        }

        $context = stream_context_create(array(
            'http' => array(
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'timeout' => 5,
                'content' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ),
        ));

        @file_get_contents($webhook, false, $context);
    }

    private function getCacheTtl(): int
    {
        $hours = (int)($this->config['cache_hours'] ?? 24);
        if ($hours <= 0) {
            return 0;
        }
        return $hours * 3600;
    }
}
