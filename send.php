<?php
declare(strict_types=1);

/**
 * Обработчик заявок с сайта.
 * Отправляет письмо на почту сервиса.
 *
 * Антиспам:
 *  - honeypot-поле: заполняют только боты (отбрасываем молча);
 *  - скорость заполнения: быстрее 3 секунд - бот;
 *  - валидация номера и e-mail;
 *  - rate limiting по IP: не более RATE_LIMIT_PER_HOUR заявок в час.
 * Fallback-заявки в leads/ старее LEADS_TTL_DAYS удаляются автоматически.
 */

header('Content-Type: application/json; charset=utf-8');

// Куда отправлять заявки
const TO_EMAIL = 'info@microdomservice.by';
const TO_NAME  = 'Микродом Сервис';

const MIN_SECONDS = 3;
const PHONE_RE = '/^(?:\+?375|80)\s?\(?\d{2}\)?[\s-]?\d{3}[\s-]?\d{2}[\s-]?\d{2}$/';

// Антиспам: лимит заявок с одного IP в час.
const RATE_LIMIT_PER_HOUR = 5;
const RATE_WINDOW_SEC = 3600;

// Fallback-заявки храним максимум столько дней (ПДн клиентов).
const LEADS_TTL_DAYS = 60;

function respondError(string $message, int $status = 200): void
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Rate limiting по IP (файловое хранилище в leads/rate/).
 * Возвращает true, если лимит исчерпан.
 */
function rateLimitExceeded(): bool
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $dir = __DIR__ . '/leads/rate';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $file = $dir . '/' . substr(hash('sha256', $ip), 0, 20) . '.json';

    $data = ['count' => 0, 'window' => time()];
    $raw = @file_get_contents($file);
    if ($raw !== false) {
        $parsed = json_decode($raw, true);
        if (is_array($parsed) && isset($parsed['count'], $parsed['window'])) {
            $data = $parsed;
        }
    }

    if (time() - (int)$data['window'] > RATE_WINDOW_SEC) {
        $data = ['count' => 0, 'window' => time()];
    }
    $data['count'] = (int)$data['count'] + 1;

    @file_put_contents($file, json_encode($data), LOCK_EX);
    return $data['count'] > RATE_LIMIT_PER_HOUR;
}

/**
 * Периодически чистит fallback-заявки старше LEADS_TTL_DAYS.
 * Проверка не чаще раза в сутки (маркер .cleanup).
 */
function cleanupOldLeads(int $days): void
{
    $dir = __DIR__ . '/leads';
    $marker = $dir . '/.cleanup';
    if (is_file($marker) && time() - (int)@file_get_contents($marker) < 86400) {
        return;
    }
    @file_put_contents($marker, (string)time());

    $cutoff = time() - $days * 86400;
    foreach (glob($dir . '/lead_*.txt') ?: [] as $f) {
        if (@filemtime($f) < $cutoff) {
            @unlink($f);
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respondError('Только POST-запросы.');
}

function clean(string $key): string
{
    $v = $_POST[$key] ?? '';
    return trim(strip_tags($v));
}

// 1. Honeypot: поле «company» заполняют только боты.
//    Отвечаем «успехом», чтобы бот не долбил повторно.
if (clean('company') !== '') {
    echo json_encode(['ok' => true]);
    exit;
}

// 2. Скорость заполнения: меньше MIN_SECONDS секунд - бот.
if (isset($_POST['start'], $_POST['sent_at'])) {
    $elapsed = ((int)$_POST['sent_at'] - (int)$_POST['start']) / 1000;
    if ($elapsed < MIN_SECONDS) {
        echo json_encode(['ok' => true]);
        exit;
    }
}

// 3. Rate limiting: после honeypot и проверки скорости,
//    чтобы не считать бототрафик, но блокировать повторные всплески.
if (rateLimitExceeded()) {
    respondError('Слишком много заявок за короткое время. Попробуйте позже или позвоните нам.', 429);
}

// 4. CSRF (double-submit): значение в скрытом поле формы должно совпадать
//    с cookie csrf_token, который выдал token.php. Защита от отправки с чужих сайтов.
if (!isset($_COOKIE['csrf_token'], $_POST['csrf'])
    || !is_string($_POST['csrf'])
    || !hash_equals($_COOKIE['csrf_token'], $_POST['csrf'])) {
    respondError('Сессия устарела. Обновите страницу и попробуйте снова.', 403);
}

$name    = clean('name');
$phone   = clean('phone');
$email   = clean('email');
$device  = clean('device');
$message = clean('message');

// 5. Валидация
if ($name === '' || mb_strlen($name) > 100) {
    respondError('Укажите ваше имя.');
}

if (!preg_match(PHONE_RE, $phone) || mb_strlen($phone) > 30) {
    respondError('Укажите корректный номер, например +375 (29) 123-45-67.');
}

if ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 100)) {
    respondError('Проверьте формат e-mail.');
}

if (mb_strlen($device) > 120 || mb_strlen($message) > 2000) {
    respondError('Слишком длинный текст.');
}

// 6. Формируем письмо
$subject = 'Новая заявка с сайта: ' . ($name !== '' ? $name : 'без имени');

$bodyLines = [
    'Имя: ' . ($name !== '' ? $name : '-'),
    'Телефон: ' . $phone,
    'E-mail: ' . ($email !== '' ? $email : '-'),
    'Модель ноутбука: ' . ($device !== '' ? $device : '-'),
    'Проблема: ' . ($message !== '' ? $message : '-'),
    '',
    'Отправлено: ' . date('d.m.Y H:i'),
    'IP: ' . ($_SERVER['REMOTE_ADDR'] ?? '-'),
];
$body = implode("\r\n", $bodyLines);

require __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$mail = new PHPMailer(true);

// Настройки SMTP из переменных окружения (для контейнера/Mailpit и хостинга).
// На хостинге можно оставить пустыми - тогда используем встроенный mail().
$smtpHost = getenv('SMTP_HOST') ?: '';
$smtpPort = getenv('SMTP_PORT') ?: 587;
$smtpUser = getenv('SMTP_USER') ?: '';
$smtpPass = getenv('SMTP_PASS') ?: '';

// Нормализуем домен отправителя: убираем порт и localhost (для email-адреса)
$hostRaw = $_SERVER['HTTP_HOST'] ?? 'microdom.by';
$host = preg_replace('/:\d+$/', '', $hostRaw);
$host = (preg_match('/^[a-z0-9.-]+$/i', $host) && !preg_match('/^(localhost|127\.0\.0\.1|\d+\.\d+\.\d+\.\d+)$/', $host))
    ? $host
    : 'microdom.by';
$fromEmail = 'no-reply@' . $host;

$sent = false;
if ($smtpHost !== '') {
    try {
        $mail->isSMTP();
        $mail->Host = $smtpHost;
        $mail->SMTPAuth = $smtpUser !== '';
        if ($mail->SMTPAuth) {
            $mail->Username = $smtpUser;
            $mail->Password = $smtpPass;
        }
        $mail->Port = (int)$smtpPort;
        $mail->CharSet = 'UTF-8';
        $mail->setFrom($fromEmail, TO_NAME);
        $mail->addAddress(TO_EMAIL, TO_NAME);
        if ($email !== '') {
            $mail->addReplyTo($email);
        }
        $mail->Subject = $subject;
        $mail->Body = $body;
        $sent = $mail->send();
    } catch (Exception $e) {
        error_log('PHPMailer: ' . $mail->ErrorInfo);
        $sent = false;
    }
} else {
    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=utf-8',
        'From: ' . TO_NAME . ' <' . $fromEmail . '>',
        'Reply-To: ' . ($email !== '' ? $email : $phone),
        'X-Mailer: PHP/' . PHP_VERSION,
    ];
    $sent = @mail(TO_EMAIL, $subject, $body, implode("\r\n", $headers));
}

// Если почта не отправлена - сохраняем заявку в файлы leads/ как fallback.
if (!$sent) {
    $dir = __DIR__ . '/leads';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $file = $dir . '/lead_' . date('Y-m-d_H-i-s') . '_' . bin2hex(random_bytes(3)) . '.txt';
    $saved = @file_put_contents($file, "Тема: $subject\r\n$body\r\n");
}

// Чистим старые fallback-заявки (не чаще раза в сутки).
cleanupOldLeads(LEADS_TTL_DAYS);

if ($sent || !empty($saved)) {
    echo json_encode(['ok' => true]);
} else {
    respondError('Не удалось отправить письмо. Попробуйте позже или позвоните нам.');
}