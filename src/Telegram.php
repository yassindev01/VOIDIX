<?php
// src/Telegram.php

namespace App;

use MadelineProto\API;
use MadelineProto\Logger;
use MadelineProto\Settings;
use Exception;

class Telegram
{
    private $madelineProto;
    private $config;
    public $madelineSessionPath;

    public function __construct(string $userTelegramId, int $apiId = null, string $apiHash = null)
    {
        $this->config = Config::getInstance();

        $baseSessionPath = $this->config->get('MADELINE_SESSION_PATH');
        if (empty($baseSessionPath)) {
            throw new Exception('MADELINE_SESSION_PATH not configured in .env');
        }

        $userSessionDir = $baseSessionPath . '/' . $userTelegramId;
        if (!is_dir($userSessionDir)) {
            if (!mkdir($userSessionDir, 0777, true)) {
                throw new Exception('Failed to create MadelineProto user session directory: ' . $userSessionDir);
            }
        }
        $this->madelineSessionPath = $userSessionDir . '/' . $userTelegramId . '.madeline';

        // Use provided API ID/Hash or fallback to .env
        $telegramApiId = $apiId ?? (int) $this->config->get('TELEGRAM_API_ID');
        $telegramApiHash = $apiHash ?? $this->config->get('TELEGRAM_API_HASH');

        if (!$telegramApiId || !$telegramApiHash) {
            throw new Exception('Telegram API ID or Hash not provided and not configured in .env');
        }

        $settings = new Settings;
        $settings->setAppInfo([
            'api_id' => $telegramApiId,
            'api_hash' => $telegramApiHash,
        ]);
        $settings->setDb([
            'type' => 'mysql',
            'uri' => 'mysql://' . $this->config->get('DB_USER') . ':' . $this->config->get('DB_PASS') . '@' . $this->config->get('DB_HOST') . '/' . $this->config->get('DB_NAME'),
        ]);

        $this->madelineProto = new API($this->madelineSessionPath, $settings);
    }

    public function start(): void
    {
        $this->madelineProto->start();
    }

    public function getSelf(): array
    {
        return $this->madelineProto->getSelf();
    }

    public function getFullSelf(): array
    {
        return $this->madelineProto->getFullSelf();
    }

    public function getDialogs(): array
    {
        return $this->madelineProto->getDialogs();
    }

    public function getInfo($peer): array
    {
        return $this->madelineProto->getInfo($peer);
    }

    public function getDialogsCount(): int
    {
        return $this->madelineProto->getDialogsCount();
    }

    public function getChatCounts(): array
    {
        $dialogs = $this->madelineProto->getDialogs();
        $groups = 0;
        $channels = 0;
        $supergroups = 0;
        $bots = 0;
        $privateChats = 0;
        $totalDialogs = count($dialogs);

        foreach ($dialogs as $dialog) {
            try {
                $info = $this->madelineProto->getInfo($dialog);
                if ($info['bot']) {
                    $bots++;
                } elseif ($info['user']) {
                    $privateChats++;
                } elseif ($info['channel']) {
                    if ($info['supergroup']) {
                        $supergroups++;
                    } else {
                        $channels++;
                    }
                } elseif ($info['chat']) {
                    $groups++;
                }
            } catch (\Exception $e) { continue; }
        }

        return [
            'total_dialogs' => $totalDialogs,
            'groups' => $groups,
            'channels' => $channels,
            'supergroups' => $supergroups,
            'bots' => $bots,
            'private_chats' => $privateChats,
        ];
    }

    public function getProfilePhotoCount(array $fullUser): int
    {
        $photos = $fullUser['profile_photos']['photos'] ?? [];
        return count($photos);
    }

    public function logout(): void
    {
        $this->madelineProto->logout();
    }

    public function extractInputPeer($dialog): array
    {
        if (isset($dialog['peer']['_'])) {
            $type = $dialog['peer']['_'];
            if ($type === 'peerUser') {
                return ['_' => 'inputPeerUser', 'user_id' => $dialog['peer']['user_id'], 'access_hash' => 0];
            } elseif ($type === 'peerChat') {
                return ['_' => 'inputPeerChat', 'chat_id' => $dialog['peer']['chat_id']];
            } elseif ($type === 'peerChannel') {
                return ['_' => 'inputPeerChannel', 'channel_id' => $dialog['peer']['channel_id'], 'access_hash' => 0];
            }
        }
        return ['_' => 'inputPeerEmpty'];
    }

    public function __call($name, $arguments)
    {
        if (method_exists($this->madelineProto, $name)) {
            return call_user_func_array([$this->madelineProto, $name], $arguments);
        }
        throw new Exception("Method {$name} does not exist.");
    }
}
