<?php
// api/index.php

// المسار الآن أصبح خطوة واحدة للخلف للوصول لـ vendor
require_once __DIR__ . '/../vendor/autoload.php';

use App\Config;
use App\Database;
use App\Auth;
use App\Telegram;
use App\CleanEngine;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use MadelineProto\RPCErrorException;

// تحميل الإعدادات من المجلد الرئيسي
$config = Config::getInstance();
header('Content-Type: application/json');

function handleError($message, $statusCode = 500) {
    http_response_code($statusCode);
    echo json_encode(['success' => false, 'message' => $message]);
    exit();
}

$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$requestMethod = $_SERVER['REQUEST_METHOD'];

// تعديل المسار البرمجي ليتناسب مع الهيكل الجديد
// إذا كان الطلب يأتي لـ /api/send-code سيبقى كما هو
$input = json_decode(file_get_contents('php://input'), true);

try {
    $database = Database::getInstance();
    $auth = new Auth($database);
} catch (Exception $e) {
    handleError('Initialization failed: ' . $e->getMessage());
}

$telegram = null;

function authenticateJwt($authInstance, $databaseInstance, &$telegramInstance) {
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? '';

    if (empty($authHeader) || !preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
        handleError('Authorization header missing.', 401);
    }

    $jwt = $matches[1];
    $decodedJwt = $authInstance->validateJwtToken($jwt);

    if (!$decodedJwt) {
        handleError('Invalid token.', 401);
    }

    $session = $authInstance->getSessionByHash($jwt);
    if (!$session || strtotime($session['expires_at']) < time()) {
        handleError('Session expired.', 401);
    }

    $pdo = $databaseInstance->getConnection();
    $stmt = $pdo->prepare("SELECT telegram_id, api_id, api_hash FROM users WHERE id = ?");
    $stmt->execute([$decodedJwt->uid]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        handleError('User not found.', 404);
    }

    try {
        $telegramInstance = new Telegram((string) $user['telegram_id'], (int) $user['api_id'], $user['api_hash']);
    } catch (Exception $e) {
        handleError('Telegram failed: ' . $e->getMessage());
    }

    return $decodedJwt;
}

// استخراج اسم العملية من المسار (مثلاً send-code من /api/send-code)
$endpoint = str_replace('/api/', '', $requestUri);

switch ($endpoint) {
    case 'send-code':
        if ($requestMethod === 'POST') {
            $phone = $input['phone_number'] ?? null;
            $apiId = $input['api_id'] ?? null;
            $apiHash = $input['api_hash'] ?? null;

            if (!$phone || !$apiId || !$apiHash) {
                handleError('Phone, API ID, and API Hash are required.', 400);
            }

            try {
                $tempId = 'login_' . preg_replace('/[^0-9]/', '', $phone);
                $telegram = new Telegram($tempId, (int)$apiId, $apiHash);
                $telegram->start();
                $telegram->phone_login($phone);
                echo json_encode(['success' => true, 'message' => 'Code sent!']);
            } catch (\Throwable $e) {
                handleError('Error: ' . $e->getMessage());
            }
        }
        break;

    case 'verify-code':
        if ($requestMethod === 'POST') {
            $code = $input['code'] ?? null;
            $phone = $input['phone_number'] ?? null;
            $apiId = $input['api_id'] ?? null;
            $apiHash = $input['api_hash'] ?? null;

            try {
                $tempId = 'login_' . preg_replace('/[^0-9]/', '', $phone);
                $telegram = new Telegram($tempId, (int)$apiId, $apiHash);
                $telegram->start();
                $authorization = $telegram->complete_phone_login($code);

                if (isset($authorization['user'])) {
                    $tgUser = $authorization['user'];
                    $tgUser['phone'] = $phone;

                    $userId = $auth->findOrCreateUser($tgUser, (int)$apiId, $apiHash);
                    
                    $finalTelegram = new Telegram((string)$tgUser['id'], (int)$apiId, $apiHash);
                    $jwtToken = $auth->generateJwtToken($userId, $finalTelegram->madelineSessionPath);

                    $decoded = JWT::decode($jwtToken, new Key($config->get('JWT_SECRET'), 'HS256'));
                    $auth->createSession($userId, $finalTelegram->madelineSessionPath, $jwtToken, $decoded->exp);

                    echo json_encode(['success' => true, 'token' => $jwtToken, 'user' => $tgUser]);
                }
            } catch (\Throwable $e) {
                handleError('Verify failed: ' . $e->getMessage());
            }
        }
        break;

    case 'account/info':
        $decoded = authenticateJwt($auth, $database, $telegram);
        try {
            $telegram->start();
            $fullSelf = $telegram->getFullSelf();
            echo json_encode(['success' => true, 'data' => [
                'first_name' => $fullSelf['first_name'] ?? null,
                'last_name' => $fullSelf['last_name'] ?? null,
                'username' => $fullSelf['username'] ?? null,
                'bio' => $fullSelf['about'] ?? null,
                'account_id' => $fullSelf['id'] ?? null,
                'profile_photo_count' => $telegram->getProfilePhotoCount($fullSelf),
            ]]);
        } catch (\Throwable $e) { handleError($e->getMessage()); }
        break;

    case 'account/stats':
        $decoded = authenticateJwt($auth, $database, $telegram);
        try {
            $telegram->start();
            echo json_encode(['success' => true, 'data' => $telegram->getChatCounts()]);
        } catch (\Throwable $e) { handleError($e->getMessage()); }
        break;

    case 'account/dialogs':
        $decoded = authenticateJwt($auth, $database, $telegram);
        try {
            $telegram->start();
            echo json_encode(['success' => true, 'data' => array_values($telegram->getDialogs())]);
        } catch (\Throwable $e) { handleError($e->getMessage()); }
        break;

    case 'account/clean':
        $decoded = authenticateJwt($auth, $database, $telegram);
        try {
            $cleanEngine = new CleanEngine($telegram, $auth, $database, $decoded->uid);
            $jobId = $cleanEngine->startCleanJob($input['options'] ?? []);
            $cleanEngine->runCleanJob($input['options'] ?? []);
            echo json_encode(['success' => true, 'job_id' => $jobId]);
        } catch (\Throwable $e) { handleError($e->getMessage()); }
        break;

    case 'logout':
        $decoded = authenticateJwt($auth, $database, $telegram);
        $headers = getallheaders();
        $jwt = str_replace('Bearer ', '', $headers['Authorization'] ?? '');
        $auth->invalidateSession($jwt);
        try {
            $telegram->logout();
            echo json_encode(['success' => true]);
        } catch (\Throwable $e) { handleError($e->getMessage()); }
        break;

    default:
        handleError('Not Found: ' . $endpoint, 404);
        break;
}
