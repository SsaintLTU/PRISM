<?php
class ServerModes_Friends
{
    private const PANEL_GROUP = 'FriendsPanel';
    private const NOTIFY_GROUP = 'FriendNotify';

    private serverModes $plugin;
    private array $config = array();
    private int $notificationDuration = 6;
    private array $notifications = array();
    private array $panelDirty = array();

    public function __construct(serverModes $plugin, array $config = array())
    {
        $this->plugin = $plugin;
        $this->applyConfig($config);
    }

    public function applyConfig(array $config): void
    {
        $this->config = array_replace($this->config, $config);
        $this->notificationDuration = max(3, (int)($this->config['notification_seconds'] ?? 6));
    }

    public function onPlayerConnected(array &$player): void
    {
        $this->ensureState($player);
        $ucid = $player['ucid'] ?? 0;
        if ($ucid > 0) {
            $this->panelDirty[$ucid] = true;
        }
    }

    public function onClientInfo(array &$player): void
    {
        $this->ensureState($player);
        $userId = $player['user_id'] ?? 0;
        if ($userId <= 0) {
            return;
        }

        $this->loadFriends($player);
        $this->updateOnlineStatuses($player);
        $this->notifyFriendsOfStatus($player, true);
    }

    public function onPlayerDisconnected(array &$player): void
    {
        $ucid = $player['ucid'] ?? 0;
        if ($ucid > 0) {
            ButtonManager::removeButtonsByGroup($ucid, self::PANEL_GROUP);
            ButtonManager::removeButtonsByGroup($ucid, self::NOTIFY_GROUP);
            unset($this->panelDirty[$ucid], $this->notifications[$ucid]);
        }

        $userId = $player['user_id'] ?? 0;
        if ($userId > 0) {
            $this->notifyFriendsOfStatus($player, false);
        }
    }

    public function handleAddCommand(int $ucid, string $targetName): void
    {
        $player =& $this->plugin->getPlayerRecord($ucid);
        $this->ensureState($player);

        $userId = $player['user_id'] ?? 0;
        if ($userId <= 0) {
            $this->plugin->MsgToUCID($ucid, '^1Please wait until your profile is loaded.');
            return;
        }

        $targetName = trim($targetName);
        if ($targetName === '') {
            $this->plugin->MsgToUCID($ucid, '^1Usage:^7 !add <player name>');
            return;
        }

        $match = $this->findPlayerByName($targetName);
        if ($match === null) {
            $this->plugin->MsgToUCID($ucid, '^1Player not found.');
            return;
        }

        if (($match['user_id'] ?? 0) === 0) {
            $this->plugin->MsgToUCID($ucid, '^1That player has not fully joined yet.');
            return;
        }

        if ($match['user_id'] === $userId) {
            $this->plugin->MsgToUCID($ucid, '^1You cannot add yourself.');
            return;
        }

        $friends =& $player['friends']['list'];
        if (isset($friends[$match['user_id']])) {
            $this->plugin->MsgToUCID($ucid, '^8That player is already on your friends list.');
            return;
        }

        $displayName = $match['nickname'] ?? $match['username'];
        $this->plugin->getDatabase()->addFriend($userId, $match['user_id'], $displayName);

        $friends[$match['user_id']] = array(
            'name' => $displayName,
            'online' => true,
            'ucid' => $match['ucid'] ?? 0,
            'online_since' => (int)($match['connected_at'] ?? time()),
        );

        $this->markStateDirty($player);
        $this->panelDirty[$ucid] = true;
        $this->plugin->MsgToUCID($ucid, '^2Added ^3' . $displayName . '^2 to your friends list.');
    }

    public function handleFriendsCommand(int $ucid, string $argument): void
    {
        $player =& $this->plugin->getPlayerRecord($ucid);
        $this->ensureState($player);

        $argument = trim($argument);
        if ($argument === '') {
            $this->togglePanel($player);
            return;
        }

        switch (strtolower($argument)) {
            case 'hide':
            case 'off':
                $this->setPanelVisible($player, false);
                break;
            case 'show':
            case 'on':
                $this->setPanelVisible($player, true);
                break;
            case 'notify':
                $this->showFriendSnapshot($ucid, $player);
                break;
            default:
                if (stripos($argument, 'move') === 0) {
                    $this->handleMoveCommand($player, $argument);
                } else {
                    $this->plugin->MsgToUCID($ucid, '^7Commands:^3 !friends, !friends notify, !friends move <x> <y>');
                }
                break;
        }
    }

    public function handleButton(int $ucid, string $action, $extra = null): void
    {
        $player =& $this->plugin->getPlayerRecord($ucid);
        $this->ensureState($player);

        switch ($action) {
            case 'panel_toggle':
                $this->togglePanel($player);
                break;
            case 'panel_up':
                $this->offsetPanel($player, 0, -3);
                break;
            case 'panel_down':
                $this->offsetPanel($player, 0, 3);
                break;
            case 'panel_left':
                $this->offsetPanel($player, -3, 0);
                break;
            case 'panel_right':
                $this->offsetPanel($player, 3, 0);
                break;
        }
    }

    public function tick(): void
    {
        foreach ($this->plugin->getPlayerMap() as $ucid => &$player) {
            if (empty($player['friends']['loaded'])) {
                continue;
            }
            if (!empty($this->panelDirty[$ucid])) {
                $this->renderPanel($player);
                $this->panelDirty[$ucid] = false;
            }
        }
        unset($player);

        $this->tickNotifications();
    }

    private function ensureState(array &$player): void
    {
        if (!isset($player['friends']) || !is_array($player['friends'])) {
            $player['friends'] = array(
                'list' => array(),
                'loaded' => false,
            );
        }

        if (!isset($player['state']) || !is_array($player['state'])) {
            $player['state'] = array();
        }

        if (!isset($player['state']['social']) || !is_array($player['state']['social'])) {
            $player['state']['social'] = array();
        }

        if (!isset($player['state']['social']['friends']) || !is_array($player['state']['social']['friends'])) {
            $player['state']['social']['friends'] = array(
                'visible' => false,
                'left' => 120,
                'top' => 60,
            );
            $this->markStateDirty($player);
        }
    }

    private function loadFriends(array &$player): void
    {
        $userId = $player['user_id'] ?? 0;
        if ($userId <= 0) {
            return;
        }

        $friends = array();
        foreach ($this->plugin->getDatabase()->loadFriends($userId) as $row) {
            $friendId = (int)$row['friend_id'];
            $friends[$friendId] = array(
                'name' => $row['friend_name'],
                'online' => false,
                'ucid' => 0,
                'online_since' => 0,
            );
        }

        $player['friends']['list'] = $friends;
        $player['friends']['loaded'] = true;
        $this->panelDirty[$player['ucid']] = true;
    }

    private function updateOnlineStatuses(array &$player): void
    {
        foreach ($player['friends']['list'] as $friendId => &$info) {
            $info['online'] = false;
            $info['ucid'] = 0;
            $info['online_since'] = 0;
        }
        unset($info);

        foreach ($this->plugin->getPlayerMap() as $other) {
            $otherUser = $other['user_id'] ?? 0;
            if ($otherUser && isset($player['friends']['list'][$otherUser])) {
                $friend =& $player['friends']['list'][$otherUser];
                $friend['online'] = true;
                $friend['ucid'] = $other['ucid'] ?? 0;
                $connectedAt = (int)($other['connected_at'] ?? time());
                if (($friend['online_since'] ?? 0) === 0 || $friend['online_since'] > $connectedAt) {
                    $friend['online_since'] = $connectedAt;
                }
            }
        }
        unset($friend);

        $this->panelDirty[$player['ucid']] = true;
    }

    private function notifyFriendsOfStatus(array &$player, bool $online): void
    {
        $userId = $player['user_id'] ?? 0;
        if ($userId <= 0) {
            return;
        }

        foreach ($this->plugin->getPlayerMap() as $ucid => &$other) {
            if ($other['user_id'] ?? 0) {
                $this->ensureState($other);
                $friends =& $other['friends']['list'];
                if (isset($friends[$userId])) {
                    $friends[$userId]['online'] = $online;
                    $friends[$userId]['ucid'] = $online ? ($player['ucid'] ?? 0) : 0;
                    if ($online) {
                        $friends[$userId]['online_since'] = (int)($player['connected_at'] ?? time());
                    } else {
                        $friends[$userId]['online_since'] = 0;
                    }
                    $this->panelDirty[$ucid] = true;
                    if ($online) {
                        $name = $player['nickname'] ?? $player['username'] ?? 'Player';
                        $this->addNotification($ucid, '^2Friend ^3' . $name . '^2 is online.');
                    }
                }
            }
        }
        unset($other);
    }

    private function renderPanel(array &$player): void
    {
        $ucid = $player['ucid'];
        $state =& $player['state']['social']['friends'];
        if (empty($state['visible'])) {
            ButtonManager::removeButtonsByGroup($ucid, self::PANEL_GROUP);
            return;
        }

        $friends = $this->sortFriends($player['friends']['list']);
        $left = max(0, min(IS_X_MAX - 90, (int)($state['left'] ?? 120)));
        $top = max(10, min(IS_Y_MAX - 60, (int)($state['top'] ?? 60)));
        $rows = max(1, count($friends));
        $height = 12 + ($rows * 6) + 8;

        $background = new Button($ucid, 'FriendsBG', self::PANEL_GROUP);
        $background->L($left)->T($top)->W(90)->H($height)->BStyle(ISB_DARK)->Text('')->Send();

        $title = new Button($ucid, 'FriendsTitle', self::PANEL_GROUP);
        $title->L($left + 2)->T($top + 2)->W(86)->H(6)->BStyle(ISB_DARK | ISB_YELLOW)->Text('^7Friends List')->Send();

        $toggle = new Button($ucid, 'FriendsToggle', self::PANEL_GROUP);
        $toggle->L($left + 60)->T($top + 2)->W(28)->H(6)->BStyle(ISB_DARK | ISB_CLICK | ISB_LEFT)->Text('^1Close')->registerOnClick($this->plugin, 'handleFriendsButton', array($ucid, 'panel_toggle'));
        $toggle->Send();

        $rowIndex = 0;
        if (empty($friends)) {
            $row = new Button($ucid, 'FriendsEmpty', self::PANEL_GROUP);
            $row->L($left + 2)->T($top + 10)->W(86)->H(5)->BStyle(ISB_DARK | ISB_LEFT)->Text('^8No friends added yet.')->Send();
        } else {
            foreach ($friends as $friend) {
                if (!empty($friend['online'])) {
                    $since = (int)($friend['online_since'] ?? 0);
                    $duration = $since > 0 ? $this->formatDuration(time() - $since) : 'just now';
                    $status = sprintf('^2Online ^7(%s)', $duration);
                } else {
                    $status = '^8Offline';
                }
                $text = sprintf('^3%s ^8- %s', $friend['name'], $status);
                $row = new Button($ucid, 'FriendRow' . $rowIndex, self::PANEL_GROUP);
                $row->L($left + 2)->T($top + 10 + ($rowIndex * 6))->W(86)->H(5)->BStyle(ISB_DARK | ISB_LEFT)->Text($text)->Send();
                $rowIndex++;
            }
        }

        $hint = new Button($ucid, 'FriendsHint', self::PANEL_GROUP);
        $hint->L($left + 2)->T($top + $height - 13)->W(86)->H(5)->BStyle(ISB_DARK | ISB_LEFT)->Text('^7Use ^3!add name ^7to add')->Send();

        $moveUp = new Button($ucid, 'FriendsMoveUp', self::PANEL_GROUP);
        $moveUp->L($left + 35)->T($top + $height - 8)->W(6)->H(5)->BStyle(ISB_DARK | ISB_CLICK)->Text('^')->registerOnClick($this->plugin, 'handleFriendsButton', array($ucid, 'panel_up'));
        $moveUp->Send();

        $moveLeft = new Button($ucid, 'FriendsMoveLeft', self::PANEL_GROUP);
        $moveLeft->L($left + 27)->T($top + $height - 3)->W(6)->H(5)->BStyle(ISB_DARK | ISB_CLICK)->Text('<')->registerOnClick($this->plugin, 'handleFriendsButton', array($ucid, 'panel_left'));
        $moveLeft->Send();

        $moveDown = new Button($ucid, 'FriendsMoveDown', self::PANEL_GROUP);
        $moveDown->L($left + 35)->T($top + $height - 3)->W(6)->H(5)->BStyle(ISB_DARK | ISB_CLICK)->Text('v')->registerOnClick($this->plugin, 'handleFriendsButton', array($ucid, 'panel_down'));
        $moveDown->Send();

        $moveRight = new Button($ucid, 'FriendsMoveRight', self::PANEL_GROUP);
        $moveRight->L($left + 43)->T($top + $height - 3)->W(6)->H(5)->BStyle(ISB_DARK | ISB_CLICK)->Text('>')->registerOnClick($this->plugin, 'handleFriendsButton', array($ucid, 'panel_right'));
        $moveRight->Send();
    }

    private function togglePanel(array &$player): void
    {
        $state =& $player['state']['social']['friends'];
        $state['visible'] = !empty($state['visible']) ? false : true;
        $this->markStateDirty($player);
        if (!$state['visible']) {
            ButtonManager::removeButtonsByGroup($player['ucid'], self::PANEL_GROUP);
        }
        $this->panelDirty[$player['ucid']] = true;
    }

    private function setPanelVisible(array &$player, bool $visible): void
    {
        $state =& $player['state']['social']['friends'];
        if (!empty($state['visible']) === $visible) {
            return;
        }
        $state['visible'] = $visible;
        $this->markStateDirty($player);
        if (!$visible) {
            ButtonManager::removeButtonsByGroup($player['ucid'], self::PANEL_GROUP);
        }
        $this->panelDirty[$player['ucid']] = true;
    }

    private function offsetPanel(array &$player, int $dx, int $dy): void
    {
        $state =& $player['state']['social']['friends'];
        $state['left'] = ($state['left'] ?? 120) + $dx;
        $state['top'] = ($state['top'] ?? 60) + $dy;
        $this->markStateDirty($player);
        $this->panelDirty[$player['ucid']] = true;
    }

    private function markStateDirty(array &$player): void
    {
        $player['state_dirty'] = true;
    }

    private function formatDuration(int $seconds): string
    {
        if ($seconds <= 0) {
            return '0s';
        }

        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $remaining = $seconds % 60;

        if ($hours > 0) {
            return sprintf('%dh %02dm', $hours, $minutes);
        }

        if ($minutes > 0) {
            return sprintf('%dm %02ds', $minutes, $remaining);
        }

        return sprintf('%ds', $remaining);
    }

    private function sortFriends(array $friends): array
    {
        uasort($friends, function ($a, $b) {
            if (($a['online'] ?? false) === ($b['online'] ?? false)) {
                return strcmp($this->cleanName($a['name']), $this->cleanName($b['name']));
            }
            return ($a['online'] ?? false) ? -1 : 1;
        });
        return $friends;
    }

    private function findPlayerByName(string $needle): ?array
    {
        $needle = $this->normaliseName($needle);
        $best = null;
        foreach ($this->plugin->getPlayerMap() as $player) {
            $name = $this->normaliseName($player['nickname'] ?? $player['username'] ?? '');
            if ($name === $needle) {
                return $player;
            }
            if (strpos($name, $needle) === 0 && $best === null) {
                $best = $player;
            }
        }
        return $best;
    }

    private function normaliseName(string $name): string
    {
        return strtolower($this->cleanName($name));
    }

    private function cleanName(string $name): string
    {
        return trim(preg_replace('/\^./', '', $name));
    }

    private function addNotification(int $ucid, string $message): void
    {
        ButtonManager::removeButtonsByGroup($ucid, self::NOTIFY_GROUP);
        $this->notifications[$ucid] = array(
            'text' => $message,
            'expires' => microtime(true) + $this->notificationDuration,
            'shown' => false,
        );
        $this->plugin->MsgToUCID($ucid, $message);
    }

    private function tickNotifications(): void
    {
        foreach ($this->notifications as $ucid => &$notice) {
            if (!$notice['shown']) {
                $button = new Button($ucid, 'FriendNotify', self::NOTIFY_GROUP);
                $button->L(40)->T(40)->W(120)->H(6)->BStyle(ISB_DARK | ISB_YELLOW)->Text($notice['text'])->Send();
                $notice['shown'] = true;
            }
            if ($notice['expires'] <= microtime(true)) {
                ButtonManager::removeButtonsByGroup($ucid, self::NOTIFY_GROUP);
                unset($this->notifications[$ucid]);
            }
        }
        unset($notice);
    }

    private function showFriendSnapshot(int $ucid, array &$player): void
    {
        $online = array();
        foreach ($player['friends']['list'] as $friend) {
            if (!empty($friend['online'])) {
                $online[] = $friend['name'];
            }
        }
        if (empty($online)) {
            $this->plugin->MsgToUCID($ucid, '^8No friends online.');
            return;
        }
        $text = '^2Online friends:^3 ' . implode(', ', array_slice($online, 0, 5));
        $this->addNotification($ucid, $text);
    }

    private function handleMoveCommand(array &$player, string $argument): void
    {
        $parts = preg_split('/\s+/', trim($argument));
        if (count($parts) < 3) {
            $this->plugin->MsgToUCID($player['ucid'], '^1Usage:^7 !friends move <x> <y>');
            return;
        }

        $x = (int)$parts[1];
        $y = (int)$parts[2];
        $state =& $player['state']['social']['friends'];
        $state['left'] = $x;
        $state['top'] = $y;
        $this->markStateDirty($player);
        $this->panelDirty[$player['ucid']] = true;
    }
}
