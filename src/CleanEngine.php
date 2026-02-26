<?php
// src/CleanEngine.php

namespace App;

use MadelineProto\API;
use MadelineProto\RPCErrorException;
use Exception;

class CleanEngine
{
    private $telegram;
    private $auth;
    private $database;
    private $userId;
    private $jobId;
    private $stopRequested = false;

    // Configuration for delays and batch sizes to prevent FloodWait
    const ACTION_DELAY_SECONDS = 1; // Delay between individual actions (e.g., leaving a group)
    const BATCH_SIZE = 10;          // Number of actions to perform before a longer pause

    public function __construct(Telegram $telegram, Auth $auth, Database $database, int $userId)
    {
        $this->telegram = $telegram;
        $this->auth = $auth;
        $this->database = $database;
        $this->userId = $userId;
    }

    /**
     * Initiates a clean job.
     * @param array $options An array of cleaning options (e.g., 'leave_groups' => true)
     * @return int The ID of the created job.
     */
    public function startCleanJob(array $options = []): int
    {
        $pdo = $this->database->getConnection();
        $stmt = $pdo->prepare(
            "INSERT INTO clean_jobs (user_id, job_type, status, progress) VALUES (?, ?, ?, ?)"
        );

        $jobType = json_encode($options); // Store options as job type for simplicity or define specific types
        $progress = json_encode(['status' => 'initialized', 'current_step' => '', 'total_items' => 0, 'processed_items' => 0]);

        $stmt->execute([$this->userId, 'void_clean', 'pending', $progress]);
        $this->jobId = $pdo->lastInsertId();

        return $this->jobId;
    }

    /**
     * Runs the actual cleaning process.
     * @param array $options
     */
    public function runCleanJob(array $options): void
    {
        $this->updateJobStatus('in_progress');
        $this->telegram->start(); // Ensure MadelineProto is started for the operations

        try {
            if ($options['leave_groups'] ?? false) {
                $this->leaveGroups();
            }
            if ($options['leave_channels'] ?? false) {
                $this->leaveChannels();
            }
            if ($options['delete_bot_chats'] ?? false) {
                $this->deleteBotChats();
            }
            if ($options['delete_private_chats'] ?? false) {
                $this->deletePrivateChats();
            }
            if ($options['clear_archive'] ?? false) {
                $this->clearArchive();
            }
            if ($options['remove_profile_photos'] ?? false) {
                $this->removeProfilePhotos();
            }
            if ($options['clear_bio'] ?? false) {
                $this->clearBio();
            }

            $this->updateJobStatus('completed');
        } catch (RPCErrorException $e) {
            $this->updateJobStatus('failed', ['error_message' => 'Telegram Error: ' . $e->getMessage()]);
            throw $e; // Re-throw to inform the caller
        } catch (Exception $e) {
            $this->updateJobStatus('failed', ['error_message' => $e->getMessage()]);
            throw $e;
        }
    }

    private function updateJobStatus(string $status, array $data = []): void
    {
        $pdo = $this->database->getConnection();
        $updateFields = ['status = ?'];
        $params = [$status];

        // Retrieve existing progress to merge
        $stmt = $pdo->prepare("SELECT progress FROM clean_jobs WHERE id = ?");
        $stmt->execute([$this->jobId]);
        $existingProgress = $stmt->fetchColumn();
        $progressData = json_decode($existingProgress, true) ?? [];


        if (isset($data['current_step'])) {
            $progressData['current_step'] = $data['current_step'];
        }
        if (isset($data['total_items'])) {
            $progressData['total_items'] = $data['total_items'];
        }
        if (isset($data['processed_items'])) {
            $progressData['processed_items'] = $data['processed_items'];
        }

        $updateFields[] = 'progress = ?';
        $params[] = json_encode($progressData);

        if (isset($data['error_message'])) {
            $updateFields[] = 'error_message = ?';
            $params[] = $data['error_message'];
        }
        if ($status === 'completed' || $status === 'failed' || $status === 'cancelled') {
            $updateFields[] = 'end_time = CURRENT_TIMESTAMP';
        }

        $sql = "UPDATE clean_jobs SET " . implode(', ', $updateFields) . " WHERE id = ?";
        $params[] = $this->jobId;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    }

    private function performActionWithDelay(callable $action, string $step, int $total, int &$processed): void
    {
        if ($this->stopRequested) {
            throw new Exception("Clean process stopped by user.");
        }
        $action(); // Perform the action first
        $processed++;
        $this->updateJobStatus('in_progress', [
            'current_step' => $step,
            'total_items' => $total,
            'processed_items' => $processed
        ]);
        sleep(self::ACTION_DELAY_SECONDS);
    }

    private function leaveGroups(): void
    {
        $dialogs = $this->telegram->getDialogs();
        $groupsToLeave = [];
        foreach ($dialogs as $dialog) {
            $info = $this->telegram->getInfo($dialog);
            if ($info['chat'] && !$info['bot']) { // Regular group, not a bot chat
                $groupsToLeave[] = $dialog;
            }
        }

        $total = count($groupsToLeave);
        $processed = 0;
        foreach ($groupsToLeave as $group) {
            $this->performActionWithDelay(function() use ($group) {
                // leaveChannel works for basic chats too
                $this->telegram->channels->leaveChannel(['channel' => $group]);
            }, 'Leaving group ' . (isset($group['title']) ? $group['title'] : 'ID: ' . $group['id']), $total, $processed);
        }
    }

    private function leaveChannels(): void
    {
        $dialogs = $this->telegram->getDialogs();
        $channelsToLeave = [];
        foreach ($dialogs as $dialog) {
            $info = $this->telegram->getInfo($dialog);
            // Channels or Supergroups (which are also channels in MP)
            if ($info['channel'] && ($info['supergroup'] || $info['gigagroup'])) { // Covers Supergroups and Gigagroups
                $channelsToLeave[] = $dialog;
            }
        }
        $total = count($channelsToLeave);
        $processed = 0;
        foreach ($channelsToLeave as $channel) {
            $this->performActionWithDelay(function() use ($channel) {
                $this->telegram->channels->leaveChannel(['channel' => $channel]);
            }, 'Leaving channel/supergroup ' . (isset($channel['title']) ? $channel['title'] : 'ID: ' . $channel['id']), $total, $processed);
        }
    }

    private function deleteBotChats(): void
    {
        $dialogs = $this->telegram->getDialogs();
        $botChatsToDelete = [];
        foreach ($dialogs as $dialog) {
            $info = $this->telegram->getInfo($dialog);
            if ($info['bot']) { // Check if it's a bot
                $botChatsToDelete[] = $dialog;
            }
        }

        $total = count($botChatsToDelete);
        $processed = 0;
        foreach ($botChatsToDelete as $botChat) {
            $this->performActionWithDelay(function() use ($botChat) {
                // For deleting chats, use messages.deleteHistory
                $this->telegram->messages->deleteHistory([
                    'peer' => $botChat,
                    'max_id' => 0, // 0 deletes all messages
                    'just_clear' => false, // false to delete, true to clear
                    'revoke' => true, // delete for everyone
                ]);
            }, 'Deleting bot chat with ' . (isset($botChat['title']) ? $botChat['title'] : 'ID: ' . $botChat['id']), $total, $processed);
        }
    }

    private function deletePrivateChats(): void
    {
        $dialogs = $this->telegram->getDialogs();
        $privateChatsToDelete = [];
        foreach ($dialogs as $dialog) {
            $info = $this->telegram->getInfo($dialog);
            if ($info['user'] && !$info['bot'] && !$info['self']) { // Private chat with a user, not a bot or self
                $privateChatsToDelete[] = $dialog;
            }
        }

        $total = count($privateChatsToDelete);
        $processed = 0;
        foreach ($privateChatsToDelete as $privateChat) {
            $this->performActionWithDelay(function() use ($privateChat) {
                $this->telegram->messages->deleteHistory([
                    'peer' => $privateChat,
                    'max_id' => 0,
                    'just_clear' => false,
                    'revoke' => true,
                ]);
            }, 'Deleting private chat with ' . (isset($privateChat['title']) ? $privateChat['title'] : 'ID: ' . $privateChat['id']), $total, $processed);
        }
    }

    public function clearArchive(): void
    {
        // MadelineProto doesn't have a direct "clear archive" method.
        // We need to fetch archived dialogs and then apply leave/delete logic.
        $archivedDialogs = $this->telegram->messages->getDialogs([
            'offset_id' => 0,
            'offset_date' => 0,
            'offset_peer' => ['_' => 'inputPeerEmpty'],
            'limit' => 100, // Fetch in batches
            'exclude_pinned' => false,
            'folder_id' => 1, // Folder ID for archived chats
        ]);

        $dialogsToClean = [];
        foreach ($archivedDialogs as $dialog) {
            // Need to convert 'peer' from dialog to proper 'inputPeer' for getInfo
            $inputPeer = $this->telegram->extractInputPeer($dialog);
            $info = $this->telegram->getInfo($inputPeer);

            if ($info['chat'] && !$info['bot']) {
                $dialogsToClean[] = $inputPeer;
            } elseif ($info['channel'] && ($info['supergroup'] || $info['gigagroup'])) {
                $dialogsToClean[] = $inputPeer;
            } elseif ($info['user'] && !$info['bot'] && !$info['self']) {
                $dialogsToClean[] = $inputPeer;
            }
        }

        $total = count($dialogsToClean);
        $processed = 0;
        foreach ($dialogsToClean as $dialog) {
            $info = $this->telegram->getInfo($dialog); // Get info again as it might be a different object

            if ($info['chat'] && !$info['bot']) { // Regular group
                $this->performActionWithDelay(function() use ($dialog) {
                    $this->telegram->channels->leaveChannel(['channel' => $dialog]);
                }, 'Leaving archived group ' . (isset($info['title']) ? $info['title'] : 'ID: ' . $info['id']), $total, $processed);
            } elseif ($info['channel'] && ($info['supergroup'] || $info['gigagroup'])) { // Channel or Supergroup
                $this->performActionWithDelay(function() use ($dialog) {
                    $this->telegram->channels->leaveChannel(['channel' => $dialog]);
                }, 'Leaving archived channel/supergroup ' . (isset($info['title']) ? $info['title'] : 'ID: ' . $info['id']), $total, $processed);
            } elseif ($info['user'] && !$info['bot'] && !$info['self']) { // Private chat
                $this->performActionWithDelay(function() use ($dialog) {
                    $this->telegram->messages->deleteHistory([
                        'peer' => $dialog,
                        'max_id' => 0,
                        'just_clear' => false,
                        'revoke' => true,
                    ]);
                }, 'Deleting archived private chat with ' . (isset($info['title']) ? $info['title'] : 'ID: ' . $info['id']), $total, $processed);
            }
        }
    }

    public function removeProfilePhotos(): void
    {
        $photos = $this->telegram->photos->getUserPhotos([
            'user_id' => $this->telegram->getSelf(), // Pass current user object
            'offset' => 0,
            'max_id' => 0,
            'limit' => 100 // Get up to 100 photos
        ]);

        $profilePhotos = $photos['photos'] ?? [];
        $total = count($profilePhotos);
        $processed = 0;

        foreach ($profilePhotos as $photo) {
            $this->performActionWithDelay(function() use ($photo) {
                // To remove a profile photo, we need its 'id' and 'access_hash'
                // This typically comes from a UserProfilePhoto object.
                // MadelineProto's `photos.updateProfilePhoto` can set a new one, but not directly remove existing ones by ID.
                // A common way to "remove" is to set a new (empty/default) one, or use `photos.deletePhotos` if it's available for profile photos.
                // For now, we'll assume setting a new empty one is sufficient for "removing".
                // However, the `updateProfilePhoto` method requires a 'photo' InputPhoto object, not just deleting.
                // A more direct removal of ALL profile photos might involve deleting each photo if a method exists,
                // or just setting the main profile photo to null/default, which isn't directly exposed as 'remove'.
                // Given the API, a "visual reset" might mean changing it to a generic avatar rather than truly deleting all history.
                // For direct deletion, `photos.deletePhotos` is for uploaded photos, not necessarily profile photos.
                // This is a complex operation with MadelineProto. For now, it's a placeholder.
                $this->telegram->photos->updateProfilePhoto(['id' => ['_' => 'inputPhotoEmpty']]); // Setting an empty photo, essentially removing it
            }, 'Removing profile photo', $total, $processed);
        }
    }

    public function clearBio(): void
    {
        $this->performActionWithDelay(function() {
            $this->telegram->account->updateProfile([
                'about' => '', // Clear the 'about' field (bio)
            ]);
        }, 'Clearing user bio', 1, $processed = 0);
    }

    public function requestStop(): void
    {
        $this->stopRequested = true;
    }
}
