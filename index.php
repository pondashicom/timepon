<?php
// SPDX-License-Identifier: MIT
/* =========================================================
 * カンファレンスタイマー 「TIME-PON」
 * バージョン 1.0.1
 * 
  * 【概要】
 * 1) カンファレンスやプレゼンテーションなどの場で、遠隔操作で講演者に残り時間を表示したり、カンペを出すための Web アプリケーションです。  
 * 2) タイマーを演台において、制御側はオペレータが操作するという、ビューとコントロールが完全に分離されたタイマーシステムです。
 * 3) スピーカービューはカウントダウンタイマーと、オペレーターからの「カンペ」を見ることができます。
 * 4) オペレータは、全体の時間や警告時間などを設定し、適時スピーカーに「カンペ」を送ることができます。
 *
 * 【設置】
 * 1) 本ファイルをWebサーバーの公開ディレクトリに配置してください（例: /public_html/timepon.php）。
 * 2) 同階層に data/ が無い場合は自動作成されます。手動作成時は 0775 以上の書込権限を付与。
 *    直接アクセス対策として data/ を直リンク不可にすることを推奨（例: .htaccess で「Deny from all」）。
 *    もしくは公開領域外に移し、id_to_file() のパスを変更してください。
 * 3) PHP 8 以上推奨。排他制御/ロック（flock）が使用できる環境を推奨。
 *
 * 【使い方】
 * - ルートURLにアクセスし、「オペレータ」または「演台」を選択。
 * - 新規ルーム作成で6桁IDが発行されます。
 *   管理URL: ?op=1&id=XXXXXX / 演台URL: ?id=XXXXXX を配布してください。
 * - 時計設定（持ち時間・第1/第2警告）はルームIDと独立に保存・更新されます（同じIDのまま）。
 * - 警告色の遷移：グレー → 黄色（第1） → 朱色（第2） → 赤（0秒以降はやわらか点滅）。
 *
 * 【ショートカットキー】
 *  - SHIFT+SPACE : スタート/一時停止のトグル
 *  - SHIFT+R     : リセット
 *  - SHIFT+K     : カンペ送信
 *  - SHIFT+C     : カンペ消去
 *
 * 【注意事項】
 * - ルームの保存期間：最後の更新から7日で自動削除されます。
 * - 管理キー：作成時に生成され、管理URLの #k= フラグメントに含まれます。管理URLは共有しないでください。
 *   演台URL（?id=XXXXXX）のみ登壇者へ配布してください。adminKeyは推測困難ですが再発行はできません。
 * - 6桁IDの性質：ID自体は秘匿情報ではありません（推測可能）。「管理URL」を漏らさない運用が前提です。
 * - 保存データ：持ち時間・警告設定・カンペ・ステージ状態・adminKey が data/ 配下のJSONに保存されます。
 *   個人情報や機微情報はカンペに入力しないでください。
 *   パーミッションの目安は dir=0775, file=0664（umask 007）です。
 * - レート制限：作成10件/分、HB 300件/分、書き込み120件/分（IP単位）。429/エラー相当の応答が出たら間隔を空けて再試行してください。
 * - 免責：MITライセンス・無保証です。本番利用前に各自の環境・要件に合わせて十分なテストを行ってください。
 *
 * 【ライセンス】
 * MIT License
 *
 * Copyright (c) 2025 pondashi.com
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 * ========================================================= */


ini_set('display_errors', 0);
date_default_timezone_set('Asia/Tokyo');
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-Frame-Options: DENY');
    header("Content-Security-Policy: default-src 'self'; connect-src 'self'; img-src 'self' data: https://api.qrserver.com; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'; object-src 'none'; upgrade-insecure-requests;");
    header('Permissions-Policy: accelerometer=(), autoplay=(), camera=(), clipboard-read=(), clipboard-write=(), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), payment=(), usb=()');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Cross-Origin-Resource-Policy: same-origin');
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}
umask(007);
$DATA_DIR = getenv('TIMEPON_DATA_DIR') ?: (__DIR__ . '/data');
if (!is_dir($DATA_DIR)) { @mkdir($DATA_DIR, 0775, true); }
$ht = $DATA_DIR . '/.htaccess';
if (!file_exists($ht)) { @file_put_contents($ht, "Require all denied\n"); }
function h($s){ return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function json_out($x){
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo json_encode($x, JSON_UNESCAPED_UNICODE);
    exit;
}
function id_to_file($id){
    global $DATA_DIR; $p = preg_replace('/\D/', '', (string)$id);
    if ($p === '') $p = '000000';
    return $DATA_DIR . '/' . $p . '.json';
}
function gen_id($len=6){
    $n = random_int(0, 10**$len - 1);
    return str_pad((string)$n, $len, '0', STR_PAD_LEFT);
}
function gen_unique_id($len=6, $maxTry=50){
    for ($i=0; $i<$maxTry; $i++){
        $id = gen_id($len);
        $file = id_to_file($id);
        if (!file_exists($file)) return $id;
    }
    return substr((string)(time()%1000000 + 1000000), -6);
}
function redact_state($st){ if (is_array($st)) { unset($st['adminKey']); } return $st; }
function gen_admin_key(): string { return bin2hex(random_bytes(16)); }
function load_state($id){
    $file = id_to_file($id);
    if (!file_exists($file)) {
        return [
            'id'=>$id,
            'state'=>'idle',
            'durationSec'=>2400,
            'warn1Min'=>10,
            'warn2Min'=>5,
            'startedAtMs'=>0,
            'pausedAccumMs'=>0,
            'pausedAtMs'=>0,
            'message'=>'',
            'messageAtMs'=>0,
            'autoPrompt'=>true,
            'promptOnly'=>false,
            'stage'=>['lastSeen'=>0,'fullscreen'=>false,'startedAckMs'=>0,'msgAckMs'=>0],
            'colors'=>['n'=>'#1e293b','w1'=>'#facc15','w2'=>'#ef4444'],
            'lang'=>'ja',
            'flash'=>false,
            'adminKey'=>null,
            'updatedAt'=>time()
        ];
    }
    $j = @file_get_contents($file);
    $d = @json_decode($j, true);
    if (!is_array($d)) $d = [];
    if (!isset($d['warn1Min']) && isset($d['warnSec'])) $d['warn1Min'] = max(0, intval($d['warnSec']/60));
    if (!isset($d['warn2Min'])) $d['warn2Min'] = 5;
    if (!isset($d['colors']) || !is_array($d['colors'])) {
        $d['colors'] = ['n'=>'#1e293b','w1'=>'#facc15','w2'=>'#ef4444'];
    } else {
        $d['colors']['n']  = $d['colors']['n']  ?? '#1e293b';
        $d['colors']['w1'] = $d['colors']['w1'] ?? '#facc15';
        $d['colors']['w2'] = $d['colors']['w2'] ?? '#ef4444';
    }
    $d['lang'] = in_array(($d['lang'] ?? 'ja'), ['ja','en'], true) ? $d['lang'] : 'ja';
    return array_merge([
        'id'=>$id,
        'state'=>'idle',
        'durationSec'=>2400,
        'warn1Min'=>10,
        'warn2Min'=>5,
        'startedAtMs'=>0,
        'pausedAccumMs'=>0,
        'pausedAtMs'=>0,
        'message'=>'',
        'messageAtMs'=>0,
        'autoPrompt'=>true,
        'promptOnly'=>false,
        'stage'=>['lastSeen'=>0,'fullscreen'=>false,'startedAckMs'=>0,'msgAckMs'=>0],
        'colors'=>['n'=>'#1e293b','w1'=>'#facc15','w2'=>'#ef4444'],
        'lang'=>'ja',
        'flash'=>false,
        'adminKey'=>null,
        'updatedAt'=>time()
    ], $d);
}
function save_state(string $id, array $st): bool {
    $file = id_to_file($id);
    $dir  = dirname($file);
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    $st['updatedAt'] = time();
    $json = json_encode($st, JSON_UNESCAPED_UNICODE);
    if ($json === false) return false;
    $tmp = $file . '.tmp';
    $ok = false;
    $fp = @fopen($tmp, 'wb');
    if ($fp) {
        if (@flock($fp, LOCK_EX)) {
            $w = (@fwrite($fp, $json) !== false);
            @fflush($fp);
            @flock($fp, LOCK_UN);
            $ok = $w;
        }
        @fclose($fp);
    }
    if (!$ok) { @unlink($tmp); return false; }
    if (!@rename($tmp, $file)) { @unlink($tmp); return false; }
    @chmod($file, 0660);
    return true;
}
function throttle_create(): void {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $ip = preg_replace('/[^0-9a-fA-F:\.]/', '_', $ip);
    $dir = dirname(id_to_file('000000')) . '/_ip';
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    $f = $dir . '/' . $ip . '.json';
    $now = time(); $win = 60; $max = 10;
    $d = @json_decode(@file_get_contents($f), true) ?: ['ts'=>0,'cnt'=>0];
    if ($now - (int)$d['ts'] > $win) { $d = ['ts'=>$now,'cnt'=>0]; }
    if ($d['cnt'] >= $max) { json_out(['ok'=>false,'error'=>'rate_limited']); }
    $d['cnt']++;
    @file_put_contents($f, json_encode($d, JSON_UNESCAPED_UNICODE), LOCK_EX);
    @chmod($f, 0660);
}
function throttle_get_hb(): void {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $ip = preg_replace('/[^0-9a-fA-F:\.]/', '_', $ip);
    $dir = dirname(id_to_file('000000')) . '/_ip';
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    $f = $dir . '/r_' . $ip . '.json';
    $now = time(); $win = 60; $max = 300;
    $d = @json_decode(@file_get_contents($f), true) ?: ['ts'=>0,'cnt'=>0];
    if ($now - (int)$d['ts'] > $win) { $d = ['ts'=>$now,'cnt'=>0]; }
    $d['cnt']++;
    @file_put_contents($f, json_encode($d, JSON_UNESCAPED_UNICODE), LOCK_EX);
    @chmod($f, 0660);
    if ($d['cnt'] > $max) { json_out(['ok'=>false,'error'=>'rate_limited']); }
}
function gc_old_rooms(int $days=14): void {
    $limit = time() - $days*86400;
    $dir = dirname(id_to_file('000000'));
    $it = @scandir($dir) ?: [];
    foreach ($it as $name) {
        if (!preg_match('/^\d{6}\.json$/', $name)) continue;
        $p = $dir.'/'.$name;
        $m = @filemtime($p) ?: 0;
        if ($m && $m < $limit) { @unlink($p); }
    }
}
function same_origin_ok(): bool {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    $self   = $scheme . ($_SERVER['HTTP_HOST'] ?? '');
    $origin = $_SERVER['HTTP_ORIGIN']  ?? '';
    if ($origin !== '') {
        return (strcasecmp(rtrim($origin, '/'), rtrim($self, '/')) === 0);
    }
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    if ($referer !== '') {
        $p = @parse_url($referer);
        if (isset($p['scheme'], $p['host'])) {
            $ref_origin = strtolower($p['scheme'].'://'.$p['host'].(isset($p['port'])?':'.$p['port']:''));
            return (strcasecmp(rtrim($ref_origin, '/'), rtrim(strtolower($self), '/')) === 0);
        }
        return false;
    }
    return false;
}
function require_same_origin(): void {
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        json_out(['ok'=>false,'error'=>'method_not_allowed']);
    }
    if (!same_origin_ok()) { json_out(['ok'=>false,'error'=>'bad_origin']); }
}

function throttle_write(): void {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $ip = preg_replace('/[^0-9a-fA-F:\.]/', '_', $ip);
    $dir = dirname(id_to_file('000000')) . '/_ip';
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    $f = $dir . '/w_' . $ip . '.json';
    $now = time(); $win = 60; $max = 120;
    $d = @json_decode(@file_get_contents($f), true) ?: ['ts'=>0,'cnt'=>0];
    if ($now - (int)$d['ts'] > $win) { $d = ['ts'=>$now,'cnt'=>0]; }
    $d['cnt']++;
    @file_put_contents($f, json_encode($d, JSON_UNESCAPED_UNICODE), LOCK_EX);
    @chmod($f, 0660);
    if ($d['cnt'] > $max) { json_out(['ok'=>false,'error'=>'rate_limited']); }
}
function gc_old_ip(int $days=7): void {
    $base = dirname(id_to_file('000000')) . '/_ip';
    if (!is_dir($base)) return;
    $limit = time() - $days * 86400;
    foreach (@scandir($base) ?: [] as $n) {
        if ($n === '.' || $n === '..') continue;
        $p = $base . '/' . $n;
        if (!is_file($p)) continue;
        $m = @filemtime($p) ?: 0;
        if ($m && $m < $limit) { @unlink($p); }
    }
}
if (mt_rand(1, 50) === 1) {
    gc_old_rooms(7);
    gc_old_ip(7);
}
$act = $_REQUEST['act'] ?? '';
$id  = $_REQUEST['id']  ?? '';
if ($act === 'create' && $_SERVER['REQUEST_METHOD']==='POST') {
    require_same_origin();
    throttle_create();
    $id = gen_unique_id(6);
    $st = load_state($id);
    $st['durationSec'] = 40*60;
    $st['warn1Min'] = 10;
    $st['warn2Min'] = 5;
    $st['state'] = 'idle';
    $st['startedAtMs'] = 0;
    $st['pausedAccumMs'] = 0;
    $st['pausedAtMs'] = 0;
    $st['message'] = '';
    $st['autoPrompt'] = true;
    $st['stage']['startedAckMs'] = 0;
    $st['adminKey'] = gen_admin_key();
    save_state($id, $st);
    json_out(['ok'=>true, 'id'=>$id, 'adminKey'=>$st['adminKey']]);
}
if ($act === 'get') {
    throttle_get_hb();
    $id = preg_replace('/\D/', '', $id);
    if ($id === '') json_out(['ok'=>false,'error'=>'id required']);
    $fileExists = file_exists(id_to_file($id));
    $st = load_state($id);
    json_out([
        'ok'=>true,
        'state'=>redact_state($st),
        'exists'=>$fileExists,
        'serverNowMs'=>(int)(microtime(true)*1000)
    ]);
}

if ($act === 'set' && $_SERVER['REQUEST_METHOD']==='POST') {
    require_same_origin();
    throttle_write();
    $id = preg_replace('/\D/', '', $id);
    if ($id === '') json_out(['ok'=>false,'error'=>'id required']);
    $st = load_state($id);
    $kPost = $_POST['k'] ?? '';
    if (empty($st['adminKey'])) {
        if (getenv('TIMEPON_ALLOW_CLAIM') === '1') {
            if (!preg_match('/^[0-9a-f]{32,}$/i', (string)$kPost)) { json_out(['ok'=>false,'error'=>'forbidden']); }
            $st['adminKey'] = (string)$kPost;
            save_state($id, $st);
        } else {
            json_out(['ok'=>false,'error'=>'forbidden']);
        }
    }
    if (!hash_equals((string)$st['adminKey'], (string)$kPost)) { json_out(['ok'=>false,'error'=>'forbidden']); }
    $cmd = $_POST['cmd'] ?? '';
    $now = (int)(microtime(true)*1000);
    switch ($cmd) {
        case 'start':
            if ($st['state'] === 'idle') {
                if (isset($_POST['durationSec'])) {
                    $st['durationSec'] = min(86400, max(5, (int)$_POST['durationSec']));
                }
                $st['startedAtMs']   = $now;
                $st['pausedAccumMs'] = 0;
                $st['pausedAtMs']    = 0;
                $st['state']         = 'running';
                $st['stage']['startedAckMs'] = 0;
            } elseif ($st['state'] === 'paused') {
                $add = $st['pausedAtMs'] ? ($now - (int)$st['pausedAtMs']) : 0;
                $st['pausedAccumMs'] = (int)$st['pausedAccumMs'] + max(0, $add);
                $st['pausedAtMs']    = 0;
                $st['state']         = 'running';
                $st['stage']['startedAckMs'] = 0;
            } else {
            }
            break;
        case 'pause':
            if ($st['state'] === 'running') {
                $st['pausedAtMs'] = $now;
                $st['state']      = 'paused';
            }
            break;
        case 'reset':
            $st['state']='idle'; $st['startedAtMs']=0; $st['pausedAccumMs']=0; $st['pausedAtMs']=0; $st['stage']['startedAckMs']=0;
            break;
        case 'message':
            $txt = (string)($_POST['text'] ?? '');
            $txt = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u','', $txt);
            if (function_exists('mb_strlen') && function_exists('mb_substr')) {
                if (mb_strlen($txt, 'UTF-8') > 500) $txt = mb_substr($txt, 0, 500, 'UTF-8');
            } else {
                if (strlen($txt) > 2000) $txt = substr($txt, 0, 2000);
            }
            $st['message'] = $txt;
            $st['messageAtMs'] = (int)(microtime(true)*1000);
            break;
        case 'promptOnly':
            $v = $_POST['v'] ?? '';
            $st['promptOnly'] = ($v==='1' || strtolower((string)$v)==='true');
            break;
        case 'flash':
            $v = $_POST['v'] ?? '';
            $st['flash'] = ($v==='1' || strtolower((string)$v)==='true');
            break;
        default: ;
    }
    save_state($id, $st);
    json_out(['ok'=>true]);
}
if ($act === 'setSettings' && $_SERVER['REQUEST_METHOD']==='POST') {
    require_same_origin();
    throttle_write();
    $id = preg_replace('/\D/', '', $id);
    if ($id === '') json_out(['ok'=>false,'error'=>'id required']);
    $st = load_state($id);
    $kPost = $_POST['k'] ?? '';
    if (empty($st['adminKey'])) {
        if (getenv('TIMEPON_ALLOW_CLAIM') === '1') {
            if (!preg_match('/^[0-9a-f]{32,}$/i', (string)$kPost)) { json_out(['ok'=>false,'error'=>'forbidden']); }
            $st['adminKey'] = (string)$kPost;
            save_state($id, $st);
        } else {
            json_out(['ok'=>false,'error'=>'forbidden']);
        }
    }
    if (!hash_equals((string)$st['adminKey'], (string)$kPost)) { json_out(['ok'=>false,'error'=>'forbidden']); }
    $durMin   = min(720, max(1, (int)($_POST['durMin']   ?? ceil($st['durationSec']/60))));
    $warn1Min = min($durMin, max(0, (int)($_POST['warn1Min'] ?? $st['warn1Min'])));
    $warn2Min = min($durMin, max(0, (int)($_POST['warn2Min'] ?? $st['warn2Min'])));
    $autoPrompt = isset($_POST['autoPrompt']) ? (($_POST['autoPrompt']=='1'||strtolower((string)$_POST['autoPrompt'])==='true') ? true : false) : ($st['autoPrompt'] ?? true);
    $st['durationSec'] = $durMin * 60;
    $st['warn1Min']    = $warn1Min;
    $st['warn2Min']    = $warn2Min;
    $st['autoPrompt']  = $autoPrompt;
    $hex = '/^#[0-9A-Fa-f]{6}$/';
    $cn = $_POST['cN'] ?? null;
    $c1 = $_POST['c1'] ?? null;
    $c2 = $_POST['c2'] ?? null;
    if (!isset($st['colors']) || !is_array($st['colors'])) { $st['colors'] = []; }
    if (is_string($cn) && preg_match($hex, $cn)) { $st['colors']['n']  = $cn; }
    if (is_string($c1) && preg_match($hex, $c1)) { $st['colors']['w1'] = $c1; }
    if (is_string($c2) && preg_match($hex, $c2)) { $st['colors']['w2'] = $c2; }
    $lang = $_POST['lang'] ?? ($st['lang'] ?? 'ja');
    $lang = in_array($lang, ['ja','en'], true) ? $lang : 'ja';
    $st['lang'] = $lang;
    save_state($id, $st);
    json_out(['ok'=>true]);
}
if ($act === 'ackStart' && $_SERVER['REQUEST_METHOD']==='POST') {
    require_same_origin();
    throttle_write();
    $id = preg_replace('/\D/', '', $id);
    if ($id === '') json_out(['ok'=>false,'error'=>'id required']);
    $st = load_state($id);
    $st['stage']['startedAckMs'] = (int)(microtime(true)*1000);
    save_state($id, $st);
    json_out(['ok'=>true]);
}
if ($act === 'ackMsg' && $_SERVER['REQUEST_METHOD']==='POST') {
    require_same_origin();
    throttle_write();
    $id = preg_replace('/\D/', '', $id);
    if ($id === '') json_out(['ok'=>false,'error'=>'id required']);
    $st = load_state($id);
    $st['stage']['msgAckMs'] = (int)(microtime(true)*1000);
    save_state($id, $st);
    json_out(['ok'=>true]);
}
if ($act === 'hb' && $_SERVER['REQUEST_METHOD']==='POST') {
    require_same_origin();
    throttle_get_hb();
    $id = preg_replace('/\D/', '', $id);
    if ($id === '') json_out(['ok'=>false,'error'=>'id required']);
    $st = load_state($id);
    $fs = isset($_POST['fs']) && (($_POST['fs']=='1'||strtolower($_POST['fs'])==='true'));
    $st['stage']['lastSeen']   = time();
    $st['stage']['fullscreen'] = $fs;
    save_state($id, $st);
    json_out(['ok'=>true, 'state'=>redact_state($st), 'serverNowMs'=>(int)(microtime(true)*1000)]);
}
?>
<!doctype html>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black">
<title>TIME-PON</title>
<style>
:root {
  --n:#1e293b;
  --g:#facc15;
  --o:#ef4444;
  --r:#b91c1c;
  --fg:#fff;
}
html, body {
  height:100%;
  margin:0;
  font-family:-apple-system,system-ui,Segoe UI,Roboto,"Noto Sans JP",sans-serif;
}
body {
  background:#0b1016;
  color:#e5e7eb;
}
h1, h2, h3 {
  color:#e5e7eb;
}
a {
  color:#93c5fd;
}
.card > h3 {
  margin-top:1px;
}
.container {
  max-width:1200px;
  margin:24px auto;
  padding:0 16px;
}
.card {
  border:1px solid #1f2937;
  border-radius:12px;
  padding:14px;
  margin-bottom:14px;
  background:#111827;
}
.row {
  display:flex;
  gap:12px;
  flex-wrap:wrap;
  align-items:center;
}
#adminGrid {
  display:block;
}
.topRow{
    display: grid;
    grid-template-columns: 2.1fr 1.1fr;
    column-gap: 16px;
    row-gap: 6px;
    grid-template-areas:
        "left righttop"
        "left rightbottom";
    align-items: stretch;
    grid-template-rows: 1fr 1fr;
}
.topRow > *{
    min-width: 0;
}
.cardLeft {
  grid-area:left;
  min-width: 0;
}
.cardRightTop {
  grid-area:righttop;
}
.cardRightBottom {
  grid-area:rightbottom;
}
@media (min-aspect-ratio:16/9) {
  #adminGrid {
    display:grid;
    grid-template-columns:minmax(0, 1fr) minmax(0, 2.3fr);
    gap:16px;
    align-items:start;
  }
  .colL, .colR {
    min-width:0;
  }
  .colL > .card {
    margin-bottom:16px;
  }
  .colR > .card {
    margin-bottom:16px;
  }
  .stick {
    position:sticky;
    top:16px;
  }
}
#qrCard { overflow:hidden; }
#stageLink{
  display:block;
  max-width:100%;
  white-space:nowrap;
  overflow:hidden;
  text-overflow:ellipsis;
}
input, button {
  padding:10px 14px;
  font-size:16px;
}
input[type=number] {
  width:5ch;
}
#msg {
  width:520px;
  max-width:70vw;
}
#adur, #aw1, #aw2 {
  width:5ch;
  text-align:right;
}
button {
  border:none;
  border-radius:10px;
  background:#2563eb;
  color:#fff;
  cursor:pointer;
}
button.secondary {
  background:#374151;
}
button.danger {
  background:#b91c1c;
}
.btnxl {
  font-size:22px;
  padding:16px 22px;
  border-radius:14px;
}
#admin {
  transform: scale(0.9);
  transform-origin: top left;
  width: 111.111%;
  height: 111.111%;
}
#admin .btnxl {
  margin-top:10px;
  margin-bottom:8px;
}
.big{
  font-size: clamp(56px, 14vw, 120px);
  font-weight: 800;
}
@media (max-width: 420px){
  .topRow .big{ font-size: clamp(32px, 7vw, 56px); }
}
.mono {
  font-variant-numeric: lining-nums tabular-nums;
  font-feature-settings: "lnum" 1, "tnum" 1;
  letter-spacing:.02em;
  line-height:.9;
}
.muted {
  opacity:.8;
  font-size:12px;
}
.hidden {
  display:none;
}
.bad {
  color:#ef4444;
  font-weight:700;
}
.good {
  color:#10b981;
  font-weight:700;
}
#aremain {
  display:block;
  min-height:1.05em;
  font-variant-numeric:tabular-nums;
  letter-spacing:.02em;
  line-height:.9;
}
#aremain.txtG {
  color: var(--g);
}
#aremain.txtO {
  color: var(--o);
}
#aremain.txtR {
  color: var(--r, #b91c1c);
}
.copyBtn {
  padding:6px 10px;
  font-size:14px;
  background:#10b981;
  border-radius:8px;
  color:#052e26;
}
.idBadge {
  display:inline-block;
  background:#111827;
  color:#fff;
  border-radius:8px;
  padding:4px 8px;
  font-weight:700;
  letter-spacing:.03em;
  border:1px solid #334155;
}
.roomMeta {
  display:flex;
  flex-wrap:wrap;
  gap:8px 12px;
  align-items:center;
  line-height:1.9;
}
.roomMeta .item {
  display:flex;
  align-items:center;
  gap:6px;
}
.roomMeta a {
  overflow-wrap:anywhere;
  text-decoration:underline;
  color:#93c5fd;
}
#curRoom .item + .item {
  margin-top:10px;
}
.stageWrap {
  height:100svh;
  height:100vh;
  padding-left:env(safe-area-inset-left);
  padding-right:env(safe-area-inset-right);
  padding-top:env(safe-area-inset-top);
  padding-bottom:env(safe-area-inset-bottom);
  display:flex;
  flex-direction:column;
  align-items:center;
  justify-content:center;
  background:var(--n);
  color:var(--fg);
  position: relative;
}
.stageFooter{
  position: absolute;
  left: 0;
  right: 0;
  bottom: calc(8px + env(safe-area-inset-bottom));
  text-align: center;
  font-size: clamp(24px, 7vw, 32px);
  font-weight: 700;
  letter-spacing: .02em;
  opacity: .9;
  pointer-events: none;
}
#sclockLabel {
  font-size: clamp(14px, 3vw, 18px);
  font-weight: 600;
  margin-right: 6px;
}
#sclock {
  font-size: inherit;
  font-weight: inherit;
}
.time {
  font-size:20vw;
  line-height:1;
  font-weight:800;
  letter-spacing:.03em;
  text-shadow:0 4px 16px rgba(0,0,0,.2);
}
.msg {
  font-size:8vw;
  margin-top:2vh;
  opacity:.95;
  text-align:center;
  text-shadow:0 4px 16px rgba(0,0,0,.25);
}
.msg.attn {
  animation:attn 1.4s ease-in-out infinite;
}
@keyframes attn {
  0%   { opacity:1; }
  50%  { opacity:.6; }
  100% { opacity:1; }
}
.softflash {
  animation:softflash 2.4s ease-in-out infinite;
}
@keyframes softflash {
  0%   { opacity:1; }
  50%  { opacity:.55; }
  100% { opacity:1; }
}
.flashPulse {
  animation: flashpulse 0.8s ease-in-out infinite;
}
@keyframes flashpulse {
  0%   { filter: none; }
  50%  { 
    filter: brightness(1.8) contrast(1.15) saturate(1.1);
  }
  100% { filter: none; }
}
#flashToggle.active {
  position: relative;
  animation: btnflash 0.85s ease-in-out infinite;
  border-color: #ef4444;
  will-change: filter;
}
#flashToggle.active::after {
  content: "";
  position: absolute;
  inset: -3px;
  border-radius: inherit;
  border: 2px solid rgba(239,68,68,.95);
  box-shadow: 0 0 10px rgba(239,68,68,.65);
  animation: borderflash 0.85s ease-in-out infinite;
  pointer-events: none;
}
@keyframes btnflash {
  0%   { filter: none; }
  50%  { filter: brightness(1.6) contrast(1.18); }
  100% { filter: none; }
}
@keyframes borderflash {
  0%   { opacity: .35; box-shadow: 0 0 6px  rgba(239,68,68,.40); }
  50%  { opacity: 1;   box-shadow: 0 0 16px rgba(239,68,68,.95); }
  100% { opacity: .35; box-shadow: 0 0 6px  rgba(239,68,68,.40); }
}
#qrImg {
  width:256px;
  height:256px;
  border:1px solid #334155;
  border-radius:12px;
  background:#fff;
}
.langSwitch {
  position:fixed;
  top:calc(16px + env(safe-area-inset-top));
  right:calc(16px + env(safe-area-inset-right));
  z-index:2147483647;
  display:inline-flex;
  align-items:center;
}
.langSwitch input {
  position:absolute;
  opacity:0;
  pointer-events:none;
}
.langSwitch .switch {
  display:block;
  width:74px;
  height:32px;
  border-radius:9999px;
  position:relative;
  background:rgba(31,41,55,.92);
  border:1px solid rgba(148,163,184,.35);
  box-shadow:0 6px 20px rgba(0,0,0,.35), 0 0 0 1px rgba(255,255,255,.03) inset;
  backdrop-filter:saturate(140%) blur(6px);
  -webkit-backdrop-filter:saturate(140%) blur(6px);
  transition:background .2s ease, border-color .2s ease, box-shadow .2s ease;
  cursor:pointer;
}
.langSwitch .labels {
  position:absolute;
  inset:0;
  display:flex;
  align-items:center;
  justify-content:space-between;
  padding:0 10px;
  font-size:12px;
  letter-spacing:.02em;
  color:#9ca3af;
  user-select:none;
  pointer-events:none;
}
.langSwitch .thumb {
  position:absolute;
  top:3px;
  left:3px;
  width:26px;
  height:26px;
  border-radius:9999px;
  background:#fff;
  box-shadow:0 2px 6px rgba(0,0,0,.35);
  transition:transform .2s ease;
  will-change:transform;
}
.langSwitch input:checked + label.switch .thumb {
  transform:translateX(41px);
}
.langSwitch input:checked + label.switch {
  background:#2563eb;
}
.langSwitch input:focus-visible + label.switch {
  outline:2px solid #93c5fd;
  outline-offset:3px;
  border-color:#93c5fd;
}
.pill {
  display:inline-flex;
  align-items:center;
  gap:6px;
  padding:2px 8px;
  border-radius:9999px;
  font-size:12px;
  font-weight:700;
  letter-spacing:.02em;
  border:1px solid #334155;
  background:#0b1522;
}
.pill::before {
  content:"";
  display:inline-block;
  width:8px;
  height:8px;
  border-radius:9999px;
  background:#64748b;
}
.pill.on {
  color:#10b981;
  border-color:rgba(16,185,129,.35);
  background:rgba(16,185,129,.08);
}
.pill.on::before {
  background:#10b981;
}
.pill.pend {
  color:#f59e0b;
  border-color:rgba(245,158,11,.35);
  background:rgba(245,158,11,.08);
}
.pill.pend::before {
  background:#f59e0b;
}
.pill.off {
  color:#94a3b8;
}
.pill.offl {
  color:#ef4444;
  border-color:rgba(239,68,68,.35);
  background:rgba(239,68,68,.08);
}
@keyframes borderpulse {
  0%   { box-shadow:0 0 0 0 rgba(0,0,0,0), inset 0 0 0 2px transparent; }
  50%  { box-shadow:0 0 0 8px rgba(0,0,0,.05), inset 0 0 0 2px currentColor; }
  100% { box-shadow:0 0 0 0 rgba(0,0,0,0), inset 0 0 0 2px transparent; }
}
#msg.msgShowing {
  color:#ef4444;
  animation:borderpulse 1.8s ease-in-out infinite;
}
#msg.msgPending {
  color:#f59e0b;
  animation:borderpulse 1.8s ease-in-out infinite;
}
.formRow {
  display:flex;
  gap:12px;
  flex-wrap:wrap;
  align-items:center;
}
.formRow label {
  display:inline-flex;
  align-items:center;
  gap:6px;
}
.formRow .pushR {
  margin-left:auto;
}
.switchRow {
  display:flex;
  align-items:center;
  gap:8px;
  margin-top:8px;
}
.switchRow label {
  display:inline-flex;
  align-items:center;
  gap:8px;
}
#anow{
  font-size: clamp(14px, 3.8vw, 22px);
  font-weight: 800;
  letter-spacing: .02em;
  line-height: 1;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
#anowdate {
  opacity:.85;
}
.cardRightTop,
.cardRightBottom{
  display:flex;
  flex-direction:column;
  justify-content:center;
  box-sizing: border-box;
  min-width: 0;
  max-width: 100%;
}
#analogClock {
  width: 100%;
  max-width: 90px;
  aspect-ratio: 1 / 1;
  margin: 6px auto 0;
  position: relative;
  box-sizing: border-box;
  border-radius: 50%;
  border: 1px solid #334155;
  background:
    radial-gradient(circle at 50% 50%, rgba(255,255,255,.06), rgba(0,0,0,0) 45%),
    #0f172a;
}
#analogClock .num {
  position: absolute;
  color: #e5e7eb;
  font-weight: 700;
  user-select: none;
  transform: translate(-50%, -50%);
  font-size: clamp(8px, 4cqw, 12px);
}
#analogClock .n12 { top: 12%; left: 50%; }
#analogClock .n3  { top: 50%; left: 88%; }
#analogClock .n6  { top: 88%; left: 50%; }
#analogClock .n9  { top: 50%; left: 12%; }
#analogClock .tick {
  position: absolute;
  left: 50%; top: 50%;
  width: 1px; height: 8%;
  background: #64748b;
  opacity: 0.6;
  transform-origin: 50% 100%;
}
#analogClock .t0   { transform: translate(-50%, -100%) rotate(0deg); }
#analogClock .t90  { transform: translate(-50%, -100%) rotate(90deg); }
#analogClock .t180 { transform: translate(-50%, -100%) rotate(180deg); }
#analogClock .t270 { transform: translate(-50%, -100%) rotate(270deg); }
#analogClock .hand {
  position: absolute;
  left: 50%; top: 50%;
  transform-origin: 50% 90%;
  transform: translate(-50%, -90%) rotate(0deg);
  border-radius: 9999px;
}
#analogClock .h-hour { width: 3px; height: 25%; background: #e5e7eb; }
#analogClock .h-min  { width: 2px; height: 35%; background: #e5e7eb; opacity: .95; }
#analogClock .h-sec  { width: 1.5px; height: 40%; background: #f59e0b; }
#analogClock .cap {
  position: absolute;
  left: 50%; top: 50%;
  width: 6px; height: 6px;
  transform: translate(-50%, -50%);
  border-radius: 50%;
  background: #e5e7eb;
  box-shadow: 0 0 0 1px #111827 inset;
}
.cp{
  inline-size: 28px;
  block-size: 28px;
  padding: 0;
  border: 1px solid #334155;
  border-radius: 6px;
  background: transparent;
  cursor: pointer;
}
.cp::-webkit-color-swatch-wrapper{ padding:0; }
.cp::-webkit-color-swatch{
  border: none; border-radius: 4px;
}
.cp::-moz-color-swatch{ border: none; border-radius: 4px; }
.onlyPromptLbl{
  display:inline-flex;
  align-items:center;
  gap:6px;
  margin-left:12px;
  padding:2px 6px;
  border-radius:8px;
  border:1px solid #334155;
  background:#0b1522;
  font-size:12px;
  user-select:none;
}
.onlyPromptLbl input{
  inline-size:14px;
  block-size:14px;
}
#apromptWrap{
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}
#apromptWrap .pill,
#apromptWrap .onlyPromptLbl{
  line-height: 1;
  vertical-align: middle;
}
.time .mm,
.time .ss,
#anow,
#sclock {
  font-family:
    ui-monospace, SFMono-Regular, Menlo, Consolas, "Segoe UI Mono",
    "Roboto Mono", "Noto Sans Mono", "Liberation Mono", monospace;
  font-variant-numeric: normal;
  font-feature-settings: normal;
}
.time { white-space: nowrap; }
.time .unit{
  font-size:.35em;
  font-weight:800;
  opacity:.9;
  letter-spacing:.02em;
  vertical-align:baseline;
  margin:0 .06em;
}
.time .pre{ margin-right:.12em; }
.time .sign{
  display:inline-block;
  width:0.7em;
  text-align:right;
  font-weight:800;
}
#sprogress{
  width:min(92vw,1200px);
  height:18px;
  border-radius:9999px;
  margin-top:10px;
  position:relative;
  overflow:hidden;
  background: #111;
}
#sprogressFill{
  position:absolute;
  top:2px; bottom:2px; left:0; right:0;
  background:
    linear-gradient(to right,
      color-mix(in srgb, var(--n) 80%, white) 0 var(--p1,75%),
      color-mix(in srgb, var(--g) 80%, white) var(--p1,75%) var(--p2,87.5%),
      color-mix(in srgb, var(--o) 80%, white) var(--p2,87.5%) 100%);
  border-radius:inherit;
  clip-path: inset(0 0 0 var(--elapsed,0%));
  transition: clip-path .8s linear;
}
.multiWrap{ overflow:auto }
input:not([type=color]),
textarea,
select{
  background:#0f172a;
  color:#e5e7eb;
  border:1px solid #334155;
  border-radius:10px;
}
input:not([type=color])::placeholder,
textarea::placeholder{
  color:#94a3b8;
  opacity:.55;
}
input:not([type=color]):focus,
textarea:focus,
select:focus{
  outline:2px solid rgba(147,197,253,.35);
  outline-offset:2px;
  border-color:#93c5fd;
}
.multiTable{
  width:100%;
  border-collapse:separate;
  border-spacing:0;
  font-size:14px;
  border:1px solid rgba(148,163,184,.18);
  border-radius:10px;
  overflow:hidden;
}
.multiTable th, .multiTable td{
  border-bottom:1px solid #1f2937;
  padding:10px 8px;
  vertical-align:middle;
  white-space:nowrap;
}
.multiTable th + th,
.multiTable td + td{
  border-left:1px solid rgba(148,163,184,.12);
}
.multiTable thead th{
  position:sticky;
  top:0;
  background:#0f172a;
  z-index:1;
}
.multiTable .remain .mm,
.multiTable .remain .ss{
  font-size: 2em;
  font-weight: 800;
  line-height: 1;
}
.mId{ width:12ch }
.mName{ width:24ch; max-width:24ch; white-space:normal }
.multiTable th:nth-child(3),
.multiTable td:nth-child(3){ width:26ch; }

.multiTable th:nth-child(4),
.multiTable td:nth-child(4){
  font-size:16px;
  font-weight:700;
  letter-spacing: .01em;
  font-variant-numeric: tabular-nums;
}
.badge{
  display:inline-block;
  padding:2px 8px;
  border-radius:9999px;
  font-weight:700;
  letter-spacing:.02em;
  border:1px solid #334155;
}
.b-on{ color:#10b981; border-color:rgba(16,185,129,.35); background:rgba(16,185,129,.08) }
.b-off{ color:#ef4444; border-color:rgba(239,68,68,.35); background:rgba(239,68,68,.08) }
.b-pend{ color:#f59e0b; border-color:rgba(245,158,11,.35); background:rgba(245,158,11,.08) }
.tG{ color: var(--g) }
.tO{ color: var(--o) }
.tR{ color: var(--r, #b91c1c) }
</style>
<div id="langSwitch" class="langSwitch" role="group" aria-label="Language">
  <input id="langChk" type="checkbox" aria-label="Toggle English/Japanese">
  <label class="switch" for="langChk">
    <div class="labels"><span>JP</span><span>EN</span></div>
    <div class="thumb" aria-hidden="true"></div>
  </label>
</div>
<div class="container" id="home">
  <h1 data-i18n="homeTitle">管理画面</h1>
  <div class="card">
    <h2 data-i18n="homeEnterTitle">入場</h2>
    <div class="row">
      <button id="toAdmin" data-i18n="operator">オペレータ</button>
      <button id="toStage" data-i18n="stage">演台</button>
      <button id="toMulti" data-i18n="multiRooms">複数ルーム管理</button>
    </div>
    <div class="card muted" style="margin-top:8px">
      <strong data-i18n="homeNoticeTitle">このシステムはプレビュー版（2025年9月6日現在）です。</strong>
      <p data-i18n="homeNoticeP1">
        本システムは、配布版に先立って機能や使い勝手に関するご意見をいただくためにこのサイトで一時的に利用できるようにしている
        あくまでサンプルとしての位置づけの、プレビュー版です。恒久的なWebサービスとしての提供をするものではありません。
      </p>
      <ul>
        <li data-i18n="homeNoticeLi1">予告なく仕様の変更やサービス停止、ルームの削除などを行う場合があります。（しかも頻繁に）</li>
        <li data-i18n="homeNoticeLi2">そのため、現時点ではイベント本番などでのご利用はお控えください</li>
        <li>
          <span data-i18n="homeNoticeLi3a">使い勝手、バグ報告、ご要望をぜひ</span>
          <a href="https://x.com/vtrpon2" target="_blank" rel="noopener noreferrer" data-i18n="authorLink">作者</a>
          <span data-i18n="homeNoticeLi3b">までお寄せください。</span>
        </li>
      </ul>
      <p data-i18n="homeNoticeP2">以上のことに同意いただける場合だけ、お使いください</p>
    </div>
  </div>
</div>
<div class="container hidden" id="admin">
  <h1 data-i18n="adminTitle">管理画面</h1>
  <div id="adminGrid">
    <div class="colL">
      <div class="card">
        <div class="row">
          <button id="create" data-i18n="createRoom">新規ルーム作成</button>
          <input id="aid" data-i18n-ph="aidPlaceholder" placeholder="既存ルームID（6桁）を開く" inputmode="numeric" pattern="\d*">
          <button id="open" data-i18n="open">開く</button>
        </div>
        <div id="curRoom" class="roomMeta muted" style="margin-top:8px;display:none">
          <div class="item">
            <span data-i18n="lblCurrentRoom">現在のルームID：</span>
            <span class="idBadge" id="curId">------</span>
          </div>
          <div class="item">
            <button class="copyBtn" id="copyAdminUrl" data-i18n="copyAdmin">管理用URLをコピー</button>
          </div>
          <div class="item">
            <button class="copyBtn" id="copyStageUrl" data-i18n="copyStage">演台用URLをコピー</button>
          </div>
        </div>
      </div>
      <div class="card stick" id="qrCard" style="display:none;text-align:center">
        <h3 data-i18n="qrTitle">演台用QR</h3>
        <img id="qrImg" alt="QR" referrerpolicy="no-referrer">
        <div style="margin-top:20px"><span data-i18n="labelURL">URL:</span> <a id="stageLink" target="_blank" rel="noopener"></a></div>
        <div class="muted" style="margin-top:6px" data-i18n="qrHelp">※ スマートフォン、タブレットでQRを読むと、タイマーが表示されます。</div>
      </div>
    </div>
    <div class="colR">
      <div class="topRow" id="topRow">
        <div class="card cardLeft" id="cardLeft">
          <div><span data-i18n="lblState">状態</span>: <span id="astate">-</span></div>
          <div><span data-i18n="lblRemain">残り時間</span>: <span id="aremain" class="big mono">--:--</span></div>
          <div class="muted"><span data-i18n="lblStartedAt">開始時刻</span>: <span id="astarted">-</span></div>
          <div class="row"  style="margin-top:28px" >
          <button id="start" class="btnxl" data-i18n="start">スタート</button>
          <button id="pause" class="secondary btnxl" data-i18n="pauseResume">一時停止</button>
          <button id="reset" class="danger btnxl" data-i18n="reset">リセット</button>
        </div>
        </div>
        <div class="card cardRightTop" id="cardNow">
          <div><span data-i18n="lblNow">現在時刻</span>: <span id="anow" class="mono">--:--:--</span></div>
          <div class="muted"><span data-i18n="lblNowDate">日付</span>: <span id="anowdate">-</span></div>
          <div id="analogClock" aria-label="Analog clock"></div>
        </div>
        <div class="card cardRightBottom" id="cardMonitor">
          <div><span data-i18n="lblOnlineTitle">演台監視</span>: <span id="aonline">offline</span></div>
          <div class="muted"><span data-i18n="lblFullscreen">フルスクリーン</span> <span id="afs">-</span></div>
          <div class="muted"><span data-i18n="lblLastSeen">最終監視時間</span> <span id="alast">-</span></div>
          <div class="muted"><span data-i18n="lblStartAck">開始ACK</span>: <span id="aack">-</span></div>
        </div>
      </div>
      <div class="card">
        <h3 data-i18n="promptTitle">カンペ</h3>
        <div class="row" style="flex-wrap:nowrap; align-items:stretch; gap:12px;">
          <input id="msg" maxlength="500" data-i18n-ph="msgPlaceholder" placeholder="カンペ（演台に表示）" style="flex:1; min-width:180px;">
          <div style="display:flex; gap:8px; flex:0 0 auto; white-space:nowrap;">
            <button id="sendMsg" class="secondary" data-i18n="send">送信</button>
            <button id="clearMsg" class="secondary" data-i18n="clear">消去</button>
            <button id="flashToggle" class="secondary" data-i18n="flash">点滅</button>
          </div>
        </div>
        <div id="apromptWrap" class="muted" style="margin-top:8px">
          <span data-i18n="lblPrompt">カンペ（演台)</span>:
          <span id="apromptText">-</span>
          <span id="apromptPill" class="pill off" role="status" aria-live="polite">-</span>
          <label id="onlyPromptWrap" class="onlyPromptLbl">
            <input id="onlyPrompt" type="checkbox">
            <span data-i18n="onlyPromptLabel">タイマーを消してカンペのみ表示</span>
          </label>
        </div>
      </div>
      <div class="card">
        <h3 data-i18n="clockTitle">時計設定</h3>
          <div class="row formRow">
            <label>
              <span data-i18n="labelDuration">持ち時間</span>
              <input id="adur" type="number" min="1" value="40">
              <span data-i18n="labelMin">分</span>
              <input id="cN" type="color" class="cp" value="#1e293b" title="通常色（背景）">
            </label>
            <label>
              <span data-i18n="labelWarn1">第1警告</span>
              <input id="aw1" type="number" min="0" value="10">
              <span data-i18n="labelBefore">分前</span>
              <input id="c1" type="color" class="cp" value="#facc15" title="第1警告色（黄）">
            </label>
            <label>
              <span data-i18n="labelWarn2">第2警告</span>
              <input id="aw2" type="number" min="0" value="5">
              <span data-i18n="labelBefore">分前</span>
              <input id="c2" type="color" class="cp" value="#ef4444" title="第2警告色（朱）">
            </label>
            <button id="saveSettings" class="secondary pushR" data-i18n="saveSettings">設定保存</button>
          </div>
          <div class="switchRow">
            <label>
              <input id="aap" type="checkbox">
              <span data-i18n="autoPromptSwitchLong">警告時間に自動でカンペを送出する</span>
            </label>
          </div>
      </div>
    </div>
  </div>
</div>
</div>
<div class="container hidden" id="multi">
  <h1 data-i18n="multiTitle">複数ルーム管理</h1>

  <div class="card">
    <div class="row" style="align-items:flex-end">
      <label style="display:inline-flex;align-items:center;gap:8px">
        <span data-i18n="multiHowMany">管理するルーム数</span>
        <input id="mCount" type="number" min="1" max="50" value="3" style="width:6ch">
        <span data-i18n="multiRoomsUnit">件</span>
      </label>
      <button id="mApply" class="secondary" data-i18n="multiApply">反映</button>
      <div class="muted" style="margin-left:auto" id="mHelp" data-i18n="multiHelp">
        6桁IDと任意名を入力すると、各ルームの演台表示（タイマー/カンペ）の状態を1秒毎に監視します。
      </div>
    </div>
  </div>
  <div class="card">
    <div class="multiWrap">
      <table class="multiTable" id="mTable" aria-label="Rooms monitor">
        <thead>
          <tr>
            <th style="width:7ch" data-i18n="multiIdx">#</th>
            <th style="width:13ch" data-i18n="multiRoomId">ルームID</th>
            <th data-i18n="multiRoomName">ルーム名</th>
            <th style="width:18ch" data-i18n="multiTimer">タイマーステータス</th>
            <th style="width:22ch" data-i18n="multiPrompt">カンペステータス</th>
            <th style="width:10ch" data-i18n="multiOnline">演台</th>
          </tr>
        </thead>
        <tbody id="mRows">
        </tbody>
      </table>
    </div>
  </div>
</div>
<div class="container hidden" id="stageEnter">
  <h1 data-i18n="stageEnterTitle">演台</h1>
  <div class="card">
    <div class="row">
      <input id="sid" data-i18n-ph="sidPlaceholder" placeholder="ルームID（6桁）" inputmode="numeric" pattern="\d*">
      <button id="sgo" data-i18n="sgo">入る</button>
    </div>
  </div>
</div>

<div id="stageView" class="hidden">
  <div class="stageWrap" id="bg">
    <div class="time mono" id="stime">00:00</div>
    <div id="sprogress" aria-hidden="true">
      <div id="sprogressFill"></div>
    </div>
    <div class="msg" id="smsg"></div>
      <div class="stageFooter mono">
        <span id="sclockLabel" data-i18n="stageClockLabel">現在時刻</span>
        <span id="sclock">--:--:--</span>
      </div>
  </div>
</div>
<script>
const el = (id)=>document.getElementById(id);
function h(s){
  return String(s ?? '').replace(/[&<>"']/g, c => ({
    '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
  }[c]));
}
const fmt = (sec)=>{ const neg=sec<0; sec=Math.abs(sec); const m=(sec/60|0), s=(sec%60|0); return (neg?'-':'')+String(m).padStart(2,'0')+':'+String(s).padStart(2,'0'); };
function fmtRich(sec){
  const neg = sec < 0;
  sec = Math.abs(sec);
  const m = (sec/60|0), s = (sec%60|0);
  const pre = t('remainPrefix');
  const mu  = t('minUnit');
  const su  = t('secUnit');
  const sign = neg ? '-' : '';
  return `<span class="unit pre">${pre}</span>`
    + `<span class="mm">${sign}${String(m).padStart(2,'0')}</span>`
    + `<span class="unit mu">${mu}</span>`
    + `<span class="ss">${String(s).padStart(2,'0')}</span>`
    + `<span class="unit su">${su}</span>`;
}
const BASE_URL = location.origin + location.pathname;
const usp = new URLSearchParams(location.search);
const Q_ID = usp.get('id');
const Q_OP = usp.get('op');
const HASH = new URLSearchParams(location.hash.slice(1));
let ADMIN_KEY = HASH.get('k') || '';
if (!ADMIN_KEY && Q_ID) {
  ADMIN_KEY = localStorage.getItem('timepon.k.' + Q_ID) || '';
}
if (ADMIN_KEY && Q_ID) {
  localStorage.setItem('timepon.k.' + Q_ID, ADMIN_KEY);
}
const SUPPORTED_LANGS = ['ja','en'];
const paramLang = (usp.get('lang') || '').toLowerCase();
let LANG = SUPPORTED_LANGS.includes(paramLang)
  ? paramLang
  : (localStorage.getItem('timepon.lang') || ((navigator.language||'').toLowerCase().startsWith('ja') ? 'ja' : 'en'));
const I18N = {
  ja: {
    homeTitle: 'カンファレンスタイマー　TIME-PON',
    homeEnterTitle: '入場',
    operator: 'オペレータ',
    stage: '演台',
    homeHelp1: 'このURLは共通です。オペレーターは「オペレータ」、登壇者は「演台」を押してください。',
    homeNoticeTitle: 'このシステムはプレビュー版（2025年9月6日現在）です。',
    homeNoticeP1: '本システムは、配布版に先立って機能や使い勝手に関するご意見をいただくためにこのサイトで一時的に利用できるようにしている あくまでサンプルとしての位置づけの、プレビュー版です。恒久的なWebサービスとしての提供をするものではありません。',
    homeNoticeLi1: '予告なく仕様の変更やサービス停止、ルームの削除などを行う場合があります。（しかも頻繁に）',
    homeNoticeLi2: 'そのため、現時点ではイベント本番などでのご利用はお控えください',
    homeNoticeLi3a: '使い勝手、バグ報告、ご要望をぜひ',
    authorLink: '作者',
    homeNoticeLi3b: 'までお寄せください。',
    homeNoticeP2: '以上のことに同意いただける場合だけ、お使いください',
    adminTitle: 'TIME-PON コントロールパネル',
    createRoom: '新規ルーム作成',
    open: '開く',
    lblCurrentRoom: '現在のルームID：',
    qrTitle: '演台用QR',
    labelURL: 'URL:',
    qrHelp: '※ スマートフォン、タブレットでQRを読むと、タイマーが表示されます。',
    promptTitle: 'カンペ',
    clockTitle: '時計設定',
    stageEnterTitle: 'TIME-PON タイマー',
    start: 'スタート',
    pauseResume: '一時停止',
    reset: 'リセット',
    send: '送信',
    clear: '消去',
    flash: '点滅',
    saveSettings: '設定保存',
    sgo: '入る',
    msgPlaceholder: 'カンペ（演台に表示）',
    sidPlaceholder: 'ルームID（6桁）',
    aidPlaceholder: '既存ルームID（6桁）を開く',
    lblState: '状態',
    lblRemain: '残り時間',
    lblStartedAt: '開始時刻',
    lblOnlineTitle: '演台監視',
    lblFullscreen: 'フルスクリーン',
    lblLastSeen: '最終監視時間',
    lblStartAck: '開始ACK',
    lblNow: '現在時刻',
    lblNowDate: '日付',
    labelDuration: '持ち時間',
    labelMin: '分',
    labelWarn1: '第1警告',
    labelWarn1Tail: '分前（緑）',
    labelWarn2: '第2警告',
    labelWarn2Tail: '分前（オレンジ）',
    clockHint: '※ 例：第1=10、第2=5 →「10分前に緑」「5分前にオレンジ」「0で赤」',
    autoPromptSwitch: '警告時間で自動カンペ', 
    labelBefore: '分前',
    autoPromptSwitchLong: '警告時間に自動でカンペを送出する',
    onlyPromptLabel: 'タイマーを消してカンペのみ表示',
    state_idle: '待機',
    state_running: '進行中',
    state_paused: '一時停止',
    online: 'online',
    offline: 'offline',
    on: 'ON',
    off: 'OFF',
    copyAdmin: '管理用URLをコピー',
    copyStage: '演台用URLをコピー',
    copied: 'コピー済',
    errOpenFirst: '先にルームを開いてください',
    errCreateFail: '作成失敗',
    errSaveFail: '保存失敗',
    errId6: '6桁のIDを入力してください',
    lblPrompt: '演台側カンペ状態',
    statusShown: 'カンペ表示中',
    statusTrying: '送信試行中',
    statusOffline: '演台がオフラインのため表示できません',
    statusNone: 'カンペは表示されていません',
    autoMinLeft: '残り{n}分',
    remainPrefix: '残り',
    minUnit: '分',
    secUnit: '秒',
    stageClockLabel: '現在時刻',
    multiRooms: '複数ルーム管理',
    multiTitle: 'TIME-PON ダッシュボード',
    multiHowMany: '管理するルーム数',
    multiRoomsUnit: '件',
    multiApply: '反映',
    multiIdx: 'No.',
    multiRoomId: 'ルームID',
    multiRoomName: 'ルーム名',
    multiTimer: 'タイマーステータス',
    multiPrompt: 'カンペステータス',
    multiOnline: '演台',
    multiHelp: '6桁IDと任意名を入力すると、各ルームの演台表示（タイマー/カンペ）の状態を1秒毎に監視します。',
    multiTimerIdle: '待機',
    multiTimerRunning: '進行中',
    multiTimerPaused: '一時停止',
    multiTimerRemain: '残り{mm}:{ss}',
    multiPromptShown: '表示中',
    multiPromptNone: 'なし',
    multiPromptPending: '送信中',
    multiPromptOffline: '演台オフライン',
  },
  en: {
    homeTitle: 'Conference Timer TIME-PON',
    homeEnterTitle: 'Enter',
    operator: 'Operator',
    stage: 'Stage',
    homeHelp1: 'This URL is shared. Operators tap “Operator”, speakers tap “Stage”.',
    homeNoticeTitle: 'This is a preview edition (as of September 6, 2025).',
    homeNoticeP1: 'This site temporarily hosts a preview to gather feedback on features and usability before distribution. It is not intended as a permanent hosted service.',
    homeNoticeLi1: 'We may change specs, stop the service, or delete rooms without notice (possibly frequently).',
    homeNoticeLi2: 'Please refrain from using it for critical live events at this time.',
    homeNoticeLi3a: 'Feedback, bug reports, and requests are welcome ? contact the',
    authorLink: 'developer',
    homeNoticeLi3b: '.',
    homeNoticeP2: 'Use this only if you agree with the above.',
    adminTitle: 'TIME-PON Control Panel',
    createRoom: 'Create Room',
    open: 'Open',
    lblCurrentRoom: 'Room ID:',
    qrTitle: 'Stage QR',
    labelURL: 'URL:',
    qrHelp: 'Scan this QR with your phone or tablet to open the timer.',
    promptTitle: 'Prompt',
    clockTitle: 'Clock Settings',
    stageEnterTitle: 'TIME-PON Timer',
    start: 'Start',
    pauseResume: 'Pause',
    reset: 'Reset',
    send: 'Send',
    clear: 'Clear',
    flash: 'Flash',
    saveSettings: 'Save Settings',
    sgo: 'Enter',
    msgPlaceholder: 'Prompt (shown on stage)',
    sidPlaceholder: 'Room ID (6 digits)',
    aidPlaceholder: 'Open existing room ID (6 digits)',
    lblState: 'State',
    lblRemain: 'Remain',
    lblStartedAt: 'Started At',
    lblOnlineTitle: 'Stage Monitor',
    lblFullscreen: 'Fullscreen',
    lblLastSeen: 'Last Seen',
    lblStartAck: 'Start ACK',
    lblNow: 'Current Time',
    lblNowDate: 'Date',
    labelDuration: 'Time',
    labelMin: 'min',
    labelWarn1: 'Warn 1',
    labelWarn1Tail: 'min (green)',
    labelWarn2: 'Warn 2',
    labelWarn2Tail: 'min (orange)',
    clockHint: 'Ex: 10 & 5 → 10m=green, 5m=orange, 0=red',
    autoPromptSwitch: 'Auto prompt at warnings',
    labelBefore: 'min before',
    autoPromptSwitchLong: 'Send prompt automatically at warning times',
    onlyPromptLabel: 'Hide timer and show prompt only',
    state_idle: 'idle',
    state_running: 'running',
    state_paused: 'paused',
    online: 'online',
    offline: 'offline',
    on: 'ON',
    off: 'OFF',
    copyAdmin: 'Copy admin URL',
    copyStage: 'Copy stage URL',
    copied: 'Copied',
    errOpenFirst: 'Open a room first',
    errCreateFail: 'Create failed',
    errSaveFail: 'Save failed',
    errId6: 'Enter a 6-digit ID',
    lblPrompt: 'Prompt Status',
    statusShown: 'Prompt shown',
    statusTrying: 'Sending…',
    statusOffline: 'Cannot display: stage is offline',
    statusNone: 'Prompt not shown',
    autoMinLeft: '{n} minutes remaining',
    remainPrefix: 'Rem.',
    minUnit: 'm',
    secUnit: 's',
    stageClockLabel: 'Current',
    multiRooms: 'Multi-Rooms',
    multiTitle: 'TIME-PON Dashboard',
    multiHowMany: 'Number of rooms',
    multiRoomsUnit: '',
    multiApply: 'Apply',
    multiIdx: '#',
    multiRoomId: 'Room ID',
    multiRoomName: 'Room Name',
    multiTimer: 'Timer',
    multiPrompt: 'Prompt',
    multiOnline: 'Stage',
    multiHelp: 'Enter 6-digit IDs and names; this page polls each room every second.',
    multiTimerIdle: 'idle',
    multiTimerRunning: 'running',
    multiTimerPaused: 'paused',
    multiTimerRemain: 'Rem. {mm}:{ss}',
    multiPromptShown: 'shown',
    multiPromptNone: 'none',
    multiPromptPending: 'sending…',
    multiPromptOffline: 'offline',
  }
};
function t(key, params){
  let s = (I18N[LANG] && I18N[LANG][key]) || key;
  if (params && typeof params.n !== 'undefined'){
    const n = Number(params.n);
    if (LANG === 'en' && key === 'autoMinLeft'){
      s = (n === 1) ? '{n} minute remaining' : '{n} minutes remaining';
    }
    s = s.replace('{n}', String(n));
  }
  return s;
}
function applyLangAll(){
  document.documentElement.lang = LANG;
  document.querySelectorAll('[data-i18n]').forEach(e=>{
    const k = e.getAttribute('data-i18n');
    if (k && I18N[LANG] && I18N[LANG][k]) e.textContent = I18N[LANG][k];
  });
  document.querySelectorAll('[data-i18n-ph]').forEach(e=>{
    const k = e.getAttribute('data-i18n-ph');
    if (k && I18N[LANG] && I18N[LANG][k]) e.placeholder = I18N[LANG][k];
  });
  const ca = el('copyAdminUrl'); if (ca) ca.textContent = t('copyAdmin');
  const cs = el('copyStageUrl'); if (cs) cs.textContent = t('copyStage');
  const langChk = document.getElementById('langChk'); if (langChk) langChk.checked = (LANG === 'en');
}
function setLang(l){
  LANG = SUPPORTED_LANGS.includes(l) ? l : 'ja';
  localStorage.setItem('timepon.lang', LANG);
  applyLangAll();
}
function showLangSwitch(show){
  const s = document.getElementById('langSwitch');
  if (s) s.style.display = show ? '' : 'none';
}
let CLOCK_DRIFT_MS = 0;
function updateDrift(serverNowMs){
  const srv = Number(serverNowMs) || 0;
  if (srv > 0) CLOCK_DRIFT_MS = srv - Date.now();
}
document.body.classList.add('darkui');
const langChk = document.getElementById('langChk');
if (langChk){
  langChk.checked = (LANG === 'en');
  langChk.addEventListener('change', ()=>{
    const newLang = langChk.checked ? 'en' : 'ja';
    setLang(newLang);
    if (A_id) { pushLangToRoom(newLang); renderRoomHeader(); }
  });
}
applyLangAll();
showLangSwitch(true);
el('toAdmin').onclick = ()=>{
  el('home').classList.add('hidden');
  el('admin').classList.remove('hidden');
  el('multi').classList.add('hidden');
  showLangSwitch(true);
  try { wireColorPickers(); } catch(_e){}
};
el('toStage').onclick = ()=>{
  el('home').classList.add('hidden');
  el('admin').classList.add('hidden');
  el('multi').classList.add('hidden');
  el('stageEnter').classList.remove('hidden');
  showLangSwitch(false);
};
el('toMulti').onclick = ()=>{
  el('home').classList.add('hidden');
  el('admin').classList.add('hidden');
  el('stageEnter').classList.add('hidden');
  el('multi').classList.remove('hidden');
  showLangSwitch(true);
  multiInit();
};
window.addEventListener('load', ()=>{
  if (Q_ID) {
    if (Q_OP === '1') { showLangSwitch(true);  openAdmin(Q_ID); }
    else if (Q_OP === '2') { showLangSwitch(true); el('home').classList.add('hidden'); el('multi').classList.remove('hidden'); multiInit(); }
    else { showLangSwitch(false); openStage(Q_ID); }
  } else {
    showLangSwitch(true);
  }
});
let A_id = null, A_state=null, A_loadedOnce=false;
let A_lastRemainSec = null;
let A_auto1Sent = false, A_auto2Sent = false;
let _colorPushTimer = null;
function openAdmin(id){
  el('home').classList.add('hidden'); el('admin').classList.remove('hidden');
  showLangSwitch(true);
  A_id = id;
  renderRoomHeader();
  wireColorPickers();
  const cb = document.getElementById('onlyPrompt');
  if (cb){
    cb.addEventListener('change', ()=>{
      cmd('promptOnly', { v: cb.checked ? '1' : '0' });
    });
  }
  adminPull();
}
function renderRoomHeader(){
    if (!A_id) return;
    el('curRoom').style.display = 'block';
    el('curId').textContent = A_id;
    const k = ADMIN_KEY || localStorage.getItem('timepon.k.' + A_id) || '';
    const adminURL = `${BASE_URL}?op=1&id=${A_id}${k ? ('#k=' + encodeURIComponent(k)) : ''}`;
    const stageURL = `${BASE_URL}?id=${A_id}&lang=${encodeURIComponent(LANG)}`;
  const ca = el('copyAdminUrl');
  if (ca) ca.onclick = ()=>{
    navigator.clipboard.writeText(adminURL);
    const prev = t('copyAdmin');
    ca.textContent = t('copied');
    setTimeout(()=>{ ca.textContent = prev; }, 1200);
  };
  const cs = el('copyStageUrl');
  if (cs) cs.onclick = ()=>{
    navigator.clipboard.writeText(stageURL);
    const prev = t('copyStage');
    cs.textContent = t('copied');
    setTimeout(()=>{ cs.textContent = prev; }, 1200);
  };
  renderQr(A_id);
}
function renderQr(id){
  const stageURL = `${BASE_URL}?id=${encodeURIComponent(id)}&lang=${encodeURIComponent(LANG)}`;
  const qrAPI = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&margin=10&data=' + encodeURIComponent(stageURL);
  el('qrImg').src = qrAPI;
  el('stageLink').textContent = stageURL; el('stageLink').href = stageURL;
  el('qrCard').style.display = 'block';
}
async function parseJSONorThrow(res){
  const ct = (res.headers.get('content-type') || '').toLowerCase();
  if (res.ok && ct.includes('application/json')) {
    return await res.json();
  }
  const text = await res.text().catch(()=>'(no body)');
  console.error('[create] Non-JSON response', { status: res.status, ct, preview: text.slice(0,500) });
  throw new Error('Non-JSON response: ' + res.status + ' ' + ct);
}
el('create').onclick = async ()=>{
  let res, j;
  try{
    res = await fetch('?act=create', { method:'POST', headers: { 'Accept':'application/json' }, cache:'no-store' });
    j = await parseJSONorThrow(res);
  }catch(e){
    alert(t('errCreateFail') + ' (network)'); 
    return;
  }
  if (!j.ok){ alert(t('errCreateFail') + ': ' + (j.error||'')); return; }
  try { sessionStorage.setItem('timepon.resetColorsOnce', '1'); } catch(e){}
  location.href = `${BASE_URL}?op=1&id=${encodeURIComponent(j.id)}#k=${encodeURIComponent(j.adminKey||'')}`;
};
el('open').onclick = ()=>{
  const v = (el('aid').value||'').trim().replace(/\D/g,'');
  if (!v || v.length!==6){ alert(t('errId6')); return; }
  const storedK = localStorage.getItem('timepon.k.' + v) || '';
  const kFrag = storedK ? ('#k=' + encodeURIComponent(storedK)) : '';
  location.href = `${BASE_URL}?op=1&id=${encodeURIComponent(v)}${kFrag}`;
};
async function parseJSONorThrowSS(res){
  const ct = (res.headers.get('content-type') || '').toLowerCase();
  if (res.ok && ct.includes('application/json')) {
    return await res.json();
  }
  const text = await res.text().catch(()=>'(no body)');
  console.error('[setSettings] Non-JSON response', { status: res.status, ct, preview: text.slice(0,500) });
  throw new Error('Non-JSON response: ' + res.status + ' ' + ct);
}
el('saveSettings').onclick = async ()=>{
    if (!A_id){ alert(t('errOpenFirst')); return; }
    const durMin   = Math.max(1, Number(el('adur').value)||40);
    const warn1Min = Math.max(0, Number(el('aw1').value)||10);
    const warn2Min = Math.max(0, Number(el('aw2').value)||5);
    const fd = new FormData();
    fd.append('act','setSettings'); fd.append('id',A_id);
    fd.append('durMin',durMin); fd.append('warn1Min',warn1Min); fd.append('warn2Min',warn2Min);
    fd.append('autoPrompt', (document.getElementById('aap')?.checked ? '1' : '0'));
    const k = ADMIN_KEY || localStorage.getItem('timepon.k.' + A_id) || '';
    if (k) fd.append('k', k);
    let r, j;
    try{
      r = await fetch('?act=setSettings', { method:'POST', body:fd, headers: { 'Accept':'application/json' }, cache:'no-store' });
      j = await parseJSONorThrowSS(r);
    }catch(e){
      alert(t('errSaveFail') + ' (network)');
      return;
    }
    if (!j.ok){ alert(t('errSaveFail') + ': ' + (j.error||'')); return; }
    A_auto1Sent = false; A_auto2Sent = false; A_lastRemainSec = null;
    setTimeout(adminPull, 200);
};
async function adminPull(){
  if (!A_id){ setTimeout(adminPull, 1000); return; }
  try{
    const r = await fetch(`?act=get&id=${encodeURIComponent(A_id)}&t=${Date.now()}`, {cache:'no-store'});
    let j = null;
    try {
      const ct = (r.headers.get('content-type')||'').toLowerCase();
      if (r.ok && ct.includes('application/json')) {
        j = await r.json();
      } else {
        j = null;
      }
    } catch(_e){ j = null; }
    if (j && j.ok){
      if (j.serverNowMs) updateDrift(j.serverNowMs);
      A_state = j.state;
      if (A_state && A_state.lang && A_state.lang !== LANG) {
        setLang(A_state.lang);
      }
      applyColorsFromState(A_state);
      adminRender();
    } else {
      adminRender();
    }
  }catch(e){
    adminRender();
  }
  setTimeout(adminPull, 1000);
}
function remainFromState(st){
  const now = Date.now() + CLOCK_DRIFT_MS;
  if (st.state==='running'){
    return Math.ceil((st.durationSec*1000 - (now - st.startedAtMs - (st.pausedAccumMs||0)))/1000);
  } else if (st.state==='paused'){
    const used = (st.pausedAccumMs||0) + (st.pausedAtMs? (now - st.pausedAtMs):0);
    return Math.ceil((st.durationSec*1000 - (st.startedAtMs? (now - st.startedAtMs - used):0))/1000);
  } else {
    return st.durationSec||0;
  }
}
function maybeAutoPrompt(remain){
  if (!A_state) return;
  if (A_state.autoPrompt === false) return;
  const w1 = (A_state.warn1Min||0)*60;
  const w2 = (A_state.warn2Min||0)*60;
  if (A_lastRemainSec!=null){
    if (!A_auto1Sent && A_lastRemainSec>w1 && remain<=w1 && w1>0){
      const text = t('autoMinLeft', {n: A_state.warn1Min});
      cmd('message', {text});
      setTimeout(()=>{ cmd('message', {text:''}); }, 30000);
      A_auto1Sent = true;
    }
    if (!A_auto2Sent && A_lastRemainSec>w2 && remain<=w2 && w2>0){
      const text = t('autoMinLeft', {n: A_state.warn2Min});
      cmd('message', {text});
      setTimeout(()=>{ cmd('message', {text:''}); }, 30000);
      A_auto2Sent = true;
    }
  }
  A_lastRemainSec = remain;
  if (A_state.state==='idle'){ A_auto1Sent=false; A_auto2Sent=false; A_lastRemainSec=null; }
}
function paintAdminRemain(remain, w1, w2){
  const e = el('aremain');
  if (!e) return;
  e.classList.remove('txtG','txtO','txtR');
  if (remain <= 0){
    e.classList.add('txtR');
  } else if (remain <= w2){
    e.classList.add('txtO');
  } else if (remain <= w1){
    e.classList.add('txtG');
  }
}
function syncTopRowHeights(){
}
function adminRender(){
  if (!A_state) return;
  if (!A_loadedOnce){
    el('adur').value = Math.max(1, Math.round((A_state.durationSec||2400)/60));
    el('aw1').value  = A_state.warn1Min ?? 10;
    el('aw2').value  = A_state.warn2Min ?? 5;
    const aap = document.getElementById('aap');
    if (aap) aap.checked = (A_state.autoPrompt !== false);
    A_loadedOnce = true;
  }
  const stName = (A_state && A_state.state) ? A_state.state : 'idle';
  el('astate').textContent = t('state_' + stName);
  const remain = remainFromState(A_state);
  el('aremain').textContent = fmt(remain);
  (function updateNow(){
      const now = new Date(Date.now() + CLOCK_DRIFT_MS);
      const hh = String(now.getHours()).padStart(2, '0');
      const mm = String(now.getMinutes()).padStart(2, '0');
      const ss = String(now.getSeconds()).padStart(2, '0');
      const an = el('anow'); if (an) an.textContent = `${hh}:${mm}:${ss}`;
      const ad = el('anowdate'); if (ad) ad.textContent = now.toLocaleDateString();
  })();
  const w1 = (A_state.warn1Min || 0) * 60;
  const w2 = (A_state.warn2Min || 0) * 60;
  paintAdminRemain(remain, w1, w2);
  el('astarted').textContent = A_state.startedAtMs ? new Date(A_state.startedAtMs).toLocaleString() : '-';
  const seen = A_state.stage?.lastSeen ? Number(A_state.stage.lastSeen)*1000 : 0;
  const serverNow = Date.now() + CLOCK_DRIFT_MS;
  const online = seen && (serverNow - seen < 6000);
  const aonline = el('aonline');
  aonline.textContent = online ? t('online') : t('offline');
  aonline.classList.toggle('bad', !online);
  aonline.classList.toggle('good', !!online);
  el('afs').textContent = A_state.stage?.fullscreen ? t('on') : t('off');
  el('alast').textContent = seen ? new Date(seen).toLocaleTimeString() : '-';
  el('aack').textContent  = A_state.stage?.startedAckMs ? new Date(Number(A_state.stage.startedAckMs)).toLocaleTimeString() : '-';
  const apText = el('apromptText');
  const apPill = el('apromptPill');
  const msgBox = el('msg');
  const msg    = (A_state.message || '').trim();
  const msgAt  = Number(A_state.messageAtMs || 0);
  const msgAck = Number(A_state.stage?.msgAckMs || 0);
  apText.textContent = msg ? msg : '-';
  const hasMsg     = !!msg;
  const isShown    = hasMsg && online && msgAck >= msgAt
  const isTrying   = hasMsg && online && msgAck <  msgAt;
  const isOffline  = hasMsg && !online;
  apPill.classList.remove('on','pend','off','offl');
  if (!hasMsg) {
    apPill.classList.add('off');
    apPill.textContent = t('statusNone');
  } else if (isShown) {
    apPill.classList.add('on');
    apPill.textContent = t('statusShown');
  } else if (isOffline) {
    apPill.classList.add('offl');
    apPill.textContent = t('statusOffline');
  } else if (isTrying) {
    apPill.classList.add('pend');
    apPill.textContent = t('statusTrying');
  }
  if (msgBox){
    msgBox.classList.toggle('msgShowing', isShown);
    msgBox.classList.toggle('msgPending', isTrying);
  }
  const cbOnly = document.getElementById('onlyPrompt');
  if (cbOnly && A_state){
    const want = !!A_state.promptOnly;
    if (cbOnly.checked !== want) cbOnly.checked = want;
  }
  const flashBtn = document.getElementById('flashToggle');
  if (flashBtn){
    flashBtn.classList.toggle('active', !!A_state.flash);
  }
  maybeAutoPrompt(remain);
  syncTopRowHeights();
}
async function cmd(c, extra={}){
    if (!A_id){ alert(t('errOpenFirst')); return; }
    const fd = new FormData(); fd.append('act','set'); fd.append('id',A_id); fd.append('cmd',c);
    const k = ADMIN_KEY || localStorage.getItem('timepon.k.' + A_id) || '';
    if (k) fd.append('k', k);
    Object.entries(extra).forEach(([kk,vv])=>fd.append(kk,vv));
    await fetch('?act=set', {method:'POST', body:fd});
}
el('start').onclick = ()=>{
  const durMin = Math.max(1, Number(el('adur').value)||40);
  A_auto1Sent=false; A_auto2Sent=false; A_lastRemainSec=null;
  const payload = {};
  if (!A_state || A_state.state === 'idle') {
    payload['durationSec'] = durMin * 60;
  }
  cmd('start', payload);
};
el('pause').onclick = ()=>{ if (A_state) cmd('pause'); };
el('reset').onclick = ()=>{ A_auto1Sent=false; A_auto2Sent=false; A_lastRemainSec=null; cmd('reset'); };
let _sendLock = false;
el('sendMsg').onclick = async ()=>{
  if (_sendLock) return;
  _sendLock = true;
  try {
    await cmd('message', {text: el('msg').value || ''});
  } finally {
    setTimeout(()=>{ _sendLock = false; }, 300);
  }
};
el('clearMsg').onclick= ()=>{ el('msg').value=''; cmd('message', {text: ''}); };
const flashBtn = el('flashToggle');
if (flashBtn){
  flashBtn.addEventListener('click', ()=>{
    const next = !(A_state && A_state.flash);
    flashBtn.classList.toggle('active', next);
    cmd('flash', { v: next ? '1' : '0' });
  });
}
let S_id = null, S_state=null, S_lastRunning=false, S_lastMsgAtMs=0;
function isiOS(){ return /iP(hone|ad|od)/.test(navigator.userAgent); }
function nudgeScroll(){
  if (!isiOS()) return;
  setTimeout(()=>{ window.scrollTo(0, 1); }, 250);
  setTimeout(()=>{ window.scrollTo(0, 2); }, 600);
}
function openStage(id){
  el('home').classList.add('hidden');
  el('stageEnter').classList.add('hidden');
  el('stageView').classList.remove('hidden');
  showLangSwitch(false);
  S_id = id;
  nudgeScroll();
  window.addEventListener('orientationchange', ()=>{ setTimeout(nudgeScroll, 500); });
  stageLoop(); stageHeartbeat();
}
function pushLangToRoom(l){
  if (!A_id) return;
  const fd = new FormData();
  fd.append('act','setSettings');
  fd.append('id', A_id);
  const k = ADMIN_KEY || localStorage.getItem('timepon.k.' + A_id) || '';
  if (k) fd.append('k', k);
  fd.append('lang', l);
  fetch('?act=setSettings', {method:'POST', body:fd}).catch(()=>{});
}
el('sgo').onclick = ()=>{
  const v = (el('sid').value||'').trim().replace(/\D/g,'');
  if (!v || v.length!==6){ alert(t('errId6')); return; }
  openStage(v);
};
async function stagePull(){
  if (!S_id){ setTimeout(stagePull, 1000); return; }
  try{
    const fd = new FormData();
    fd.append('act','hb');
    fd.append('id', S_id);
    fd.append('fs','0');
    const r = await fetch('?act=hb', {method:'POST', body:fd, cache:'no-store'});
    let j = null;
    try {
      const ct = (r.headers.get('content-type')||'').toLowerCase();
      if (r.ok && ct.includes('application/json')) {
        j = await r.json();
      } else {
        j = null;
      }
    } catch(_e){ j = null; }
    if (j && j.ok){
      if (j.serverNowMs) updateDrift(j.serverNowMs);
      S_state = j.state;
      if (S_state && S_state.lang && S_state.lang !== LANG) {
        setLang(S_state.lang);
      }
      applyColorsFromState(S_state);
    }
  }catch(e){
  }
  setTimeout(stagePull, 1000);
}
function applyColorsFromState(st){
  const c = st && st.colors;
  if (!c) return;
  const upd = {};
  if (c.n)  upd.n  = c.n;
  if (c.w1) upd.w1 = c.w1;
  if (c.w2) upd.w2 = c.w2;
  if (Object.keys(upd).length){
    applyColors(upd);
    const elN  = document.getElementById('cN');
    const elW1 = document.getElementById('c1');
    const elW2 = document.getElementById('c2');
    if (elN  && c.n)  elN.value  = c.n;
    if (elW1 && c.w1) elW1.value = c.w1;
    if (elW2 && c.w2) elW2.value = c.w2;
  }
}
function paint(remain, warn1Min, warn2Min){
  const BG = document.getElementById('bg');
  BG.classList.remove('softflash');
  const w1 = (warn1Min||0)*60, w2 = (warn2Min||0)*60;
  if (remain<=0){
    BG.style.background='var(--r)';
    BG.classList.add('softflash');
  }
  else if (remain<=w2){
    BG.style.background='var(--o)';
  }
  else if (remain<=w1){
    BG.style.background='var(--g)';
  }
  else {
    BG.style.background='var(--n)';
  }
}
function remainFromStateStage(st){
  const now = Date.now() + CLOCK_DRIFT_MS;
  if (st.state==='running'){
    return Math.ceil((st.durationSec*1000 - (now - st.startedAtMs - (st.pausedAccumMs||0)))/1000);
  } else if (st.state==='paused'){
    const used = (st.pausedAccumMs||0) + (st.pausedAtMs? (now - st.pausedAtMs):0);
    return Math.ceil((st.durationSec*1000 - (st.startedAtMs? (now - st.startedAtMs - used):0))/1000);
  } else {
    return st.durationSec||0;
  }
}
function stageRender(){
  if (!S_state) return;
  const remain = remainFromStateStage(S_state);
  el('stime').innerHTML = fmtRich(remain);
  const tEl = document.getElementById('stime');
  const prog = document.getElementById('sprogress');
  const sclockLabel = document.getElementById('sclockLabel');
  const sclock = document.getElementById('sclock');
  const hideTimer = (S_state && S_state.promptOnly);
  if (tEl) tEl.style.display = hideTimer ? 'none' : '';
  if (prog) prog.style.display = hideTimer ? 'none' : '';
  if (sclockLabel) sclockLabel.style.display = hideTimer ? 'none' : '';
  if (sclock) sclock.style.display = hideTimer ? 'none' : '';
  const m = S_state.message||'';
  const msgEl = el('smsg');
  msgEl.textContent = m;
  msgEl.classList.toggle('attn', !!m);
  const now = new Date(Date.now() + CLOCK_DRIFT_MS);
  const hh = String(now.getHours()).padStart(2,'0');
  const mm = String(now.getMinutes()).padStart(2,'0');
  const ss = String(now.getSeconds()).padStart(2,'0');
  if (sclock) sclock.textContent = `${hh}:${mm}:${ss}`;
  paint(remain, S_state.warn1Min||10, S_state.warn2Min||5);
  const BG = document.getElementById('bg');
  if (BG){
    BG.classList.toggle('flashPulse', !!S_state.flash);
  }
  const isRunning = (S_state.state==='running');
  if (isRunning && !S_lastRunning){
    fetch('?act=ackStart', {method:'POST', body: new URLSearchParams({id:S_id})});
  }
  S_lastRunning = isRunning;
  const curMsgAt = Number(S_state.messageAtMs || 0);
  if (curMsgAt && curMsgAt !== S_lastMsgAtMs){
    fetch('?act=ackMsg', {method:'POST', body: new URLSearchParams({id:S_id})});
    S_lastMsgAtMs = curMsgAt;
  }
  const total = Math.max(1, (S_state.durationSec || 1));
  const remainSec = Math.max(0, remain);
  const w1 = (S_state.warn1Min || 0) * 60;
  const w2 = (S_state.warn2Min || 0) * 60;
  const p1 = Math.max(0, Math.min(100, 100 * (total - w1) / total));
  const p2 = Math.max(0, Math.min(100, 100 * (total - w2) / total));
  const elapsedPct = Math.max(0, Math.min(100, 100 * (total - remainSec) / total));
  const pf = document.getElementById('sprogressFill');
  if (pf){
    pf.style.setProperty('--p1', p1 + '%');
    pf.style.setProperty('--p2', p2 + '%');
    pf.style.setProperty('--elapsed', elapsedPct + '%');
  }
}
setInterval(()=>{
  const an = document.getElementById('anow');
  const ad = document.getElementById('anowdate');
  if (!an && !ad) return;
  const now = new Date(Date.now() + (window.CLOCK_DRIFT_MS || 0));
  const hh = String(now.getHours()).padStart(2, '0');
  const mm = String(now.getMinutes()).padStart(2, '0');
  const ss = String(now.getSeconds()).padStart(2, '0');
  if (an) an.textContent = `${hh}:${mm}:${ss}`;
  if (ad) ad.textContent = now.toLocaleDateString();
}, 1000);
function stageLoop(){ stagePull(); (function tick(){ stageRender(); requestAnimationFrame(tick); })(); }
function stageHeartbeat(){ if (!S_id) return; setTimeout(stageHeartbeat, 3000); }
(function setupShortcuts(){
    document.addEventListener('keydown', function(e){
        if (e.isComposing) return;
        if (!e.shiftKey) return;
        if (e.repeat) return;
        const admin = document.getElementById('admin');
        if (!admin || admin.classList.contains('hidden')) return;
        const ae = document.activeElement;
        const isEditable = !!ae && (
            ae.tagName === 'INPUT' ||
            ae.tagName === 'TEXTAREA' ||
            ae.isContentEditable === true
        );
        const allowMsgSend = (ae && ae.id === 'msg' && (e.code === 'KeyK' || (e.key && e.key.toLowerCase() === 'k')));
        if (isEditable && !allowMsgSend) return;
        const stop = ()=>{ e.preventDefault(); e.stopPropagation(); };
        if (e.code === 'Space' || e.key === ' ') {
            stop();
            if (A_state && A_state.state === 'running') {
                document.getElementById('pause').click();
            } else {
                document.getElementById('start').click();
            }
            return;
        }
        switch ((e.code || '').toUpperCase()) {
            case 'KEYR':
                stop();
                document.getElementById('reset').click();
                break;
            case 'KEYK':
                stop();
                document.getElementById('sendMsg').click();
                break;
            case 'KEYC':
                stop();
                document.getElementById('clearMsg').click();
                break;
            default:
                break;
        }
    }, true);
})();
(function initAnalogClock(){
  const host = document.getElementById('analogClock');
  if (!host) return;
  host.innerHTML = `
    <div class="num n12">12</div>
    <div class="num n3">3</div>
    <div class="num n6">6</div>
    <div class="num n9">9</div>
    <div class="tick t0"></div>
    <div class="tick t90"></div>
    <div class="tick t180"></div>
    <div class="tick t270"></div>
    <div class="hand h-hour" id="ah"></div>
    <div class="hand h-min"  id="am"></div>
    <div class="hand h-sec"  id="as"></div>
    <div class="cap"></div>
  `;
  const elH = document.getElementById('ah');
  const elM = document.getElementById('am');
  const elS = document.getElementById('as');
  function updateAnalog(){
    const now = new Date(Date.now() + (window.CLOCK_DRIFT_MS || 0));
    const h = now.getHours() % 12;
    const m = now.getMinutes();
    const s = now.getSeconds();
    const ms = now.getMilliseconds();
    const secDeg  = (s + ms/1000) * 6;
    const minDeg  = (m + s/60)     * 6;
    const hourDeg = (h + m/60)     * 30;
    elH.style.transform = `translate(-50%, -90%) rotate(${hourDeg}deg)`;
    elM.style.transform = `translate(-50%, -90%) rotate(${minDeg}deg)`;
    elS.style.transform = `translate(-50%, -90%) rotate(${secDeg}deg)`;
    requestAnimationFrame(updateAnalog);
  }
  requestAnimationFrame(updateAnalog);
})();
function applyColors({n,w1,w2}){
  const root = document.documentElement;
  if (n)  root.style.setProperty('--n', n);
  if (w1) root.style.setProperty('--g', w1);
  if (w2) root.style.setProperty('--o', w2);
}
function wireColorPickers(){
  const els = {
    n: document.getElementById('cN'),
    w1: document.getElementById('c1'),
    w2: document.getElementById('c2'),
  };
  if (!els.n || !els.w1 || !els.w2) return;
  const rootStyle = getComputedStyle(document.documentElement);
  const getVar = (name, fallback)=> (rootStyle.getPropertyValue(name) || fallback).trim();
  const initN  = (A_state && A_state.colors && A_state.colors.n)  || getVar('--n', '#1e293b');
  const initW1 = (A_state && A_state.colors && A_state.colors.w1) || getVar('--g', '#facc15');
  const initW2 = (A_state && A_state.colors && A_state.colors.w2) || getVar('--o', '#ef4444');
  els.n.value  = initN;
  els.w1.value = initW1;
  els.w2.value = initW2;
  applyColors({ n:initN, w1:initW1, w2:initW2 });
  ['n','w1','w2'].forEach(k=>{
    els[k].addEventListener('input', ()=>{
      const upd = {
        n:  (k==='n'  ? els.n.value  : null),
        w1: (k==='w1' ? els.w1.value : null),
        w2: (k==='w2' ? els.w2.value : null),
      };
      applyColors(upd);
      clearTimeout(_colorPushTimer);
      _colorPushTimer = setTimeout(async ()=>{
        if (!A_id) return;
        const fd = new FormData();
        fd.append('act','setSettings');
        fd.append('id', A_id);
        const kAdmin = ADMIN_KEY || localStorage.getItem('timepon.k.' + A_id) || '';
        if (kAdmin) fd.append('k', kAdmin);
        fd.append('cN', els.n.value);
        fd.append('c1', els.w1.value);
        fd.append('c2', els.w2.value);
        try { await fetch('?act=setSettings', { method:'POST', body:fd }); } catch(e){}
      }, 150);
    });
  });
}
function colorsFromURL(){
  const u = new URL(location.href);
  const get = (k)=> u.searchParams.get(k) || '';
  let n = get('cN'), w1 = get('c1'), w2 = get('c2');
  const good = s => /^#[0-9a-f]{6}$/i.test(s);
  const out = {};
  if (good(n))  out.n  = n;
  if (good(w1)) out.w1 = w1;
  if (good(w2)) out.w2 = w2;
  return out;
}
function mStorageKey(){ return 'timepon.multiRooms.v1'; }
function mLoad(){
    try {
        const j = JSON.parse(localStorage.getItem(mStorageKey()) || '[]');
        if (Array.isArray(j)) return j.map(x=>({ id: String(x.id||'').replace(/\D/g,''), name: String(x.name||'') }));
    } catch(_e){}
    return [];
}
function mSave(list){
    try { localStorage.setItem(mStorageKey(), JSON.stringify(list)); } catch(_e){}
}
function mDefaultRows(n){
    const cur = mLoad();
    const out = [];
    for (let i=0;i<n;i++){
        out.push(cur[i] || { id:'', name:'' });
    }
    return out;
}
function mFmtRemain(sec){
    sec = Math.max(-359999, Math.min(359999, Number(sec)||0));
    const neg = sec < 0;
    const s = Math.abs(sec)|0;
    const mm = String((s/60|0)).padStart(2,'0');
    const ss = String((s%60)).padStart(2,'0');
    return t('multiTimerRemain').replace('{mm}', (neg?('-'+mm):mm)).replace('{ss}', ss);
}
function remainGeneric(st){
    const now = Date.now() + CLOCK_DRIFT_MS;
    if (!st) return 0;
    if (st.state==='running'){
        return Math.ceil((st.durationSec*1000 - (now - st.startedAtMs - (st.pausedAccumMs||0)))/1000);
    } else if (st.state==='paused'){
        const used = (st.pausedAccumMs||0) + (st.pausedAtMs? (now - st.pausedAtMs):0);
        return Math.ceil((st.durationSec*1000 - (st.startedAtMs? (now - st.startedAtMs - used):0))/1000);
    } else {
        return st.durationSec||0;
    }
}
function mPromptStatus(st, online){
    const msg = (st?.message||'').trim();
    const msgAt = Number(st?.messageAtMs||0);
    const ack   = Number(st?.stage?.msgAckMs||0);
    if (!msg) return { text: t('multiPromptNone'), cls: '' };           // ← バッジを外す
    if (!online) return { text: t('multiPromptOffline'), cls: 'badge b-off' };
    if (ack >= msgAt) return { text: t('multiPromptShown'), cls: 'badge b-on' };
    return { text: t('multiPromptPending'), cls: 'badge b-pend' };
}
function mFmtRemainRich(sec){
    sec = Math.max(-359999, Math.min(359999, Number(sec)||0));
    const neg = sec < 0;
    const s = Math.abs(sec)|0;
    const mm = String((s/60|0)).padStart(2,'0');
    const ss = String((s%60)).padStart(2,'0');
    const mmTxt = neg ? ('-' + mm) : mm;
    return `<span class="remain"><span class="mm">${mmTxt}</span>:<span class="ss">${ss}</span></span>`;
}
function mTimerStatus(st){
    const state = (st?.state)||'idle';
    const remain = remainGeneric(st);
    let html;
    if (state==='running')      html = t('multiTimerRunning') + ' / ' + mFmtRemainRich(remain);
    else if (state==='paused')  html = t('multiTimerPaused')  + ' / ' + mFmtRemainRich(remain);
    else                        html = t('multiTimerIdle')    + ' / ' + mFmtRemainRich(remain);
    const w1 = (st?.warn1Min||0)*60;
    const w2 = (st?.warn2Min||0)*60;
    let cls = '';
    if (remain <= 0)      cls = 'tR';
    else if (remain <= w2) cls = 'tO';
    else if (remain <= w1) cls = 'tG';
    return { html, cls };
}
function mOnline(st){
    const seen = Number(st?.stage?.lastSeen||0)*1000;
    const now  = Date.now() + CLOCK_DRIFT_MS;
    const on   = seen && (now - seen < 6000);
    return { on, html: `<span class="badge ${on?'b-on':'b-off'}">${on ? t('online') : t('offline')}</span>` };
}
function multiRenderRows(){
    const n = Math.max(1, Math.min(50, Number(el('mCount').value)||3));
    const rows = mDefaultRows(n);
    const tb = el('mRows');
    tb.innerHTML = '';
    rows.forEach((r, i)=>{
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td>${i+1}</td>
            <td><input class="mId" id="mId_${i}" inputmode="numeric" pattern="\\d*" value="${h(r.id)}" placeholder="000000" style="width:9ch"></td>
            <td><input class="mName" id="mName_${i}" value="${h(r.name)}" placeholder="${h(t('multiRoomName'))}" style="width:24ch"></td>
            <td id="mTimer_${i}">-</td>
            <td id="mPrompt_${i}">-</td>
            <td id="mOn_${i}">-</td>
        `;
        tb.appendChild(tr);
    });
}
let _mPollTimer = null;
function mCollectAndSave(){
    const n = Math.max(1, Math.min(50, Number(el('mCount').value)||3));
    const list = [];
    for (let i=0;i<n;i++){
        const id = (el('mId_'+i)?.value||'').replace(/\D/g,'').slice(0,6);
        const name = (el('mName_'+i)?.value||'').slice(0,100);
        list.push({ id, name });
    }
    mSave(list);
    return list;
}
async function mFetch(id){
    if (!id || id.length!==6) return null;
    try{
        const r = await fetch(`?act=get&id=${encodeURIComponent(id)}&t=${Date.now()}`, { cache:'no-store' });
        const ct = (r.headers.get('content-type')||'').toLowerCase();
        if (r.ok && ct.includes('application/json')){
            const j = await r.json();
            if (j && j.ok) {
                if (j.serverNowMs) updateDrift(j.serverNowMs);
                if (j.exists === false) return null;
                return j.state || null;
            }
        }
    }catch(_e){}
    return null;
}
async function mPollOnce(){
    const list = mCollectAndSave();
    await Promise.all(list.map(async (item, i)=>{
        const st = await mFetch(item.id);
        const tdTimer  = el('mTimer_'+i);
        const tdPrompt = el('mPrompt_'+i);
        const tdOn     = el('mOn_'+i);
        if (!st){
            if (tdTimer)  tdTimer.textContent = '-';
            if (tdPrompt) tdPrompt.textContent = '-';
            if (tdOn)     tdOn.innerHTML = `<span class="badge b-off">${t('offline')}</span>`;
            return;
        }
        const on = mOnline(st);
        if (tdOn) tdOn.innerHTML = on.html;
        const ts = mTimerStatus(st);
        if (tdTimer){ tdTimer.innerHTML = ts.html; tdTimer.className = ts.cls; }
        const ps = mPromptStatus(st, on.on);
        if (tdPrompt){ tdPrompt.textContent = ps.text; tdPrompt.className = ps.cls; }
    }));
}
function mStartPolling(){
    clearInterval(_mPollTimer);
    _mPollTimer = setInterval(mPollOnce, 2000);
    mPollOnce();
}
function multiInit(){
    const saved = mLoad();
    el('mCount').value = Math.max(1, Math.min(50, saved.length || 3));
    multiRenderRows();
    saved.forEach((r, i)=>{
        const id = el('mId_'+i); const nm = el('mName_'+i);
        if (id) id.value = r.id||'';
        if (nm) nm.value = r.name||'';
    });
    el('mApply').onclick = ()=>{
        multiRenderRows();
        mCollectAndSave();
        mStartPolling();
    };
    el('mRows').addEventListener('input', ()=>{
        mCollectAndSave();
    }, { passive:true });
    mStartPolling();
}

window.addEventListener('resize', syncTopRowHeights);
</script>