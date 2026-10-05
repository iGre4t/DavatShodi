<?php
declare(strict_types=1);

require_once __DIR__ . '/egm-period-draws.php';

function egmCompetitionQueueKey(): string { return 'competition_prize_queue'; }
function egmCompetitionStateKey(string $period): string { return 'competition_prizes:' . hash('sha256', $period); }

function egmCompetitionQueueValidate($value): array
{
    if (!is_array($value) || count($value) > 100 || array_values($value) !== $value) {
        throw new InvalidArgumentException('صف جوایز باید حداکثر ۱۰۰ رتبه داشته باشد.');
    }
    $queue = [];
    foreach ($value as $rank) {
        if (filter_var($rank, FILTER_VALIDATE_INT) === false || (int)$rank < 1 || (int)$rank > 4) {
            throw new InvalidArgumentException('هر جای صف باید رتبه‌ای بین ۱ تا ۴ داشته باشد.');
        }
        $queue[] = (int)$rank;
    }
    return $queue;
}

function egmCompetitionStatePublic(array $state, array $queue, array $inventory): array
{
    $confirmed = is_array($state['confirmed'] ?? null) ? array_values($state['confirmed']) : [];
    $pending = is_array($state['pending'] ?? null) ? $state['pending'] : null;
    $position = count($confirmed);
    return [
        'queue' => $queue,
        'confirmed' => $confirmed,
        'pending' => $pending,
        'nextRank' => $queue[$position] ?? null,
        'position' => $position,
        'previewPrizes' => array_values(array_map(
            static fn(array $row): string => (string)$row['name'],
            array_filter($inventory, static fn(array $row): bool => empty($row['isFake']) && (int)($row['last'] ?? 0) > 0)
        )),
    ];
}

function egmCompetitionEligiblePrizes(array $inventory, int $rank): array
{
    return array_values(array_filter($inventory, static fn(array $row): bool =>
        empty($row['isFake']) && (int)($row['rank'] ?? 0) === $rank && (int)($row['last'] ?? 0) > 0));
}

function egmCompetitionRun(array $context, string $period, string $action, array $input, string $actor): array
{
    $pdo = $context['pdo'] ?? null;
    $code = (string)($context['code'] ?? '');
    if (!$pdo instanceof PDO || $code === '') throw new RuntimeException('پایگاه داده EGM آماده نیست.');
    $lock = 'egm_comp_' . substr(hash('sha256', $code . ':' . $period), 0, 48);
    $lockQuery = $pdo->prepare('SELECT GET_LOCK(:lock_name, 10)');
    $lockQuery->execute([':lock_name' => $lock]);
    if ((int)$lockQuery->fetchColumn() !== 1) throw new RuntimeException('صف جوایز مشغول است. دوباره تلاش کنید.');
    $inventoryPath = (string)$context['mission_dir'] . DIRECTORY_SEPARATOR . 'EGM Prizes.json';
    try {
        $template = egmCompetitionQueueValidate(egmInstanceReadData($pdo, $code, egmCompetitionQueueKey(), []));
        $stateKey = egmCompetitionStateKey($period);
        $state = egmInstanceReadData($pdo, $code, $stateKey, []);
        if (!is_array($state)) $state = [];
        $confirmed = is_array($state['confirmed'] ?? null) ? array_values($state['confirmed']) : [];
        $queue = isset($state['queue']) ? egmCompetitionQueueValidate($state['queue']) : $template;
        if ($action === 'save_queue') {
            $submitted = egmCompetitionQueueValidate($input['queue'] ?? null);
            $expected = (string)($input['version'] ?? '');
            if ($expected === '' || !hash_equals(hash('sha256', json_encode($template)), $expected)) {
                throw new InvalidArgumentException('صف تغییر کرده است؛ صفحه را تازه کنید.');
            }
            egmInstanceWriteData($pdo, $code, egmCompetitionQueueKey(), $submitted);
            $template = $submitted;
            if (!$confirmed && empty($state['pending'])) $queue = $submitted;
        }
        $inventory = egmPrizeInventoryReadSnapshot($inventoryPath);
        if (!is_array($inventory)) throw new RuntimeException('انبار جوایز در دسترس نیست.');
        if ($action === 'roll') {
            if ($queue === []) throw new InvalidArgumentException('ابتدا صف جوایز را تنظیم کنید.');
            if (!empty($state['pending'])) throw new InvalidArgumentException('ابتدا جایزه باز را تأیید یا لغو کنید.');
            $position = count($confirmed);
            $rank = $queue[$position] ?? null;
            if ($rank === null) throw new InvalidArgumentException('صف جوایز تمام شده است.');
            $card = filter_var($input['card'] ?? null, FILTER_VALIDATE_INT);
            if ($card === false || $card < 1 || $card > 9) throw new InvalidArgumentException('یک کارت از ۱ تا ۹ انتخاب کنید.');
            $eligible = egmCompetitionEligiblePrizes($inventory, $rank);
            if (!$eligible) throw new InvalidArgumentException('برای این رتبه جایزهٔ موجودی وجود ندارد.');
            $prize = $eligible[random_int(0, count($eligible) - 1)];
            $state['queue'] = $queue;
            $state['confirmed'] = $confirmed;
            $state['pending'] = [
                'token' => bin2hex(random_bytes(16)), 'card' => $card, 'rank' => $rank,
                'prizeId' => (string)$prize['id'], 'prizeName' => (string)$prize['name'],
                'createdAt' => gmdate('c'), 'createdBy' => $actor,
            ];
            egmInstanceWriteData($pdo, $code, $stateKey, $state);
        } elseif ($action === 'cancel') {
            if (!empty($state['pending'])) {
                $state['pending'] = null;
                egmInstanceWriteData($pdo, $code, $stateKey, $state);
            }
        } elseif ($action === 'confirm') {
            $pending = $state['pending'] ?? null;
            if (!is_array($pending) || !hash_equals((string)($pending['token'] ?? ''), (string)($input['token'] ?? ''))) {
                throw new InvalidArgumentException('جایزهٔ آمادهٔ تأیید پیدا نشد.');
            }
            $lockedInventory = egmPrizeInventoryReadForUpdate($inventoryPath);
            if (!is_array($lockedInventory)) throw new RuntimeException('انبار جوایز در دسترس نیست.');
            try {
                $found = false;
                foreach ($lockedInventory as &$row) {
                    if ((string)$row['id'] !== (string)$pending['prizeId']) continue;
                    if ((int)($row['rank'] ?? 0) !== (int)$pending['rank'] || (int)$row['last'] < 1 || !empty($row['isFake'])) {
                        throw new InvalidArgumentException('موجودی این جایزه تغییر کرده است؛ انتخاب را لغو کنید.');
                    }
                    $row['last'] = (int)$row['last'] - 1;
                    $found = true;
                    break;
                }
                unset($row);
                if (!$found) throw new InvalidArgumentException('جایزه از انبار حذف شده است؛ انتخاب را لغو کنید.');
                $oldState = $state;
                $record = $pending + ['confirmedAt' => gmdate('c'), 'confirmedBy' => $actor];
                unset($record['token']);
                $state['confirmed'] = [...$confirmed, $record];
                $state['pending'] = null;
                egmInstanceWriteData($pdo, $code, $stateKey, $state);
                if (!egmPrizeInventoryCommit($inventoryPath, $lockedInventory, false)) {
                    egmInstanceWriteData($pdo, $code, $stateKey, $oldState);
                    throw new RuntimeException('ثبت موجودی جایزه ناموفق بود.');
                }
                $inventory = $lockedInventory;
            } finally {
                egmPrizeInventoryEnd($inventoryPath);
            }
        } elseif (!in_array($action, ['state', 'save_queue'], true)) {
            throw new InvalidArgumentException('عملیات نامعتبر است.');
        }
        return egmCompetitionStatePublic($state, $queue, $inventory) + [
            'queueTemplate' => $template,
            'queueVersion' => hash('sha256', json_encode($template)),
        ];
    } finally {
        $release = $pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
        $release->execute([':lock_name' => $lock]);
    }
}
