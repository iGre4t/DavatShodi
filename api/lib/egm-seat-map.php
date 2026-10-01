<?php
declare(strict_types=1);

require_once __DIR__ . '/egm-instance-storage.php';

/** @return array{enabled:bool,ticketId:string,rows:array<int,int>,segments:array<int,array<int,int>>} */
function egmSeatMapNormalize($value): array
{
    $source = is_array($value) ? $value : [];
    $enabled = (bool)($source['enabled'] ?? false);
    $ticketId = trim((string)($source['ticketId'] ?? ''));
    $rows = $source['segments'] ?? $source['rows'] ?? [];
    if (!is_array($rows) || count($rows) > 200) throw new InvalidArgumentException('حداکثر ۲۰۰ ردیف برای سالن مجاز است.');
    $counts = [];
    $segments = [];
    foreach ($rows as $row) {
        $blocks = is_array($row) ? $row : [$row];
        if ($blocks === [] || count($blocks) > 20) throw new InvalidArgumentException('هر ردیف باید بین ۱ تا ۲۰ بخش صندلی داشته باشد.');
        $normalizedBlocks = [];
        foreach ($blocks as $count) {
            if (filter_var($count, FILTER_VALIDATE_INT) === false || (int)$count < 1 || (int)$count > 500) {
                throw new InvalidArgumentException('تعداد صندلی هر بخش باید بین ۱ تا ۵۰۰ باشد.');
            }
            $normalizedBlocks[] = (int)$count;
        }
        if (array_sum($normalizedBlocks) > 500) throw new InvalidArgumentException('مجموع صندلی‌های هر ردیف نباید بیشتر از ۵۰۰ باشد.');
        $segments[] = $normalizedBlocks;
        $counts[] = array_sum($normalizedBlocks);
    }
    if ($enabled && ($ticketId === '' || $counts === [])) {
        throw new InvalidArgumentException('برای فعال‌کردن صندلی، بلیت مبنا و تعداد صندلی هر ردیف را تعیین کنید.');
    }
    return ['enabled' => $enabled, 'ticketId' => $ticketId, 'rows' => $counts, 'segments' => $segments];
}

function egmSeatMapValidateTicket(array $context, array $map): void
{
    if (!$map['enabled']) return;
    $settings = egmInstanceReadData($context['pdo'], (string)$context['code'], 'settings', []);
    if (empty($settings['customNumberTicketSettings']['active'])) {
        throw new InvalidArgumentException('ابتدا چاپ Custom Number Ticket را در تنظیمات EGM فعال کنید.');
    }
    $tickets = (array)($settings['customNumberTicketSettings']['tickets'] ?? []);
    $ids = array_column($tickets, 'id');
    if ($ids === []) $ids = ['default'];
    if (!in_array($map['ticketId'], $ids, true)) {
        throw new InvalidArgumentException('بلیت شماره‌دار مبنای صندلی در تنظیمات EGM وجود ندارد.');
    }
}

/** @return array{enabled:bool,ticketId:string,rows:array<int,int>,segments:array<int,array<int,int>>} */
function egmSeatMapEffective(array $context, string $periodCode): array
{
    $default = egmSeatMapNormalize(egmInstanceReadData($context['pdo'], (string)$context['code'], 'seat_map:default', []));
    $override = egmInstanceReadData($context['pdo'], (string)$context['code'], 'seat_map:' . $periodCode, []);
    $map = is_array($override) && ($override['mode'] ?? '') === 'custom'
        ? egmSeatMapNormalize($override['map'] ?? []) : $default;
    return $map;
}

/** @return array<int,array{row:int,chair:int}> */
function egmSeatMapFindContiguous(array $rows, array $used, int $quantity, ?array $segments = null): array
{
    $segments ??= array_map(static fn(int $count): array => [$count], $rows);
    // Fill an available run in the earliest row before opening another row.
    // This keeps successive small parties together and uses existing rows.
    foreach ($segments as $rowIndex => $blocks) {
        $offset = 0;
        foreach ($blocks as $blockIndex => $chairCount) {
            $run = 0;
            for ($localChair = 1; $localChair <= $chairCount; $localChair++) {
                $chair = $offset + $localChair;
                $run = isset($used[($rowIndex + 1) . ':' . $chair]) ? 0 : $run + 1;
                if ($run >= $quantity) {
                    $seats = [];
                    for ($number = $chair - $quantity + 1; $number <= $chair; $number++) {
                        $seat = ['row' => $rowIndex + 1, 'chair' => $number];
                        if (count($blocks) > 1) $seat['block'] = $blockIndex + 1;
                        $seats[] = $seat;
                    }
                    return $seats;
                }
            }
            $offset += $chairCount;
        }
    }
    // When no row can hold the party, keep the split within one aligned block.
    if ($quantity >= 3) {
        $rowCount = count($segments);
        for ($height = 2; $height <= min($rowCount, $quantity); $height++) {
            $width = (int)ceil($quantity / $height);
            if ($width < $height) break;
            for ($startRow = 0; $startRow + $height <= $rowCount; $startRow++) {
                foreach ($segments[$startRow] as $blockIndex => $_) {
                    $maximumStart = PHP_INT_MAX;
                    for ($offset = 0; $offset < $height; $offset++) {
                        if (count($segments[$startRow + $offset]) !== count($segments[$startRow])
                            || !isset($segments[$startRow + $offset][$blockIndex])) {
                            $maximumStart = 0;
                            break;
                        }
                        $inRow = min($width, $quantity - $offset * $width);
                        $maximumStart = min($maximumStart, $segments[$startRow + $offset][$blockIndex] - $inRow + 1);
                    }
                    for ($localStart = 1; $localStart <= $maximumStart; $localStart++) {
                        $candidate = [];
                        $valid = true;
                        for ($offset = 0; $offset < $height && $valid; $offset++) {
                            $rowIndex = $startRow + $offset;
                            $rowBlocks = $segments[$rowIndex];
                            $chairOffset = array_sum(array_slice($rowBlocks, 0, $blockIndex));
                            $inRow = min($width, $quantity - count($candidate));
                            for ($local = $localStart; $local < $localStart + $inRow; $local++) {
                                $chair = $chairOffset + $local;
                                if (isset($used[($rowIndex + 1) . ':' . $chair])) {
                                    $valid = false;
                                    break;
                                }
                                $seat = ['row' => $rowIndex + 1, 'chair' => $chair];
                                if (count($rowBlocks) > 1) $seat['block'] = $blockIndex + 1;
                                $candidate[] = $seat;
                            }
                        }
                        if ($valid && count($candidate) === $quantity) return $candidate;
                    }
                }
            }
        }
    }
    // Neighboring rows may align only within the same block layout.
    foreach ($segments as $startRow => $blocks) {
        foreach ($blocks as $blockIndex => $chairCount) {
            for ($startChair = 1; $startChair <= $chairCount; $startChair++) {
                $seats = [];
                for ($rowIndex = $startRow; $rowIndex < count($segments); $rowIndex++) {
                    if (count($segments[$rowIndex]) !== count($blocks)) break;
                    $rowBlocks = $segments[$rowIndex];
                    $rowOffset = array_sum(array_slice($rowBlocks, 0, $blockIndex));
                    $run = 0;
                    for ($localChair = $startChair; $localChair <= $rowBlocks[$blockIndex]; $localChair++) {
                        $chair = $rowOffset + $localChair;
                        if (isset($used[($rowIndex + 1) . ':' . $chair])) break;
                        $seat = ['row' => $rowIndex + 1, 'chair' => $chair];
                        if (count($rowBlocks) > 1) $seat['block'] = $blockIndex + 1;
                        $seats[] = $seat;
                        $run++;
                        if (count($seats) === $quantity) return $seats;
                    }
                    if ($run === 0) break;
                }
            }
        }
    }
    return [];
}

/** @return array<int,array{row:int,chair:int,block:int,x:int}> */
function egmSeatMapFreeSeats(array $segments, array $used): array
{
    $free = [];
    foreach ($segments as $rowIndex => $blocks) {
        $offset = 0;
        foreach ($blocks as $blockIndex => $count) {
            for ($local = 1; $local <= $count; $local++) {
                $chair = $offset + $local;
                if (isset($used[($rowIndex + 1) . ':' . $chair])) continue;
                $free[] = [
                    'row' => $rowIndex + 1, 'chair' => $chair, 'block' => $blockIndex + 1,
                    'x' => $chair + 3 * $blockIndex,
                ];
            }
            $offset += $count;
        }
    }
    return $free;
}

/** Fill as few nearby rows as possible, allowing gaps and aisles only with approval. */
function egmSeatMapFindClosest(array $segments, array $used, int $quantity): array
{
    $byRow = [];
    foreach (egmSeatMapFreeSeats($segments, $used) as $seat) $byRow[$seat['row']][] = $seat;
    $rowCount = count($segments);
    $bestBand = null;
    $bestScore = null;
    for ($start = 1; $start <= $rowCount; $start++) {
        for ($end = $start; $end <= $rowCount; $end++) {
            $available = 0;
            $occupiedRows = 0;
            for ($row = $start; $row <= $end; $row++) {
                $count = count($byRow[$row] ?? []);
                $available += $count;
                if ($count > 0) $occupiedRows++;
            }
            if ($available < $quantity) continue;
            // Row count wins over column alignment; a large party fills one row
            // before taking the remaining chairs in the next nearby row.
            $score = [$occupiedRows, $end - $start, -count($byRow[$start] ?? []), $start];
            if ($bestScore === null || $score < $bestScore) {
                $bestScore = $score;
                $bestBand = [$start, $end];
            }
            break;
        }
    }
    if ($bestBand === null) return [];
    $rowAllocations = [];
    $remaining = $quantity;
    for ($row = $bestBand[0]; $row <= $bestBand[1]; $row++) {
        if (($byRow[$row] ?? []) !== []) $rowAllocations[$row] = 0;
    }
    // Keep a split group as rectangular as the available chairs allow.
    while ($remaining > 0) {
        foreach ($rowAllocations as $row => $count) {
            if ($remaining === 0) break;
            if ($count >= count($byRow[$row])) continue;
            $rowAllocations[$row]++;
            $remaining--;
        }
    }
    $best = [];
    foreach ($rowAllocations as $row => $needed) {
        if ($needed === 0) continue;
        $free = $byRow[$row];
        if (count($free) === $needed) {
            array_push($best, ...$free);
            continue;
        }
        // Keep each row's chairs in the tightest available run.
        $selected = [];
        $selectedScore = null;
        for ($index = 0, $last = count($free) - $needed; $index <= $last; $index++) {
            $window = array_slice($free, $index, $needed);
            $first = $window[0];
            $lastSeat = $window[count($window) - 1];
            $score = [$lastSeat['x'] - $first['x'], $lastSeat['block'] - $first['block'], $first['x']];
            if ($selectedScore === null || $score < $selectedScore) {
                $selectedScore = $score;
                $selected = $window;
            }
        }
        array_push($best, ...$selected);
    }
    return array_map(static fn(array $seat): array => [
        'row' => $seat['row'], 'chair' => $seat['chair'], 'block' => $seat['block'],
    ], $best);
}

final class EgmSeatSplitRequiredException extends InvalidArgumentException
{
    public array $suggestedSeats;

    public function __construct(array $suggestedSeats)
    {
        $this->suggestedSeats = $suggestedSeats;
        parent::__construct('صندلی‌های یکپارچه برای این گروه پیدا نشد. صندلی‌های نزدیکِ جدا از هم پیشنهاد شده‌اند؛ برای ثبت ورود باید تأیید کنید.');
    }
}

final class EgmSeatCapacityException extends InvalidArgumentException
{
    public function __construct()
    {
        parent::__construct('ظرفیت صندلی‌های سالن برای این مهمان کافی نیست. می‌توانید با تأیید اپراتور بلیت «در صورت خالی بودن صندلی» صادر کنید.');
    }
}

function egmSeatMapLock(array $context): void
{
    $dataTable = (string)$context['tables']['data'];
    $lock = $context['pdo']->prepare("SELECT `data_key` FROM `{$dataTable}` WHERE `data_key`=:key FOR UPDATE");
    $lock->execute([':key' => EGM_INSTANCE_SCHEMA_VERSION_KEY]);
    if (!$lock->fetchColumn()) throw new RuntimeException('قفل سالن در دسترس نیست.');
}

function egmSeatMapValidateOccupied(array $context, string $periodCode, array $map): void
{
    if (!$map['enabled']) return;
    $table = (string)$context['tables']['user_periods'];
    $sql = "SELECT `period_code`,`seat_assignment_json` FROM `{$table}` WHERE `seat_assignment_json` IS NOT NULL";
    $params = [];
    if ($periodCode !== '') {
        $sql .= ' AND `period_code`=:period_code';
        $params[':period_code'] = $periodCode;
    }
    $find = $context['pdo']->prepare($sql);
    $find->execute($params);
    $overrideCache = [];
    foreach ($find->fetchAll(PDO::FETCH_ASSOC) as $record) {
        $code = (string)$record['period_code'];
        if ($periodCode === '') {
            if (!array_key_exists($code, $overrideCache)) {
                $override = egmInstanceReadData($context['pdo'], (string)$context['code'], 'seat_map:' . $code, []);
                $overrideCache[$code] = is_array($override) && ($override['mode'] ?? '') === 'custom';
            }
            if ($overrideCache[$code]) continue;
        }
        $assignment = json_decode((string)$record['seat_assignment_json'], true);
        if (!is_array($assignment)) continue;
        if ((string)($assignment['ticket_id'] ?? '') !== $map['ticketId']) {
            throw new InvalidArgumentException('بلیت مبنای سالن پس از تخصیص صندلی قابل تغییر نیست.');
        }
        foreach ((array)($assignment['seats'] ?? []) as $seat) {
            $row = (int)($seat['row'] ?? 0);
            $chair = (int)($seat['chair'] ?? 0);
            if ($row < 1 || $chair < 1 || $chair > ($map['rows'][$row - 1] ?? 0)) {
                throw new InvalidArgumentException('نقشه جدید یکی از صندلی‌های تخصیص‌یافته را حذف می‌کند.');
            }
            if (isset($seat['block'])) {
                $block = (int)$seat['block'];
                $blocks = $map['segments'][$row - 1] ?? [];
                $start = array_sum(array_slice($blocks, 0, max(0, $block - 1))) + 1;
                $end = $start + (int)($blocks[$block - 1] ?? 0) - 1;
                if ($block < 1 || $chair < $start || $chair > $end) {
                    throw new InvalidArgumentException('نقشه جدید بخش صندلی‌های تخصیص‌یافته را تغییر می‌دهد.');
                }
            }
        }
    }
}

/** A free-seat guest consumes one place without reserving a numbered chair. */
function egmSeatMapFreeGuestCount(array $context, string $periodCode): int
{
    $table = (string)$context['tables']['user_periods'];
    $find = $context['pdo']->prepare(
        "SELECT COUNT(*) FROM `{$table}` WHERE `period_code`=:period_code AND `seat_mode`='free' "
        . "AND `entered_date` IS NOT NULL AND `entered_time` IS NOT NULL"
    );
    $find->execute([':period_code' => $periodCode]);
    return (int)$find->fetchColumn();
}

function egmSeatMapEnsureFreeCapacity(array $context, string $periodCode, bool $allowOverflow = false): void
{
    $map = egmSeatMapEffective($context, $periodCode);
    if (!$map['enabled']) return;
    $table = (string)$context['tables']['user_periods'];
    $find = $context['pdo']->prepare(
        "SELECT `seat_assignment_json` FROM `{$table}` WHERE `period_code`=:period_code AND `seat_assignment_json` IS NOT NULL"
    );
    $find->execute([':period_code' => $periodCode]);
    $assignedCount = 0;
    foreach ($find->fetchAll(PDO::FETCH_COLUMN) as $json) {
        $assignment = json_decode((string)$json, true);
        $assignedCount += count((array)($assignment['seats'] ?? []));
    }
    if (!$allowOverflow && $assignedCount + egmSeatMapFreeGuestCount($context, $periodCode) > array_sum($map['rows'])) {
        throw new EgmSeatCapacityException();
    }
}

function egmSeatMapValidateCapacity(array $context, string $periodCode, array $map): void
{
    if (!$map['enabled']) return;
    $table = (string)$context['tables']['user_periods'];
    $find = $context['pdo']->prepare(
        "SELECT `period_code`,`seat_assignment_json` FROM `{$table}` "
        . "WHERE `seat_assignment_json` IS NOT NULL"
        . ($periodCode !== '' ? ' AND `period_code`=:period_code' : '')
    );
    $find->execute($periodCode !== '' ? [':period_code' => $periodCode] : []);
    $totals = [];
    $skip = [];
    foreach ($find->fetchAll(PDO::FETCH_ASSOC) as $record) {
        $code = (string)$record['period_code'];
        if ($periodCode === '') {
            if (!array_key_exists($code, $skip)) {
                $override = egmInstanceReadData($context['pdo'], (string)$context['code'], 'seat_map:' . $code, []);
                $skip[$code] = is_array($override) && ($override['mode'] ?? '') === 'custom';
            }
            if ($skip[$code]) continue;
        }
        $assignment = json_decode((string)($record['seat_assignment_json'] ?? ''), true);
        $totals[$code] = ($totals[$code] ?? 0) + count((array)($assignment['seats'] ?? []));
    }
    foreach ($totals as $total) {
        if ($total > array_sum($map['rows'])) {
            throw new InvalidArgumentException('ظرفیت نقشه جدید از تعداد مهمانان واردشده کمتر است.');
        }
    }
}

/** Allocate inside the caller's transaction, after its guest period row is locked. */
function egmSeatMapAssign(array $context, string $periodCode, int $userId, array $ticketNumbers, bool $allowSplit = false, bool $allowFreeSeat = false): ?array
{
    $map = egmSeatMapEffective($context, $periodCode);
    if (!$map['enabled']) return null;
    $rawQuantity = trim((string)($ticketNumbers[$map['ticketId']] ?? ''));
    if ($rawQuantity === '') return null;
    if (!preg_match('/^[0-9]{1,4}$/D', $rawQuantity) || (int)$rawQuantity < 1 || (int)$rawQuantity > 500) {
        throw new InvalidArgumentException('تعداد بلیت مبنای صندلی باید بین ۱ تا ۵۰۰ باشد.');
    }
    $quantity = (int)$rawQuantity;
    $pdo = $context['pdo'];
    if (!$pdo->inTransaction()) throw new LogicException('تخصیص صندلی باید در تراکنش ورود انجام شود.');
    $periodsTable = (string)$context['tables']['user_periods'];
    $find = $pdo->prepare("SELECT `id`,`seat_assignment_json`,`seat_mode` FROM `{$periodsTable}` WHERE `user_id`=:user_id AND `period_code`=:period_code FOR UPDATE");
    $find->execute([':user_id' => $userId, ':period_code' => $periodCode]);
    $current = $find->fetch(PDO::FETCH_ASSOC);
    if (!is_array($current)) throw new RuntimeException('دعوت مهمان برای تخصیص صندلی پیدا نشد.');
    if ((string)($current['seat_mode'] ?? '') === 'free') return null;
    $existing = json_decode((string)($current['seat_assignment_json'] ?? ''), true);
    if (is_array($existing) && !empty($existing['seats'])) {
        if (count($existing['seats']) !== $quantity) throw new InvalidArgumentException('تعداد بلیت با صندلی‌های تخصیص‌یافته قبلی یکسان نیست.');
        return $existing;
    }
    $all = $pdo->prepare("SELECT `seat_assignment_json` FROM `{$periodsTable}` WHERE `period_code`=:period_code AND `seat_assignment_json` IS NOT NULL FOR UPDATE");
    $all->execute([':period_code' => $periodCode]);
    $used = [];
    foreach ($all->fetchAll(PDO::FETCH_COLUMN) as $json) {
        $assignment = json_decode((string)$json, true);
        foreach ((array)($assignment['seats'] ?? []) as $seat) {
            $used[(int)($seat['row'] ?? 0) . ':' . (int)($seat['chair'] ?? 0)] = true;
        }
    }
    $freeGuests = egmSeatMapFreeGuestCount($context, $periodCode);
    $insufficient = count($used) + $freeGuests + $quantity > array_sum($map['rows']);
    if (!$insufficient) {
        $freeSeats = egmSeatMapFreeSeats($map['segments'], $used);
        $insufficient = count($freeSeats) < $quantity;
    }
    if ($insufficient) {
        if (!$allowFreeSeat) throw new EgmSeatCapacityException();
        $saveFree = $pdo->prepare("UPDATE `{$periodsTable}` SET `seat_mode`='free',`seat_assignment_json`=NULL WHERE `id`=:id");
        $saveFree->execute([':id' => (int)$current['id']]);
        return null;
    }
    $seats = egmSeatMapFindContiguous($map['rows'], $used, $quantity, $map['segments']);
    $suggested = egmSeatMapFindClosest($map['segments'], $used, $quantity);
    $contiguousRows = count(array_unique(array_column($seats, 'row')));
    $suggestedRows = count(array_unique(array_column($suggested, 'row')));
    $split = false;
    if ($seats === [] || ($suggestedRows > 0 && $suggestedRows < $contiguousRows)) {
        if (count($suggested) !== $quantity) throw new EgmSeatCapacityException();
        if (!$allowSplit) throw new EgmSeatSplitRequiredException($suggested);
        $seats = $suggested;
        $split = true;
    }
    $assignment = ['ticket_id' => $map['ticketId'], 'seats' => $seats, 'split' => $split, 'assigned_at' => date('Y-m-d H:i:s')];
    $save = $pdo->prepare("UPDATE `{$periodsTable}` SET `seat_assignment_json`=:assignment WHERE `id`=:id");
    $save->execute([':assignment' => json_encode($assignment, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), ':id' => (int)$current['id']]);
    return $assignment;
}
