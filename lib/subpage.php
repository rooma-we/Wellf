<?php
// subpage.php — صفحه پروفایل اشتراک عمومی (معادل subscription_page.py و get_public_page_html در pages.py)
// صفحه‌ی RTL با همان هویت بصری و ویجت‌های مصرف/لینک/QR

declare(strict_types=1);
// PANEL_LOAD_GUARD — جلوگیری از اجرای مستقیم این فایل از وب (دفاع عمیق، علاوه بر .htaccess)
if (!isset($GLOBALS['WV_PANEL'])) { http_response_code(403); exit('Forbidden'); }


require_once __DIR__ . '/links.php';
require_once __DIR__ . '/subs.php';

/**
 * معادل build_3x_sub_page_data() — view model صفحه پروفایل
 */
function build_sub_page_data(
    string $subscription_id,
    string $label,
    string $host,
    bool $enabled,
    int $used_bytes,
    int $limit_bytes,
    ?string $expires_at,
    array $links,
    bool $is_online = false,
    string $announcement = '',
): array {
    $expire_epoch = 0;
    if ($expires_at) {
        try {
            $expire_epoch = (new DateTimeImmutable($expires_at))->getTimestamp();
        } catch (Exception) {
            $expire_epoch = 0;
        }
    }
    $remained = $limit_bytes > 0 ? max($limit_bytes - $used_bytes, 0) : 0;
    $sub_url = 'https://' . $host . '/sub/' . $subscription_id;
    return [
        'host' => $host,
        'sId' => $subscription_id,
        'enabled' => $enabled,
        'isOnline' => $is_online,
        'download' => fmt_bytes($used_bytes),
        'total' => $limit_bytes > 0 ? fmt_bytes($limit_bytes) : '∞',
        'used' => fmt_bytes($used_bytes),
        'remained' => $limit_bytes > 0 ? fmt_bytes($remained) : '',
        'expire' => $expire_epoch,
        'downloadByte' => $used_bytes,
        'totalByte' => $limit_bytes,
        'subUrl' => $sub_url,
        'subFeedUrl' => $sub_url . '?view=raw',
        'subTitle' => $label,
        'announce' => $announcement,
        'links' => $links,
    ];
}

function esc_html(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function to_fa(string $s): string
{
    return strtr($s, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']);
}

function render_qr_modal(): string
{
    return <<<'HTML'
<div class="qr-modal" id="qr-modal" onclick="this.classList.remove('open')">
  <div class="qr-box" onclick="event.stopPropagation()">
    <div class="qr-title" id="qr-label">QR Code</div>
    <div class="qr-img"><img id="qr-img" src="" alt="QR"></div>
    <button class="btn btn-g" style="width:100%;justify-content:center" onclick="document.getElementById('qr-modal').classList.remove('open')"><i class="ti ti-x"></i> بستن</button>
  </div>
</div>
HTML;
}

function render_sub_common_js(): string
{
    return <<<'JS'
function esc(s){return String(s||'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]))}
function fmtB(b){if(!b||b===0)return '0 B';if(b<1024)return b+' B';if(b<1024**2)return (b/1024).toFixed(1)+' KB';if(b<1024**3)return (b/1024**2).toFixed(2)+' MB';return (b/1024**3).toFixed(2)+' GB'}
function toFa(n){return String(n).replace(/\d/g,d=>'۰۱۲۳۴۵۶۷۸۹'[d])}
function copyText(t,msg){navigator.clipboard.writeText(t).then(()=>toast(msg||'کپی شد','ok')).catch(()=>toast('کپی ناموفق بود'))}
let isDark=localStorage.getItem('wv-pub-theme')!=='light';
function applyTheme(dark){document.documentElement.setAttribute('data-theme',dark?'dark':'light');const i=document.getElementById('theme-icon');if(i)i.className='ti '+(dark?'ti-sun':'ti-moon')}
function toggleTheme(){isDark=!isDark;localStorage.setItem('wv-pub-theme',isDark?'dark':'light');applyTheme(isDark)}
applyTheme(isDark);
function toast(msg,type=''){const t=document.getElementById('toast');t.textContent=msg;t.className='toast show'+(type?' '+type:'');setTimeout(()=>t.classList.remove('show'),2400)}
function showQR(label,link){document.getElementById('qr-label').textContent=label;document.getElementById('qr-img').src='https://api.qrserver.com/v1/create-qr-code/?size=260x260&data='+encodeURIComponent(link);document.getElementById('qr-modal').classList.add('open')}
function protoChip(p){p=p||'vless-ws';
 if(p==='mtproto')return '<span class="proto-chip pc-trojan"><i class="ti ti-brand-telegram"></i> Telegram Proxy</span>';
 if(p.startsWith('shadowsocks'))return '<span class="proto-chip pc-ss"><i class="ti ti-shield-lock-filled"></i> Shadowsocks</span>';
 if(p.startsWith('trojan'))return '<span class="proto-chip pc-trojan"><i class="ti ti-shield-lock"></i> '+esc(p)+'</span>';
 if(p.startsWith('xhttp'))return '<span class="proto-chip pc-xhttp">'+esc(p)+'</span>';
 return '<span class="proto-chip pc-ws">VLESS · WS</span>';}
JS;
}

/**
 * صفحه پروفایل تک‌لینک /sub/{uuid} (معادل get_public_page_html با subscription_type=single)
 */
function render_sub_page(array $d): string
{
    $json = json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $percent = ($d['totalByte'] ?? 0) > 0
        ? min(100, round(($d['downloadByte'] ?? 0) / $d['totalByte'] * 100, 1))
        : 0;
    $remaining_days = '∞';
    if (($d['expire'] ?? 0) > 0) {
        $remaining_days = (string) max(0, intdiv((int) $d['expire'] - time() + 86399, 86400)) . ' روز';
    }
    $links_json = json_encode($d['links'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG);
    $links_html = '';
    foreach ($d['links'] as $i => $lnk) {
        $links_html .= '<div class="cfg-card">'
            . '<div class="cfg-top"><div class="cfg-head"><div class="cfg-label">کانفیگ #' . ($i + 1) . '</div>'
            . '<div class="cfg-badges">' . ($d['enabled'] ? '<span class="cfg-status ok"><span class="dot"></span> فعال</span>' : '<span class="cfg-status no">غیرفعال</span>') . '</div></div>'
            . '<div class="cfg-vless">' . esc_html($lnk) . '</div>'
            . '<div class="cfg-actions">'
            . '<button class="btn btn-p" onclick="copyLink(' . $i . ')"><i class="ti ti-copy"></i> کپی</button>'
            . '<button class="btn btn-g" onclick="qrLink(' . $i . ')"><i class="ti ti-qrcode"></i> QR</button>'
            . '</div></div></div>';
    }

    return "<!DOCTYPE html>\n<html lang=\"fa\" dir=\"rtl\">\n<head>\n<meta charset=\"UTF-8\">\n<meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n<title>WelfVita Sub · " . esc_html($d['subTitle'] ?? '') . "</title>\n"
        . sub_page_head_css()
        . "</head>\n<body>\n<div class=\"bg-fx\"></div><div class=\"grid-fx\"></div>\n<div class=\"toast\" id=\"toast\"></div>\n"
        . render_qr_modal()
        . '<div class="wrap">
  <div class="top">
    <div class="brand">
      <div class="brand-logo"><i class="ti ti-bolt"></i></div>
      <div><div class="brand-name">WelfVita Sub</div><div class="brand-sub">Gateway · v9.2</div></div>
    </div>
    <div class="top-actions">
      <button class="icon-btn" onclick="toggleTheme()" title="تغییر تم"><i class="ti ti-sun" id="theme-icon"></i></button>
    </div>
  </div>
  <div class="sub-info">
    <div class="sub-eyebrow"><i class="ti ti-user"></i> پروفایل اشتراک</div>
    <div class="sub-name">' . esc_html($d['subTitle'] ?? 'اشتراک') . '</div>
    ' . ($d['announce'] ? '<div class="sub-desc">' . esc_html($d['announce']) . '</div>' : '') . '
    <div class="sub-sub-box">
      <i class="ti ti-link" style="color:var(--accent2)"></i>
      <div class="sub-sub-url" id="sub-url">' . esc_html($d['subUrl'] ?? '') . '</div>
      <button class="btn btn-g" onclick="copyText(document.getElementById(\'sub-url\').textContent,\'آدرس اشتراک کپی شد\')"><i class="ti ti-copy"></i> کپی</button>
    </div>
    <div class="total-usage-box">
      <div class="tu-head"><div class="tu-label"><i class="ti ti-chart-arcs"></i> مصرف کل</div><div class="tu-val">' . esc_html($d['used'] ?? '0 B') . ' / ' . esc_html($d['total'] ?? '∞') . '</div></div>
      <div class="tu-bar"><div class="tu-bar-f" style="width:' . $percent . '%"></div></div>
      <div class="tu-foot"><span>' . ($d['remained'] ? 'باقی‌مانده: ' . esc_html($d['remained']) : '') . '</span><span class="tu-pct">' . to_fa((string) $percent) . '٪</span></div>
    </div>
  </div>
  <div class="stats-bar">
    <div class="stat-card"><div class="stat-label">دانلود</div><div class="stat-val">' . esc_html($d['download'] ?? '0 B') . '</div><div class="stat-sub">مصرف‌شده</div></div>
    <div class="stat-card"><div class="stat-label">حجم کل</div><div class="stat-val">' . esc_html($d['total'] ?? '∞') . '</div><div class="stat-sub">سهمیه</div></div>
    <div class="stat-card"><div class="stat-label">انقضا</div><div class="stat-val">' . esc_html($remaining_days) . '</div><div class="stat-sub">زمان باقی‌مانده</div></div>
  </div>
  <div class="copy-all-bar">
    <div class="copy-all-text">
      <div class="copy-all-title"><i class="ti ti-rocket"></i> خوش آمدید</div>
      <div class="copy-all-sub">برای استفاده، لینک زیر را در کلاینت خود ایمپورت کنید</div>
    </div>
    <button class="copy-all-btn" onclick="copyText(\'' . esc_js((string) ($d['subUrl'] ?? '')) . '\',\'آدرس اشتراک کپی شد\')"><i class="ti ti-copy"></i> کپی آدرس اشتراک</button>
  </div>
  <div class="cfg-title"><i class="ti ti-plug-connected"></i> کانفیگ‌ها (' . count($d['links']) . ')</div>
  <div class="cfg-grid">' . $links_html . '</div>
  <div class="footer">WelfVita Gateway · v9.2</div>
</div>
<script>' . render_sub_common_js() . '
window.__SUB_LINKS__=' . $links_json . ';
function copyLink(i){const l=(window.__SUB_LINKS__||[])[i];if(l)copyText(l,"لینک کپی شد")}
function qrLink(i){const l=(window.__SUB_LINKS__||[])[i];if(l)showQR("کانفیگ #"+(i+1),l)}
</script>
</body>
</html>';
}

/** صفحه عمومی گروه اشتراک /p/{uuid_key} — با پشتیبانی رمز */
function render_public_group_page(string $uuid_key): string
{
    $sub = find_sub_by_key($uuid_key);
    if (!$sub) {
        return '<h2 style="font-family:sans-serif;padding:40px">گروه پیدا نشد</h2>';
    }
    $uuid_key_js = json_encode($uuid_key);
    $qrModal = render_qr_modal();
    $subName = $sub['name'] ?? 'اشتراک';
    $commonJs = render_sub_common_js();
    $script = <<<'JS'
async function loadData(pw=''){
  try{
    const r=await fetch('/api/public/sub/'+UUID_KEY+(pw?'?pw='+encodeURIComponent(pw):''));
    const d=await r.json();
    if(d.locked){renderLock();return;}
    renderData(d);
  }catch(e){document.getElementById('root').innerHTML='<div class="empty-state"><i class="ti ti-wifi-off"></i>خطا در بارگذاری</div>'}
}
function renderLock(){
  document.getElementById('root').innerHTML=`<div class="lock-stage"><div class="lock-card">
   <div class="lock-banner"><div class="lock-shield"><i class="ti ti-lock"></i></div><div class="lock-title">این اشتراک قفل است</div><div class="lock-sub">برای نمایش کانفیگ‌ها رمز را وارد کنید</div></div>
   <div class="lock-form"><div class="lock-field"><i class="ti ti-lock lock-lockicon"></i><input id="lock-pw" class="lock-inp" type="password" placeholder="رمز اشتراک" onkeydown="if(event.key==='Enter')tryUnlock()"></div><div class="lock-err" id="lock-err"></div><button class="btn btn-p lock-btn" onclick="tryUnlock()"><i class="ti ti-lock-open"></i> باز کردن</button></div>
   <div class="lock-footer">WelfVita Gateway</div></div></div>`;
  document.getElementById('lock-pw').focus();
}
async function tryUnlock(){
  const pw=document.getElementById('lock-pw').value;
  const r=await fetch('/api/public/sub/'+UUID_KEY+'?pw='+encodeURIComponent(pw));
  const d=await r.json();
  if(d.locked){document.getElementById('lock-err').textContent='رمز اشتباه است';return;}
  savedPw=pw;loadData(pw);
}
function renderData(d){
  const pct=d.total_limit>0?Math.min(100,Math.round(d.total_used/d.total_limit*100)):0;
  let expTxt='∞';
  if(d.expiry_date){const days=Math.max(0,Math.floor((new Date(d.expiry_date)-new Date())/86400000));expTxt=days+' روز'}
  let cards='';
  (d.links||[]).forEach((l,i)=>{
    const lp=l.limit_bytes>0?Math.min(100,Math.round(l.used_bytes/l.limit_bytes*100)):0;
    window.__SUB_LINKS__=d.links;
    cards+=`<div class="cfg-card ${l.active?'':'inactive'}">
      <div class="cfg-top">
        <div class="cfg-head"><div><div class="cfg-label">${esc(l.label)}</div><div class="cfg-badges">${protoChip(l.protocol)}</div></div>
        <div class="cfg-status ${l.active?'ok':'no'}">${l.active?'<span class="dot"></span> فعال':'غیرفعال'}</div></div>
        <div class="cfg-usage"><div class="ubar"><div class="ubar-f" style="width:${lp}%;background:${l.active?'var(--green)':'var(--red)'}"></div></div>
        <div class="utxt"><span>${fmtB(l.used_bytes)}${l.limit_bytes>0?' / '+fmtB(l.limit_bytes):''}</span><span>${l.expiry_date?new Date(l.expiry_date).toLocaleDateString('fa-IR'):'∞'}</span></div></div>
      </div>
      <div class="cfg-tear"></div>
      <div class="cfg-bottom">
        <button class="cfg-link-toggle" id="vt-${i}" onclick="toggleLink(${i})"><span class="ltl"><i class="ti ti-eye"></i><span>نمایش لینک کانفیگ</span></span><i class="ti ti-chevron-down"></i></button>
        <div class="cfg-vless-wrap" id="vw-${i}"><div class="cfg-vless-inner"><div class="cfg-vless">${esc(l.vless_link)}</div></div></div>
        <div class="cfg-actions">
          <button class="btn btn-p" onclick="copyLink(${i})"><i class="ti ti-copy"></i> کپی لینک</button>
          <button class="btn btn-g" onclick="qrLink(${i})"><i class="ti ti-qrcode"></i> QR</button>
        </div>
      </div>
    </div>`;
  });
  if(!(d.links||[]).length){cards='<div class="empty-state"><i class="ti ti-inbox"></i>کانفیگی در این گروه وجود ندارد</div>'}
  document.getElementById('root').innerHTML=`
   <div class="sub-info">
     <div class="sub-eyebrow"><i class="ti ti-users-group"></i> اشتراک گروهی</div>
     <div class="sub-name">${esc(d.name)}</div>
     ${d.desc?'<div class="sub-desc">'+esc(d.desc)+'</div>':''}
     <div class="sub-sub-box"><i class="ti ti-link" style="color:var(--accent2)"></i>
       <div class="sub-sub-url" id="sub-url">${esc(d.sub_url)}</div>
       <button class="btn btn-g" onclick="copyText('${esc(d.sub_url)}','آدرس اشتراک کپی شد')"><i class="ti ti-copy"></i> کپی</button></div>
     <div class="total-usage-box">
       <div class="tu-head"><div class="tu-label"><i class="ti ti-chart-arcs"></i> مصرف کل گروه</div><div class="tu-val">${fmtB(d.total_used)} / ${d.total_limit>0?fmtB(d.total_limit):'∞'}</div></div>
       <div class="tu-bar"><div class="tu-bar-f" style="width:${pct}%"></div></div>
       <div class="tu-foot"><span>انقضای نزدیک‌ترین کانفیگ: ${expTxt}</span><span class="tu-pct">${toFa(String(pct))}٪</span></div>
     </div>
   </div>
   <div class="copy-all-bar">
     <div class="copy-all-text"><div class="copy-all-title"><i class="ti ti-rocket"></i> کپی همه کانفیگ‌های فعال</div>
     <div class="copy-all-sub">تمام کانفیگ‌های فعال در کلیپ‌بورد قرار می‌گیرند</div></div>
     <button class="copy-all-btn" onclick="copyAll()"><i class="ti ti-copy"></i> کپی همه</button>
   </div>
   <div class="cfg-title"><i class="ti ti-plug-connected"></i> کانفیگ‌ها (${(d.links||[]).length})</div>
   <div class="cfg-grid">${cards}</div>`;
}
function toggleLink(i){
  const w=document.getElementById('vw-'+i),b=document.getElementById('vt-'+i);
  const open=w.classList.toggle('open');b.classList.toggle('open',open);
  b.querySelector('.ltl span').textContent=open?'پنهان کردن لینک':'نمایش لینک کانفیگ';
}
function copyLink(i){const l=(window.__SUB_LINKS__||[])[i];if(l)copyText(l.vless_link,'لینک کپی شد')}
function qrLink(i){const l=(window.__SUB_LINKS__||[])[i];if(l)showQR(l.label||('کانفیگ #'+(i+1)),l.vless_link)}
function copyAll(){
  const active=(window.__SUB_LINKS__||[]).filter(l=>l.active).map(l=>l.vless_link);
  if(!active.length){toast('کانفیگ فعالی وجود ندارد');return}
  navigator.clipboard.writeText(active.join('\n')).then(()=>toast('همه کانفیگ‌های فعال کپی شد','ok'));
}
const _origRender=renderData;
renderData=function(d){window.__SUB_LINKS__=d.links||[];_origRender(d)};
loadData(savedPw);
JS;
    $css = sub_page_head_css();
    return <<<"HTML"
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>WelfVita Sub · {$subName}</title>
{$css}
</head>
<body>
<div class="bg-fx"></div><div class="grid-fx"></div>
<div class="toast" id="toast"></div>
{$qrModal}
<div class="wrap">
  <div class="top">
    <div class="brand">
      <div class="brand-logo"><i class="ti ti-bolt"></i></div>
      <div><div class="brand-name">WelfVita Sub</div><div class="brand-sub">Gateway · v9.2</div></div>
    </div>
    <div class="top-actions">
      <button class="icon-btn" onclick="toggleTheme()" title="تغییر تم"><i class="ti ti-sun" id="theme-icon"></i></button>
    </div>
  </div>
  <div id="root">
    <div class="empty-state"><i class="ti ti-loader-2" style="animation:spin 1s linear infinite"></i>در حال بارگذاری...</div>
  </div>
  <div class="footer">WelfVita Gateway · v9.2</div>
</div>
<script>
{$commonJs}
const UUID_KEY={$uuid_key_js};
let savedPw='';
{$script}
</script>
</body>
</html>
HTML;
}

/** استایل مشترک صفحات عمومی (معادل CSS صفحات subscription_page.py) */
function sub_page_head_css(): string
{
    $css = <<<'CSS'
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@3.19.0/dist/tabler-icons.min.css">
<style>
*{margin:0;padding:0;box-sizing:border-box;-webkit-tap-highlight-color:transparent}
:root{
  --bg:#060a14;--card:#0c1326;--card-b:rgba(96,148,246,0.12);--card-bh:rgba(96,148,246,0.28);
  --accent:#3B7CF6;--accent2:#6EA3FF;--accent-d:rgba(59,124,246,0.1);
  --green:#1FB87E;--green-bg:rgba(31,184,126,0.1);--green-t:#3FD79C;
  --red:#EF4444;--red-bg:rgba(239,68,68,0.1);--red-t:#FB8585;
  --t1:#EFF4FF;--t2:#8AA0C4;--t3:#48577A;
  --radius:18px;--shadow:0 12px 40px rgba(0,0,0,0.45);
}
[data-theme="light"]{
  --bg:#F0F3FA;--card:#FFFFFF;--card-b:rgba(59,124,246,0.14);--card-bh:rgba(59,124,246,0.32);
  --accent:#2E63D6;--accent2:#1E4CB8;--accent-d:rgba(46,99,214,0.08);
  --green:#0E9A6A;--green-bg:rgba(14,154,106,0.08);--green-t:#0A7553;
  --red:#DC2626;--red-bg:rgba(220,38,68,0.08);--red-t:#A51E1E;
  --t1:#101A30;--t2:#48577A;--t3:#8694B0;
  --shadow:0 12px 36px rgba(20,40,90,0.12);
}
html,body{min-height:100%;background:var(--bg);color:var(--t1);font-size:14px;font-family:'Vazirmatn',system-ui,sans-serif}
.bg-fx{position:fixed;inset:0;background:radial-gradient(ellipse 70% 45% at 50% -8%,rgba(59,124,246,0.13),transparent 62%),var(--bg);z-index:0;pointer-events:none}
.grid-fx{position:fixed;inset:0;background-image:linear-gradient(rgba(96,148,246,0.025) 1px,transparent 1px),linear-gradient(90deg,rgba(96,148,246,0.025) 1px,transparent 1px);background-size:46px 46px;z-index:0;pointer-events:none}
.wrap{position:relative;z-index:10;max-width:800px;margin:0 auto;padding:24px 16px 64px}
.top{display:flex;align-items:center;justify-content:space-between;margin-bottom:26px;gap:10px}
.brand{display:flex;align-items:center;gap:11px}
.brand-logo{width:40px;height:40px;border-radius:12px;background:linear-gradient(135deg,#3B7CF6,#2952C8);color:#fff;display:flex;align-items:center;justify-content:center;font-size:20px}
.brand-name{font-size:14.5px;font-weight:800}
.brand-sub{font-size:9.5px;color:var(--t3)}
.top-actions{display:flex;gap:6px}
.icon-btn{width:36px;height:36px;border-radius:11px;background:var(--card);border:1px solid var(--card-b);color:var(--t2);display:flex;align-items:center;justify-content:center;font-size:16px;cursor:pointer;transition:.18s}
.icon-btn:hover{background:var(--accent-d);color:var(--accent2)}
.sub-info{background:var(--card);border:1px solid var(--card-b);border-radius:22px;padding:24px;margin-bottom:16px;box-shadow:var(--shadow)}
.sub-eyebrow{font-size:10px;font-weight:700;color:var(--accent2);text-transform:uppercase;letter-spacing:.12em;margin-bottom:8px;display:flex;align-items:center;gap:6px}
.sub-name{font-size:23px;font-weight:800;margin-bottom:6px}
.sub-desc{font-size:12.5px;color:var(--t2);line-height:1.8;margin-bottom:14px}
.sub-sub-box{background:var(--accent-d);border:1px solid var(--card-b);border-radius:13px;padding:12px 14px;display:flex;align-items:center;gap:9px;flex-wrap:wrap}
.sub-sub-url{font-family:ui-monospace,monospace;font-size:10px;color:var(--accent2);word-break:break-all;flex:1;min-width:140px}
.total-usage-box{background:rgba(0,0,0,.14);border:1px solid var(--card-b);border-radius:13px;padding:14px 16px;margin-top:12px}
[data-theme="light"] .total-usage-box{background:rgba(46,99,214,.03)}
.tu-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:9px;gap:8px;flex-wrap:wrap}
.tu-label{font-size:10.5px;color:var(--t2);font-weight:700;display:flex;align-items:center;gap:6px}
.tu-label i{color:var(--accent2)}
.tu-val{font-size:11.5px;font-weight:800;font-family:ui-monospace,monospace}
.tu-bar{height:9px;border-radius:6px;background:rgba(96,148,246,0.12);overflow:hidden}
.tu-bar-f{height:100%;border-radius:6px;background:linear-gradient(90deg,var(--accent),var(--accent2));transition:width .6s ease}
.tu-foot{display:flex;justify-content:space-between;margin-top:7px;font-size:9.5px;color:var(--t3)}
.tu-pct{font-weight:800}
.stats-bar{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:18px}
.stat-card{background:var(--card);border:1px solid var(--card-b);border-radius:16px;padding:16px 17px}
.stat-label{font-size:9px;color:var(--t3);font-weight:700;text-transform:uppercase;letter-spacing:.07em;margin-bottom:7px}
.stat-val{font-size:22px;font-weight:800;line-height:1}
.stat-sub{font-size:9.5px;color:var(--t3);margin-top:6px}
.copy-all-bar{display:flex;align-items:center;gap:12px;background:linear-gradient(120deg,var(--accent) 0%,#2952C8 100%);border-radius:18px;padding:16px 19px;margin-bottom:18px;flex-wrap:wrap}
.copy-all-text{flex:1;min-width:160px}
.copy-all-title{font-size:13.5px;font-weight:800;color:#fff;display:flex;align-items:center;gap:6px}
.copy-all-sub{font-size:10px;color:rgba(255,255,255,.78);margin-top:3px}
.copy-all-btn{background:#fff;color:#1D4ED8;border:none;border-radius:12px;padding:10px 19px;font-family:inherit;font-size:12.5px;font-weight:800;cursor:pointer;display:flex;align-items:center;gap:6px}
.cfg-title{font-size:12px;font-weight:800;color:var(--t2);margin-bottom:13px;display:flex;align-items:center;gap:6px;text-transform:uppercase;letter-spacing:.07em}
.cfg-grid{display:grid;gap:13px}
.cfg-card{background:var(--card);border:1px solid var(--card-b);border-radius:18px;position:relative;overflow:hidden}
.cfg-top{padding:17px 19px 15px;position:relative}
.cfg-top::after{content:'';position:absolute;top:0;right:0;width:3px;height:100%;background:var(--green)}
.cfg-card.inactive .cfg-top::after{background:var(--red)}
.cfg-head{display:flex;align-items:flex-start;justify-content:space-between;gap:8px;margin-bottom:12px;flex-wrap:wrap}
.cfg-label{font-size:14.5px;font-weight:700}
.cfg-badges{display:flex;gap:5px;flex-wrap:wrap;margin-top:6px}
.proto-chip{font-size:9px;padding:3px 8px;border-radius:7px;font-weight:800}
.pc-ws{background:var(--accent-d);color:var(--accent2)}
.pc-trojan{background:rgba(157,123,240,0.1);color:#BCA4F7}
.pc-xhttp{background:rgba(157,123,240,0.1);color:#BCA4F7}
.pc-ss{background:rgba(157,123,240,0.1);color:#BCA4F7}
.cfg-status{display:flex;align-items:center;gap:5px;font-size:10px;font-weight:700;padding:4px 10px;border-radius:20px}
.cfg-status.ok{background:var(--green-bg);color:var(--green-t)}
.cfg-status.no{background:var(--red-bg);color:var(--red-t)}
.ubar{height:6px;border-radius:4px;background:rgba(96,148,246,0.1);overflow:hidden;margin-bottom:5px}
.ubar-f{height:100%;border-radius:4px;background:var(--green)}
.utxt{font-size:10px;color:var(--t3);display:flex;justify-content:space-between;gap:8px}
.cfg-tear{position:relative;height:0;border-top:1.5px dashed var(--card-b);margin:0 19px}
.cfg-bottom{padding:15px 19px 18px}
.cfg-link-toggle{width:100%;display:flex;align-items:center;justify-content:space-between;background:transparent;border:1px dashed var(--card-b);border-radius:11px;padding:10px 13px;cursor:pointer;font-family:inherit;color:var(--t2);font-size:11.5px;font-weight:600}
.cfg-vless-wrap{display:grid;grid-template-rows:0fr;transition:grid-template-rows .25s ease}
.cfg-vless-wrap.open{grid-template-rows:1fr}
.cfg-vless-inner{overflow:hidden}
.cfg-vless{background:rgba(0,0,0,.22);border:1px solid var(--card-b);border-radius:10px;padding:11px 13px;font-size:9.8px;font-family:ui-monospace,monospace;color:var(--accent2);word-break:break-all;line-height:1.7;margin-top:9px;max-height:90px;overflow-y:auto}
[data-theme="light"] .cfg-vless{background:rgba(46,99,214,.05)}
.cfg-actions{display:flex;gap:7px;flex-wrap:wrap;margin-top:11px}
.btn{font-family:inherit;font-size:11.5px;font-weight:700;border-radius:10px;padding:8px 15px;cursor:pointer;display:inline-flex;align-items:center;gap:5px;border:none;transition:all .15s}
.btn-p{background:var(--accent);color:#fff}
.btn-g{background:var(--accent-d);color:var(--accent2);border:1px solid rgba(96,148,246,.16)}
.lock-stage{display:flex;align-items:center;justify-content:center;min-height:78vh}
.lock-card{background:var(--card);border:1px solid var(--card-b);border-radius:26px;max-width:380px;width:100%;overflow:hidden;box-shadow:var(--shadow)}
.lock-banner{background:linear-gradient(150deg,rgba(59,124,246,.16),rgba(59,124,246,.02) 70%);padding:38px 30px 26px;text-align:center}
.lock-shield{width:64px;height:64px;border-radius:18px;background:var(--accent-d);border:1px solid var(--card-bh);display:flex;align-items:center;justify-content:center;margin:0 auto 18px}
.lock-shield i{font-size:28px;color:var(--accent2)}
.lock-title{font-size:18px;font-weight:800;margin-bottom:6px}
.lock-sub{font-size:12px;color:var(--t3);line-height:1.7}
.lock-form{padding:24px 30px 30px}
.lock-inp{width:100%;padding:13px 44px;border-radius:13px;border:1px solid var(--card-b);background:rgba(0,0,0,.2);color:var(--t1);font-family:inherit;font-size:14px;text-align:center;letter-spacing:.14em}
[data-theme="light"] .lock-inp{background:rgba(46,99,214,.04)}
.lock-err{color:var(--red-t);font-size:11.5px;margin-bottom:10px;min-height:16px;display:flex;align-items:center;justify-content:center}
.lock-btn{width:100%;justify-content:center;padding:13px;font-size:13px;border-radius:13px}
.lock-footer{padding:14px 30px;border-top:1px solid var(--card-b);font-size:10px;color:var(--t3);text-align:center}
.empty-state{text-align:center;padding:80px 20px;color:var(--t3)}
.empty-state i{font-size:38px;display:block;margin-bottom:14px}
.toast{position:fixed;bottom:22px;left:50%;transform:translateX(-50%) translateY(40px);background:var(--card);border:1px solid var(--card-b);color:var(--t1);border-radius:12px;padding:10px 20px;font-size:12.5px;font-weight:600;opacity:0;transition:all .25s;z-index:999;pointer-events:none}
.toast.show{opacity:1;transform:translateX(-50%) translateY(0)}
.toast.ok{border-color:rgba(31,184,126,.35);color:var(--green-t)}
.qr-modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.72);z-index:600;align-items:center;justify-content:center;padding:20px}
.qr-modal.open{display:flex}
.qr-box{background:var(--card);border:1px solid var(--card-b);border-radius:22px;padding:26px;text-align:center;max-width:340px;width:100%}
.qr-title{font-size:13.5px;font-weight:800;margin-bottom:16px}
.qr-img img{width:100%;display:block;background:#fff;padding:10px;border-radius:14px}
.footer{text-align:center;padding-top:28px;font-size:10.5px;color:var(--t3)}
@keyframes spin{to{transform:rotate(360deg)}}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.25}}
.dot{width:5px;height:5px;border-radius:50%;background:var(--green);display:inline-block;animation:pulse 2s infinite}
@media(max-width:520px){.stats-bar{grid-template-columns:1fr 1fr}.stats-bar .stat-card:nth-child(3){grid-column:1/-1}.sub-name{font-size:19px}}
</style>
CSS;
    return $css;
}

function esc_js(string $s): string
{
    return addslashes($s);
}
