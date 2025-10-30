<?php
class aiControl extends Plugins
{
    const URL = '';
    const NAME = 'AI Control Toolkit';
    const AUTHOR = 'OpenAI';
    const VERSION = '1.0.0';
    const DESCRIPTION = 'Commands for driving AI cars and streaming telemetry via InSim AIC/AII packets.';

    private static $INPUT_MAP = array(
        'steer'       => CS_MSX,
        'throttle'    => CS_THROTTLE,
        'brake'       => CS_BRAKE,
        'shiftup'     => CS_CHUP,
        'shiftdown'   => CS_CHDN,
        'ignition'    => CS_IGNITION,
        'extralight'  => CS_EXTRALIGHT,
        'lights'      => CS_HEADLIGHTS,
        'siren'       => CS_SIREN,
        'horn'        => CS_HORN,
        'flash'       => CS_FLASH,
        'clutch'      => CS_CLUTCH,
        'handbrake'   => CS_HANDBRAKE,
        'indicators'  => CS_INDICATORS,
        'gear'        => CS_GEAR,
        'look'        => CS_LOOK,
        'pitlimiter'  => CS_PITSPEED,
        'tc'          => CS_TCDISABLE,
        'fogrear'     => CS_FOGREAR,
        'fogfront'    => CS_FOGFRONT,
    );

    private static $ALIAS_MAP = array(
        'signals'  => 'indicators',
        'signal'   => 'indicators',
        'pitspeed' => 'pitlimiter',
    );

    private static $TOGGLE_MAP = array(
        'toggle' => 1,
        'off'    => 2,
        'on'     => 3,
    );

    private static $INDICATOR_MAP = array(
        'off'    => 1,
        'left'   => 2,
        'right'  => 3,
        'hazard' => 4,
    );

    private static $LOOK_MAP = array(
        'none'   => 0,
        'left'   => 4,
        'left+'  => 5,
        'right'  => 6,
        'right+' => 7,
    );

    private static $SIREN_MAP = array(
        'off'  => 0,
        'fast' => 1,
        'slow' => 2,
    );

    private $infoWatchers = array();

    public function __construct()
    {
        $this->registerCommand('ai', 'handleAiCommand', 'ai <send|reset|stop|info|repeat|help>', ADMIN_GAME);
        $this->registerConsoleCommand('ai', 'handleAiConsole', 'Control AI drivers from the PRISM console');
        $this->registerPacket('onAiInfo', ISP_AII);
    }

    public function handleAiConsole($string)
    {
        $this->executeAiCommand($string, null, true);
    }

    public function handleAiCommand($cmdString, $ucid = null, $packet = null)
    {
        $this->executeAiCommand($cmdString, $ucid, false);
    }

    private function executeAiCommand($cmdString, $ucid, $isConsole)
    {
        $parts = preg_split('/\s+/', trim($cmdString));

        if (!$parts || ($command = strtolower(array_shift($parts))) !== 'ai') {
            return;
        }

        $action = strtolower((count($parts) ? array_shift($parts) : ''));

        switch ($action) {
            case 'send':
                $this->handleSend($parts, $ucid);
                break;
            case 'reset':
                $this->handleSimpleControl($parts, $ucid, CS_RESET_INPUTS, 'Reset all AI inputs for PLID %d.');
                break;
            case 'stop':
                $this->handleSimpleControl($parts, $ucid, CS_STOP_CONTROL, 'Ordered AI PLID %d to stop.');
                break;
            case 'info':
                $this->handleInfo($parts, $ucid, $isConsole);
                break;
            case 'repeat':
                $this->handleRepeat($parts, $ucid, $isConsole);
                break;
            case 'help':
            case '':
                $this->sendHelp($ucid, $isConsole);
                break;
            default:
                $this->respond('Unknown AI command. Use "ai help" for usage.', $ucid, $isConsole);
                break;
        }
    }

    private function sendHelp($ucid, $isConsole)
    {
        $lines = array(
            'ai send <plid> <input> <value> [holdSeconds] - send a control value to an AI driver',
            'ai reset <plid> - clear all control inputs for the AI driver',
            'ai stop <plid> - instruct the AI driver to stop the car',
            'ai info <plid> - request a single AI telemetry sample',
            'ai repeat <plid> <seconds|stop> - stream AI telemetry at the requested interval (0-2.55s)',
            'Inputs: steer, throttle, brake, clutch, handbrake, shiftup, shiftdown, gear, look, indicators/signals,',
            '         lights, ignition, pitlimiter, tc, fogfront, fogrear, extralight, horn, siren, flash',
        );

        foreach ($lines as $line) {
            $this->respond($line, $ucid, $isConsole);
        }
    }

    private function handleSend(array $parts, $ucid)
    {
        if (count($parts) < 3) {
            $this->respond('Usage: ai send <plid> <input> <value> [holdSeconds]', $ucid);
            return;
        }

        $plid = (int)array_shift($parts);
        $inputKey = strtolower(array_shift($parts));

        if (isset(self::$ALIAS_MAP[$inputKey])) {
            $inputKey = self::$ALIAS_MAP[$inputKey];
        }

        if (!isset(self::$INPUT_MAP[$inputKey])) {
            $this->respond("Unknown AI input '{$inputKey}'.", $ucid);
            return;
        }

        $rawValue = array_shift($parts);
        $holdRaw = count($parts) ? array_shift($parts) : null;

        $parsed = $this->parseInputValue($inputKey, $rawValue);
        if ($parsed['error'] !== null) {
            $this->respond($parsed['error'], $ucid);
            return;
        }

        $hold = $this->parseHoldTime($holdRaw);
        if ($hold === null) {
            $this->respond('Hold time must be a numeric value in seconds (0-2.55).', $ucid);
            return;
        }

        $input = new AIInputVal();
        $input->Input(self::$INPUT_MAP[$inputKey])->Time($hold)->Value($parsed['value']);

        $valueLabel = ($parsed['label'] !== null) ? $parsed['label'] : (string)$parsed['value'];
        $display = ($valueLabel !== (string)$parsed['value']) ? sprintf('%s (%d)', $valueLabel, $parsed['value']) : $valueLabel;
        $message = sprintf('Applied %s=%s to PLID %d.', $inputKey, $display, $plid);

        if ($hold > 0) {
            $message .= sprintf(' Hold %.2fs.', $hold / 100);
        }

        $this->sendAiInputs($plid, array($input), $ucid, $message);
    }

    private function handleSimpleControl(array $parts, $ucid, $inputCode, $messageTemplate)
    {
        if (count($parts) < 1) {
            $this->respond('Usage: ai '.($inputCode === CS_STOP_CONTROL ? 'stop' : 'reset').' <plid>', $ucid);
            return;
        }

        $plid = (int)array_shift($parts);
        $input = new AIInputVal();
        $input->Input($inputCode)->Time(0)->Value(0);

        $this->sendAiInputs($plid, array($input), $ucid, sprintf($messageTemplate, $plid));
    }

    private function handleInfo(array $parts, $ucid, $isConsole)
    {
        if (count($parts) < 1) {
            $this->respond('Usage: ai info <plid>', $ucid, $isConsole);
            return;
        }

        $plid = (int)array_shift($parts);
        $hostId = $this->getCurrentHostId();
        $this->ensureWatcher($hostId, $plid);

        if ($ucid === null) {
            $this->infoWatchers[$hostId][$plid]['onceConsole'] = true;
        } else {
            $this->infoWatchers[$hostId][$plid]['once'][] = $ucid;
        }

        $input = new AIInputVal();
        $input->Input(CS_SEND_AI_INFO)->Time(0)->Value(0);

        $this->sendAiInputs($plid, array($input), $ucid, sprintf('Requested AI telemetry for PLID %d.', $plid));
    }

    private function handleRepeat(array $parts, $ucid, $isConsole)
    {
        if (count($parts) < 2) {
            $this->respond('Usage: ai repeat <plid> <seconds|stop>', $ucid, $isConsole);
            return;
        }

        $plid = (int)array_shift($parts);
        $intervalToken = strtolower(array_shift($parts));

        $stopRequested = ($intervalToken === 'stop' || $intervalToken === 'off');
        $interval = 0;

        if (!$stopRequested) {
            if (!is_numeric($intervalToken)) {
                $this->respond('Repeat interval must be numeric seconds between 0.01 and 2.55, or "stop".', $ucid, $isConsole);
                return;
            }

            $interval = $this->parseHoldTime($intervalToken);
            if ($interval === null) {
                $this->respond('Repeat interval must be numeric seconds between 0.01 and 2.55.', $ucid, $isConsole);
                return;
            }

            if ($interval === 0) {
                $interval = 1; // minimum interval when user supplies a very small positive number
            }
        }

        $hostId = $this->getCurrentHostId();
        $this->ensureWatcher($hostId, $plid);

        if ($stopRequested || $interval === 0) {
            if ($ucid === null) {
                $this->infoWatchers[$hostId][$plid]['repeatConsole'] = false;
            } else {
                unset($this->infoWatchers[$hostId][$plid]['repeat'][$ucid]);
            }

            $message = sprintf('Stopped telemetry streaming for PLID %d.', $plid);
        } else {
            if ($ucid === null) {
                $this->infoWatchers[$hostId][$plid]['repeatConsole'] = true;
            } else {
                $this->infoWatchers[$hostId][$plid]['repeat'][$ucid] = true;
            }

            $message = sprintf('Streaming AI telemetry for PLID %d every %.2fs.', $plid, $interval / 100);
        }

        $this->cleanupWatcher($hostId, $plid);

        $input = new AIInputVal();
        $input->Input(CS_REPEAT_AI_INFO)->Time($interval)->Value(0);

        $this->sendAiInputs($plid, array($input), $ucid, $message);
    }

    public function onAiInfo(IS_AII $packet)
    {
        $hostId = $this->getCurrentHostId();
        $plid = $packet->PLID;
        $message = $this->formatAiSummary($packet);
        $delivered = false;

        if (isset($this->infoWatchers[$hostId][$plid])) {
            $watch =& $this->infoWatchers[$hostId][$plid];

            if (!empty($watch['once'])) {
                foreach ($watch['once'] as $targetUcid) {
                    $this->respond($message, $targetUcid);
                }
                $watch['once'] = array();
                $delivered = true;
            }

            if (!empty($watch['onceConsole'])) {
                console('[AIControl] '.$message);
                $watch['onceConsole'] = false;
                $delivered = true;
            }

            if (!empty($watch['repeat'])) {
                foreach (array_keys($watch['repeat']) as $targetUcid) {
                    $this->respond($message, $targetUcid);
                }
                $delivered = true;
            }

            if (!empty($watch['repeatConsole'])) {
                console('[AIControl] '.$message);
                $delivered = true;
            }

            $this->cleanupWatcher($hostId, $plid);
        }

        if (!$delivered) {
            console('[AIControl] '.$message);
        }

        return PLUGIN_CONTINUE;
    }

    private function parseInputValue($inputKey, $raw)
    {
        $result = array('value' => null, 'label' => null, 'error' => null);
        $rawLower = is_string($raw) ? strtolower($raw) : $raw;

        switch ($inputKey) {
            case 'steer':
                if (!is_numeric($rawLower)) {
                    $result['error'] = 'Steer expects a numeric value between -1..1 or 0..65535.';
                    break;
                }

                $value = (float)$rawLower;
                if ($value >= -1 && $value <= 1) {
                    $value = ($value + 1) * 32767.5;
                }

                $value = (int)round($value);

                if ($value < 0 || $value > 65535) {
                    $result['error'] = 'Steer value out of range (0-65535).';
                    break;
                }

                $result['value'] = $value;
                $result['label'] = (string)$value;
                break;

            case 'throttle':
            case 'brake':
            case 'clutch':
            case 'handbrake':
                if (!is_numeric($rawLower)) {
                    $result['error'] = ucfirst($inputKey).' expects a numeric value between 0..1 or 0..65535.';
                    break;
                }

                $value = (float)$rawLower;
                if ($value >= 0 && $value <= 1) {
                    $value = $value * 65535;
                }

                $value = (int)round($value);

                if ($value < 0 || $value > 65535) {
                    $result['error'] = ucfirst($inputKey).' value out of range (0-65535).';
                    break;
                }

                $result['value'] = $value;
                $result['label'] = (string)$value;
                break;

            case 'gear':
                if (!is_numeric($rawLower)) {
                    $result['error'] = 'Gear expects a numeric value (0=reverse, 1=neutral, 2=first...).';
                    break;
                }

                $value = (int)round($rawLower);
                if ($value < 0) {
                    $value = 0;
                }
                if ($value > 255) {
                    $value = 255;
                }

                $result['value'] = $value;
                $result['label'] = (string)$value;
                break;

            case 'look':
                if (is_numeric($rawLower)) {
                    $value = (int)$rawLower;
                } elseif (isset(self::$LOOK_MAP[$rawLower])) {
                    $value = self::$LOOK_MAP[$rawLower];
                } else {
                    $result['error'] = 'Look expects one of none/left/right/left+/right+ or a numeric value.';
                    break;
                }

                if ($value < 0) {
                    $value = 0;
                }
                if ($value > 7 && $value !== 255) {
                    $value = 7;
                }

                $result['value'] = $value;
                $result['label'] = isset(self::$LOOK_MAP[$rawLower]) ? $rawLower : (string)$value;
                break;

            case 'indicators':
                if (is_numeric($rawLower)) {
                    $value = (int)$rawLower;
                } elseif (isset(self::$INDICATOR_MAP[$rawLower])) {
                    $value = self::$INDICATOR_MAP[$rawLower];
                } else {
                    $result['error'] = 'Indicators expects off/left/right/hazard or a numeric value.';
                    break;
                }

                if ($value < 0) {
                    $value = 0;
                }
                if ($value > 4) {
                    $value = 4;
                }

                $result['value'] = $value;
                $result['label'] = isset(self::$INDICATOR_MAP[$rawLower]) ? $rawLower : (string)$value;
                break;

            case 'horn':
                if ($rawLower === 'off') {
                    $value = 0;
                } elseif (is_numeric($rawLower)) {
                    $value = (int)$rawLower;
                } else {
                    $result['error'] = 'Horn expects off or a horn number (1-5).';
                    break;
                }

                if ($value < 0) {
                    $value = 0;
                }
                if ($value > 5) {
                    $value = 5;
                }

                $result['value'] = $value;
                $result['label'] = ($value === 0 && $rawLower === 'off') ? 'off' : (string)$value;
                break;

            case 'siren':
                if (is_numeric($rawLower)) {
                    $value = (int)$rawLower;
                } elseif (isset(self::$SIREN_MAP[$rawLower])) {
                    $value = self::$SIREN_MAP[$rawLower];
                } else {
                    $result['error'] = 'Siren expects off/fast/slow or a numeric value (0-2).';
                    break;
                }

                if ($value < 0) {
                    $value = 0;
                }
                if ($value > 2) {
                    $value = 2;
                }

                $result['value'] = $value;
                $result['label'] = isset(self::$SIREN_MAP[$rawLower]) ? $rawLower : (string)$value;
                break;

            case 'flash':
                if ($rawLower === 'on' || $rawLower === 'hold' || $rawLower === 'start') {
                    $value = 1;
                    $label = 'on';
                } elseif ($rawLower === 'off' || $rawLower === 'release' || $rawLower === 'stop') {
                    $value = 0;
                    $label = 'off';
                } elseif (is_numeric($rawLower)) {
                    $value = (int)$rawLower ? 1 : 0;
                    $label = (string)$value;
                } else {
                    $result['error'] = 'Flash expects on/off or 0/1.';
                    break;
                }

                $result['value'] = $value;
                $result['label'] = $label;
                break;

            case 'lights':
            case 'pitlimiter':
            case 'tc':
            case 'ignition':
            case 'extralight':
            case 'fogfront':
            case 'fogrear':
                if (is_numeric($rawLower)) {
                    $value = (int)$rawLower;
                } elseif (isset(self::$TOGGLE_MAP[$rawLower])) {
                    $value = self::$TOGGLE_MAP[$rawLower];
                } else {
                    $result['error'] = ucfirst($inputKey).' expects on/off/toggle or a numeric value (0-3).';
                    break;
                }

                if ($value < 0) {
                    $value = 0;
                }
                if ($value > 3) {
                    $value = 3;
                }

                $result['value'] = $value;
                $result['label'] = isset(self::$TOGGLE_MAP[$rawLower]) ? $rawLower : (string)$value;
                break;

            default:
                if (!is_numeric($rawLower)) {
                    $result['error'] = ucfirst($inputKey).' expects a numeric value.';
                    break;
                }

                $value = (int)round($rawLower);
                if ($value < 0) {
                    $value = 0;
                }
                if ($value > 65535) {
                    $value = 65535;
                }

                $result['value'] = $value;
                $result['label'] = (string)$value;
                break;
        }

        return $result;
    }

    private function parseHoldTime($raw)
    {
        if ($raw === null || $raw === '') {
            return 0;
        }

        if (!is_numeric($raw)) {
            return null;
        }

        $seconds = (float)$raw;
        $hundredths = (int)round($seconds * 100);

        if ($seconds > 0 && $hundredths === 0) {
            $hundredths = 1;
        }

        if ($hundredths < 0) {
            $hundredths = 0;
        }

        if ($hundredths > 255) {
            $hundredths = 255;
        }

        return $hundredths;
    }

    private function sendAiInputs($plid, array $inputs, $ucid, $message = null)
    {
        $packet = IS_AIC();
        $packet->PLID((int)$plid);

        foreach ($inputs as $input) {
            if ($input instanceof AIInputVal) {
                $packet->addInput($input);
            }
        }

        if (empty($packet->Inputs)) {
            $this->respond('No valid AI inputs were queued.', $ucid);
            return;
        }

        $packet->send();

        if ($message !== null) {
            $this->respond($message, $ucid);
        }
    }

    private function respond($message, $ucid, $isConsole = false)
    {
        if ($ucid === null) {
            console('[AIControl] '.$message);
            return;
        }

        IS_MTC()->UCID($ucid)->Text('^3[AI]^7 '.$message)->Send();
    }

    private function ensureWatcher($hostId, $plid)
    {
        if (!isset($this->infoWatchers[$hostId])) {
            $this->infoWatchers[$hostId] = array();
        }

        if (!isset($this->infoWatchers[$hostId][$plid])) {
            $this->infoWatchers[$hostId][$plid] = array(
                'once'         => array(),
                'repeat'       => array(),
                'onceConsole'  => false,
                'repeatConsole'=> false,
            );
        }
    }

    private function cleanupWatcher($hostId, $plid)
    {
        if (!isset($this->infoWatchers[$hostId][$plid])) {
            return;
        }

        $watch = $this->infoWatchers[$hostId][$plid];
        if (empty($watch['once']) && empty($watch['repeat']) && !$watch['onceConsole'] && !$watch['repeatConsole']) {
            unset($this->infoWatchers[$hostId][$plid]);

            if (empty($this->infoWatchers[$hostId])) {
                unset($this->infoWatchers[$hostId]);
            }
        }
    }

    private function formatAiSummary(IS_AII $packet)
    {
        $speed = 0.0;
        if (isset($packet->OSData['Vel'])) {
            $vel = $packet->OSData['Vel'];
            $speed = sqrt(($vel['X'] * $vel['X']) + ($vel['Y'] * $vel['Y']) + ($vel['Z'] * $vel['Z'])) * 3.6;
        }

        $pos = array('X' => 0, 'Y' => 0, 'Z' => 0);
        if (isset($packet->OSData['Pos'])) {
            $pos = $packet->OSData['Pos'];
        }

        $gear = $this->formatGear($packet->Gear);
        $flags = $this->describeFlags($packet->Flags);

        return sprintf(
            'PLID %d | gear %s | rpm %.0f | speed %.1f km/h | pos (%.1f, %.1f, %.1f) | flags %s',
            $packet->PLID,
            $gear,
            $packet->RPM,
            $speed,
            $pos['X'] / 65536,
            $pos['Y'] / 65536,
            $pos['Z'] / 65536,
            $flags
        );
    }

    private function formatGear($gear)
    {
        if ($gear <= 0) {
            return 'R';
        }

        if ($gear === 1) {
            return 'N';
        }

        return (string)($gear - 1);
    }

    private function describeFlags($flags)
    {
        $bits = array();

        if ($flags & AIFLAGS_IGNITION) {
            $bits[] = 'ignition';
        }

        if ($flags & AIFLAGS_CHUP) {
            $bits[] = 'shift_up';
        }

        if ($flags & AIFLAGS_CHDN) {
            $bits[] = 'shift_down';
        }

        if (empty($bits)) {
            return 'none';
        }

        return implode(',', $bits);
    }
}
