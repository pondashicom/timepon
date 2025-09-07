<?php
// SPDX-License-Identifier: MIT
/* =========================================================
 * カンファレンスタイマー 「TIME-PON」
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
 * - 警告色の遷移：青 → 緑（第1） → オレンジ（第2） → 赤（0秒以降はやわらか点滅）。
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
    header("Content-Security-Policy: default-src 'self'; connect-src 'self'; img-src 'self' data: https://api.qrserver.com; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'; object-src 'none';");
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

umask(007);

$DATA_DIR = getenv('TIMEPON_DATA_DIR') ?: (__DIR__ . '/data');
if (!is_dir($DATA_DIR)) { @mkdir($DATA_DIR, 0775, true); }
$ht = $DATA_DIR . '/.htaccess';
if (!file_exists($ht)) { @file_put_contents($ht, "Require all denied\n"); }

/* ---------- helpers ---------- */
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
            'durationSec'=>2400, // 40分
            'warn1Min'=>10,
            'warn2Min'=>5,
            'startedAtMs'=>0,
            'pausedAccumMs'=>0,
            'pausedAtMs'=>0,
            'message'=>'',
            'stage'=>['lastSeen'=>0,'fullscreen'=>false,'startedAckMs'=>0],
            'adminKey'=>null,
            'updatedAt'=>time()
        ];
    }
    $j = @file_get_contents($file);
    $d = @json_decode($j, true);
    if (!is_array($d)) $d = [];
    if (!isset($d['warn1Min']) && isset($d['warnSec'])) $d['warn1Min'] = max(0, intval($d['warnSec']/60));
    if (!isset($d['warn2Min'])) $d['warn2Min'] = 5;
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
        'stage'=>['lastSeen'=>0,'fullscreen'=>false,'startedAckMs'=>0],
        'adminKey'=>null,
        'updatedAt'=>time()
    ], $d);
}

function save_state($id, $st){
    $file = id_to_file($id);
    $tmp  = $file . '.tmp';
    $st['updatedAt'] = time();
    $fp = @fopen($tmp, 'c+'); if (!$fp) return false;
    flock($fp, LOCK_EX);
    ftruncate($fp, 0);
    fwrite($fp, json_encode($st, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    @rename($tmp, $file);
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
    $now = time(); $win = 60; $max = 300; // 300req/分
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
    $refer  = $_SERVER['HTTP_REFERER'] ?? '';
    if ($origin !== '') { return stripos($origin, $self) === 0; }
    if ($refer  !== '') { return stripos($refer,  $self) === 0; }
    return true;
}
function require_same_origin(): void {
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

/* ========= API ========= */
if (mt_rand(1, 50) === 1) { gc_old_rooms(7); }

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
    $st['stage']['startedAckMs'] = 0;
    $st['adminKey'] = gen_admin_key();
    save_state($id, $st);
    json_out(['ok'=>true, 'id'=>$id, 'adminKey'=>$st['adminKey']]);
}
if ($act === 'get') {
    throttle_get_hb();
    $id = preg_replace('/\D/', '', $id);
    if ($id === '') json_out(['ok'=>false,'error'=>'id required']);
    $st = load_state($id);
    json_out(['ok'=>true,'state'=>redact_state($st), 'serverNowMs'=>(int)(microtime(true)*1000)]);
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
                // running: ignore
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
    $st['durationSec'] = $durMin * 60;
    $st['warn1Min']    = $warn1Min;
    $st['warn2Min']    = $warn2Min;
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
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black">
<title>TIME-PON</title>

<style>
:root{--n:#0ea5e9;--g:#22c55e;--o:#ff8c00;--r:#dc2626;--fg:#fff}
html,body{height:100%;margin:0;font-family:-apple-system,system-ui,Segoe UI,Roboto,"Noto Sans JP",sans-serif}
body{background:#0b1016;color:#e5e7eb}
h1,h2,h3{color:#e5e7eb}
a{color:#93c5fd}
.container{max-width:1200px;margin:24px auto;padding:0 16px}
.card{border:1px solid #1f2937;border-radius:12px;padding:16px;margin-bottom:16px;background:#111827}
.row{display:flex;gap:12px;flex-wrap:wrap;align-items:center}
#adminGrid{display:block}
.topRow{display:grid;grid-template-columns:2.1fr 1.1fr;gap:16px}
@media (min-aspect-ratio:16/9){#adminGrid{display:grid;grid-template-columns:1fr 2.3fr;gap:16px;align-items:start}.colL>.card{margin-bottom:16px}.colR>.card{margin-bottom:16px}.stick{position:sticky;top:16px}}
input,button{padding:10px 14px;font-size:16px}
input[type=number]{width:5ch}
#msg{width:520px;max-width:70vw}
#adur,#aw1,#aw2{width:5ch;text-align:right}
button{border:none;border-radius:10px;background:#2563eb;color:#fff;cursor:pointer}
button.secondary{background:#374151}
button.danger{background:#b91c1c}
.btnxl{font-size:22px;padding:16px 22px;border-radius:14px}
#admin .btnxl{margin-top:24px;margin-bottom:24px}
.big{font-size:clamp(56px,12vw,96px);font-weight:800}
.mono{font-variant-numeric:tabular-nums;letter-spacing:.02em;line-height:.9}
.muted{opacity:.8;font-size:12px}
.hidden{display:none}
.bad{color:#ef4444;font-weight:700}
.good{color:#10b981;font-weight:700}
#aremain{display:block;min-height:1.05em;font-variant-numeric:tabular-nums;letter-spacing:.02em;line-height:.9}
#aremain.txtG{ color: var(--g); }
#aremain.txtO{ color: var(--o); }
#aremain.txtR{ color: var(--r); }
.copyBtn{padding:6px 10px;font-size:14px;background:#10b981;border-radius:8px;color:#052e26}
.idBadge{display:inline-block;background:#111827;color:#fff;border-radius:8px;padding:4px 8px;font-weight:700;letter-spacing:.03em;border:1px solid #334155}
.roomMeta{display:flex;flex-wrap:wrap;gap:8px 12px;align-items:center;line-height:1.9}
.roomMeta .item{display:flex;align-items:center;gap:6px}
.roomMeta a{overflow-wrap:anywhere;text-decoration:underline;color:#93c5fd}
#curRoom .item + .item{margin-top:10px}
.stageWrap{height:100svh;height:100vh;padding-left:env(safe-area-inset-left);padding-right:env(safe-area-inset-right);padding-top:env(safe-area-inset-top);padding-bottom:env(safe-area-inset-bottom);display:flex;flex-direction:column;align-items:center;justify-content:center;background:var(--n);color:var(--fg)}
.time{font-size:18vw;line-height:1;font-weight:800;letter-spacing:.03em;text-shadow:0 4px 16px rgba(0,0,0,.2)}
.msg{font-size:8vw;margin-top:2vh;opacity:.95;text-align:center;text-shadow:0 4px 16px rgba(0,0,0,.25)}
.msg.attn{animation:attn 1.4s ease-in-out infinite}
@keyframes attn{0%{opacity:1}50%{opacity:.6}100%{opacity:1}}
.softflash{animation:softflash 2.4s ease-in-out infinite}
@keyframes softflash{0%{opacity:1}50%{opacity:.55}100%{opacity:1}}
#qrImg{width:240px;height:240px;border:1px solid #334155;border-radius:12px;background:#fff}

.langSwitch{
  position: fixed;
  top:  calc(16px + env(safe-area-inset-top));
  right:calc(16px + env(safe-area-inset-right));
  z-index: 2147483647;
  display: inline-flex;
  align-items: center;
}
.langSwitch input{ position:absolute; opacity:0; pointer-events:none; }
.langSwitch .switch{
  display:block;
  width:74px; height:32px; border-radius:9999px; position:relative;
  background: rgba(31,41,55,.92);
  border: 1px solid rgba(148,163,184,.35);
  box-shadow:
    0 6px 20px rgba(0,0,0,.35),
    0 0 0 1px rgba(255,255,255,.03) inset;
  backdrop-filter: saturate(140%) blur(6px);
  -webkit-backdrop-filter: saturate(140%) blur(6px);
  transition: background .2s ease, border-color .2s ease, box-shadow .2s ease;
  cursor: pointer;
}

.langSwitch .labels{
  position:absolute; inset:0; display:flex; align-items:center; justify-content:space-between;
  padding:0 10px; font-size:12px; letter-spacing:.02em; color:#9ca3af; user-select:none; pointer-events:none;
}
.langSwitch .thumb{
  position:absolute; top:3px; left:3px;
  width:26px; height:26px; border-radius:9999px; background:#fff;
  box-shadow: 0 2px 6px rgba(0,0,0,.35);
  transition: transform .2s ease;
  will-change: transform;
}
.langSwitch input:checked + label.switch .thumb{ transform: translateX(41px); }
.langSwitch input:checked + label.switch{ background:#2563eb; }
.langSwitch input:focus-visible + label.switch{ outline:2px solid #93c5fd; outline-offset:3px; border-color:#93c5fd; }
</style>

<!-- EN/JP toggle (hidden on Stage) -->
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
    </div>

    <div class="muted" style="margin-top:8px" data-i18n="homeHelp1">
      このURLは共通です。オペレーターは「オペレータ」、登壇者は「演台」を押してください。
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
        <img id="qrImg" alt="QR">
        <div style="margin-top:8px"><span data-i18n="labelURL">URL:</span> <a id="stageLink" target="_blank" rel="noopener"></a></div>
        <div class="muted" data-i18n="qrHelp">※ スマートフォン、タブレットでQRを読むと、タイマーが表示されます。</div>
      </div>
    </div>

    <div class="colR">
      <div class="topRow">
        <div class="card">
          <div><span data-i18n="lblState">状態</span>: <span id="astate">-</span></div>
          <div><span data-i18n="lblRemain">残り時間</span>: <span id="aremain" class="big mono">--:--</span></div>
          <div class="muted"><span data-i18n="lblStartedAt">開始時刻</span>: <span id="astarted">-</span></div>
        </div>
        <div class="card">
          <div><span data-i18n="lblOnlineTitle">演台監視</span>: <span id="aonline">offline</span></div>
          <div class="muted"><span data-i18n="lblFullscreen">フルスクリーン</span> <span id="afs">-</span></div>
          <div class="muted"><span data-i18n="lblLastSeen">最終監視時間</span> <span id="alast">-</span></div>
          <div class="muted"><span data-i18n="lblStartAck">開始ACK</span>: <span id="aack">-</span></div>
        </div>
      </div>

      <div class="card">
        <div class="row">
          <button id="start" class="btnxl" data-i18n="start">スタート</button>
          <button id="pause" class="secondary btnxl" data-i18n="pauseResume">一時停止</button>
          <button id="reset" class="danger btnxl" data-i18n="reset">リセット</button>
        </div>
      </div>

      <div class="card">
        <h3 data-i18n="promptTitle">カンペ</h3>
        <div class="row">
          <input id="msg" data-i18n-ph="msgPlaceholder" placeholder="カンペ（演台に表示）">
          <button id="sendMsg" class="secondary" data-i18n="send">送信</button>
          <button id="clearMsg" class="secondary" data-i18n="clear">消去</button>
        </div>
      </div>

      <div class="card">
        <h3 data-i18n="clockTitle">時計設定</h3>
        <div class="row">
          <label><span data-i18n="labelDuration">持ち時間</span> <input id="adur" type="number" min="1" value="40"> <span data-i18n="labelMin">分</span></label>
          <label><span data-i18n="labelWarn1">第1警告</span> <input id="aw1" type="number" min="0" value="10"> <span data-i18n="labelWarn1Tail">分前（緑）</span></label>
          <label><span data-i18n="labelWarn2">第2警告</span> <input id="aw2" type="number" min="0" value="5"> <span data-i18n="labelWarn2Tail">分前（オレンジ）</span></label>
          <button id="saveSettings" class="secondary" data-i18n="saveSettings">設定を保存</button>
        </div>
        <div class="muted" data-i18n="clockHint">※ 例：第1=10、第2=5 →「10分前に緑」「5分前にオレンジ」「0で赤（やわらか点滅）」</div>
      </div>
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
    <div class="msg" id="smsg"></div>
  </div>
</div>

<script>
const el = (id)=>document.getElementById(id);
const fmt = (sec)=>{ const neg=sec<0; sec=Math.abs(sec); const m=(sec/60|0), s=(sec%60|0); return (neg?'-':'')+String(m).padStart(2,'0')+':'+String(s).padStart(2,'0'); };
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

/* ===== ultra-light i18n (JA/EN) ===== */
const SUPPORTED_LANGS = ['ja','en'];
const paramLang = (usp.get('lang') || '').toLowerCase();
let LANG = SUPPORTED_LANGS.includes(paramLang)
  ? paramLang
  : (localStorage.getItem('timepon.lang') || ((navigator.language||'').toLowerCase().startsWith('ja') ? 'ja' : 'en'));

const I18N = {
  ja: {
    // Menu/Headings
    homeTitle: '管理画面',
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

    adminTitle: '管理画面',
    createRoom: '新規ルーム作成',
    open: '開く',
    lblCurrentRoom: '現在のルームID：',
    qrTitle: '演台用QR',
    labelURL: 'URL:',
    qrHelp: '※ スマートフォン、タブレットでQRを読むと、タイマーが表示されます。',
    promptTitle: 'カンペ',
    clockTitle: '時計設定',
    stageEnterTitle: '演台',

    // Buttons & placeholders
    start: 'スタート',
    pauseResume: '一時停止',
    reset: 'リセット',
    send: '送信',
    clear: '消去',
    saveSettings: '設定を保存',
    sgo: '入る',
    msgPlaceholder: 'カンペ（演台に表示）',
    sidPlaceholder: 'ルームID（6桁）',
    aidPlaceholder: '既存ルームID（6桁）を開く',

    // Admin labels
    lblState: '状態',
    lblRemain: '残り時間',
    lblStartedAt: '開始時刻',
    lblOnlineTitle: '演台監視',
    lblFullscreen: 'フルスクリーン',
    lblLastSeen: '最終監視時間',
    lblStartAck: '開始ACK',

    // Clock labels
    labelDuration: '持ち時間',
    labelMin: '分',
    labelWarn1: '第1警告',
    labelWarn1Tail: '分前（緑）',
    labelWarn2: '第2警告',
    labelWarn2Tail: '分前（オレンジ）',
    clockHint: '※ 例：第1=10、第2=5 →「10分前に緑」「5分前にオレンジ」「0で赤（やわらか点滅）」',

    // Status values / toggles
    state_idle: '待機',
    state_running: '進行中',
    state_paused: '一時停止',
    online: 'online',
    offline: 'offline',
    on: 'ON',
    off: 'OFF',

    // Copy / toast
    copyAdmin: '管理用URLをコピー',
    copyStage: '演台用URLをコピー',
    copied: 'コピー済',

    // Alerts / errors
    errOpenFirst: '先にルームを開いてください',
    errCreateFail: '作成失敗',
    errSaveFail: '保存失敗',
    errId6: '6桁のIDを入力してください',

    // Auto prompt
    autoMinLeft: 'あと{n}分です'
  },
  en: {
    homeTitle: 'Control Panel',
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

    adminTitle: 'Control Panel',
    createRoom: 'Create Room',
    open: 'Open',
    lblCurrentRoom: 'Room ID:',
    qrTitle: 'Stage QR',
    labelURL: 'URL:',
    qrHelp: 'Scan this QR with your phone or tablet to open the timer.',
    promptTitle: 'Prompt',
    clockTitle: 'Clock Settings',
    stageEnterTitle: 'Stage',

    start: 'Start',
    pauseResume: 'Pause',
    reset: 'Reset',
    send: 'Send',
    clear: 'Clear',
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

    labelDuration: 'Time',
    labelMin: 'min',
    labelWarn1: 'Warn 1',
    labelWarn1Tail: 'min (green)',
    labelWarn2: 'Warn 2',
    labelWarn2Tail: 'min (orange)',
    clockHint: 'Ex: 10 & 5 → 10m=green, 5m=orange, 0=red',

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

    autoMinLeft: '{n} minutes remaining'
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

/* 初期表示はダーク */
document.body.classList.add('darkui');

/* EN/JP switch init */
const langChk = document.getElementById('langChk');
if (langChk){
  langChk.checked = (LANG === 'en');
  langChk.addEventListener('change', ()=> setLang(langChk.checked ? 'en' : 'ja'));
}
applyLangAll();
showLangSwitch(true); // 初期はホームで表示

el('toAdmin').onclick = ()=>{
  el('home').classList.add('hidden');
  el('admin').classList.remove('hidden');
  showLangSwitch(true);   // 管理側に表示
};
el('toStage').onclick = ()=>{
  el('home').classList.add('hidden');
  el('stageEnter').classList.remove('hidden');
  showLangSwitch(false);  // 演台側では非表示
};

window.addEventListener('load', ()=>{
  if (Q_ID) {
    if (Q_OP === '1') { showLangSwitch(true);  openAdmin(Q_ID); }
    else             { showLangSwitch(false); openStage(Q_ID); }
  } else {
    showLangSwitch(true);
  }
});


/* ===== Admin ===== */
let A_id = null, A_state=null, A_loadedOnce=false;
let A_lastRemainSec = null;
let A_auto1Sent = false, A_auto2Sent = false;

function openAdmin(id){
  el('home').classList.add('hidden'); el('admin').classList.remove('hidden');
  showLangSwitch(true);
  A_id = id;
  renderRoomHeader();
  adminPull();
}

function renderRoomHeader(){
    if (!A_id) return;
    el('curRoom').style.display = 'block';
    el('curId').textContent = A_id;
    const k = ADMIN_KEY || localStorage.getItem('timepon.k.' + A_id) || '';
    const adminURL = `${BASE_URL}?op=1&id=${A_id}${k ? ('#k=' + encodeURIComponent(k)) : ''}`;
    const stageURL = `${BASE_URL}?id=${A_id}`;

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
  const stageURL = `${BASE_URL}?id=${encodeURIComponent(id)}`;
  const qrAPI = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&margin=10&data=' + encodeURIComponent(stageURL);
  el('qrImg').src = qrAPI;
  el('stageLink').textContent = stageURL; el('stageLink').href = stageURL;
  el('qrCard').style.display = 'block';
}

el('create').onclick = async ()=>{
  const res = await fetch('?act=create', {method:'POST'});
  const j = await res.json();
  if (!j.ok){ alert(t('errCreateFail') + ': ' + (j.error||'')); return; }
  location.href = `${BASE_URL}?op=1&id=${encodeURIComponent(j.id)}#k=${encodeURIComponent(j.adminKey||'')}`;
};
el('open').onclick = ()=>{
  const v = (el('aid').value||'').trim().replace(/\D/g,'');
  if (!v || v.length!==6){ alert(t('errId6')); return; }
  const storedK = localStorage.getItem('timepon.k.' + v) || '';
  const kFrag = storedK ? ('#k=' + encodeURIComponent(storedK)) : '';
  location.href = `${BASE_URL}?op=1&id=${encodeURIComponent(v)}${kFrag}`;
};

el('saveSettings').onclick = async ()=>{
    if (!A_id){ alert(t('errOpenFirst')); return; }
    const durMin   = Math.max(1, Number(el('adur').value)||40);
    const warn1Min = Math.max(0, Number(el('aw1').value)||10);
    const warn2Min = Math.max(0, Number(el('aw2').value)||5);
    const fd = new FormData();
    fd.append('act','setSettings'); fd.append('id',A_id);
    fd.append('durMin',durMin); fd.append('warn1Min',warn1Min); fd.append('warn2Min',warn2Min);
    const k = ADMIN_KEY || localStorage.getItem('timepon.k.' + A_id) || '';
    if (k) fd.append('k', k);
    const r = await fetch('?act=setSettings', {method:'POST', body:fd}); const j = await r.json();
    if (!j.ok){ alert(t('errSaveFail') + ': ' + (j.error||'')); return; }
    A_auto1Sent = false; A_auto2Sent = false; A_lastRemainSec = null;
    setTimeout(adminPull, 200);
};

async function adminPull(){
  if (!A_id){ setTimeout(adminPull, 1000); return; }
  try{
    const r = await fetch(`?act=get&id=${encodeURIComponent(A_id)}&t=${Date.now()}`, {cache:'no-store'});
    const j = await r.json();
    if (j && j.ok){
      if (j.serverNowMs) updateDrift(j.serverNowMs);
      A_state = j.state;
      adminRender();
    }
  }catch(e){}
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

function adminRender(){
  if (!A_state) return;
  if (!A_loadedOnce){
    el('adur').value = Math.max(1, Math.round((A_state.durationSec||2400)/60));
    el('aw1').value  = A_state.warn1Min ?? 10;
    el('aw2').value  = A_state.warn2Min ?? 5;
    A_loadedOnce = true;
  }
  const stName = (A_state && A_state.state) ? A_state.state : 'idle';
  el('astate').textContent = t('state_' + stName);

  const remain = remainFromState(A_state);
  el('aremain').textContent = fmt(remain);

  // ★ しきい値（秒）を元にフォント色を切り替え
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

  maybeAutoPrompt(remain);
}

async function cmd(c, extra={}){
    if (!A_id){ alert(t('errOpenFirst')); return; }
    const fd = new FormData(); fd.append('act','set'); fd.append('id',A_id); fd.append('cmd',c);
    const k = ADMIN_KEY || localStorage.getItem('timepon.k.' + A_id) || '';
    if (k) fd.append('k', k);
    Object.entries(extra).forEach(([kk,vv])=>fd.append(kk,vv));
    await fetch('?act=set', {method:'POST', body:fd});
}

/* Buttons */
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
el('sendMsg').onclick = ()=> cmd('message', {text: el('msg').value||''});
el('clearMsg').onclick= ()=>{ el('msg').value=''; cmd('message', {text: ''}); };

/* ===== Stage ===== */
let S_id = null, S_state=null, S_lastRunning=false;

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
  showLangSwitch(false); // 演台側では非表示
  S_id = id;

  nudgeScroll();
  window.addEventListener('orientationchange', ()=>{ setTimeout(nudgeScroll, 500); });

  stageLoop(); stageHeartbeat();
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
    const j = await r.json();
    if (j && j.ok){
      if (j.serverNowMs) updateDrift(j.serverNowMs);
      S_state = j.state;
    }
  }catch(e){}
  setTimeout(stagePull, 1000);
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
  el('stime').textContent = fmt(remain);

  const m = S_state.message||'';
  const msgEl = el('smsg');
  msgEl.textContent = m;
  msgEl.classList.toggle('attn', !!m);

  paint(remain, S_state.warn1Min||10, S_state.warn2Min||5);

  const isRunning = (S_state.state==='running');
  if (isRunning && !S_lastRunning){
    fetch('?act=ackStart', {method:'POST', body: new URLSearchParams({id:S_id})});
  }
  S_lastRunning = isRunning;
}
function stageLoop(){ stagePull(); (function tick(){ stageRender(); requestAnimationFrame(tick); })(); }
function stageHeartbeat(){ if (!S_id) return; setTimeout(stageHeartbeat, 3000); }

/* ===== Shortcuts (Admin) =====
 * SHIFT+SPACE : スタート/一時停止のトグル
 * SHIFT+R     : リセット
 * SHIFT+K     : カンペ送信
 * SHIFT+C     : カンペ消去
 *  - 管理画面表示時のみ有効
 *  - 入力中は無効（#msg での SHIFT+K は送信として許可）
 */
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
        // 入力中は原則無効。ただし #msg + SHIFT+K は許可
        const allowMsgSend = (ae && ae.id === 'msg' && (e.code === 'KeyK' || (e.key && e.key.toLowerCase() === 'k')));
        if (isEditable && !allowMsgSend) return;

        const stop = ()=>{ e.preventDefault(); e.stopPropagation(); };

        // SHIFT + SPACE: トグル（走行中→一時停止 / それ以外→スタート）
        if (e.code === 'Space' || e.key === ' ') {
            stop();
            if (A_state && A_state.state === 'running') {
                document.getElementById('pause').click();
            } else {
                document.getElementById('start').click();
            }
            return;
        }

        // SHIFT + R/K/C
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
</script>

