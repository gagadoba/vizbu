<?php
// ── SQLite-подключение и автоинициализация схемы ─────────────────
require_once __DIR__ . '/config.php';

if (!is_dir(dirname(DB_FILE))) {
    mkdir(dirname(DB_FILE), 0755, true);
}

try {
    $db = new PDO('sqlite:' . DB_FILE);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA foreign_keys = ON;');
} catch (PDOException $e) {
    http_response_code(500);
    die(json_encode(['ok' => false, 'error' => 'DB connection failed']));
}

// Создание таблиц при первом запуске
$db->exec("
CREATE TABLE IF NOT EXISTS contractors (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  name        TEXT NOT NULL,
  phone       TEXT NOT NULL,
  email       TEXT,
  status_legal TEXT,           -- самозанятый / ИП / физ.лицо / ООО
  team        TEXT,            -- работает один / бригада 2-3 / 4+
  experience  INTEGER,
  categories  TEXT,            -- JSON массив выбранных категорий
  areas       TEXT,            -- JSON массив районов ЛО
  portfolio   TEXT,
  about       TEXT,
  link_token  TEXT UNIQUE,     -- токен для привязки Telegram
  tg_chat_id  TEXT,            -- chat_id в Telegram после привязки
  tg_username TEXT,
  mod_status  TEXT DEFAULT 'pending', -- pending / approved / rejected
  rating_sum  INTEGER DEFAULT 0,
  rating_cnt  INTEGER DEFAULT 0,
  total_orders INTEGER DEFAULT 0,
  created_at  INTEGER NOT NULL DEFAULT (strftime('%s', 'now'))
);

CREATE TABLE IF NOT EXISTS orders (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  client_name   TEXT NOT NULL,
  client_phone  TEXT NOT NULL,
  service       TEXT NOT NULL,
  category      TEXT,
  address       TEXT,
  comment       TEXT,
  extras        TEXT,           -- JSON с доп. полями из формы
  promo         TEXT,
  photos        TEXT,           -- JSON массив URL фото в Telegram (file_id)
  status        TEXT NOT NULL DEFAULT 'new',
  manager_note  TEXT,           -- то, что менеджер уточнил по звонку
  budget        TEXT,           -- бюджет после согласования с клиентом
  contractor_id INTEGER,        -- кто взял
  taken_at      INTEGER,
  completed_at  INTEGER,
  rating        INTEGER,        -- оценка 1-5 от клиента
  created_at    INTEGER NOT NULL DEFAULT (strftime('%s', 'now')),
  updated_at    INTEGER NOT NULL DEFAULT (strftime('%s', 'now')),
  FOREIGN KEY (contractor_id) REFERENCES contractors(id)
);

CREATE TABLE IF NOT EXISTS order_history (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  order_id    INTEGER NOT NULL,
  status_from TEXT,
  status_to   TEXT NOT NULL,
  by_who      TEXT,            -- 'client' / 'admin' / 'contractor:<id>'
  note        TEXT,
  at          INTEGER NOT NULL DEFAULT (strftime('%s', 'now')),
  FOREIGN KEY (order_id) REFERENCES orders(id)
);

CREATE TABLE IF NOT EXISTS broadcast_log (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  order_id     INTEGER NOT NULL,
  contractor_id INTEGER NOT NULL,
  message_id   INTEGER,         -- id сообщения в Telegram (чтобы потом удалить у других)
  sent_at      INTEGER NOT NULL DEFAULT (strftime('%s', 'now')),
  FOREIGN KEY (order_id) REFERENCES orders(id),
  FOREIGN KEY (contractor_id) REFERENCES contractors(id)
);

CREATE INDEX IF NOT EXISTS idx_orders_phone ON orders(client_phone);
CREATE INDEX IF NOT EXISTS idx_orders_status ON orders(status);
CREATE INDEX IF NOT EXISTS idx_contractors_tg ON contractors(tg_chat_id);
CREATE INDEX IF NOT EXISTS idx_contractors_token ON contractors(link_token);
");

function db_log($order_id, $from, $to, $by_who, $note = null) {
    global $db;
    $stmt = $db->prepare('INSERT INTO order_history (order_id, status_from, status_to, by_who, note) VALUES (?,?,?,?,?)');
    $stmt->execute([$order_id, $from, $to, $by_who, $note]);
}

function tg_send($chat_id, $text, $reply_markup = null) {
    $payload = [
        'chat_id' => $chat_id,
        'text' => $text,
        'parse_mode' => 'Markdown',
    ];
    if ($reply_markup) $payload['reply_markup'] = json_encode($reply_markup);

    $ch = curl_init('https://api.telegram.org/bot' . BOT_TOKEN . '/sendMessage');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 10,
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    return json_decode($res, true);
}

function tg_edit($chat_id, $message_id, $text, $reply_markup = null) {
    $payload = [
        'chat_id' => $chat_id,
        'message_id' => $message_id,
        'text' => $text,
        'parse_mode' => 'Markdown',
    ];
    if ($reply_markup) $payload['reply_markup'] = json_encode($reply_markup);

    $ch = curl_init('https://api.telegram.org/bot' . BOT_TOKEN . '/editMessageText');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 10,
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    return json_decode($res, true);
}

function tg_answer_callback($callback_id, $text = null, $alert = false) {
    $payload = ['callback_query_id' => $callback_id];
    if ($text !== null) $payload['text'] = $text;
    if ($alert) $payload['show_alert'] = true;

    $ch = curl_init('https://api.telegram.org/bot' . BOT_TOKEN . '/answerCallbackQuery');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 5,
    ]);
    curl_exec($ch); curl_close($ch);
}
