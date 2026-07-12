# Elder simulator formulas

## Core score formula

```
λ = ln(2) / halflife_days
score = Σ exp(-λ · age_i)
```

where `age_i` is the age, in days, of the i-th counted message at the moment of calculation.

The physical meaning: each message contributes 1.0 when it's sent and decays exponentially over
time. Over `halflife_days` days its contribution halves.

---

## Approximation for the simulator (no real data)

If a member posts a steady `r` messages per day for `D` days, the score is approximately:

```
score ≈ r · (1 - exp(-λ · D)) / λ
```

As D → ∞ (steady state):

```
score_max = r / λ = r · halflife_days / ln(2)
```

### Worked example

With `halflife_days = 120`, `r = 0.5` msg/day:

```
λ = ln(2) / 120 ≈ 0.005776
score_max = 0.5 / 0.005776 ≈ 86.6
```

Over `D = 160` days:

```
score ≈ 0.5 · (1 - exp(-0.005776 · 160)) / 0.005776
      ≈ 0.5 · (1 - 0.398) / 0.005776
      ≈ 0.5 · 104.2
      ≈ 52.1
```

→ with `elder_threshold = 50`, the member becomes an elder in about 160 days.

---

## JavaScript implementation for the panel simulator

```javascript
// Exact calculation (iterate over days) — for small D
function calcScoreExact(messagesPerDay, totalDays, halflifeDays) {
    const lambda = Math.log(2) / halflifeDays;
    let score = 0;
    for (let d = 0; d < totalDays; d++) {
        const age = totalDays - d; // age in days
        score += messagesPerDay * Math.exp(-lambda * age);
    }
    return score;
}

// Approximation (instant) — for sliders and live updates
function calcScoreApprox(messagesPerDay, totalDays, halflifeDays) {
    const lambda = Math.log(2) / halflifeDays;
    return messagesPerDay * (1 - Math.exp(-lambda * totalDays)) / lambda;
}

// Days to reach the threshold (approximation)
function daysToElder(messagesPerDay, elderThreshold, halflifeDays) {
    const lambda = Math.log(2) / halflifeDays;
    const scoreMax = messagesPerDay / lambda;
    if (scoreMax <= elderThreshold) return Infinity; // never
    // score(D) = threshold => solve for D
    return -Math.log(1 - elderThreshold * lambda / messagesPerDay) / lambda;
}

// Member category
function getCategory(score, elderThreshold) {
    if (score >= elderThreshold)         return 'elder';      // elder
    if (score >= elderThreshold * 0.5)   return 'active';     // core
    if (score >= elderThreshold * 0.1)   return 'occasional'; // fringe
    return 'ghost';                                            // invisible
}
```

---

## Typical member profiles

With `halflife_days = 120`, `elder_threshold = 50`:

| Profile | Activity | score_max | Days to elder |
|---|---|---|---|
| Top 5% | 2 msg/day | 346 | ~38 days |
| Core | 0.5 msg/day | 87 | ~160 days |
| Fringe | 5 msg/month | 14 | never |
| Invisible | 1 msg/month | 3 | never |

---

## Computing the score from real data (PHP, cron)

```php
function recalcScore(int $chatId, int $userId, PDO $pdo, array $groupConfig): float
{
    $halflifeDays = $groupConfig['halflife_days'];
    $lambda = log(2) / $halflifeDays;
    $ttlDays = $halflifeDays * 4;
    $cutoff = date('Y-m-d H:i:s', strtotime("-{$ttlDays} days")); // nothing older than 4×halflife

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

## TTL for the messages table

Records older than `halflife_days × 4` can be deleted without losing accuracy:

```
exp(-λ · 4 · halflife_days) = exp(-4 · ln(2)) = 2^(-4) = 0.0625
```

By then a single message contributes 6.25% of its original weight. At typical volumes that's
negligible.

SQL for cron:

```sql
DELETE FROM messages
WHERE chat_id = :chat_id
  AND sent_at < DATE_SUB(NOW(), INTERVAL :ttl_days DAY);
-- ttl_days = halflife_days × 4
```
