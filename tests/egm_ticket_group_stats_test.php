<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/api/lib/egm-check-in.php';
function statsAssert(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$rows = [
    ['group_id'=>'a','entered_date'=>'2026-09-16','entered_time'=>'10:00','ticket_numbers_json'=>'{"food":"6","gift":"2"}'],
    ['group_id'=>'a','entered_date'=>'2026-09-16','entered_time'=>'10:01','ticket_numbers_json'=>'{"food":"9","gift":"۳"}'],
    ['group_id'=>'b','entered_date'=>'2026-09-16','entered_time'=>'10:02','ticket_numbers_json'=>'{"food":"5","gift":"0"}'],
    ['group_id'=>'a','entered_date'=>null,'entered_time'=>null,'ticket_numbers_json'=>'{"food":"100"}'],
    ['group_id'=>'','entered_date'=>'2026-09-16','entered_time'=>'10:03','ticket_numbers_json'=>'{"food":"4"}'],
    ['group_id'=>'b','entered_date'=>'2026-09-16','entered_time'=>'10:04','ticket_numbers_json'=>'{}','number_of_ticket'=>'7']
];
$tickets = [['id'=>'food','title'=>'غذا'],['id'=>'gift','title'=>'هدیه'],['id'=>'default','title'=>'بلیت قدیمی']];
$groups = [['id'=>'a','title'=>'گروه الف'],['id'=>'b','title'=>'گروه ب'],['id'=>'c','title'=>'گروه خالی']];
$stats = egmCheckInTicketAndGroupStats($rows, $tickets, $groups);
statsAssert(array_column($stats['ticket_totals'],'sum') === ['24','5','7'], 'Ticket sums incorrect');
statsAssert($stats['groups'][0]['total'] === 3 && $stats['groups'][0]['entered'] === 2, 'Group A progress incorrect');
statsAssert($stats['groups'][1]['total'] === 2 && $stats['groups'][1]['entered'] === 2, 'Group B progress incorrect');
statsAssert($stats['groups'][2]['total'] === 0 && $stats['groups'][2]['entered'] === 0, 'Empty group lost');
statsAssert(egmCheckInAddTicketQuantity('99999999999999999999999999999999','1') === '100000000000000000000000000000000', 'Large ticket quantity overflowed');
$rows[5]['ticket_numbers_json'] = '{"default":"3"}';
statsAssert(egmCheckInTicketAndGroupStats($rows,$tickets,$groups)['ticket_totals'][2]['sum'] === '3', 'Legacy quantity counted twice');

$manual = egmCheckInManualTicketTotalsFromRows([
    ['ticket_id'=>'food','ticket_title'=>'Old food title','quantity'=>'6'],
    ['ticket_id'=>'food','ticket_title'=>'Old food title','quantity'=>'۹'],
    ['ticket_id'=>'gift','ticket_title'=>'Gift','quantity'=>'0005'],
    ['ticket_id'=>'bad','ticket_title'=>'Bad','quantity'=>'not-a-number'],
], $tickets);
statsAssert(array_column($manual, 'sum') === ['15','5'], 'Manual ticket database totals incorrect');
statsAssert(($manual[0]['title'] ?? '') === ($tickets[0]['title'] ?? ''), 'Configured manual ticket title not preferred');
echo "Ticket totals and group progress test passed.\n";
