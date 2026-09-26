<?php
namespace WaChat;

interface Sender
{
    public function send(array $account, string $recipient, string $body, int $outboxId): array;
}

final class GraphClient implements Sender
{
    private string $version;
    public function __construct(string $version)
    {
        if (!preg_match('/^v[0-9]+\.[0-9]+$/D', $version)) { throw new \InvalidArgumentException('Invalid Graph version'); }
        $this->version=$version;
    }

    public function send(array $account, string $recipient, string $body, int $outboxId): array
    {
        $payload = ['messaging_product'=>'whatsapp', 'to'=>$recipient, 'type'=>'text',
            'text'=>['body'=>$body, 'preview_url'=>false], 'biz_opaque_callback_data'=>'wa:' . $outboxId];
        $curl = curl_init('https://graph.facebook.com/' . $this->version . '/' . $account['phone_number_id'] . '/messages');
        curl_setopt_array($curl, [CURLOPT_POST=>true, CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_HTTPHEADER=>['Authorization: Bearer ' . $account['token'], 'Content-Type: application/json'],
            CURLOPT_POSTFIELDS=>json_encode($payload, JSON_THROW_ON_ERROR),
            CURLOPT_CONNECTTIMEOUT=>5, CURLOPT_TIMEOUT=>15, CURLOPT_FOLLOWLOCATION=>false]);
        $raw = curl_exec($curl);
        $errno = curl_errno($curl);
        $http = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        unset($curl);
        return self::classify($http, $errno, $raw);
    }

    public static function classify(int $http, int $errno, $raw): array
    {
        // Do not persist raw responses: upstream diagnostics can contain sensitive values.
        if ($raw === false || $errno) { return ['state'=>'uncertain','error'=>'transport_' . $errno]; }
        $data = json_decode($raw, true);
        if ($http >= 200 && $http < 300 && !empty($data['messages'][0]['id'])) {
            return ['state'=>'accepted','wamid'=>$data['messages'][0]['id']];
        }
        $code = (int)($data['error']['code'] ?? 0);
        if ($code && ($http === 429 || !empty($data['error']['is_transient']) || in_array($code, [4,17,32,613,130429,131056], true))) {
            return ['state'=>'retry','error'=>'meta_' . $code];
        }
        // A definite Graph rejection is safe to surface as failed; malformed/5xx replies are ambiguous.
        if ($code && $http >= 400 && $http < 500) { return ['state'=>'failed','error'=>'meta_' . $code]; }
        return ['state'=>'uncertain','error'=>'http_' . $http];
    }
}
