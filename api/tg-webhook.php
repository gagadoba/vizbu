<?php
// ── Telegram webhook handler ─────────────────────────────────────
// Подключается так:
// https://api.telegram.org/bot<TOKEN>/setWebhook?url=https://visbu.ru/api/tg-webhook.php
require_once __DIR__ . '/db.php';

$update = json_decode(file_get_contents('php://input'), true);
if (!$update) { http_response_code(200); exit; }

// ────────────────────────────────────────────────
// 1. Текстовые сообщения (для /start <token>)
// ────────────────────────────────────────────────
if (isset($update['message'])) {
    $msg = $update['message'];
    $chat_id = $msg['chat']['id'];
    $text    = $msg['text'] ?? '';
    $from    = $msg['from'] ?? [];
    $username = $from['username'] ?? '';

    // /start с токеном привязки
    if (preg_match('/^\/start\s+([a-f0-9]+)$/i', $text, $m)) {
        $token = $m[1];
        $stmt = $db->prepare('SELECT * FROM contractors WHERE link_token = ?');
        $stmt->execute([$token]);
        $c = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$c) {
            tg_send($chat_id, "❌ Токен привязки не найден или уже использован.");
            exit;
        }
        $stmt = $db->prepare('UPDATE contractors SET tg_chat_id = ?, tg_username = ? WHERE id = ?');
        $stmt->execute([$chat_id, $username, $c['id']]);

        $modText = $c['mod_status'] === 'approved'
            ? "✅ Telegram привязан, ваш аккаунт уже одобрен — будем присылать заявки!"
            : "✅ Telegram привязан. Ваш аккаунт ждёт одобрения админом — мы перезвоним.";
        tg_send($chat_id, "Здравствуйте, {$c['name']}!\n\n$modText\n\nЕсли что — напишите Карине @karina_visbu");
        // Уведомим админа
        tg_send(ADMIN_CHAT, "🔗 Подрядчик #{$c['id']} ({$c['name']}) привязал Telegram: @$username");
        exit;
    }

    if ($text === '/start' || $text === '/help') {
        tg_send($chat_id, "Привет! Я бот ВИсбу.\n\n"
            . "Если ты подрядчик — зарегистрируйся на сайте https://visbu.ru/#contractors и нажми кнопку «Привязать Telegram». Дальше я буду присылать тебе заявки клиентов.\n\n"
            . "Если ты клиент — оставь заявку на https://visbu.ru, мы перезвоним за час.\n\n"
            . "Связь: @karina_visbu, +7 911 950-73-58");
        exit;
    }

    if ($text === '/me') {
        $stmt = $db->prepare('SELECT * FROM contractors WHERE tg_chat_id = ?');
        $stmt->execute([$chat_id]);
        $c = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$c) { tg_send($chat_id, "Вы не зарегистрированы как подрядчик."); exit; }
        $rating = $c['rating_cnt'] > 0 ? round($c['rating_sum'] / $c['rating_cnt'], 1) . '★' : '— нет оценок';
        $statusMap = [
            'pending' => '⏳ На модерации',
            'approved'=> '✅ Одобрен',
            'rejected'=> '❌ Отклонён',
        ];
        tg_send($chat_id, "*Ваш профиль ВИсбу*\n\n"
            . "👤 {$c['name']}\n"
            . "📱 {$c['phone']}\n"
            . "Статус: {$statusMap[$c['mod_status']]}\n"
            . "Заказов выполнено: {$c['total_orders']}\n"
            . "Рейтинг: $rating");
        exit;
    }

    // По умолчанию ничего не делаем (можно эхо)
    exit;
}

// ────────────────────────────────────────────────
// 2. Callback от inline-кнопок (Взять / Выполнено / Сорвалось)
// ────────────────────────────────────────────────
if (isset($update['callback_query'])) {
    $cb = $update['callback_query'];
    $cbid = $cb['id'];
    $data = $cb['data'] ?? '';
    $chat_id = $cb['message']['chat']['id'];
    $message_id = $cb['message']['message_id'];

    // Найдём подрядчика
    $stmt = $db->prepare('SELECT * FROM contractors WHERE tg_chat_id = ?');
    $stmt->execute([$chat_id]);
    $contractor = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$contractor) { tg_answer_callback($cbid, 'Вы не зарегистрированы', true); exit; }

    // Парсим callback_data: "take:5", "skip:5", "done:5", "cancel:5"
    if (!preg_match('/^(take|skip|done|cancel):(\d+)$/', $data, $m)) {
        tg_answer_callback($cbid, 'Неизвестная команда');
        exit;
    }
    [$_, $cmd, $oid] = $m;
    $oid = (int)$oid;

    $stmt = $db->prepare('SELECT * FROM orders WHERE id = ?');
    $stmt->execute([$oid]);
    $o = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$o) { tg_answer_callback($cbid, 'Заказ не найден', true); exit; }

    if ($cmd === 'take') {
        // Заказ должен быть в статусе broadcasting
        if ($o['status'] !== 'broadcasting') {
            tg_answer_callback($cbid, '⚠️ Заказ уже забрал кто-то другой', true);
            // Удалим у этого подрядчика клавиатуру
            tg_edit($chat_id, $message_id, "❌ *Заказ уже занят другим подрядчиком*\n\n_{$o['service']}_", null);
            exit;
        }

        // Назначаем
        $stmt = $db->prepare('UPDATE orders SET status="taken", contractor_id=?, taken_at=strftime("%s","now"), updated_at=strftime("%s","now") WHERE id = ?');
        $stmt->execute([$contractor['id'], $oid]);
        db_log($oid, 'broadcasting', 'taken', 'contractor:' . $contractor['id']);

        // Этому подрядчику — расширенное сообщение с кнопками "Выполнено / Сорвалось" и контактом
        $newText = "✅ *Заказ #$oid — ваш!*\n\n"
                 . "🔧 *Услуга:* {$o['service']}\n"
                 . "👤 *Клиент:* {$o['client_name']}\n"
                 . "📱 *Телефон:* `{$o['client_phone']}`\n"
                 . ($o['address'] ? "📍 *Адрес:* {$o['address']}\n" : '')
                 . ($o['budget']  ? "💰 *Бюджет:* {$o['budget']}\n" : '')
                 . ($o['manager_note'] ? "💬 *От менеджера:* {$o['manager_note']}\n" : '')
                 . "\n*Свяжитесь с клиентом, согласуйте время.*\n"
                 . "После выполнения — нажмите кнопку ниже.";
        $kb = ['inline_keyboard' => [[
            ['text' => '🏁 Выполнено', 'callback_data' => "done:$oid"],
            ['text' => '❌ Сорвалось', 'callback_data' => "cancel:$oid"],
        ]]];
        tg_edit($chat_id, $message_id, $newText, $kb);

        // Остальным подрядчикам — заблокировать сообщение
        $stmt = $db->prepare('SELECT * FROM broadcast_log WHERE order_id = ? AND contractor_id != ?');
        $stmt->execute([$oid, $contractor['id']]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $log) {
            // Получим chat_id этого подрядчика
            $st2 = $db->prepare('SELECT tg_chat_id FROM contractors WHERE id = ?');
            $st2->execute([$log['contractor_id']]);
            $other = $st2->fetchColumn();
            if ($other) {
                tg_edit($other, $log['message_id'],
                    "❌ *Заказ #$oid забрал другой подрядчик*\n\n_{$o['service']}_", null);
            }
        }

        // Уведомим админа
        tg_send(ADMIN_CHAT, "👷 Заказ #$oid взял *{$contractor['name']}* (@{$contractor['tg_username']})");
        tg_answer_callback($cbid, '✅ Заказ ваш! Контакты клиента в сообщении выше.');
        exit;
    }

    if ($cmd === 'skip') {
        tg_edit($chat_id, $message_id, "🤝 Понятно, ищем другого мастера. Спасибо!");
        tg_answer_callback($cbid, 'Отметили, что вы пропустили');
        exit;
    }

    if ($cmd === 'done') {
        if ((int)$o['contractor_id'] !== (int)$contractor['id']) {
            tg_answer_callback($cbid, 'Это не ваш заказ', true); exit;
        }
        $db->prepare('UPDATE orders SET status="completed", completed_at=strftime("%s","now"), updated_at=strftime("%s","now") WHERE id=?')->execute([$oid]);
        $db->prepare('UPDATE contractors SET total_orders = total_orders + 1 WHERE id = ?')->execute([$contractor['id']]);
        db_log($oid, 'taken', 'completed', 'contractor:' . $contractor['id']);

        tg_edit($chat_id, $message_id,
            "🏁 *Заказ #$oid выполнен!*\n\n_{$o['service']}_\n\nСпасибо за работу!\n"
          . "Клиента попросим оценить через сайт. Хорошая оценка = больше заказов в будущем.", null);
        tg_send(ADMIN_CHAT, "🏁 Заказ #$oid выполнил *{$contractor['name']}*. Клиенту {$o['client_name']} ({$o['client_phone']}) предложите оценить.");
        tg_answer_callback($cbid, 'Спасибо!');
        exit;
    }

    if ($cmd === 'cancel') {
        if ((int)$o['contractor_id'] !== (int)$contractor['id']) {
            tg_answer_callback($cbid, 'Это не ваш заказ', true); exit;
        }
        $db->prepare('UPDATE orders SET status="cancelled", contractor_id=NULL, updated_at=strftime("%s","now") WHERE id=?')->execute([$oid]);
        db_log($oid, 'taken', 'cancelled', 'contractor:' . $contractor['id'], 'отказ после взятия');

        tg_edit($chat_id, $message_id, "❌ *Заказ #$oid сорвался*\n\nАдмин разберётся.", null);
        tg_send(ADMIN_CHAT, "❌ *Заказ #$oid сорвался* у *{$contractor['name']}*. Возможно надо перепустить в эфир или связаться с клиентом.");
        tg_answer_callback($cbid, 'Отмечено как сорвалось');
        exit;
    }
}

http_response_code(200);
