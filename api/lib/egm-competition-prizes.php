<?php
declare(strict_types=1);

require_once __DIR__ . '/egm-period-draws.php';

function egmCompetitionQueueKey(): string { return 'competition_prize_queue'; }
function egmCompetitionStateKey(string $period): string { return 'competition_prizes:' . hash('sha256', $period); }

function egmCompetitionPrizeNameKey(string $name): string
{
    if (class_exists('Normalizer')) $name = Normalizer::normalize($name, Normalizer::FORM_C) ?: $name;
    $name = str_replace(['ي', 'ك', "\u{200C}"], ['ی', 'ک', ' '], $name);
    return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $name) ?? $name), 'UTF-8');
}

/** Stock controls eligibility, not probability. Confirmed names are period-scoped. */
function egmCompetitionAvailablePrizes(array $inventory, array $confirmed, ?int $rank = null, bool $preventRepeatedNames = true, bool $preventRepeatedPrizes = true): array
{
    $ids = []; $names = [];
    foreach ($confirmed as $award) {
        $ids[(string)($award['prizeId'] ?? '')] = true;
        $key = egmCompetitionPrizeNameKey((string)($award['prizeName'] ?? ''));
        if ($key !== '') $names[$key] = true;
    }
    // Keep an awarded ID excluded after a rename and exclude its current name too.
    foreach ($inventory as $row) if (isset($ids[(string)($row['id'] ?? '')])) {
        $key = egmCompetitionPrizeNameKey((string)($row['name'] ?? ''));
        if ($key !== '') $names[$key] = true;
    }
    return array_values(array_filter($inventory, static fn(array $row): bool =>
        empty($row['isFake']) && (int)($row['last'] ?? 0) > 0
        && ($rank === null || (int)($row['rank'] ?? 0) === $rank)
        && (!$preventRepeatedPrizes || !isset($ids[(string)($row['id'] ?? '')]))
        && (!$preventRepeatedNames || isset($ids[(string)($row['id'] ?? '')]) || !isset($names[egmCompetitionPrizeNameKey((string)($row['name'] ?? ''))]))));
}

/** Priority prizes share 60% while alternatives exist; more has twice normal weight. */
function egmCompetitionChanceWeights(array $eligible): array
{
    $priorityCount = 0; $otherWeight = 0;
    foreach ($eligible as $row) {
        if (($row['chance'] ?? 'normal') === 'priority') $priorityCount++;
        else $otherWeight += ($row['chance'] ?? 'normal') === 'more' ? 2 : 1;
    }
    return array_map(static function(array $row) use ($priorityCount, $otherWeight): int {
        if (($row['chance'] ?? 'normal') === 'priority') return $otherWeight > 0 ? 3 * $otherWeight : 1;
        $base = ($row['chance'] ?? 'normal') === 'more' ? 2 : 1;
        return $priorityCount > 0 ? 2 * $priorityCount * $base : $base;
    }, $eligible);
}

function egmCompetitionChoosePrize(array $eligible, ?int $ticket = null): array
{
    if (!$eligible) throw new InvalidArgumentException('جایزه‌ای برای انتخاب وجود ندارد.');
    $weights = egmCompetitionChanceWeights($eligible); $total = array_sum($weights);
    $ticket ??= random_int(1, $total);
    if ($ticket < 1 || $ticket > $total) throw new InvalidArgumentException('انتخاب جایزه معتبر نیست.');
    foreach ($eligible as $index => $row) {
        $ticket -= $weights[$index];
        if ($ticket <= 0) return $row;
    }
    throw new RuntimeException('انتخاب جایزه انجام نشد.');
}

function egmCompetitionRequireUnusedCard(array $confirmed, int $card, bool $keepConfirmedCards = true): void
{
    if (!$keepConfirmedCards) return;
    foreach ($confirmed as $item) {
        if ((int)($item['card'] ?? 0) === $card) {
            throw new InvalidArgumentException('این کارت قبلاً تأیید شده است؛ کارت دیگری انتخاب کنید.');
        }
    }
}

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

function egmCompetitionStatePublic(array $state, array $queue, array $inventory, bool $queueEnabled = true, bool $keepConfirmedCards = true, bool $preventRepeatedNames = true, bool $preventRepeatedPrizes = true): array
{
    $confirmed = is_array($state['confirmed'] ?? null) ? array_values($state['confirmed']) : [];
    $pending = is_array($state['pending'] ?? null) ? $state['pending'] : null;
    $position = count($confirmed);
    return [
        'queue' => $queueEnabled ? $queue : array_fill(0, 9, 1),
        'queueEnabled' => $queueEnabled,
        'keepConfirmedCards' => $keepConfirmedCards,
        'preventRepeatedPrizeNames' => $preventRepeatedNames,
        'preventRepeatedPrizes' => $preventRepeatedPrizes,
        'confirmed' => $confirmed,
        'pending' => $pending,
        'nextRank' => $queueEnabled ? ($queue[$position] ?? null) : ($position < 9 ? 1 : null),
        'position' => $position,
        'resetVersion' => hash('sha256', json_encode([$confirmed, $pending])),
        'previewPrizes' => array_values(array_map(
            static fn(array $row): string => (string)$row['name'],
            egmCompetitionAvailablePrizes($inventory, $confirmed, null, $preventRepeatedNames, $preventRepeatedPrizes)
        )),
    ];
}

function egmCompetitionEligiblePrizes(array $inventory, int $rank, array $confirmed = []): array
{
    return egmCompetitionAvailablePrizes($inventory, $confirmed, $rank);
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
        $queueEnabled = (bool)egmInstanceReadData($pdo, $code, 'competition_prize_queue_enabled', true);
        $keepConfirmedCards = ($context['competition_keep_confirmed_cards'] ?? true) !== false;
        $preventRepeatedNames = ($context['competition_prevent_repeated_names'] ?? true) !== false;
        $preventRepeatedPrizes = ($context['competition_prevent_repeated_prizes'] ?? true) !== false;
        $stateKey = egmCompetitionStateKey($period);
        $state = egmInstanceReadData($pdo, $code, $stateKey, []);
        if (!is_array($state)) $state = [];
        $confirmed = is_array($state['confirmed'] ?? null) ? array_values($state['confirmed']) : [];
        $queue = isset($state['queue']) ? egmCompetitionQueueValidate($state['queue']) : $template;
        if ($queueEnabled && $queue === [] && $template !== []) $queue = $template;
        if ($action === 'save_queue') {
            $submitted = egmCompetitionQueueValidate($input['queue'] ?? null);
            $expected = (string)($input['version'] ?? '');
            if ($expected === '' || !hash_equals(hash('sha256', json_encode($template)), $expected)) {
                throw new InvalidArgumentException('صف تغییر کرده است؛ صفحه را تازه کنید.');
            }
            if (array_key_exists('queueEnabled', $input) && !is_bool($input['queueEnabled'])) throw new InvalidArgumentException('وضعیت صف نامعتبر است.');
            egmInstanceWriteData($pdo, $code, egmCompetitionQueueKey(), $submitted);
            if (array_key_exists('queueEnabled', $input)) {
                $queueEnabled = $input['queueEnabled'];
                egmInstanceWriteData($pdo, $code, 'competition_prize_queue_enabled', $queueEnabled);
            }
            $template = $submitted;
            if (!$confirmed && empty($state['pending'])) $queue = $submitted;
        }
        $inventory = egmPrizeInventoryReadSnapshot($inventoryPath);
        if (!is_array($inventory)) throw new RuntimeException('انبار جوایز در دسترس نیست.');
        if ($action === 'roll') {
            if ($queueEnabled && $queue === []) throw new InvalidArgumentException('ابتدا صف جوایز را تنظیم کنید.');
            if (!empty($state['pending']) && !hash_equals((string)$state['pending']['token'], (string)($input['token'] ?? ''))) {
                throw new InvalidArgumentException('انتخاب در صفحه دیگری تغییر کرده است؛ صفحه را تازه کنید.');
            }
            $position = count($confirmed);
            $rank = $queueEnabled ? ($queue[$position] ?? null) : ($position < 9 ? 1 : null);
            if ($rank === null) throw new InvalidArgumentException('صف جوایز تمام شده است.');
            $card = filter_var($input['card'] ?? null, FILTER_VALIDATE_INT);
            if ($card === false || $card < 1 || $card > 9) throw new InvalidArgumentException('یک کارت از ۱ تا ۹ انتخاب کنید.');
            egmCompetitionRequireUnusedCard($confirmed, $card, $keepConfirmedCards);
            $eligible = egmCompetitionAvailablePrizes($inventory, $confirmed, $queueEnabled ? $rank : null, $preventRepeatedNames, $preventRepeatedPrizes);
            if (!$eligible) throw new InvalidArgumentException($queueEnabled ? 'برای این رتبه جایزهٔ قابل انتخاب وجود ندارد.' : 'هیچ جایزه‌ای با موجودی و تنظیمات فعلی قابل انتخاب نیست.');
            $prize = egmCompetitionChoosePrize($eligible);
            $state['queue'] = $queue;
            $state['confirmed'] = $confirmed;
            $state['pending'] = [
                'token' => bin2hex(random_bytes(16)), 'card' => $card, 'rank' => (int)($prize['rank'] ?? 0),
                'prizeId' => (string)$prize['id'], 'prizeName' => (string)$prize['name'],
                'createdAt' => gmdate('c'), 'createdBy' => $actor,
            ];
            egmInstanceWriteData($pdo, $code, $stateKey, $state);
        } elseif ($action === 'cancel') {
            if (!empty($state['pending'])) {
                $state['pending'] = null;
                egmInstanceWriteData($pdo, $code, $stateKey, $state);
            }
        } elseif ($action === 'reset') {
            $version = egmCompetitionStatePublic($state, $queue, $inventory)['resetVersion'];
            if (!hash_equals($version, (string)($input['version'] ?? ''))) {
                throw new InvalidArgumentException('نتایج در صفحه دیگری تغییر کرده است؛ صفحه را تازه کنید.');
            }
            $lockedInventory = egmPrizeInventoryReadForUpdate($inventoryPath);
            if (!is_array($lockedInventory)) throw new RuntimeException('انبار جوایز در دسترس نیست.');
            try {
                $returns = [];
                foreach ($confirmed as $item) {
                    $id = (string)$item['prizeId'];
                    $returns[$id] = ($returns[$id] ?? 0) + 1;
                }
                foreach ($lockedInventory as &$row) {
                    $id = (string)$row['id'];
                    if (!isset($returns[$id])) continue;
                    $row['last'] = min((int)$row['quantity'], (int)$row['last'] + $returns[$id]);
                    unset($returns[$id]);
                }
                unset($row);
                $oldState = $state;
                // Removed prizes cannot be returned to current stock. Keep an
                // audit copy, but do not prevent an authorized history reset.
                egmInstanceWriteData($pdo, $code, $stateKey . ':last_reset', [
                    'state' => $oldState, 'removedPrizeReturns' => $returns,
                    'resetAt' => gmdate('c'), 'resetBy' => $actor,
                ]);
                $state = ['queue' => $template, 'confirmed' => [], 'pending' => null];
                egmInstanceWriteData($pdo, $code, $stateKey, $state);
                if (!egmPrizeInventoryCommit($inventoryPath, $lockedInventory, false)) {
                    egmInstanceWriteData($pdo, $code, $stateKey, $oldState);
                    throw new RuntimeException('بازنشانی موجودی جایزه ناموفق بود.');
                }
                $confirmed = [];
                $queue = $template;
                $inventory = $lockedInventory;
            } finally { egmPrizeInventoryEnd($inventoryPath); }
        } elseif ($action === 'confirm') {
            $pending = $state['pending'] ?? null;
            if (!is_array($pending) || !hash_equals((string)($pending['token'] ?? ''), (string)($input['token'] ?? ''))) {
                throw new InvalidArgumentException('جایزهٔ آمادهٔ تأیید پیدا نشد.');
            }
            egmCompetitionRequireUnusedCard($confirmed, (int)$pending['card'], $keepConfirmedCards);
            $lockedInventory = egmPrizeInventoryReadForUpdate($inventoryPath);
            if (!is_array($lockedInventory)) throw new RuntimeException('انبار جوایز در دسترس نیست.');
            try {
                $found = false;
                $availableIds = array_column(egmCompetitionAvailablePrizes($lockedInventory, $confirmed, null, $preventRepeatedNames, $preventRepeatedPrizes), 'id');
                if (!in_array((string)$pending['prizeId'], $availableIds, true)) {
                    throw new InvalidArgumentException('این جایزه با موجودی یا تنظیمات فعلی قابل تأیید نیست؛ انتخاب را لغو و دوباره چرخش کنید.');
                }
                $isRepeat = in_array((string)$pending['prizeId'], array_column($confirmed, 'prizeId'), true);
                foreach ($confirmed as $award) if ($preventRepeatedNames && !($isRepeat && !$preventRepeatedPrizes) && egmCompetitionPrizeNameKey((string)($award['prizeName'] ?? '')) === egmCompetitionPrizeNameKey((string)$pending['prizeName'])) {
                    throw new InvalidArgumentException('جایزه‌ای با این نام در این بازه تأیید شده است؛ انتخاب را لغو کنید.');
                }
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
        return egmCompetitionStatePublic($state, $queue, $inventory, $queueEnabled, $keepConfirmedCards, $preventRepeatedNames, $preventRepeatedPrizes) + [
            'queueTemplate' => $template,
            'queueVersion' => hash('sha256', json_encode($template)),
        ];
    } finally {
        $release = $pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
        $release->execute([':lock_name' => $lock]);
    }
}
