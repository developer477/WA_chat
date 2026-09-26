<?php
namespace WaChat;

interface Sender
{
    public function send(array $account, string $recipient, string $body, int $outboxId): array;
}

final class GraphClient implements Sender, MediaDownloader
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

    public static function mediaUrlAllowed(string $url): bool
    {
        $parts=parse_url($url);
        $host=strtolower($parts['host'] ?? '');
        return ($parts['scheme'] ?? '')==='https' && !isset($parts['user']) && !isset($parts['pass'])
            && (!isset($parts['port']) || $parts['port']===443)
            && (bool)preg_match('/(^|\.)(facebook\.com|fbcdn\.net|fbsbx\.com|whatsapp\.net)$/D',$host);
    }

    public function download(array $account, string $id, string $path, int $limit): array
    {
        if (!ctype_digit($id)) { throw new MediaFailure('media_invalid_id'); }
        $url='https://graph.facebook.com/'.$this->version.'/'.$id.'?phone_number_id='.rawurlencode($account['phone_number_id']);
        $metadata=$this->mediaGet($url,$account['token'],null,65536);
        $data=json_decode($metadata,true);
        if (!is_array($data) || !self::mediaUrlAllowed($data['url'] ?? '')) { throw new MediaFailure('media_invalid_url'); }
        if ((int)($data['file_size'] ?? 0)>$limit) { throw new MediaFailure('media_too_large'); }
        $file=fopen($path,'wb');
        if ($file===false) { throw new MediaFailure('media_storage_failure',true); }
        try { $this->mediaGet($data['url'],$account['token'],$file,$limit); }
        finally { fclose($file); }
        return $data;
    }

    private function mediaGet(string $url, string $token, $file, int $limit): string
    {
        $body=''; $bytes=0; $tooLarge=false;
        $curl=curl_init($url);
        curl_setopt_array($curl,[CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token],
            CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>30,CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
            CURLOPT_WRITEFUNCTION=>function ($curl,string $chunk) use ($file,$limit,&$body,&$bytes,&$tooLarge): int {
                $bytes+=strlen($chunk);
                if ($bytes>$limit) { $tooLarge=true; return 0; }
                if ($file!==null) { return (int)fwrite($file,$chunk); }
                $body.=$chunk; return strlen($chunk);
            }]);
        $ok=curl_exec($curl); $errno=curl_errno($curl); $http=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE);
        unset($curl);
        if ($tooLarge) { throw new MediaFailure('media_too_large'); }
        if ($ok===false || $errno) { throw new MediaFailure('media_transport',true); }
        if ($http<200 || $http>=300) {
            throw new MediaFailure('media_http_'.$http,$http===429 || $http>=500 || $http===404);
        }
        return $body;
    }
}
