<?php
// ── Main API router for ВИсбу ────────────────────────────────────
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$body   = ($method === 'POST' && empty($_POST))
          ? json_decode(file_get_contents('php://input'), true) ?? []
          : ($_POST ?: []);

function ok($data = []) { echo json_encode(['ok' => true] + $data, JSON_UNESCAPED_UNICODE); exit; }
function fail($msg, $code = 400) { http_response_code($code); echo json_encode(['ok'=>false,'error'=>$msg], JSON_UNESCAPED_UNICODE); exit; }
function admin_check() {
    $pwd = $_GET['pass'] ?? $_POST['pass'] ?? '';
    if ($pwd !== ADMIN_PASS) fail('Неверный пароль', 401);
}
function rand_token($len = 24) {
    return bin2hex(random_bytes($len/2));
}

switch ($action) {

    // ════════════════════════════════════════════
    // КЛИЕНТ — отправляет заявку с сайта
    // ════════════════════════════════════════════
    case 'order_create': {
        $name    = trim($body['name'] ?? '');
        $phone   = trim($body['phone'] ?? '');
        $service = trim($body['service'] ?? '');
        $category= trim($body['category'] ?? '');
        $address = trim($body['address'] ?? '');
        $comment = trim($body['comment'] ?? '');
        $extras  = $body['extras'] ?? null;
        $promo   = trim($body['promo'] ?? '');

        if (!$name || !$phone || !$service) fail('Заполните имя, телефон и услугу');

        $stmt = $db->prepare('INSERT INTO orders (client_name, client_phone, service, category, address, comment, extras, promo) VALUES (?,?,?,?,?,?,?,?)');
        $stmt->execute([$name, $phone, $service, $category, $address, $comment,
                        $extras ? json_encode($extras, JSON_UNESCAPED_UNICODE) : null, $promo]);
        $order_id = (int)$db->lastInsertId();
        db_log($order_id, null, 'new', 'client');

        // Уведомляем админа
        $extrasText = '';
        if (is_array($extras)) {
            foreach ($extras as $k => $v) {
                $extrasText .= "\n   • $k: " . (is_array($v) ? implode(', ', $v) : $v);
            }
        }
        $msg = "🆕 *Новая заявка #$order_id — ВИсбу*\n"
             . "🔧 *Услуга:* $service\n"
             . "👤 *Имя:* $name\n"
             . "📱 *Телефон:* $phone\n"
             . ($address ? "📍 *Адрес:* $address\n" : '')
             . ($extrasText ? "📋 *Условия:*$extrasText\n" : '')
             . ($promo ? "🎁 *Промокод:* `$promo`\n" : '')
             . ($comment ? "💬 *Комментарий:* $comment\n" : '')
             . "\n👉 Открой админку: " . (defined('SITE_URL') ? SITE_URL : 'https://visbu.ru') . "/admin.html";

        tg_send(ADMIN_CHAT, $msg);

        ok(['order_id' => $order_id]);
    }

    // ════════════════════════════════════════════
    // КЛИЕНТ — мои заказы (по телефону)
    // ════════════════════════════════════════════
    case 'my_orders': {
        $phone = trim($body['phone'] ?? $_GET['phone'] ?? '');
        if (!$phone) fail('Укажите номер телефона');

        $stmt = $db->prepare('SELECT o.*, c.name as contractor_name, c.phone as contractor_phone
                              FROM orders o LEFT JOIN contractors c ON c.id = o.contractor_id
                              WHERE o.client_phone = ? ORDER BY o.created_at DESC');
        $stmt->execute([$phone]);
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
        ok(['orders' => $orders]);
    }

    // ════════════════════════════════════════════
    // КЛИЕНТ — оценить заказ после выполнения
    // ════════════════════════════════════════════
    case 'rate': {
        $order_id = (int)($body['order_id'] ?? 0);
        $rating   = (int)($body['rating'] ?? 0);
        $phone    = trim($body['phone'] ?? '');
        if ($rating < 1 || $rating > 5) fail('Оценка от 1 до 5');

        $stmt = $db->prepare('SELECT * FROM orders WHERE id = ? AND client_phone = ?');
        $stmt->execute([$order_id, $phone]);
        $o = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$o) fail('Заказ не найден', 404);
        if ($o['status'] !== 'completed') fail('Заказ ещё не выполнен');
        if ($o['rating']) fail('Уже оценено');

        $stmt = $db->prepare('UPDATE orders SET rating = ?, status = "rated", updated_at = strftime("%s","now") WHERE id = ?');
        $stmt->execute([$rating, $order_id]);

        $stmt = $db->prepare('UPDATE contractors SET rating_sum = rating_sum + ?, rating_cnt = rating_cnt + 1 WHERE id = ?');
        $stmt->execute([$rating, $o['contractor_id']]);

        db_log($order_id, 'completed', 'rated', 'client', "оценка: $rating★");
        tg_send(ADMIN_CHAT, "⭐ Заказ #$order_id оценён: *{$rating}★* от {$o['client_name']}");

        ok(['rating' => $rating]);
    }

    // ════════════════════════════════════════════
    // ПОДРЯДЧИК — регистрация на сайте
    // ════════════════════════════════════════════
    case 'contractor_register': {
        $name = trim($body['name'] ?? '');
        $phone = trim($body['phone'] ?? '');
        if (!$name || !$phone) fail('Заполните имя и телефон');

        $token = rand_token(20);
        $stmt = $db->prepare('INSERT INTO contractors
            (name, phone, email, status_legal, team, experience, categories, areas, portfolio, about, link_token)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([
            $name, $phone,
            trim($body['email'] ?? ''),
            trim($body['status'] ?? ''),
            trim($body['team'] ?? ''),
            (int)($body['experience'] ?? 0),
            json_encode($body['categories'] ?? [], JSON_UNESCAPED_UNICODE),
            json_encode($body['areas'] ?? [], JSON_UNESCAPED_UNICODE),
            trim($body['portfolio'] ?? ''),
            trim($body['about'] ?? ''),
            $token,
        ]);
        $cid = (int)$db->lastInsertId();

        // Уведомляем админа
        $bot_username = $body['bot_username'] ?? 'visbu_orders_bot';
        $linkUrl = "https://t.me/$bot_username?start=$token";
        $msg = "👷 *Новая регистрация подрядчика #$cid*\n"
             . "👤 $name\n📱 $phone\n"
             . "🔧 " . implode(', ', $body['categories'] ?? []) . "\n"
             . "📍 " . implode(', ', $body['areas'] ?? []) . "\n\n"
             . "👉 Открой админку, согласуй и одобри.";
        tg_send(ADMIN_CHAT, $msg);

        ok([
            'contractor_id' => $cid,
            'link_token' => $token,
            'tg_link' => $linkUrl,
        ]);
    }

    // ════════════════════════════════════════════
    // АДМИН — список заказов
    // ════════════════════════════════════════════
    case 'admin_orders': {
        admin_check();
        $filter = $_GET['filter'] ?? 'all';
        $where = $filter === 'all' ? '1=1' : 'status = ' . $db->quote($filter);
        $stmt = $db->query("SELECT o.*, c.name as contractor_name, c.phone as contractor_phone, c.tg_username as contractor_tg
                            FROM orders o LEFT JOIN contractors c ON c.id = o.contractor_id
                            WHERE $where ORDER BY o.created_at DESC LIMIT 200");
        ok(['orders' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    // ════════════════════════════════════════════
    // АДМИН — список подрядчиков
    // ════════════════════════════════════════════
    case 'admin_contractors': {
        admin_check();
        $stmt = $db->query('SELECT * FROM contractors ORDER BY created_at DESC');
        $list = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // нормализуем JSON
        foreach ($list as &$c) {
            $c['categories'] = json_decode($c['categories'] ?? '[]', true);
            $c['areas'] = json_decode($c['areas'] ?? '[]', true);
            $c['rating'] = $c['rating_cnt'] > 0 ? round($c['rating_sum'] / $c['rating_cnt'], 1) : null;
        }
        ok(['contractors' => $list]);
    }

    // ════════════════════════════════════════════
    // АДМИН — обновить заказ (уточнить детали)
    // ════════════════════════════════════════════
    case 'order_update': {
        admin_check();
        $id = (int)($body['id'] ?? 0);
        $stmt = $db->prepare('UPDATE orders SET
            client_name = COALESCE(?, client_name),
            address     = COALESCE(?, address),
            manager_note = COALESCE(?, manager_note),
            budget       = COALESCE(?, budget),
            category     = COALESCE(?, category),
            status       = COALESCE(?, status),
            updated_at   = strftime("%s","now")
            WHERE id = ?');
        $stmt->execute([
            $body['client_name'] ?? null,
            $body['address'] ?? null,
            $body['manager_note'] ?? null,
            $body['budget'] ?? null,
            $body['category'] ?? null,
            $body['status'] ?? null,
            $id,
        ]);
        ok();
    }

    // ════════════════════════════════════════════
    // АДМИН — одобрить/отклонить подрядчика
    // ════════════════════════════════════════════
    case 'contractor_moderate': {
        admin_check();
        $id = (int)($body['id'] ?? 0);
        $status = $body['status'] ?? 'pending'; // approved / rejected / pending
        $stmt = $db->prepare('UPDATE contractors SET mod_status = ? WHERE id = ?');
        $stmt->execute([$status, $id]);

        // Уведомим подрядчика если у него есть привязка
        $stmt = $db->prepare('SELECT * FROM contractors WHERE id = ?');
        $stmt->execute([$id]);
        $c = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($c && $c['tg_chat_id']) {
            $msg = $status === 'approved'
                ? "✅ Поздравляем! Ваш аккаунт ВИсбу одобрен. Мы будем присылать вам подходящие заявки. Заявок пока бесплатно — приучаем сервис, без ограничений."
                : ($status === 'rejected'
                    ? "К сожалению, ваша анкета не прошла модерацию. Если есть вопросы — напишите Карине."
                    : "Ваша анкета снова на модерации.");
            tg_send($c['tg_chat_id'], $msg);
        }
        ok();
    }

    // ════════════════════════════════════════════
    // АДМИН — отправить заказ всем подрядчикам в категории (broadcast)
    // ════════════════════════════════════════════
    case 'order_broadcast': {
        admin_check();
        $oid = (int)($body['order_id'] ?? 0);
        $stmt = $db->prepare('SELECT * FROM orders WHERE id = ?');
        $stmt->execute([$oid]);
        $o = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$o) fail('Заказ не найден', 404);

        // Найдём подрядчиков с подходящей категорией и привязанным Telegram
        $cat = $o['category'];
        $stmt = $db->prepare("SELECT * FROM contractors
            WHERE mod_status = 'approved'
              AND tg_chat_id IS NOT NULL
              AND (categories LIKE ? OR categories LIKE '%\"" . str_replace("'", "", $cat) . "\"%')");
        $like = '%"' . str_replace("'", "", $cat) . '"%';
        $stmt->execute([$like]);
        $contractors = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($contractors)) {
            fail('Нет подходящих подрядчиков с привязанным Telegram');
        }

        // Меняем статус на broadcasting
        $db->prepare('UPDATE orders SET status = "broadcasting", updated_at = strftime("%s","now") WHERE id = ?')->execute([$oid]);
        db_log($oid, $o['status'], 'broadcasting', 'admin');

        // Текст сообщения
        $extras = json_decode($o['extras'] ?? '[]', true);
        $extrasText = '';
        if (is_array($extras)) {
            foreach ($extras as $k => $v) {
                $extrasText .= "\n   • $k: " . (is_array($v) ? implode(', ', $v) : $v);
            }
        }
        $text = "🆕 *Новый заказ ВИсбу — берёт первый!*\n\n"
              . "🔧 *Услуга:* {$o['service']}\n"
              . ($o['address'] ? "📍 *Адрес:* {$o['address']}\n" : '')
              . ($o['budget']  ? "💰 *Бюджет:* {$o['budget']}\n" : '')
              . ($extrasText   ? "📋 *Условия:*$extrasText\n" : '')
              . ($o['manager_note'] ? "💬 *От менеджера:* {$o['manager_note']}\n" : '')
              . "\n_Сейчас заявки бесплатно — приучаем сервис._";

        $kb = ['inline_keyboard' => [[
            ['text' => '✅ Взять заказ', 'callback_data' => "take:$oid"],
            ['text' => '❌ Не возьму',   'callback_data' => "skip:$oid"],
        ]]];

        $sent = 0;
        foreach ($contractors as $c) {
            $r = tg_send($c['tg_chat_id'], $text, $kb);
            if (!empty($r['ok'])) {
                $mid = $r['result']['message_id'];
                $stmt = $db->prepare('INSERT INTO broadcast_log (order_id, contractor_id, message_id) VALUES (?,?,?)');
                $stmt->execute([$oid, $c['id'], $mid]);
                $sent++;
            }
        }

        ok(['sent_to' => $sent, 'contractors' => count($contractors)]);
    }

    // ════════════════════════════════════════════
    // ВЕРСИЯ — пинг
    // ════════════════════════════════════════════
    case 'ping': ok(['version' => '1.0', 'time' => time()]);

    default: fail('Неизвестный action: ' . $action);
}
