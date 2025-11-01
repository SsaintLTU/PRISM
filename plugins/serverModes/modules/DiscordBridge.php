<?php
class ServerModes_DiscordBridge
{
    private serverModes $plugin;
    private array $config = array();
    private string $token = '';
    private string $guildId = '';
    private string $activityChannel = '';
    private string $chatChannel = '';
    private string $username = 'PRISM';
    private string $avatar = '';
    private string $webhook = '';

    public function __construct(serverModes $plugin, array $config = array())
    {
        $this->plugin = $plugin;
        $this->applyConfig($config);
    }

    public function applyConfig(array $config): void
    {
        $this->config = array_replace($this->config, $config);

        $this->token = trim($this->config['discord_token'] ?? '');
        $this->guildId = trim($this->config['discord_guildId'] ?? '');
        $this->activityChannel = trim($this->config['discord_channelId1'] ?? '');
        $this->chatChannel = trim($this->config['discord_channelId2'] ?? '');
        $this->username = trim($this->config['discord_username'] ?? 'PRISM');
        $this->avatar = trim($this->config['discord_avatar'] ?? '');
        $this->webhook = trim($this->config['discord_webhook'] ?? '');
    }

    public function isActive(): bool
    {
        return ($this->token !== '' && ($this->activityChannel !== '' || $this->chatChannel !== ''))
            || $this->webhook !== '';
    }

    public function announcePlayerJoin(array $player, string $hostId, ?string $hostName = null): void
    {
        if (!$this->isActive()) {
            return;
        }

        $display = $this->buildPlayerDisplay($player);
        $hostLabel = $hostName !== null && $hostName !== '' ? $hostName : ($hostId !== '' ? $hostId : 'server');
        $content = sprintf(':inbox_tray: **%s** joined `%s`', $display, $hostLabel);
        $this->sendActivityMessage($content);
    }

    public function announcePlayerLeave(array $player, string $hostId, ?string $hostName = null): void
    {
        if (!$this->isActive()) {
            return;
        }

        $display = $this->buildPlayerDisplay($player);
        $hostLabel = $hostName !== null && $hostName !== '' ? $hostName : ($hostId !== '' ? $hostId : 'server');
        $content = sprintf(':outbox_tray: **%s** left `%s`', $display, $hostLabel);
        $this->sendActivityMessage($content);
    }

    public function relayChatMessage(array $player, string $message): void
    {
        if (!$this->isActive()) {
            return;
        }

        $channel = $this->chatChannel !== '' ? $this->chatChannel : $this->activityChannel;
        if ($channel === '') {
            return;
        }

        $display = $this->buildPlayerDisplay($player);
        $content = sprintf('**%s**: %s', $display, $message);
        $this->sendMessage($channel, $content);
    }

    public function announceVehiclePurchase(array $player, array $mod): void
    {
        if (!$this->isActive()) {
            return;
        }

        $channel = $this->activityChannel !== '' ? $this->activityChannel : $this->chatChannel;
        if ($channel === '') {
            return;
        }

        $playerName = $this->buildPlayerDisplay($player);
        $code = $mod['short_name'] !== '' ? $mod['short_name'] : $mod['id'];

        $fields = array();
        $fields[] = array('name' => 'Car Code', 'value' => $code, 'inline' => true);
        if (!empty($mod['category'])) {
            $fields[] = array('name' => 'Class', 'value' => $mod['category'], 'inline' => true);
        }
        if (!empty($mod['author'])) {
            $fields[] = array('name' => 'Author', 'value' => $mod['author'], 'inline' => true);
        }
        if (!empty($mod['power_kw'])) {
            $hp = round($mod['power_kw'] * 1.341, 1);
            $fields[] = array(
                'name' => 'Power',
                'value' => sprintf('%.1f kW (%.1f hp)', $mod['power_kw'], $hp),
                'inline' => true,
            );
        }
        if (!empty($mod['weight_kg'])) {
            $fields[] = array(
                'name' => 'Weight',
                'value' => sprintf('%.0f kg', $mod['weight_kg']),
                'inline' => true,
            );
        }
        if (!empty($mod['price'])) {
            $fields[] = array(
                'name' => 'Price',
                'value' => number_format($mod['price'], 0),
                'inline' => true,
            );
        }

        $embed = array(
            'title' => sprintf('%s purchased %s', $playerName, $mod['display_name']),
            'description' => sprintf('Say hello to %s\'s new ride!', $playerName),
            'color' => 0x3BA55D,
            'timestamp' => gmdate('c'),
            'fields' => $fields,
        );

        $this->sendMessage($channel, '', array($embed));
    }

    private function buildPlayerDisplay(array $player): string
    {
        $nickname = trim($player['nickname'] ?? '');
        $username = trim($player['username'] ?? '');

        if ($nickname !== '') {
            if ($username !== '' && strcasecmp($nickname, $username) !== 0) {
                return sprintf('%s (%s)', $nickname, $username);
            }
            return $nickname;
        }

        if ($username !== '') {
            return $username;
        }

        $ucid = $player['ucid'] ?? 0;
        return $ucid > 0 ? sprintf('UCID %d', $ucid) : 'Player';
    }

    private function sendActivityMessage(string $content = '', array $embeds = array()): bool
    {
        $channel = $this->activityChannel !== '' ? $this->activityChannel : $this->chatChannel;
        if ($channel === '') {
            return false;
        }

        return $this->sendMessage($channel, $content, $embeds);
    }

    private function sendMessage(?string $channelId, string $content = '', array $embeds = array()): bool
    {
        if (($content = trim($content)) === '' && empty($embeds)) {
            return false;
        }

        if ($this->token !== '' && $channelId !== null && $channelId !== '') {
            $payload = array('allowed_mentions' => array('parse' => array()));
            if ($content !== '') {
                $payload['content'] = $content;
            }
            if (!empty($embeds)) {
                $payload['embeds'] = $embeds;
            }

            return $this->sendViaBot($channelId, $payload);
        }

        if ($this->webhook === '') {
            return false;
        }

        $payload = array('allowed_mentions' => array('parse' => array()));
        if ($content !== '') {
            $payload['content'] = $content;
        }
        if (!empty($embeds)) {
            $payload['embeds'] = $embeds;
        }
        if ($this->username !== '') {
            $payload['username'] = $this->username;
        }
        if ($this->avatar !== '') {
            $payload['avatar_url'] = $this->avatar;
        }

        return $this->sendViaWebhook($payload);
    }

    private function sendViaBot(string $channelId, array $payload): bool
    {
        $url = 'https://discord.com/api/v10/channels/' . rawurlencode($channelId) . '/messages';
        $headers = "Content-Type: application/json\r\n";
        $headers .= 'Authorization: Bot ' . $this->token . "\r\n";
        $headers .= "User-Agent: PRISM-ServerModes (https://github.com/daniel-cit/PRISM)\r\n";

        $context = stream_context_create(array(
            'http' => array(
                'method' => 'POST',
                'header' => $headers,
                'content' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'timeout' => 5,
            ),
        ));

        $result = @file_get_contents($url, false, $context);
        if ($result === false) {
            console('serverModes: failed to send Discord API message.');
            return false;
        }

        return true;
    }

    private function sendViaWebhook(array $payload): bool
    {
        $context = stream_context_create(array(
            'http' => array(
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'timeout' => 5,
            ),
        ));

        $result = @file_get_contents($this->webhook, false, $context);
        if ($result === false) {
            console('serverModes: failed to send Discord webhook message.');
            return false;
        }

        return true;
    }
}
