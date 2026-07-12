# Ostrakon — для разработчиков

Устройство проекта для тех, кто хочет его расширять: структура файлов, асинхронный вебхук,
планировщик задач и миграции схемы. К установке отношения не имеет — она в
[Для хостера бота](for-hosters.ru.md). Общий обзор — в [README](../README.ru.md).

---

## Структура проекта

```
/
├── index.php              # веб front controller / роутер (здесь живёт админ-панель)
├── webhook.php            # вебхук: проверка secret, укладка апдейта в очередь, ответ 200
├── cron.php               # воркер: разбор очереди апдейтов + периодические задачи
├── install.php            # установщик (требования → миграции → webhook) — удалить после установки
├── php-logrotate.php      # ротация логов по размеру/раз в сутки (конфиг php-logrotate.conf, N
│                          #   архивов, опц. gzip; состояние в php-logrotate.state)
├── log.php                # dev: просмотр лога (?token=...&n=200 / &clear=1)
├── inspect.php            # dev: инспектор/правилка БД (cookie-доступ)
├── .htaccess              # Apache: запрет листинга, защита служебных файлов, rewrite в index.php
├── assets/                # веб-доступные CSS/JS (Bulma + vanilla JS) для панели
├── config/
│   ├── bot.example.php     # шаблон → скопировать в bot.php
│   ├── db.example.php      # шаблон → скопировать в db.php
│   └── defaults.php        # дефолты групп + настройки инстанса + интервалы cron
├── src/
│   ├── bootstrap.php       # автозагрузчик + корневой путь
│   ├── Config / DB / Bot / Logger / Lang / DevAuth
│   ├── Handler.php         # роутинг входящих апдейтов
│   ├── GroupManager.php    # жизненный цикл группы, участники, онбординг, язык ЛС
│   ├── VoteManager.php     # голосования: инициирование, подсчёт, пороги, финал, таймауты
│   ├── ScoreManager.php    # режим full: метаданные сообщений, пересчёт score, теги/статус аксакала
│   ├── Notifier.php        # личные уведомления в ЛС + команды-ответы на них
│   ├── Telemetry.php       # обезличенные метрики оператора (журнал событий + сводка для панели)
│   ├── Exporter.php        # экспорт/импорт JSON для переноса группы между инстансами
│   ├── Panel.php / PanelAuth.php   # веб-панель (роутер + вход через Telegram Login)
│   ├── panel/              # серверные шаблоны панели (без веб-доступа):
│   │                       #   home, group, participants, settings, journal, simulator,
│   │                       #   migration, privacy, erase, help, login, layout, error, superadmin
│   └── Migrator.php        # применение миграций
├── lang/
│   ├── 01-ru.php           # тексты бота и панели (NN-<code>.php; порядок = по имени файла)
│   ├── 02-kk.php           # казахский перевод
│   └── 03-en.php           # английский перевод
├── migrations/
│   ├── run.php             # раннер миграций (?token=SUPERADMIN_TOKEN или CLI)
│   ├── 001_initial/        # базовая схема + сидинг cron
│   ├── 002_update_queue/   # таблица очереди для асинхронного вебхука
│   ├── 003_elder_tag/      # флаг participants.elder_tagged
│   ├── 004_can_manage/     # participants.can_manage (право менеджера настроек)
│   ├── 005_users/          # язык ЛС по пользователю
│   ├── 006_notify_prefs/   # флаги participants.notify_*
│   ├── 007_elder_status/   # participants.is_elder (статус, независимо от тега)
│   ├── 008_notify_actions/ # контекст команд-ответов на уведомления
│   ├── 009_group_active/   # groups.is_active (бот подключён / удалён)
│   ├── 010_vote_thread/    # votes.thread_id (форум-топики: голосование в теме триггера)
│   ├── 011_onboarding/     # состояние онбординга группы + users.has_dm; удаление pending_setup
│   ├── 012_onboarding_pending/ # groups.onboarding_pending (отложенный онбординг супергрупп)
│   └── 013_telemetry/      # таблица telemetry (обезличенные метрики оператора)
└── logs/                   # app.log + lock-файл cron (веб-доступ запрещён)
```

> На **Nginx** `.htaccess` не действует — каталоги `config/`, `src/`, `logs/`,
> `migrations/` (кроме `run.php`) и файлы `*.md`/`*.sql`/`*.log` закрыть в конфиге сервера,
> а неизвестные пути направить в `index.php`.

`config/bot.php` и `config/db.php` содержат секреты и **не должны** попадать в git.

---

## Асинхронный вебхук

Т.к. на shared-хостинге часто нет `fastcgi_finish_request`, апдейты никогда не
обрабатываются в самом запросе вебхука:

1. `webhook.php` проверяет secret, кладёт сырой апдейт в `update_queue` (дедуп по
   `update_id`), сразу отдаёт `200` и best-effort «пинает» воркер коротким self-запросом.
2. `cron.php` — **воркер**: держит `flock` (без наложения запусков), крутится
   `worker_loop_seconds` и каждые пару секунд разбирает очередь (`Handler::handle` на
   апдейт) и выполняет наступившие задачи.

Так ответ быстрый независимо от `fastcgi_finish_request`, и даже редкий системный cron не
мешает (self-пинок закрывает промежутки, когда срабатывает).

Тайминг воркера и сам self-пинок регулируются секцией `instance` в `config/defaults.php`
(`worker_loop_seconds`, `worker_poll_seconds`, `worker_self_poke`, `worker_heartbeat`).

---

## Планировщик задач (cron)

`cron.php` читает таблицу `cron_schedule`: задачи с `next_run_at <= NOW()` выполняются, и
их `next_run_at` сдвигается на интервал из `config/defaults.php`. Так задача срабатывает
вовремя и ровно один раз, даже если системный cron дёргается реже раза в минуту.

Задачи: `vote_timeouts`, `onboarding_check`, `bot_messages_cleanup` (раз в минуту),
`reentry_check` (5 мин), `score_recalc`, `data_ttl` (раз в сутки). От наложения защищает
`flock` на `logs/do_not_delete_this.lock` (снимается автоматически; удалять не нужно).

---

## Миграции схемы

Изменения схемы после установки — папками `migrations/NNN_описание/` (одно действие на
файл `*.sql`/`*.php`). Папки применяются по возрастанию имени; папка засчитывается, только
если все её файлы прошли без ошибок (учёт в `{prefix}migrations`). В `.sql` используйте
`{prefix}` для имён таблиц.

- Веб: `https://ВАШ_ДОМЕН/migrations/run.php?token=ВАШ_SUPERADMIN_TOKEN`
- CLI: `php migrations/run.php`

---

## Математика аксакала

Затухающий рейтинг активности (`score`), пороги статуса и формулы симулятора вынесены в
отдельный файл: [Формулы аксакала](formulas.ru.md).
