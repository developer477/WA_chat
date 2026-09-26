<?php
namespace WaChat;

final class Protocol
{
    public static function signature(string $body, string $signature, string $secret): bool
    {
        return $secret !== '' && hash_equals('sha256=' . hash_hmac('sha256', $body, $secret), $signature);
    }

    public static function expired(int $lastInbound, int $now): bool
    {
        return $now >= $lastInbound + 86400;
    }

    public static function messageText(array $message): string
    {
        $type = $message['type'] ?? 'unknown';
        if ($type === 'text') { return (string)($message['text']['body'] ?? ''); }
        if ($type === 'button') { return (string)($message['button']['text'] ?? '[WhatsApp button]'); }
        if ($type === 'interactive') {
            return (string)($message['interactive']['button_reply']['title']
                ?? $message['interactive']['list_reply']['title'] ?? '[WhatsApp interactive reply]');
        }
        $caption = $message[$type]['caption'] ?? '';
        return '[WhatsApp ' . preg_replace('/[^a-z_]/', '', $type) .
            ' message: native media is not supported yet]' . ($caption !== '' ? "\n" . $caption : '');
    }

    public static function customerHtml(string $text): string
    {
        // Existing chat renders stored messages as HTML. Never store executable customer HTML.
        $html = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = str_replace('|', '&#124;', $html);
        // Older VICIdial tables use three-byte utf8. Keep emoji losslessly as HTML entities.
        return mb_encode_numericentity($html, [0x10000, 0x10FFFF, 0, 0xFFFFFF], 'UTF-8');
    }

    public static function customerName(string $name, int $limit = 50): string
    {
        $name = mb_substr($name, 0, $limit, 'UTF-8');
        while (strlen(self::customerHtml($name)) > $limit) {
            $name = mb_substr($name, 0, mb_strlen($name, 'UTF-8') - 1, 'UTF-8');
        }
        return self::customerHtml($name);
    }

    public static function whatsappText(string $html): string
    {
        $html = preg_replace('~<(script|style)\b[^>]*>.*?</\1>~is', '', $html);
        $html = preg_replace('~<br\s*/?>|</(?:p|div|li)>~i', "\n", $html);
        $html = preg_replace_callback('~<a\b[^>]*href=[\'"]([^\'"]+)[\'"][^>]*>(.*?)</a>~is', function ($m) {
            $label = strip_tags($m[2]);
            $url = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (!preg_match('~^https?://~i', $url)) { return $label; }
            return html_entity_decode($label, ENT_QUOTES | ENT_HTML5, 'UTF-8') === $url ? $url : "$label ($url)";
        }, $html);
        return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    public static function chunks(string $text, int $size = 4000): array
    {
        $parts = [];
        for ($i = 0; $i < mb_strlen($text, 'UTF-8'); $i += $size) {
            $parts[] = mb_substr($text, $i, $size, 'UTF-8');
        }
        return $parts;
    }

    public static function delivery(string $current, string $incoming): string
    {
        $rank = [''=>0, 'sent'=>1, 'failed'=>2, 'delivered'=>3, 'read'=>4];
        return ($rank[$incoming] ?? -1) > ($rank[$current] ?? 0) ? $incoming : $current;
    }

    public static function events(array $payload): array
    {
        $events = [];
        if (($payload['object'] ?? '') !== 'whatsapp_business_account') { return []; }
        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                if (($change['field'] ?? '') !== 'messages') { continue; }
                $value = $change['value'] ?? [];
                $phone = (string)($value['metadata']['phone_number_id'] ?? '');
                if ($phone === '') { continue; }
                $names = [];
                foreach ($value['contacts'] ?? [] as $contact) {
                    $names[$contact['wa_id'] ?? ''] = $contact['profile']['name'] ?? 'WhatsApp customer';
                }
                foreach ($value['messages'] ?? [] as $message) {
                    $id = (string)($message['id'] ?? '');
                    $sender = (string)($message['from'] ?? '');
                    if ($id === '' || !preg_match('/^[0-9]{5,18}$/D', $sender)
                        || !ctype_digit((string)($message['timestamp'] ?? ''))) {
                        throw new \InvalidArgumentException('Unsupported WhatsApp sender or message envelope');
                    }
                    $message['_name'] = $names[$sender] ?? 'WhatsApp customer';
                    $events[] = ['phone'=>$phone, 'kind'=>'message', 'key'=>hash('sha256', "$phone:message:$id"), 'payload'=>$message];
                }
                foreach ($value['statuses'] ?? [] as $status) {
                    if (empty($status['id']) || empty($status['status'])) { continue; }
                    $events[] = ['phone'=>$phone, 'kind'=>'status',
                        'key'=>hash('sha256', $phone . ':status:' . $status['id'] . ':' . $status['status'] . ':' . ($status['timestamp'] ?? '')),
                        'payload'=>$status];
                }
            }
        }
        return $events;
    }
}
