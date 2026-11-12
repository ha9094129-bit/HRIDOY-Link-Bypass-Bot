<?php
declare(strict_types=1);

/*
 * Telegram URL Expander Bot (safe redirect resolver)
 *
 * 1. Create a bot with @BotFather and copy its token.
 * 2. Put the token below.
 * 3. Host this file on PHP hosting with cURL enabled.
 * 4. Set Telegram webhook to: https://api.telegram.org/botYOUR_TOKEN/setWebhook?url=https://YOUR_HOST/bot.php
 *
 * This expands ordinary HTTP redirects. It does NOT bypass CAPTCHAs,
 * channel membership checks, login pages, paywalls, or other access controls.
 */

const BOT_TOKEN = 'PASTE_TELEGRAM_BOT_TOKEN_HERE';
const MAX_REDIRECTS = 10;
const REQUEST_TIMEOUT = 12;

function telegram(string $method, array $data): array {
    $url = 'https://api.telegram.org/bot' . BOT_TOKEN . '/' . $method;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $data,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => REQUEST_TIMEOUT,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    $decoded = json_decode((string)$response, true);
    return is_array($decoded) ? $decoded : [];
}

function validHttpUrl(string $url): bool {
    if (filter_var($url, FILTER_VALIDATE_URL) === false) return false;
    $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
    return in_array($scheme, ['http', 'https'], true);
}

/**
 * Follows normal HTTP redirect responses (301, 302, 303, 307, 308).
 * Does not execute JavaScript or solve human-verification challenges.
 */
function expandUrl(string $url): string {
    if (!validHttpUrl($url)) {
        throw new RuntimeException('Please send a valid http:// or https:// URL.');
    }

    $current = $url;
    for ($i = 0; $i < MAX_REDIRECTS; $i++) {
        $ch = curl_init($current);
        curl_setopt_array($ch, [
            CURLOPT_NOBODY => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => REQUEST_TIMEOUT,
            CURLOPT_USERAGENT => 'Telegram-URL-Expander/1.0',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $location = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        $headerLocation = '';
        // Read Location header explicitly, including when CURLOPT_NOBODY is used.
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        $headers = curl_exec($ch);
        if (is_string($headers) && preg_match('/^Location:\s*(.+)$/im', $headers, $m)) {
            $headerLocation = trim($m[1]);
        }
        curl_close($ch);

        if (!in_array($status, [301, 302, 303, 307, 308], true)) {
            return $current;
        }

        $next = $headerLocation !== '' ? $headerLocation : (is_string($location) ? $location : '');
        if ($next === '') return $current;

        // Resolve relative Location headers against the current URL.
        if (str_starts_with($next, '/')) {
            $parts = parse_url($current);
            $base = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '');
            if (isset($parts['port'])) $base .= ':' . $parts['port'];
            $next = $base . $next;
        } elseif (!preg_match('~^https?://~i', $next)) {
            $next = rtrim(dirname($current), '/') . '/' . $next;
        }

        if (!validHttpUrl($next)) {
            throw new RuntimeException('The redirect destination is not a valid HTTP(S) URL.');
        }
        $current = $next;
    }

    return $current; // Stop after the redirect limit.
}

$update = json_decode(file_get_contents('php://input') ?: '{}', true);
$message = $update['message'] ?? null;
if (!$message) exit;

$chatId = $message['chat']['id'] ?? null;
$text = trim((string)($message['text'] ?? ''));
if (!$chatId || $text === '') exit;

if ($text === '/start') {
    telegram('sendMessage', [
        'chat_id' => $chatId,
        'text' => "👋 Send me a normal shortened URL and I'll expand HTTP redirects to show the destination.\n\nI can't bypass CAPTCHAs, login checks, or other access controls.",
    ]);
    exit;
}

// Extract first URL from the user's message.
if (!preg_match('~https?://[^\s<>()]+~i', $text, $match)) {
    telegram('sendMessage', [
        'chat_id' => $chatId,
        'text' => 'Please send a link beginning with http:// or https://',
    ]);
    exit;
}

try {
    $original = rtrim($match[0], ".,!?;:");
    $destination = expandUrl($original);
    telegram('sendMessage', [
        'chat_id' => $chatId,
        'text' => "🔗 Submitted link:\n{$original}\n\n✅ Redirect destination:\n{$destination}",
        'disable_web_page_preview' => true,
    ]);
} catch (Throwable $e) {
    telegram('sendMessage', [
        'chat_id' => $chatId,
        'text' => '⚠️ ' . $e->getMessage(),
    ]);
}
