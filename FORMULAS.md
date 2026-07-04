# Формулы симулятора аксакала

## Основная формула score

```
λ = ln(2) / halflife_days
score = Σ exp(-λ · age_i)
```

где `age_i` — возраст i-го засчитанного сообщения в днях на момент расчёта.

Физический смысл: каждое сообщение вносит вклад 1.0 в момент отправки,
и экспоненциально затухает со временем. За `halflife_days` дней вклад
уменьшается вдвое.

---

## Аппроксимация для симулятора (без реальных данных)

Если участник пишет равномерно `r` сообщений в день на протяжении `D` дней,
score приближённо равен:

```
score ≈ r · (1 - exp(-λ · D)) / λ
```

При D → ∞ (установившийся режим):

```
score_max = r / λ = r · halflife_days / ln(2)
```

### Пример расчёта

При `halflife_days = 120`, `r = 0.5` сообщ./день:

```
λ = ln(2) / 120 ≈ 0.005776
score_max = 0.5 / 0.005776 ≈ 86.6
```

За `D = 160` дней:

```
score ≈ 0.5 · (1 - exp(-0.005776 · 160)) / 0.005776
      ≈ 0.5 · (1 - 0.398) / 0.005776
      ≈ 0.5 · 104.2
      ≈ 52.1
```

→ при `elder_threshold = 50` участник становится аксакалом примерно за 160 дней.

---

## JavaScript-реализация для симулятора в админ-панели

```javascript
// Точный расчёт (итерация по дням) — для небольших D
function calcScoreExact(messagesPerDay, totalDays, halflifeDays) {
    const lambda = Math.log(2) / halflifeDays;
    let score = 0;
    for (let d = 0; d < totalDays; d++) {
        const age = totalDays - d; // возраст в днях
        score += messagesPerDay * Math.exp(-lambda * age);
    }
    return score;
}

// Аппроксимация (мгновенная) — для слайдеров и живого обновления
function calcScoreApprox(messagesPerDay, totalDays, halflifeDays) {
    const lambda = Math.log(2) / halflifeDays;
    return messagesPerDay * (1 - Math.exp(-lambda * totalDays)) / lambda;
}

// Дней до достижения порога (аппроксимация)
function daysToElder(messagesPerDay, elderThreshold, halflifeDays) {
    const lambda = Math.log(2) / halflifeDays;
    const scoreMax = messagesPerDay / lambda;
    if (scoreMax <= elderThreshold) return Infinity; // никогда
    // score(D) = threshold => решаем относительно D
    return -Math.log(1 - elderThreshold * lambda / messagesPerDay) / lambda;
}

// Категория участника
function getCategory(score, elderThreshold) {
    if (score >= elderThreshold)         return 'elder';      // аксакал
    if (score >= elderThreshold * 0.5)   return 'active';     // ядро
    if (score >= elderThreshold * 0.1)   return 'occasional'; // болото
    return 'ghost';                                            // невидимка
}
```

---

## Типовые профили участников

При `halflife_days = 120`, `elder_threshold = 50`:

| Профиль | Активность | score_max | Дней до аксакала |
|---|---|---|---|
| Топ-5% | 2 сообщ./день | 346 | ~38 дней |
| Ядро | 0.5 сообщ./день | 87 | ~160 дней |
| Болото | 5 сообщ./мес. | 14 | никогда |
| Невидимки | 1 сообщ./мес. | 3 | никогда |

---

## Расчёт score из реальных данных (PHP, cron)

```php
function recalcScore(int $chatId, int $userId, PDO $pdo, array $groupConfig): float
{
    $halflifeDays = $groupConfig['halflife_days'];
    $lambda = log(2) / $halflifeDays;
    $ttlDays = $halflifeDays * 4;
    $cutoff = date('Y-m-d H:i:s', strtotime("-{$ttlDays} days")); // не нужно старше 4×halflife

    $stmt = $pdo->prepare(
        'SELECT sent_at FROM messages
         WHERE chat_id = ? AND user_id = ? AND sent_at > ?
         ORDER BY sent_at ASC'
    );
    $stmt->execute([$chatId, $userId, $cutoff]);

    $score = 0.0;
    $now = time();
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $ageDays = ($now - strtotime($row['sent_at'])) / 86400;
        $score += exp(-$lambda * $ageDays);
    }
    return $score;
}
```

---

## TTL для таблицы messages

Записи старше `halflife_days × 4` можно удалять без потери точности:

```
exp(-λ · 4 · halflife_days) = exp(-4 · ln(2)) = 2^(-4) = 0.0625
```

Вклад одного сообщения к тому моменту — 6.25% от исходного.
При типичных объёмах это пренебрежимо мало.

SQL для cron:

```sql
DELETE FROM messages
WHERE chat_id = :chat_id
  AND sent_at < DATE_SUB(NOW(), INTERVAL :ttl_days DAY);
-- ttl_days = halflife_days × 4
```
