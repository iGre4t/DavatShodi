<?php
declare(strict_types=1);

/** A duplicate starts as a new, inactive period with the source's configuration. */
function egmPeriodDuplicateRow(array $source, string $id, string $code, int $order): array
{
    return array_replace($source, [
        'id' => $id,
        'tagCode' => $code,
        'title' => 'کپی ' . trim((string)($source['title'] ?? 'بازه')),
        'active' => false,
        'activationPending' => true,
        'order' => $order,
        'createdAt' => date('Y-m-d H:i:s'),
        'endedAt' => '',
        'endedBy' => '',
        'endedNoQuitResolution' => '',
        'endedNoQuitCount' => 0,
    ]);
}

/** Draw definitions are settings; selections and winners belong to the old period. */
function egmPeriodDuplicateDraws(array $draws): array
{
    $copies = [];
    foreach ($draws as $id => $draw) {
        if (!is_array($draw)) continue;
        $copies[$id] = array_replace($draw, [
            'locked' => false,
            'winners' => [],
            'pending' => [],
        ]);
    }
    return $copies;
}
