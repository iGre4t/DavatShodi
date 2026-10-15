<?php
declare(strict_types=1);
require_once __DIR__ . '/egm-game-exports.php';

/** Include unfinished teams and partial rounds; never count a completed level twice. */
function egmGameRankTeams(array $game, array $teams, array $profiles, int $limit): array
{
    if (!empty($game['no_score_needed'])) return [];
    $groups = []; $levels = egmGamesPlayableLevels($game);
    foreach ($teams as $team) {
        $members = [];
        foreach ($team['members'] as $member) {
            $profile = $profiles[(int)($member['id'] ?? 0)] ?? [];
            $members[] = array_replace($member, $profile, ['id'=>$member['id'] ?? 0, 'name'=>$member['name'] ?? '']);
        }
        $gender = egmRefMonitorTeamGender($members);
        if ($game['gender_mode'] === 'separated' && !in_array($gender, ['male','female'], true)) continue;
        $team['members'] = $members; $team['ranking_total'] = 0.0; $team['completed_levels'] = 0;
        foreach ($levels as $level) {
            $score = $team['scores'][$level['id']] ?? null;
            if (is_array($score)) { $team['ranking_total'] += (float)($score['score'] ?? 0); $team['completed_levels']++; }
            else foreach ($team['score_attempts'][$level['id']] ?? [] as $attempt) $team['ranking_total'] += (float)($attempt['score'] ?? 0);
        }
        $key = $game['gender_mode'] === 'separated' ? $gender : 'all';
        $groups[$key][] = $team;
    }
    $result = [];
    foreach (['all','male','female'] as $group) {
        $list = $groups[$group] ?? [];
        usort($list, static fn($a,$b) => ($b['ranking_total'] <=> $a['ranking_total']) ?: ($a['id'] <=> $b['id']));
        $previous = null; $rank = 0;
        foreach ($list as $index=>$team) {
            if ($previous === null || $previous !== $team['ranking_total']) $rank = $index + 1;
            $previous = $team['ranking_total'];
            if ($rank > $limit) break;
            $team['rank'] = $rank; $team['ranking_group'] = $group; $result[] = $team;
        }
    }
    return $result;
}

function egmGameRankingOptions(array $context, string $period): array
{
    $state = egmGamesState($context); $options = [];
    foreach ($state['games'] as $game) if (in_array($game['id'], $state['enabled'][$period] ?? [], true) && empty($game['no_score_needed'])) $options[] = ['id'=>$game['id'], 'name'=>$game['name'], 'gender_mode'=>$game['gender_mode']];
    return $options;
}

function egmGameRankingData(array $context, string $period, string $gameId, int $limit): array
{
    if ($limit < 1 || $limit > 1000) throw new InvalidArgumentException('رتبه پایان باید بین ۱ تا ۱۰۰۰ باشد.');
    $state = egmGamesState($context); $profiles = []; $result = []; $found = false;
    foreach (egmPeriodExportGuestRows($context, $period) as $profile) $profiles[(int)$profile['user_id']] = $profile;
    foreach ($state['games'] as $game) {
        if (!in_array($game['id'], $state['enabled'][$period] ?? [], true) || ($gameId !== '' && $game['id'] !== $gameId)) continue;
        if (!empty($game['no_score_needed'])) continue;
        $found = true;
        $teams = egmRefMonitorListTeams($context['pdo'], (string)$context['code'], $period, $game['id']);
        $result[] = ['game'=>$game, 'teams'=>egmGameRankTeams($game, $teams, $profiles, $limit)];
    }
    if (!$found) throw new InvalidArgumentException('بازی امتیازی برای این بازه پیدا نشد.');
    if (!array_sum(array_map(static fn($item)=>count($item['teams']), $result))) throw new InvalidArgumentException('تیمی برای رتبه‌بندی در این بازه ثبت نشده است.');
    return $result;
}

function egmGameRankingGroup(string $group): string { return ['male'=>'آقایان','female'=>'بانوان','all'=>'همه تیم‌ها'][$group] ?? ''; }
function egmGameRankingOrdinal(int $rank): string { return [1=>'اول',2=>'دوم',3=>'سوم',4=>'چهارم',5=>'پنجم',6=>'ششم',7=>'هفتم',8=>'هشتم',9=>'نهم',10=>'دهم'][$rank] ?? strtr((string)$rank, ['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']); }

function egmGameRankingXlsx(array $data, string $periodTitle): string
{
    $sheets = []; $summary = [['رتبه‌بندی بازی‌ها', $periodTitle], ['رتبه','بازی','گروه','تیم','امتیاز کل','مراحل کامل‌شده','وضعیت']]; $used = [];
    foreach ($data as $item) foreach ($item['teams'] as $team) {
        $game = $item['game']; $group = egmGameRankingGroup($team['ranking_group']); $levels = egmGamesPlayableLevels($game);
        $status = $team['completed_levels'] === count($levels) ? 'کامل' : 'ناتمام';
        $summary[] = [$team['rank'],$game['name'],$group,$team['name'],$team['ranking_total'],$team['completed_levels'],$status];
        $name = appXlsxSheetName($team['name'], count($sheets)+1); $base = $name; $suffix = 1;
        while (isset($used[mb_strtolower($name, 'UTF-8')]) || $name === 'رتبه‌بندی') $name = appXlsxSheetName(mb_substr($base,0,23,'UTF-8').' '.(++$suffix), count($sheets)+1);
        $used[mb_strtolower($name, 'UTF-8')] = true;
        $rows = [['تیم',$team['name']],['بازی',$game['name']],['بازه',$periodTitle],['رتبه',$team['rank']],['گروه',$group],['امتیاز کل',$team['ranking_total']],['وضعیت',$status],[],['اعضای تیم'],['نام','کد ملی','کد پرسنلی','شماره همراه','جنسیت']];
        foreach ($team['members'] as $member) $rows[] = [$member['name'],(string)($member['national_id']??''),(string)($member['work_id']??''),(string)($member['phone_number']??''),(string)($member['gender']??'')];
        $rows[] = []; $rows[] = ['امتیازهای بازی']; $rows[] = ['مرحله','نوبت بازی','امتیاز','اتاق','زمان ثبت','ثبت‌کننده','وضعیت'];
        foreach ($levels as $level) {
            $score = $team['scores'][$level['id']] ?? null; $attempts = $team['score_attempts'][$level['id']] ?? [];
            if (!$attempts && is_array($score)) $attempts = [$score];
            $sum = 0.0;
            foreach ($attempts as $i=>$attempt) { $sum += (float)($attempt['score']??0); $rows[] = [$level['name'],$i+1,(float)($attempt['score']??0),(string)($attempt['room_name']??$score['room_name']??''),(string)($attempt['submitted_at']??''),(string)($attempt['submitted_by']??''),'ثبت شده']; }
            $rows[] = [$level['name'],'جمع مرحله',is_array($score)?(float)($score['score']??0):$sum,'','','',is_array($score)?'کامل':'ناتمام'];
        }
        $sheets[] = ['name'=>$name,'rows'=>$rows,'headers'=>[9,10,count($team['members'])+12,count($team['members'])+13]];
    }
    array_unshift($sheets, ['name'=>'رتبه‌بندی','rows'=>$summary,'headers'=>[1,2]]);
    $files = []; $names = ''; $rels = ''; $types = '';
    foreach ($sheets as $i=>$sheet) {
        $n = $i+1; $rows = '';
        foreach ($sheet['rows'] as $ri=>$values) {
            $r = $ri+1; $cells = '';
            foreach ($values as $ci=>$value) {
                $ref = appXlsxColumnName($ci+1).$r; $style = in_array($r,$sheet['headers'],true)?1:0;
                $cells .= '<c r="'.$ref.'" s="'.$style.'"'.(is_int($value)||is_float($value)?'><v>'.appXlsxXml((string)$value).'</v>':' t="inlineStr"><is><t xml:space="preserve">'.appXlsxXml((string)$value).'</t></is>').'</c>';
            }
            $rows .= '<row r="'.$r.'">'.$cells.'</row>';
        }
        $files['xl/worksheets/sheet'.$n.'.xml'] = '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView rightToLeft="1" workbookViewId="0"/></sheetViews><cols><col min="1" max="1" width="30" customWidth="1"/><col min="2" max="7" width="24" customWidth="1"/></cols><sheetData>'.$rows.'</sheetData></worksheet>';
        $names .= '<sheet name="'.appXlsxXml($sheet['name']).'" sheetId="'.$n.'" r:id="rId'.$n.'"/>';
        $rels .= '<Relationship Id="rId'.$n.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$n.'.xml"/>';
        $types .= '<Override PartName="/xl/worksheets/sheet'.$n.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
    }
    $files['[Content_Types].xml'] = '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'.$types.'</Types>';
    $files['_rels/.rels'] = '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>';
    $files['xl/workbook.xml'] = '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'.$names.'</sheets></workbook>';
    $files['xl/_rels/workbook.xml.rels'] = '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$rels.'<Relationship Id="styles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
    $files['xl/styles.xml'] = appXlsxStylesXml();
    return appXlsxZip($files);
}

function egmGameRankingDocx(array $data, string $periodTitle): string
{
    $paragraph = static function(string $text, string $style = 'Normal'): string {
        return '<w:p><w:pPr><w:pStyle w:val="'.$style.'"/><w:bidi/><w:jc w:val="right"/><w:keepNext w:val="'.($style==='Heading1'?'1':'0').'"/></w:pPr><w:r><w:rPr><w:rtl/></w:rPr><w:t xml:space="preserve">'.appXlsxXml($text).'</w:t></w:r></w:p>';
    };
    $body = $paragraph('رتبه‌بندی بازی‌ها', 'Title').$paragraph($periodTitle);
    foreach ($data as $item) {
        $body .= $paragraph($item['game']['name'], 'Heading1');
        foreach ($item['teams'] as $team) {
            $heading = 'رتبه '.egmGameRankingOrdinal($team['rank']);
            if ($team['ranking_group'] !== 'all') $heading .= ' '.egmGameRankingGroup($team['ranking_group']);
            $body .= $paragraph($heading, 'Heading1').$paragraph('تیم '.$team['name']);
            foreach ($team['members'] as $member) {
                $gender = egmGamesNormalizeGender((string)($member['gender']??''));
                $prefix = $gender==='male'?'آقای ':($gender==='female'?'خانم ':'');
                $body .= $paragraph('• '.$prefix.$member['name']);
            }
        }
    }
    return appXlsxZip([
        '[Content_Types].xml'=>'<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/><Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/></Types>',
        '_rels/.rels'=>'<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="document" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>',
        'word/document.xml'=>'<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'.$body.'<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1134" w:right="1134" w:bottom="1134" w:left="1134"/></w:sectPr></w:body></w:document>',
        'word/_rels/document.xml.rels'=>'<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="styles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
        'word/styles.xml'=>'<?xml version="1.0" encoding="UTF-8"?><w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Arial" w:hAnsi="Arial" w:cs="Arial"/><w:sz w:val="28"/><w:szCs w:val="28"/><w:lang w:bidi="fa-IR"/></w:rPr></w:rPrDefault><w:pPrDefault><w:pPr><w:bidi/><w:spacing w:after="160"/></w:pPr></w:pPrDefault></w:docDefaults><w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/></w:style><w:style w:type="paragraph" w:styleId="Title"><w:name w:val="Title"/><w:rPr><w:b/><w:bCs/><w:sz w:val="40"/><w:szCs w:val="40"/></w:rPr></w:style><w:style w:type="paragraph" w:styleId="Heading1"><w:name w:val="heading 1"/><w:pPr><w:keepNext/><w:spacing w:before="240" w:after="160"/></w:pPr><w:rPr><w:b/><w:bCs/><w:sz w:val="32"/><w:szCs w:val="32"/></w:rPr></w:style></w:styles>'
    ]);
}
