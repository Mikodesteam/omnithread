<?php
/**
 * Excerpt from Omnithread (module/omnithread/libraries/Core/Channels/Meta/MetaWebhook.php). Shown for illustration only.
 * The full source code is available at https://store.mikodes.com/p/omnithread/
 *
 * Webhook security shared by WhatsApp, Messenger and Instagram: the verify-token handshake and the
 * X-Hub-Signature-256 HMAC check. All comparisons are constant-time, and the signature is also
 * accepted over the escaped-unicode form of the body that Meta documents.
 */

namespace Omnithread\Core\Channels\Meta;

defined('BASEPATH') or exit('No direct script access allowed');

use Omnithread\Core\Channels\ChannelConfig;
use Omnithread\Core\Channels\WebhookRequest;
use Omnithread\Core\Channels\WebhookResult;

/**
 * The part of a Meta webhook that is the same for WhatsApp, Messenger and Instagram:
 * the GET hub.challenge handshake with the verify token, and the X-Hub-Signature-256
 * check (HMAC-SHA256 of the body with the app secret). Both compares are constant-time.
 */
final class MetaWebhook
{
    /** A challenge is echoed into the answer only when it looks like one. */
    private const CHALLENGE = '/^[A-Za-z0-9._-]{1,128}$/';

    /**
     * Answer a handshake or refuse a request that is not correctly signed JSON;
     * otherwise give the decoded body for the driver to parse.
     *
     * @return WebhookResult|array<string, mixed>
     */
    public static function check(WebhookRequest $request, ChannelConfig $config): WebhookResult|array
    {
        if (strtoupper($request->method) === 'GET') {
            return self::handshake($request, $config->secret('verify_token'));
        }
        if (!self::signed($request, $config->secret('app_secret'))) {
            return WebhookResult::rejected(401, 'ot_err_webhook_signature');
        }
        $payload = json_decode($request->body, true);

        return is_array($payload) ? $payload : WebhookResult::rejected(400, 'ot_err_webhook_payload');
    }

    private static function handshake(WebhookRequest $request, string $verifyToken): WebhookResult
    {
        $given     = (string) ($request->query['hub.verify_token'] ?? '');
        $challenge = (string) ($request->query['hub.challenge'] ?? '');
        $valid     = ($request->query['hub.mode'] ?? '') === 'subscribe' && $verifyToken !== '' && hash_equals($verifyToken, $given);
        if (!$valid || preg_match(self::CHALLENGE, $challenge) !== 1) {
            return WebhookResult::rejected(403, 'ot_err_webhook_verify_token');
        }

        return new WebhookResult(200, $challenge);
    }

    /**
     * Meta signs the payload as sent; the Messenger docs add that the hash is computed over
     * the escaped-unicode form (ä, lowercase hex), so that form is accepted too.
     */
    private static function signed(WebhookRequest $request, string $appSecret): bool
    {
        $header = $request->header('X-Hub-Signature-256');
        if ($appSecret === '' || strncmp($header, 'sha256=', 7) !== 0) {
            return false;
        }
        $given = strtolower(substr($header, 7));
        foreach (array_unique([$request->body, self::escapeUnicode($request->body)]) as $candidate) {
            if (hash_equals(hash_hmac('sha256', $candidate, $appSecret), $given)) {
                return true;
            }
        }

        return false;
    }

    private static function escapeUnicode(string $body): string
    {
        return (string) preg_replace_callback('/[^\x00-\x7F]+/u', static function (array $m): string {
            $units = str_split((string) mb_convert_encoding($m[0], 'UTF-16BE', 'UTF-8'), 2);

            return implode('', array_map(static fn (string $u): string => sprintf('\\u%04x', (int) unpack('n', $u)[1]), $units));
        }, $body);
    }
}
