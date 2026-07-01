<?php
declare(strict_types=1);

const STANDALONE_DRAW_CAPTION = 'قرعه‌کشی';

/**
 * Replace this function body to load eligible participants from your project.
 *
 * Required fields: id, code, full_name
 * Optional fields: firstname, lastname, phone_number, national_id, gender
 *
 * @return array<int, array<string, mixed>>
 */
function loadStandaloneParticipants(): array
{
    $path = __DIR__ . '/data/participants.json';
    if (!is_file($path)) {
        return [];
    }

    $decoded = json_decode((string) file_get_contents($path), true);
    $rows = is_array($decoded) ? $decoded : [];
    $participants = [];

    foreach ($rows as $index => $row) {
        if (!is_array($row)) {
            continue;
        }

        $id = trim((string) ($row['id'] ?? $index + 1));
        $code = preg_replace('/\D+/', '', (string) ($row['code'] ?? ''));
        $fullName = trim((string) ($row['full_name'] ?? ''));
        if ($id === '' || $code === '' || $fullName === '') {
            continue;
        }

        $code = str_pad(substr($code, -4), 4, '0', STR_PAD_LEFT);
        $nameParts = preg_split('/\s+/', $fullName, 2) ?: [];
        $participants[] = [
            'id' => $id,
            'number' => (int) ($row['number'] ?? $id),
            'code' => $code,
            'invite_code' => $code,
            'full_name' => $fullName,
            'firstname' => (string) ($row['firstname'] ?? $nameParts[0] ?? ''),
            'lastname' => (string) ($row['lastname'] ?? $nameParts[1] ?? ''),
            'gender' => (string) ($row['gender'] ?? ''),
            'national_id' => (string) ($row['national_id'] ?? ''),
            'phone_number' => (string) ($row['phone_number'] ?? ''),
            'event_name' => 'standalone',
            'event_code' => 'standalone',
            'event_slug' => 'standalone',
        ];
    }

    return $participants;
}
