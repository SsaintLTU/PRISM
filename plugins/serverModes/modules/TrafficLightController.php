<?php
if (!defined('TL_RED')) {
    define('TL_RED', 1);
}
if (!defined('TL_YELLOW')) {
    define('TL_YELLOW', 2);
}
if (!defined('TL_RED_YELLOW')) {
    define('TL_RED_YELLOW', 3);
}
if (!defined('TL_OFF')) {
    define('TL_OFF', 4);
}
if (!defined('TL_GREEN')) {
    define('TL_GREEN', 8);
}

class ServerModes_TrafficLightController
{
    private serverModes $plugin;
    private bool $enabled = false;
    private int $index = 149;
    private array $sequence = array();
    private array $lights = array();

    private array $stateAliases = array(
        'red' => TL_RED,
        'r' => TL_RED,
        'yellow' => TL_YELLOW,
        'amber' => TL_YELLOW,
        'y' => TL_YELLOW,
        'red_yellow' => TL_RED_YELLOW,
        'ry' => TL_RED_YELLOW,
        'off' => TL_OFF,
        'green' => TL_GREEN,
        'g' => TL_GREEN,
    );

    public function __construct(serverModes $plugin, array $config = array())
    {
        $this->plugin = $plugin;
        $this->sequence = $this->defaultSequence();
        $this->applyConfig($config);
    }

    public function applyConfig(array $config): void
    {
        $enabled = isset($config['enabled']) ? (bool)$config['enabled'] : $this->enabled;

        if (isset($config['index'])) {
            $this->index = (int)$config['index'];
        }

        if (isset($config['sequence'])) {
            $parsed = $this->parseSequence($config['sequence']);
            if (!empty($parsed)) {
                $this->sequence = $parsed;
            }
        }

        if (isset($config['lights'])) {
            $this->configureLights($config['lights']);
        }

        $this->setEnabled($enabled);
    }

    public function setEnabled(bool $enabled): void
    {
        if ($this->enabled === $enabled) {
            return;
        }

        if (!$enabled) {
            $this->resetLights();
        } else {
            foreach ($this->lights as &$light) {
                $light['last_step'] = null;
                $light['last_state'] = null;
            }
            unset($light);
        }

        $this->enabled = $enabled;
    }

    public function registerLight(int $identifier, array $options = array()): void
    {
        $this->lights[$identifier] = array(
            'offset' => (int)($options['offset'] ?? 0),
            'index' => (int)($options['index'] ?? $this->index),
            'manual' => null,
            'last_step' => null,
            'last_state' => null,
        );
    }

    public function setManualState(int $identifier, int $state, ?int $duration = null): void
    {
        if (!isset($this->lights[$identifier])) {
            $this->registerLight($identifier);
        }

        $expires = $duration !== null ? (time() + max(0, (int)$duration)) : null;
        $this->lights[$identifier]['manual'] = array('state' => $state, 'expires' => $expires);
        $this->sendState($identifier, $state, $this->lights[$identifier]);
    }

    public function clearManualState(int $identifier): void
    {
        if (!isset($this->lights[$identifier])) {
            return;
        }

        $this->lights[$identifier]['manual'] = null;
        $this->lights[$identifier]['last_step'] = null;
    }

    public function getState(int $identifier): ?int
    {
        if (!isset($this->lights[$identifier])) {
            return null;
        }

        if (isset($this->lights[$identifier]['manual']) && $this->lights[$identifier]['manual']) {
            return $this->lights[$identifier]['manual']['state'];
        }

        return $this->lights[$identifier]['last_state'];
    }

    public function previewState(int $identifier, int $seconds = 0): ?int
    {
        if (!$this->enabled || !isset($this->lights[$identifier])) {
            return null;
        }

        $light = $this->lights[$identifier];
        if (isset($light['manual']) && $light['manual']) {
            return $light['manual']['state'];
        }

        $sequence = $this->sequence;
        $offset = $light['offset'] ?? 0;
        $future = max(0, (time() + $seconds) - $offset);
        $stepIndex = $this->getStepIndex($future);

        return $sequence[$stepIndex]['state'];
    }

    public function update(?int $timestamp = null): void
    {
        if (!$this->enabled || empty($this->lights)) {
            return;
        }

        $now = $timestamp ?? time();
        foreach ($this->lights as $identifier => &$light) {
            if (isset($light['manual']) && $light['manual']) {
                $manual = $light['manual'];
                if ($manual['expires'] !== null && $now >= $manual['expires']) {
                    $light['manual'] = null;
                    $light['last_step'] = null;
                } else {
                    if ($manual['state'] !== ($light['last_state'] ?? null)) {
                        $this->sendState($identifier, $manual['state'], $light);
                    }
                    continue;
                }
            }

            $offset = $light['offset'] ?? 0;
            $elapsed = max(0, $now - $offset);
            $stepIndex = $this->getStepIndex($elapsed);

            if ($stepIndex !== $light['last_step']) {
                $light['last_step'] = $stepIndex;
                $state = $this->sequence[$stepIndex]['state'];
                $this->sendState($identifier, $state, $light);
            }
        }
        unset($light);
    }

    private function configureLights($definition): void
    {
        $parsed = $this->parseLights($definition);
        $this->lights = array();
        foreach ($parsed as $identifier => $options) {
            $this->registerLight($identifier, $options);
        }
    }

    private function resetLights(): void
    {
        $hostId = $this->plugin->getActiveHostId();
        foreach ($this->lights as $identifier => &$light) {
            IS_OCO()
                ->OCOAction(OCO_LIGHTS_UNSET)
                ->Index($light['index'] ?? $this->index)
                ->Identifier($identifier)
                ->Data(0)
                ->send($hostId !== '' ? $hostId : null);
            $light['last_state'] = null;
            $light['last_step'] = null;
            $light['manual'] = null;
        }
        unset($light);
    }

    private function sendState(int $identifier, int $state, array &$light): void
    {
        if (($light['last_state'] ?? null) === $state) {
            return;
        }

        $hostId = $this->plugin->getActiveHostId();

        IS_OCO()
            ->OCOAction(OCO_LIGHTS_SET)
            ->Index($light['index'] ?? $this->index)
            ->Identifier($identifier)
            ->Data($state)
            ->send($hostId !== '' ? $hostId : null);

        $light['last_state'] = $state;
    }

    private function parseSequence($source): array
    {
        $sequence = array();

        if (is_string($source)) {
            $entries = preg_split('/[;,]+/', $source);
        } elseif (is_array($source)) {
            $entries = $source;
        } else {
            return $sequence;
        }

        foreach ($entries as $entry) {
            if (is_array($entry)) {
                $state = $entry['state'] ?? null;
                $duration = $entry['duration'] ?? null;
            } else {
                $entry = trim((string)$entry);
                if ($entry === '') {
                    continue;
                }
                if (strpos($entry, ':') !== false) {
                    list($state, $duration) = array_map('trim', explode(':', $entry, 2));
                } elseif (strpos($entry, '@') !== false) {
                    list($state, $duration) = array_map('trim', explode('@', $entry, 2));
                } else {
                    $state = $entry;
                    $duration = null;
                }
            }

            $stateId = $this->resolveState($state);
            $duration = (int)($duration ?? 1);
            if ($stateId === null || $duration <= 0) {
                continue;
            }

            $sequence[] = array('state' => $stateId, 'duration' => $duration);
        }

        return $sequence;
    }

    private function parseLights($definition): array
    {
        $result = array();

        if (is_string($definition)) {
            $parts = preg_split('/[;,]+/', $definition);
            foreach ($parts as $part) {
                $part = trim($part);
                if ($part === '') {
                    continue;
                }

                $index = null;
                if (strpos($part, '#') !== false) {
                    list($part, $indexPart) = explode('#', $part, 2);
                    $index = (int)trim($indexPart);
                }

                $offset = 0;
                if (strpos($part, '@') !== false) {
                    list($idPart, $offsetPart) = explode('@', $part, 2);
                } elseif (strpos($part, ':') !== false) {
                    list($idPart, $offsetPart) = explode(':', $part, 2);
                } else {
                    $idPart = $part;
                    $offsetPart = 0;
                }

                $identifier = (int)trim($idPart);
                if ($identifier <= 0) {
                    continue;
                }

                $offset = (int)trim((string)$offsetPart);
                $result[$identifier] = array(
                    'offset' => $offset,
                    'index' => $index ?? $this->index,
                );
            }
        } elseif (is_array($definition)) {
            foreach ($definition as $id => $offset) {
                $identifier = (int)$id;
                if ($identifier <= 0) {
                    continue;
                }
                if (is_array($offset)) {
                    $result[$identifier] = array(
                        'offset' => (int)($offset['offset'] ?? 0),
                        'index' => isset($offset['index']) ? (int)$offset['index'] : $this->index,
                    );
                } else {
                    $result[$identifier] = array(
                        'offset' => (int)$offset,
                        'index' => $this->index,
                    );
                }
            }
        }

        return $result;
    }

    private function getStepIndex(int $elapsed): int
    {
        $sequence = $this->sequence;
        if (empty($sequence)) {
            $sequence = $this->defaultSequence();
        }

        $total = 0;
        foreach ($sequence as $step) {
            $total += (int)$step['duration'];
        }
        if ($total <= 0) {
            return 0;
        }

        $position = $elapsed % $total;
        $accum = 0;
        foreach ($sequence as $index => $step) {
            $accum += (int)$step['duration'];
            if ($position < $accum) {
                return $index;
            }
        }

        return count($sequence) - 1;
    }

    private function resolveState($state)
    {
        if ($state === null) {
            return null;
        }

        $state = strtolower((string)$state);
        if (isset($this->stateAliases[$state])) {
            return $this->stateAliases[$state];
        }

        if (is_numeric($state)) {
            return (int)$state;
        }

        return null;
    }

    private function defaultSequence(): array
    {
        return array(
            array('state' => TL_RED, 'duration' => 25),
            array('state' => TL_RED_YELLOW, 'duration' => 3),
            array('state' => TL_GREEN, 'duration' => 25),
            array('state' => TL_YELLOW, 'duration' => 3),
        );
    }
}
