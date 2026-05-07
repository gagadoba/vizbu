<?php
// ── Конфиг ВИсбу ───────────────────────────────────────────────
// ⚠️ Не выкладывать в git! Если выложил — отозвать токен в @BotFather

const BOT_TOKEN  = '8279238790:AAGh2tpnm7bnsx88BkMG2Y0PQDX9kciRI1g';
const ADMIN_CHAT = '268868539';   // chat_id Карины (главный админ)
const DB_FILE    = __DIR__ . '/data/visbu.db';
const ADMIN_PASS = 'vizbu2026';   // пароль админ-панели
const DEMO_MODE  = false;         // если true — фейковые подрядчики для теста

// Категории услуг (должны совпадать с фронтом)
const CATEGORIES = [
  'Сезон', 'Разнорабочие', 'Участок', 'Дрова',
  'Сантехника', 'Электрика', 'Строительство',
  'Вывоз', 'Сад', 'Уборка', 'Спецтехника',
  'Ремонт техники', 'Зима',
];

// Статусы заявки
const STATUSES = [
  'new'         => '🆕 Новая',
  'confirmed'   => '✅ Согласована (звонок сделан)',
  'broadcasting'=> '📢 В эфире (ждём подрядчика)',
  'taken'       => '👷 Взята подрядчиком',
  'completed'   => '🏁 Выполнена',
  'cancelled'   => '❌ Сорвалась',
  'rated'       => '⭐ Закрыта (с оценкой)',
];

// Включаем CORS для запросов с visbu.ru
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
