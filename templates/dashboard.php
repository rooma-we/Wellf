<?php
// PANEL_LOAD_GUARD — جلوگیری از اجرای مستقیم این فایل از وب (دفاع عمیق، علاوه بر .htaccess)
if (!isset($GLOBALS['WV_PANEL'])) { http_response_code(403); exit('Forbidden'); }

// templates/dashboard.php — داشبورد تک‌صفحه‌ای (معادل DASHBOARD_HTML در pages.py)
// همان ساختار: سایدبار ثابت RTL با ناوبری سمت-کاربری، بخش‌های pg-*، مودال‌ها، Chart.js، Tabler Icons
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>داشبورد · WelfVita Gateway</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@3.19.0/dist/tabler-icons.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.js"></script>
<style>
*{margin:0;padding:0;box-sizing:border-box;-webkit-tap-highlight-color:transparent}
:root{
  --bg:#060f1d;--bg2:#0a1628;--bg3:#0e1e35;--card:#0d1b2e;
  --card-b:rgba(59,130,246,0.13);--card-bh:rgba(59,130,246,0.3);
  --accent:#3B82F6;--accent2:#60A5FA;--accent-d:rgba(59,130,246,0.09);
  --green:#10B981;--green-bg:rgba(16,185,129,.1);
  --red:#EF4444;--red-bg:rgba(239,68,68,.1);
  --amber:#F59E0B;--amber-bg:rgba(245,158,11,.1);
  --purple:#8B5CF6;--purple-bg:rgba(139,92,246,.1);
  --t1:#E8F4FF;--t2:#7BAED4;--t3:#3D6B8E;
  --sidebar-w:248px;--radius:16px;--shadow:0 4px 24px rgba(0,0,0,.35);
  --border:rgba(255,255,255,.07);
}
[data-theme="light"]{
  --bg:#F0F4FA;--bg2:#E8EEF7;--bg3:#DCE6F3;--card:#FFFFFF;
  --card-b:rgba(37,99,235,0.14);--card-bh:rgba(37,99,235,0.32);
  --accent:#2563EB;--accent2:#1D4ED8;--accent-d:rgba(37,99,235,.07);
  --green:#059669;--green-bg:rgba(5,150,105,.08);
  --red:#DC2626;--red-bg:rgba(220,38,38,.08);
  --amber:#D97706;--amber-bg:rgba(217,119,6,.08);
  --purple:#7C3AED;--purple-bg:rgba(124,58,237,.08);
  --t1:#0F172A;--t2:#48607A;--t3:#8296AD;
  --shadow:0 4px 20px rgba(20,40,90,.1);--border:rgba(15,23,42,.08);
}
html,body{height:100%}
body{font-family:'Vazirmatn',system-ui,sans-serif;background:var(--bg);color:var(--t1);display:flex;overflow:hidden}
.mono{font-family:ui-monospace,'JetBrains Mono',monospace;font-variant-numeric:tabular-nums}

/* ════════ سایدبار ════════ */
.sidebar{width:var(--sidebar-w);flex-shrink:0;background:var(--bg2);border-left:1px solid var(--border);
  display:flex;flex-direction:column;height:100dvh;position:relative;z-index:50}
.sb-head{padding:18px 16px 14px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px}
.sb-logo{width:38px;height:38px;border-radius:11px;background:linear-gradient(135deg,#3B82F6,#1D4ED8);
  color:#fff;display:flex;align-items:center;justify-content:center;font-size:19px;flex-shrink:0}
.sb-title{font-size:13.5px;font-weight:800;letter-spacing:-.01em}
.sb-sub{font-size:9.5px;color:var(--t3);margin-top:1px}
.sb-nav{flex:1;overflow-y:auto;padding:10px 10px;display:flex;flex-direction:column;gap:2px}
.sb-nav::-webkit-scrollbar{width:4px}
.sb-nav::-webkit-scrollbar-thumb{background:var(--card-b);border-radius:4px}
.nav-sec{font-size:9px;font-weight:700;color:var(--t3);text-transform:uppercase;letter-spacing:.1em;padding:12px 10px 5px}
.nav-item{display:flex;align-items:center;gap:10px;padding:9.5px 12px;border-radius:10px;cursor:pointer;
  color:var(--t2);font-size:12.5px;font-weight:600;transition:.15s;border:1px solid transparent;user-select:none}
.nav-item:hover{background:var(--accent-d);color:var(--t1)}
.nav-item.active{background:var(--accent-d);color:var(--accent2);border-color:var(--card-b)}
.nav-item i{font-size:16.5px;width:20px;text-align:center}
.nav-badge{margin-inline-start:auto;font-size:9.5px;font-weight:800;background:var(--accent-d);color:var(--accent2);
  border-radius:7px;padding:2px 7px;min-width:20px;text-align:center}
.sb-foot{padding:12px;border-top:1px solid var(--border);display:flex;flex-direction:column;gap:8px}
.sb-foot-row{display:flex;gap:8px}
.sb-btn{flex:1;display:flex;align-items:center;justify-content:center;gap:6px;padding:9px;border-radius:10px;
  background:var(--card);border:1px solid var(--card-b);color:var(--t2);font-family:inherit;font-size:11.5px;
  font-weight:700;cursor:pointer;transition:.15s}
.sb-btn:hover{background:var(--accent-d);color:var(--accent2);border-color:var(--card-bh)}
.sb-btn.danger:hover{background:var(--red-bg);color:var(--red);border-color:rgba(239,68,68,.3)}

/* ════════ محتوا ════════ */
.main{flex:1;min-width:0;height:100dvh;overflow-y:auto;position:relative}
.main::-webkit-scrollbar{width:6px}
.main::-webkit-scrollbar-thumb{background:var(--card-b);border-radius:6px}
.pg{display:none;padding:22px 26px 60px;max-width:1200px;margin:0 auto}
.pg.active{display:block;animation:pgIn .3s ease}
@keyframes pgIn{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}
.topbar{display:flex;align-items:center;gap:12px;margin-bottom:22px;flex-wrap:wrap}
.topbar i.ti{font-size:22px;color:var(--accent)}
.topbar h2{font-size:19px;font-weight:800;letter-spacing:-.02em}
.topbar .sub{font-size:11px;color:var(--t3);margin-top:2px}
.topbar-actions{margin-inline-start:auto;display:flex;gap:8px;flex-wrap:wrap}

/* ════════ موبایل: هدر چسبان + همبرگر + اورلی ════════ */
.mob-header{display:none;position:sticky;top:0;z-index:45;background:var(--bg2);
  border-bottom:1px solid var(--border);align-items:center;gap:10px;padding:10px 14px}
.mob-header .mh-logo{width:30px;height:30px;border-radius:9px;background:linear-gradient(135deg,#3B82F6,#1D4ED8);
  color:#fff;display:flex;align-items:center;justify-content:center;font-size:15px;flex-shrink:0}
.mob-header .mh-title{font-size:13px;font-weight:800;letter-spacing:-.01em}
.mob-header .mh-actions{margin-inline-start:auto;display:flex;gap:6px}
.hamburger{width:36px;height:36px;border-radius:10px;background:var(--accent-d);border:1px solid var(--card-b);
  color:var(--t2);display:flex;align-items:center;justify-content:center;font-size:17px;cursor:pointer;transition:.15s;flex-shrink:0}
.hamburger:active{transform:scale(.94)}
.nav-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);backdrop-filter:blur(2px);z-index:49}
.nav-overlay.show{display:block;animation:fadeIn .2s ease}
@keyframes fadeIn{from{opacity:0}to{opacity:1}}
@media(max-width:900px){
  .mob-header{display:flex}
  .sidebar{position:fixed;top:0;bottom:0;right:-270px;transition:right .25s ease;box-shadow:var(--shadow);z-index:50}
  .sidebar.open{right:0}
  .pg{padding:16px 14px 60px}
}

/* ════════ کارت‌ها و متریک‌ها ════════ */
.metrics{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:18px}
@media(max-width:1000px){.metrics{grid-template-columns:repeat(2,1fr)}}
@media(max-width:560px){.metrics{grid-template-columns:1fr}}
.metric{background:var(--card);border:1px solid var(--card-b);border-radius:var(--radius);padding:16px 18px;
  position:relative;overflow:hidden;transition:.2s}
.metric:hover{border-color:var(--card-bh);transform:translateY(-2px)}
.metric::before{content:'';position:absolute;top:0;right:0;width:3px;height:100%;background:var(--accent)}
.metric.m-green::before{background:var(--green)}.metric.m-red::before{background:var(--red)}
.metric.m-amber::before{background:var(--amber)}.metric.m-purple::before{background:var(--purple)}
.m-label{font-size:10px;font-weight:700;color:var(--t3);text-transform:uppercase;letter-spacing:.06em;
  display:flex;align-items:center;gap:6px;margin-bottom:9px}
.m-label i{font-size:14px;color:var(--accent)}
.m-value{font-size:23px;font-weight:800;letter-spacing:-.01em;line-height:1}
.m-sub{font-size:10px;color:var(--t3);margin-top:7px}
.card{background:var(--card);border:1px solid var(--card-b);border-radius:var(--radius);padding:20px;margin-bottom:16px}
.card-title{font-size:13px;font-weight:800;margin-bottom:14px;display:flex;align-items:center;gap:8px}
.card-title i{color:var(--accent);font-size:16px}
.card-title .actions{margin-inline-start:auto;display:flex;gap:6px}

/* ════════ دکمه‌ها ════════ */
.btn{font-family:inherit;font-size:12px;font-weight:700;border-radius:10px;padding:8.5px 16px;cursor:pointer;
  display:inline-flex;align-items:center;gap:6px;border:none;transition:all .15s;white-space:nowrap}
.btn i{font-size:14px}
.btn-p{background:var(--accent);color:#fff;box-shadow:0 3px 12px rgba(59,130,246,.25)}
.btn-p:hover{background:var(--accent2)}
.btn-g{background:var(--accent-d);color:var(--accent2);border:1px solid var(--card-b)}
.btn-g:hover{border-color:var(--card-bh);background:rgba(59,130,246,.15)}
.btn-d{background:var(--red-bg);color:var(--red);border:1px solid rgba(239,68,68,.2)}
.btn-d:hover{background:rgba(239,68,68,.18)}
.btn-sm{padding:6px 11px;font-size:11px;border-radius:8px}
.icon-btn{width:32px;height:32px;border-radius:9px;background:var(--accent-d);border:1px solid var(--card-b);
  color:var(--t2);display:inline-flex;align-items:center;justify-content:center;font-size:14px;cursor:pointer;transition:.15s}
.icon-btn:hover{color:var(--accent2);border-color:var(--card-bh)}
.icon-btn.danger:hover{color:var(--red);background:var(--red-bg)}

/* ════════ چیپ/بج ════════ */
.chip{display:inline-flex;align-items:center;gap:4px;font-size:9.5px;font-weight:800;padding:3px 9px;border-radius:8px}
.chip-ok{background:var(--green-bg);color:var(--green)}
.chip-err{background:var(--red-bg);color:var(--red)}
.chip-warn{background:var(--amber-bg);color:var(--amber)}
.chip-info{background:var(--accent-d);color:var(--accent2)}
.chip-purple{background:var(--purple-bg);color:var(--purple)}
.dot{width:5px;height:5px;border-radius:50%;background:currentColor;display:inline-block;animation:pulse 2s infinite}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.3}}

/* ════════ جدول/لیست ════════ */
.tbl{width:100%;border-collapse:collapse;font-size:12px}
.tbl th{font-size:10px;color:var(--t3);font-weight:700;text-transform:uppercase;letter-spacing:.05em;
  text-align:right;padding:8px 10px;border-bottom:1px solid var(--border)}
.tbl td{padding:10px;border-bottom:1px solid var(--border)}
.tbl tr:hover td{background:var(--accent-d)}
.empty{text-align:center;padding:46px 20px;color:var(--t3);font-size:12px}
.empty i{font-size:34px;display:block;margin-bottom:12px;opacity:.6}

/* ════════ سوییچ ════════ */
.switch{position:relative;width:38px;height:21px;flex-shrink:0}
.switch input{opacity:0;width:0;height:0}
.sw-f{position:absolute;inset:0;background:var(--bg3);border:1px solid var(--border);border-radius:20px;cursor:pointer;transition:.2s}
.sw-f::before{content:'';position:absolute;width:15px;height:15px;border-radius:50%;right:2px;top:2px;background:var(--t3);transition:.2s}
.switch input:checked+.sw-f{background:var(--green);border-color:var(--green)}
.switch input:checked+.sw-f::before{transform:translateX(-16px);background:#fff}

/* ════════ نوار پیشرفت ════════ */
.bar{height:7px;border-radius:5px;background:var(--bg3);overflow:hidden}
.bar-f{height:100%;border-radius:5px;background:var(--accent);transition:width .5s ease}
.bar-f.ok{background:var(--green)}.bar-f.warn{background:var(--amber)}.bar-f.err{background:var(--red)}

/* ════════ مودال ════════ */
.modal-bg{display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);backdrop-filter:blur(5px);z-index:200;
  align-items:center;justify-content:center;padding:20px}
.modal-bg.open{display:flex}
.modal-v2{background:var(--card);border:1px solid var(--card-b);border-radius:20px;width:100%;max-width:560px;
  max-height:88dvh;overflow-y:auto;box-shadow:var(--shadow);animation:mIn .25s ease}
@keyframes mIn{from{opacity:0;transform:scale(.96) translateY(10px)}to{opacity:1;transform:none}}
.m-head{display:flex;align-items:center;gap:10px;padding:16px 20px;border-bottom:1px solid var(--border);
  position:sticky;top:0;background:var(--card);z-index:2;border-radius:20px 20px 0 0}
.m-head h3{font-size:14px;font-weight:800}
.m-close{margin-inline-start:auto;width:30px;height:30px;border-radius:9px;border:1px solid var(--border);
  background:transparent;color:var(--t2);cursor:pointer;display:flex;align-items:center;justify-content:center}
.m-close:hover{color:var(--red);background:var(--red-bg)}
.m-body{padding:20px}
.m-foot{display:flex;gap:8px;padding:14px 20px;border-top:1px solid var(--border);position:sticky;bottom:0;background:var(--card)}

/* ════════ فرم ════════ */
.f-row{margin-bottom:13px}
.f-label{display:block;font-size:11px;font-weight:700;color:var(--t2);margin-bottom:6px}
.f-in{width:100%;padding:10px 13px;border-radius:10px;border:1px solid var(--border);background:var(--bg2);
  color:var(--t1);font-family:inherit;font-size:12.5px;outline:none;transition:border-color .15s}
.f-in:focus{border-color:var(--accent)}
textarea.f-in{resize:vertical;min-height:64px}
select.f-in{cursor:pointer}
.f-grid2{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.f-grid3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px}
.pill-row{display:flex;gap:6px;flex-wrap:wrap}
.pill{font-size:11px;font-weight:700;padding:6px 13px;border-radius:9px;background:var(--bg2);
  border:1px solid var(--border);color:var(--t2);cursor:pointer;transition:.15s;font-family:inherit}
.pill:hover{border-color:var(--card-bh);color:var(--t1)}
.pill.on{background:var(--accent-d);border-color:var(--accent);color:var(--accent2)}
.proto-card{flex:1;min-width:100px;background:var(--bg2);border:1px solid var(--border);border-radius:12px;
  padding:11px 10px;cursor:pointer;text-align:center;transition:.15s;font-family:inherit;color:var(--t2)}
.proto-card:hover{border-color:var(--card-bh)}
.proto-card.on{background:var(--accent-d);border-color:var(--accent);color:var(--accent2)}
.proto-card i{font-size:19px;display:block;margin-bottom:5px}
.proto-card span{font-size:10.5px;font-weight:700}

/* ════════ اتصالات/لاگ ════════ */
.log-item{display:flex;gap:10px;padding:9px 12px;border-bottom:1px solid var(--border);font-size:11.5px;align-items:flex-start}
.log-item:last-child{border-bottom:none}
.log-time{color:var(--t3);font-size:10px;white-space:nowrap;font-family:ui-monospace,monospace;flex-shrink:0;margin-top:1px}
.log-msg{flex:1;line-height:1.7}
.lv-ok{color:var(--green)}.lv-err{color:var(--red)}.lv-warn{color:var(--amber)}.lv-info{color:var(--accent2)}

/* ════════ نمودار ════════ */
.chart-wrap{position:relative;height:260px}
.ring-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px}
.ring{display:flex;flex-direction:column;align-items:center;gap:8px}
.ring svg{transform:rotate(-90deg)}
.ring .rv{font-size:15px;font-weight:800}
.ring .rl{font-size:10px;color:var(--t3);font-weight:700}

/* ════════ توست ════════ */
.toast{position:fixed;bottom:22px;left:50%;transform:translateX(-50%) translateY(40px);background:var(--card);
  border:1px solid var(--card-b);color:var(--t1);border-radius:12px;padding:10px 20px;font-size:12.5px;font-weight:600;
  opacity:0;transition:all .25s;z-index:999;pointer-events:none;display:flex;align-items:center;gap:7px;box-shadow:var(--shadow)}
.toast.show{opacity:1;transform:translateX(-50%) translateY(0)}
.toast.ok{border-color:rgba(16,185,129,.4);color:var(--green)}
.toast.err{border-color:rgba(239,68,68,.4);color:var(--red)}

/* ════════ کپی لینک ════════ */
.link-box{background:var(--bg2);border:1px solid var(--border);border-radius:10px;padding:9px 12px;
  font-size:10px;font-family:ui-monospace,monospace;color:var(--accent2);word-break:break-all;line-height:1.7;
  max-height:76px;overflow-y:auto;direction:ltr;text-align:left}
.cfg-row{background:var(--bg2);border:1px solid var(--border);border-radius:14px;padding:15px 17px;margin-bottom:11px;transition:.15s}
.cfg-row:hover{border-color:var(--card-bh)}
.cfg-row-top{display:flex;align-items:flex-start;gap:12px;flex-wrap:wrap}
.cfg-row-title{font-size:13.5px;font-weight:800;display:flex;align-items:center;gap:8px}
.cfg-row-meta{font-size:10px;color:var(--t3);margin-top:4px;display:flex;gap:10px;flex-wrap:wrap}
.cfg-row-actions{margin-inline-start:auto;display:flex;gap:6px;align-items:center;flex-wrap:wrap}
.sub-row{display:flex;align-items:center;gap:12px;background:var(--bg2);border:1px solid var(--border);
  border-radius:14px;padding:14px 17px;margin-bottom:10px;flex-wrap:wrap}
@media(max-width:760px){.cfg-row-actions{margin-inline-start:0;width:100%;justify-content:flex-start}}
</style>
</head>
<body>

<!-- ═══════════ سایدبار ═══════════ -->
<nav class="sidebar" id="sidebar">
  <div class="sb-head">
    <div class="sb-logo"><i class="ti ti-bolt"></i></div>
    <div><div class="sb-title">WelfVita Gateway</div><div class="sb-sub">PHP Edition · v9.2</div></div>
  </div>
  <div class="sb-nav" id="nav">
    <div class="nav-sec">پایش</div>
    <div class="nav-item active" data-pg="overview"><i class="ti ti-dashboard"></i> نمای کلی</div>
    <div class="nav-item" data-pg="traffic"><i class="ti ti-chart-area-line"></i> ترافیک</div>
    <div class="nav-item" data-pg="connections"><i class="ti ti-plug-connected"></i> اتصالات <span class="nav-badge" id="nb-conn">0</span></div>
    <div class="nav-item" data-pg="logs"><i class="ti ti-history"></i> لاگ فعالیت</div>
    <div class="nav-sec">مدیریت</div>
    <div class="nav-item" data-pg="links"><i class="ti ti-link"></i> کانفیگ‌ها <span class="nav-badge" id="nb-links">0</span></div>
    <div class="nav-item" data-pg="subs"><i class="ti ti-users-group"></i> گروه اشتراک</div>
    <div class="nav-sec">سیستم</div>
    <div class="nav-item" data-pg="backup"><i class="ti ti-database-export"></i> بکاپ</div>
    <div class="nav-item" data-pg="settings"><i class="ti ti-settings"></i> تنظیمات</div>
  </div>
  <div class="sb-foot">
    <div class="sb-foot-row">
      <button class="sb-btn" onclick="toggleTheme()" id="theme-btn"><i class="ti ti-sun" id="theme-icon"></i> تم</button>
      <button class="sb-btn danger" onclick="doLogout()"><i class="ti ti-logout"></i> خروج</button>
    </div>
  </div>
</nav>

<!-- ═══════════ محتوا ═══════════ -->
<main class="main" id="main">
  <!-- هدر موبایل: همبرگر + عنوان + تم -->
  <div class="mob-header">
    <button class="hamburger" id="btn-menu" aria-label="منو" aria-expanded="false"><i class="ti ti-menu-2"></i></button>
    <div class="mh-logo"><i class="ti ti-bolt"></i></div>
    <div class="mh-title">WelfVita Gateway</div>
    <div class="mh-actions">
      <button class="hamburger" onclick="toggleTheme()" aria-label="تغییر تم"><i class="ti ti-sun" id="theme-icon-mob"></i></button>
    </div>
  </div>
  <div class="nav-overlay" id="nav-overlay"></div>

  <!-- ── نمای کلی ── -->
  <section class="pg active" id="pg-overview">
    <div class="topbar">
      <i class="ti ti-dashboard"></i>
      <div><h2>نمای کلی</h2><div class="sub">خلاصه وضعیت دروازه و مصرف لحظه‌ای</div></div>
      <div class="topbar-actions"><button class="btn btn-p" onclick="navTo('links');openCreateModal()"><i class="ti ti-plus"></i> کانفیگ جدید</button></div>
    </div>
    <div class="metrics">
      <div class="metric"><div class="m-label"><i class="ti ti-users"></i> اتصالات فعال</div><div class="m-value" id="mv-conn">0</div><div class="m-sub" id="mv-conn-sub">در حال اتصال به دروازه</div></div>
      <div class="metric m-green"><div class="m-label"><i class="ti ti-transfer-vertical"></i> ترافیک کل</div><div class="m-value" id="mv-traffic">0 MB</div><div class="m-sub" id="mv-traffic-sub">از شروع سرویس</div></div>
      <div class="metric m-amber"><div class="m-label"><i class="ti ti-link"></i> کانفیگ‌ها</div><div class="m-value" id="mv-links">0</div><div class="m-sub" id="mv-links-sub">0 فعال · 0 منقضی</div></div>
      <div class="metric m-purple"><div class="m-label"><i class="ti ti-users-group"></i> گروه‌ها</div><div class="m-value" id="mv-subs">0</div><div class="m-sub">گروه اشتراک</div></div>
    </div>
    <div class="card">
      <div class="card-title"><i class="ti ti-chart-line"></i> ترافیک ساعتی <span class="actions"><span class="chip chip-info" id="uptime-chip">آپ‌تایم 00:00:00</span></span></div>
      <div class="chart-wrap"><canvas id="ch1"></canvas></div>
    </div>
    <div class="card">
      <div class="card-title"><i class="ti ti-cpu"></i> منابع سرور</div>
      <div class="ring-grid" id="sys-rings"></div>
    </div>
    <div class="card">
      <div class="card-title"><i class="ti ti-world"></i> موقعیت سرور</div>
      <div id="loc-box" class="empty"><i class="ti ti-loader-2"></i>در حال دریافت موقعیت…</div>
    </div>
  </section>

  <!-- ── ترافیک ── -->
  <section class="pg" id="pg-traffic">
    <div class="topbar"><i class="ti ti-chart-area-line"></i>
      <div><h2>ترافیک</h2><div class="sub">نمودار مصرف و روندها</div></div></div>
    <div class="metrics">
      <div class="metric"><div class="m-label"><i class="ti ti-transfer"></i> کل درخواست‌ها</div><div class="m-value" id="tv-req">0</div></div>
      <div class="metric m-red"><div class="m-label"><i class="ti ti-alert-triangle"></i> خطاها</div><div class="m-value" id="tv-err">0</div></div>
      <div class="metric m-green"><div class="m-label"><i class="ti ti-transfer-vertical"></i> کل ترافیک</div><div class="m-value" id="tv-bytes">0 MB</div></div>
      <div class="metric m-purple"><div class="m-label"><i class="ti ti-clock"></i> آپ‌تایم</div><div class="m-value mono" id="tv-up" style="font-size:17px">00:00:00</div></div>
    </div>
    <div class="card">
      <div class="card-title"><i class="ti ti-chart-line"></i> نمودار مصرف</div>
      <div class="chart-wrap"><canvas id="ch3"></canvas></div>
    </div>
    <div class="card">
      <div class="card-title"><i class="ti ti-alert-triangle"></i> آخرین خطاها</div>
      <div id="err-list"><div class="empty"><i class="ti ti-check"></i>خطایی ثبت نشده</div></div>
    </div>
  </section>

  <!-- ── اتصالات ── -->
  <section class="pg" id="pg-connections">
    <div class="topbar"><i class="ti ti-plug-connected"></i>
      <div><h2>اتصالات</h2><div class="sub" id="conn-sub">۰ اتصال فعال</div></div>
      <div class="topbar-actions"><button class="btn btn-g" onclick="loadConnections()"><i class="ti ti-refresh"></i> بروزرسانی</button></div></div>
    <div class="card"><div id="conn-list"><div class="empty"><i class="ti ti-plug-off"></i>اتصالی وجود ندارد</div></div></div>
  </section>

  <!-- ── لاگ ── -->
  <section class="pg" id="pg-logs">
    <div class="topbar"><i class="ti ti-history"></i>
      <div><h2>لاگ فعالیت</h2><div class="sub">۱۵۰ رخداد اخیر</div></div>
      <div class="topbar-actions"><button class="btn btn-g" onclick="loadActivity()"><i class="ti ti-refresh"></i> بروزرسانی</button></div></div>
    <div class="card"><div id="log-list"><div class="empty"><i class="ti ti-history-off"></i>لاگی وجود ندارد</div></div></div>
  </section>

  <!-- ── کانفیگ‌ها ── -->
  <section class="pg" id="pg-links">
    <div class="topbar"><i class="ti ti-link"></i>
      <div><h2>کانفیگ‌ها</h2><div class="sub">ساخت و مدیریت کانفیگ‌های پروکسی</div></div>
      <div class="topbar-actions"><button class="btn btn-p" onclick="openCreateModal()"><i class="ti ti-plus"></i> کانفیگ جدید</button></div></div>
    <div id="links-list"></div>
  </section>

  <!-- ── گروه اشتراک ── -->
  <section class="pg" id="pg-subs">
    <div class="topbar"><i class="ti ti-users-group"></i>
      <div><h2>گروه اشتراک</h2><div class="sub">یک URL برای چند کانفیگ</div></div>
      <div class="topbar-actions"><button class="btn btn-p" onclick="openCreateSubModal()"><i class="ti ti-plus"></i> گروه جدید</button></div></div>
    <div id="subs-list"></div>
  </section>

  <!-- ── بکاپ ── -->
  <section class="pg" id="pg-backup">
    <div class="topbar"><i class="ti ti-database-export"></i>
      <div><h2>بکاپ و بازیابی</h2><div class="sub">خروجی/ورودی کامل وضعیت پنل</div></div></div>
    <div class="card">
      <div class="card-title"><i class="ti ti-download"></i> بکاپ کامل</div>
      <p style="font-size:12px;color:var(--t2);line-height:2;margin-bottom:14px">تمام کانفیگ‌ها، گروه‌ها و تنظیمات در یک فایل JSON ذخیره می‌شود.</p>
      <button class="btn btn-p" onclick="doExport()"><i class="ti ti-download"></i> دانلود بکاپ</button>
    </div>
    <div class="card">
      <div class="card-title"><i class="ti ti-upload"></i> بازیابی</div>
      <p style="font-size:12px;color:var(--t2);line-height:2;margin-bottom:14px">فایل بکاپ را انتخاب کنید. داده‌های فعلی جایگزین می‌شوند.</p>
      <input type="file" id="import-file" accept=".json" class="f-in" style="margin-bottom:12px">
      <label style="font-size:12px;color:var(--t2);display:flex;gap:8px;align-items:center;margin-bottom:14px">
        <input type="checkbox" id="keep-pw" checked> حفظ رمز فعلی پنل
      </label>
      <button class="btn btn-d" onclick="doImport()"><i class="ti ti-upload"></i> بازیابی از فایل</button>
    </div>
  </section>

  <!-- ── تنظیمات ── -->
  <section class="pg" id="pg-settings">
    <div class="topbar"><i class="ti ti-settings"></i>
      <div><h2>تنظیمات</h2><div class="sub">امنیت و رفتار پنل</div></div></div>
    <div class="card">
      <div class="card-title"><i class="ti ti-key"></i> تغییر رمز عبور</div>
      <div class="f-row"><label class="f-label">رمز فعلی</label><input type="password" class="f-in" id="cur-pw"></div>
      <div class="f-row"><label class="f-label">رمز جدید (حداقل ۴ کاراکتر)</label><input type="password" class="f-in" id="new-pw"></div>
      <button class="btn btn-p" onclick="doChangePw()"><i class="ti ti-key"></i> تغییر رمز</button>
    </div>
    <div class="card">
      <div class="card-title"><i class="ti ti-shield-half"></i> سایر تنظیمات</div>
      <label style="font-size:12.5px;color:var(--t2);display:flex;gap:10px;align-items:center;cursor:pointer">
        <span class="switch"><input type="checkbox" id="log-disable" onchange="saveLogging(this.checked)"><span class="sw-f"></span></span>
        توقف کامل ثبت لاگ (بیشترین سرعت ممکن)
      </label>
    </div>
  </section>
</main>

<div class="toast" id="toast"></div>
<div id="modals-root"></div>
<?php require __DIR__ . '/dashboard_js.php'; ?>
</body>
</html>
