<?php
declare(strict_types=1);
require_once __DIR__ . '/egm-period-draws.php';

function egmGeneralDrawDefinitions(array $context): array
{
    return egmInstanceReadData($context['pdo'], $context['code'], 'general_draws', []);
}

function egmGeneralDrawRun(array $context, string $action, array $input, string $actor): array
{
    $id = (string)($input['levelId'] ?? $input['id'] ?? '');
    if (in_array($action, ['list', 'create', 'save', 'delete'], true)) {
        $pdo = $context['pdo'];
        $lock = 'egm_general_' . substr(hash('sha256', $context['code']), 0, 48);
        $query = $pdo->prepare('SELECT GET_LOCK(:name, 10)');
        $query->execute([':name' => $lock]);
        if ((int)$query->fetchColumn() !== 1) throw new RuntimeException('قرعه‌کشی در حال ذخیره است.');
        try {
            $draws = egmGeneralDrawDefinitions($context);
            if ($action === 'create' || $action === 'save') {
                $settings = egmPeriodDrawSettings($input);
                $settings['keepConfirmedCards'] = filter_var($input['keepConfirmedCards'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($settings['keepConfirmedCards'] === null) throw new InvalidArgumentException('حالت کارت‌های تأییدشده معتبر نیست.');
                foreach (['preventRepeatedPrizeNames','preventRepeatedPrizes'] as $option) {
                    $settings[$option] = filter_var($input[$option] ?? ($draws[$id][$option] ?? true), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                    if ($settings[$option] === null) throw new InvalidArgumentException('تنظیم تکرار جایزه معتبر نیست.');
                }
                if ($action === 'create') $id = bin2hex(random_bytes(16));
                elseif (!isset($draws[$id])) throw new InvalidArgumentException('قرعه‌کشی پیدا نشد.');
                if ($action === 'save') {
                    $activePeriod = '';
                    try { $activePeriod = egmPeriodDrawActiveCode($context); } catch (InvalidArgumentException $error) { /* Settings can be edited between periods. */ }
                    if ($activePeriod !== '') {
                        $history = egmInstanceReadData($pdo, $context['code'], egmPeriodDrawKey($activePeriod), []);
                        if ($settings['winnerLimit'] < count($history['general-' . $id]['winners'] ?? [])) throw new InvalidArgumentException('تعداد مجاز از برندگان بازهٔ فعال کمتر است.');
                    }
                }
                $draws[$id] = $settings + ['id' => $id];
            } elseif ($action === 'delete') {
                if (!isset($draws[$id])) throw new InvalidArgumentException('قرعه‌کشی پیدا نشد.');
                unset($draws[$id]);
            }
            if ($action !== 'list') egmInstanceWriteData($pdo, $context['code'], 'general_draws', $draws);
            return ['draws' => array_values($draws)];
        } finally {
            $query = $pdo->prepare('SELECT RELEASE_LOCK(:name)');
            $query->execute([':name' => $lock]);
        }
    }
    $definition = egmGeneralDrawDefinitions($context)[$id] ?? null;
    if (!is_array($definition)) throw new InvalidArgumentException('قرعه‌کشی پیدا نشد.');
    $period = egmPeriodDrawActiveCode($context);
    // A page opened in a previous period must never confirm its candidate in a new one.
    if (trim((string)($input['period_code'] ?? '')) !== '' && $input['period_code'] !== $period) {
        throw new InvalidArgumentException('بازهٔ فعال تغییر کرده است؛ صفحه را تازه‌سازی کنید.');
    }
    $input['levelId'] = 'general-' . $id;
    $input['generalDefinition'] = $definition;
    return egmPeriodDrawRun($context, $period, $action, $input, $actor) + ['periodCode' => $period];
}
