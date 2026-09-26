<?php
namespace WaChat;

final class Database
{
    public \mysqli $link;
    private array $schemas = [];

    public function __construct(\mysqli $link)
    {
        $this->link = $link;
        $link->set_charset('utf8mb4');
        // Do not change the connection time zone: legacy chat timestamps use server time.
        $this->run("SET SESSION sql_mode = CONCAT_WS(',', @@sql_mode, 'STRICT_ALL_TABLES')");
    }

    public function run(string $sql, array $params = []): \mysqli_stmt
    {
        $stmt = $this->link->prepare($sql);
        if ($params) {
            $types = str_repeat('s', count($params));
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        return $stmt;
    }

    public function all(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public function one(string $sql, array $params = []): ?array
    {
        return $this->run($sql, $params)->get_result()->fetch_assoc();
    }

    public function timestamp(int $epoch): string
    {
        return $this->one('SELECT FROM_UNIXTIME(?) AS dt', [$epoch])['dt'];
    }

    public static function ident(string $name): string
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $name)) {
            throw new \RuntimeException('Invalid SQL identifier');
        }
        return '`' . $name . '`';
    }

    public function columns(string $table): array
    {
        if (!isset($this->schemas[$table])) {
            $this->schemas[$table] = array_column($this->all('SHOW COLUMNS FROM ' . self::ident($table)), null, 'Field');
        }
        return $this->schemas[$table];
    }

    public function primary(string $table): string
    {
        $cols = array_filter($this->columns($table), fn($c) => $c['Key'] === 'PRI');
        if (count($cols) !== 1) {
            throw new \RuntimeException("$table needs a single primary key");
        }
        return array_key_first($cols);
    }

    public function insert(string $table, array $values): void
    {
        $columns = implode(',', array_map([self::class, 'ident'], array_keys($values)));
        $marks = implode(',', array_fill(0, count($values), '?'));
        $this->run('INSERT INTO ' . self::ident($table) . " ($columns) VALUES ($marks)", array_values($values));
    }

    /** Reserve a native primary key in a durable journal BEFORE inserting.
     * Table locks also serialize ordinary agent inserts; no MyISAM rollback is assumed.
     * If a crash lets another writer take a reserved ID, allocate a new one on replay.
     */
    public function nativeInsert(string $key, string $table, array $values, ?string $archive = null): int
    {
        $pk = $this->primary($table);
        $t = self::ident($table);
        $p = self::ident($pk);
        $locks = "$t WRITE, wa_vici_operations WRITE";
        if ($archive) {
            $locks .= ', ' . self::ident($archive) . ' READ';
        }
        $this->run('LOCK TABLES ' . $locks);
        try {
            $op = $this->one('SELECT * FROM wa_vici_operations WHERE operation_key=?', [$key]);
            if ($op && $op['target_table'] !== $table) {
                throw new \RuntimeException('Operation target mismatch');
            }
            if ($op && $op['state'] === 'done') {
                return (int)$op['target_id'];
            }
            if ($op) {
                $values = json_decode($op['row_json'], true, 512, JSON_THROW_ON_ERROR);
                $id = (int)$op['target_id'];
                $existing = $this->one("SELECT * FROM $t WHERE $p=?", [$id]);
                if (!$existing && $archive) {
                    $existing = $this->one('SELECT * FROM ' . self::ident($archive) . " WHERE $p=?", [$id]);
                }
                // For chat/lead rows the agent may already have changed status/name.
                $identity = $table === 'vicidial_live_chats' ? ['lead_id', 'chat_start_time']
                    : ($table === 'vicidial_list' ? ['phone_number', 'entry_date'] : ['chat_id', 'poster', 'message_time']);
                $matches = $existing !== null;
                foreach ($identity as $field) {
                    $matches = $matches && (string)($existing[$field] ?? '') === (string)$values[$field];
                }
                if ($matches) {
                    $this->run("UPDATE wa_vici_operations SET state='done' WHERE operation_key=?", [$key]);
                    return $id;
                }
                if (!$existing) {
                    $this->insert($table, [$pk => $id] + $values);
                    $this->run("UPDATE wa_vici_operations SET state='done' WHERE operation_key=?", [$key]);
                    return $id;
                }
            }
            $id = (int)$this->one("SELECT COALESCE(MAX($p),0)+1 AS n FROM $t")['n'];
            if ($archive) {
                $id = max($id, (int)$this->one('SELECT COALESCE(MAX(' . $p . '),0)+1 AS n FROM ' . self::ident($archive))['n']);
            }
            // Include the table's sequence so deleted rows' IDs are never reused.
            $status = $this->one('SHOW TABLE STATUS WHERE Name=?', [$table]);
            $id = max($id, (int)($status['Auto_increment'] ?? 0));
            $json = json_encode($values, JSON_THROW_ON_ERROR);
            $this->run("INSERT INTO wa_vici_operations (operation_key,target_table,target_id,row_json) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE target_id=VALUES(target_id),row_json=VALUES(row_json)", [$key,$table,$id,$json]);
            $this->insert($table, [$pk => $id] + $values);
            $this->run("UPDATE wa_vici_operations SET state='done' WHERE operation_key=?", [$key]);
            return $id;
        } finally {
            $this->run('UNLOCK TABLES');
        }
    }
}
