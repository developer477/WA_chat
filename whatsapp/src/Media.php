<?php
namespace WaChat;

final class MediaFailure extends \RuntimeException
{
    public string $reason;
    public bool $retryable;
    public function __construct(string $reason, bool $retryable = false)
    {
        parent::__construct($reason);
        $this->reason=$reason; $this->retryable=$retryable;
    }
}

interface MediaDownloader
{
    public function download(array $account, string $id, string $path, int $limit): array;
}

final class Media
{
    private Database $db;
    private array $config;
    private MediaDownloader $downloader;
    public function __construct(Database $db, array $config, MediaDownloader $downloader)
    {
        $this->db=$db; $this->config=$config; $this->downloader=$downloader;
    }

    public static function supports(array $message): bool
    {
        return in_array($message['type'] ?? '', ['image','document','audio','video','sticker'], true);
    }

    public static function extension(string $mime): ?string
    {
        // Never use a customer-supplied filename as a path or executable extension.
        return [
            'image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp',
            'audio/aac'=>'aac','audio/mp4'=>'m4a','audio/mpeg'=>'mp3',
            'audio/amr'=>'amr','audio/ogg'=>'ogg','video/mp4'=>'mp4','video/3gpp'=>'3gp',
            'application/pdf'=>'pdf','text/plain'=>'txt','application/msword'=>'doc',
            'application/vnd.ms-excel'=>'xls','application/vnd.ms-powerpoint'=>'ppt',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document'=>'docx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'=>'xlsx',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation'=>'pptx',
        ][strtolower(trim(explode(';',$mime)[0]))] ?? null;
    }

    public static function validHash(string $path, string $expected): bool
    {
        $actual=hash_file('sha256',$path);
        return hash_equals($actual,strtolower($expected)) || hash_equals(base64_encode(hex2bin($actual)),$expected);
    }

    public function location(): array
    {
        $directory=trim((string)($this->db->one('SELECT sounds_web_directory FROM system_settings')['sounds_web_directory'] ?? ''),'/');
        if (!preg_match('/^[a-zA-Z0-9_-]+$/D',$directory)) { throw new MediaFailure('media_directory_configuration'); }
        $root=$this->config['media_web_root'] ?? null;
        if ($root === null) {
            // Works with both a checkout under the web root and a symlinked deployment.
            $root=realpath($this->config['chat_directory']);
            while ($root && !is_dir($root.'/'.$directory)) {
                $parent=dirname($root);
                if ($parent===$root) { $root=false; break; }
                $root=$parent;
            }
        }
        $root=$root ? realpath($root) : false;
        if (!$root || !is_dir($root.'/'.$directory)) { throw new MediaFailure('media_directory_configuration'); }
        return [$root.'/'.$directory.'/wa_media','/'.$directory.'/wa_media'];
    }

    public function html(array $message, array $account): string
    {
        $type=$message['type']; $media=$message[$type] ?? [];
        $id=(string)($media['id'] ?? '');
        if (!ctype_digit($id)) { throw new MediaFailure('media_invalid_id'); }
        $extension=self::extension((string)($media['mime_type'] ?? ''));
        if (!$extension) { throw new MediaFailure('media_unsupported_type'); }
        $hash=(string)($media['sha256'] ?? '');
        if ($hash==='') { throw new MediaFailure('media_missing_hash'); }
        [$directory,$url]= $this->location();
        if (!is_dir($directory) && !@mkdir($directory,0755,true) && !is_dir($directory)) {
            throw new MediaFailure('media_directory_permissions',true);
        }
        $rules="Options -Indexes\n<FilesMatch \"^\\.\">\nRequire all denied\n</FilesMatch>\n<IfModule mod_headers.c>\nHeader set X-Content-Type-Options nosniff\nHeader set Content-Disposition attachment\n</IfModule>\n";
        if (!is_file($directory.'/.htaccess') && file_put_contents($directory.'/.htaccess',$rules)===false) {
            throw new MediaFailure('media_directory_permissions',true);
        }
        $name=hash('sha256',$account['phone_number_id'].':'.$id.':'.$hash).'.'.$extension;
        $path=$directory.'/'.$name;
        if (!is_file($path) || !self::validHash($path,$hash)) {
            $temporary=tempnam($directory,'.download-');
            if ($temporary===false) { throw new MediaFailure('media_directory_permissions',true); }
            try {
                $limit=max(1,min(104857600,(int)($this->config['media_max_bytes'] ?? 104857600)));
                $result=$this->downloader->download($account,$id,$temporary,$limit);
                if (self::extension($result['mime_type'] ?? '')!==$extension) { throw new MediaFailure('media_type_mismatch'); }
                if (!self::validHash($temporary,$hash)) { throw new MediaFailure('media_hash_mismatch',true); }
                if (!chmod($temporary,0644) || !rename($temporary,$path)) { throw new MediaFailure('media_storage_failure',true); }
            } finally { if (is_file($temporary)) { unlink($temporary); } }
        }
        $label=(string)($media['filename'] ?? ('WhatsApp '.$type));
        $html='<a href="'.Protocol::customerHtml($url.'/'.$name).'" target="_blank" rel="noopener">'.Protocol::customerHtml($label).'</a>';
        if (!empty($media['caption'])) { $html.='<br>'.Protocol::customerHtml($media['caption']); }
        return $html;
    }
}
