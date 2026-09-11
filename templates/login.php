<?php
// PANEL_LOAD_GUARD — جلوگیری از اجرای مستقیم این فایل از وب (دفاع عمیق، علاوه بر .htaccess)
if (!isset($GLOBALS['WV_PANEL'])) { http_response_code(403); exit('Forbidden'); }

// templates/login.php — صفحه ورود (معادل LOGIN_HTML در pages.py)
// هویت بصری یکسان: تم تیره/روشن، کارت شیشه‌ای، رنگ اکسنت آبی، Vazirmatn، RTL
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ورود · WelfVita Gateway</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@3.19.0/dist/tabler-icons.min.css">
<style>
*{margin:0;padding:0;box-sizing:border-box;-webkit-tap-highlight-color:transparent}
:root{
  --bg:#0A0E15;--surface:#0E1421;--surface-2:#141C2C;--inset:rgba(255,255,255,.03);
  --accent:#3E6FD1;--accent-hi:#5A90EE;--accent-soft:rgba(62,111,209,.15);
  --btn-a:#4A7BE0;--btn-b:#2F53B8;--btn-ring:rgba(74,123,224,.34);
  --text:#E9EEF7;--mid:#9AA6BE;--dim:#66728D;
  --border:rgba(255,255,255,.09);--border-hi:rgba(255,255,255,.18);
  --ok:#3FB27F;--danger:#E0656F;
}
[data-theme="light"]{
  --bg:#EEF1F6;--surface:#FFFFFF;--surface-2:#F5F7FB;--inset:rgba(16,24,39,.022);
  --accent:#2A55A8;--accent-hi:#1F4691;--accent-soft:rgba(42,85,168,.08);
  --btn-a:#3565C4;--btn-b:#1E3F8C;--btn-ring:rgba(42,85,168,.26);
  --text:#101827;--mid:#54607A;--dim:#7C879C;
  --border:#E1E6EF;--border-hi:#C7CFDE;
  --ok:#2E8F66;--danger:#C7414D;
}
body{font-family:'Vazirmatn',system-ui,sans-serif;background:var(--bg);color:var(--text);
  height:100dvh;min-height:100dvh;display:flex;overflow:hidden}
.mono{font-family:'JetBrains Mono',ui-monospace,monospace;font-variant-numeric:tabular-nums}
.side-form{flex:0 0 46%;max-width:560px;display:flex;flex-direction:column;
  height:100%;overflow:hidden;padding:18px 26px;position:relative;background:var(--bg)}
.form-top{display:flex;align-items:center;justify-content:flex-end;gap:12px;min-height:36px}
.brand{display:flex;align-items:center;gap:11px}
.logo{width:36px;height:36px;border-radius:9px;overflow:hidden;flex-shrink:0;border:1px solid var(--border);
  background:linear-gradient(135deg,#3E6FD1,#2F53B8);color:#fff;display:flex;align-items:center;justify-content:center;font-size:18px}
.brand-name{font-size:13.5px;font-weight:700;letter-spacing:-.01em}
.brand-sub{font-size:10.5px;color:var(--dim);margin-top:2px}
.theme-btn{width:34px;height:34px;border-radius:9px;background:transparent;border:1px solid var(--border);
  color:var(--mid);display:flex;align-items:center;justify-content:center;font-size:16px;cursor:pointer;
  transition:color .18s,border-color .18s,background .18s}
.theme-btn:hover{color:var(--text);border-color:var(--border-hi);background:var(--inset)}
.form-mid{flex:1;min-height:0;display:flex;align-items:center;justify-content:center;padding:14px 0}
.form-mid>div{width:100%;max-width:380px}
.card{width:100%;max-width:380px;background:var(--surface);border:1px solid var(--border);
  border-radius:16px;overflow:hidden;box-shadow:0 26px 60px -32px rgba(0,0,0,.75);
  animation:rise .5s cubic-bezier(.22,.61,.36,1) both}
@keyframes rise{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:none}}
.card-head{display:flex;align-items:center;gap:11px;padding:16px 22px;
  border-bottom:1px solid var(--border);background:var(--surface-2)}
.ver{margin-inline-start:auto;font-size:10.5px;color:var(--mid);border:1px solid var(--border);
  border-radius:6px;padding:3px 8px;letter-spacing:.04em}
.card-body{padding:22px 22px 24px}
.card-foot{display:flex;align-items:center;justify-content:space-between;gap:10px;
  padding:12px 22px;border-top:1px solid var(--border);background:var(--surface-2);
  font-size:11.5px;color:var(--dim)}
.card-foot a{color:var(--mid);text-decoration:none;display:flex;align-items:center;gap:5px;transition:color .16s}
.card-foot a:hover{color:var(--accent-hi)}
.eyebrow{display:inline-flex;align-items:center;gap:7px;font-size:10.5px;color:var(--mid);
  border:1px solid var(--border);border-radius:999px;padding:5px 11px;margin-bottom:16px;letter-spacing:.04em}
.eyebrow .dot{width:6px;height:6px;border-radius:50%;background:var(--ok);position:relative;flex-shrink:0}
.eyebrow .dot::after{content:'';position:absolute;inset:-3px;border-radius:50%;background:var(--ok);
  opacity:.35;animation:ping 2.4s cubic-bezier(0,0,.2,1) infinite}
@keyframes ping{0%{transform:scale(.7);opacity:.4}70%,100%{transform:scale(2);opacity:0}}
h1{font-size:21px;font-weight:800;letter-spacing:-.025em;margin-bottom:6px}
.sub{font-size:12.5px;color:var(--mid);line-height:1.8;margin-bottom:22px}
.hint{display:flex;align-items:center;gap:10px;background:var(--inset);border:1px solid var(--border);
  border-radius:10px;padding:9px 12px;margin-bottom:18px}
.hint>i{color:var(--dim);font-size:15px}
.hint-label{font-size:11.5px;color:var(--mid);flex:1}
.hint-val{font-family:'JetBrains Mono',monospace;font-size:12.5px;font-weight:600;color:var(--accent-hi);
  background:var(--accent-soft);border:1px solid transparent;padding:3px 9px;border-radius:6px;
  cursor:pointer;letter-spacing:.06em;transition:border-color .18s}
.hint-val:hover{border-color:var(--accent)}
.f-field{position:relative;margin-bottom:13px}
.f-field>i.ti-lock{position:absolute;right:13px;top:50%;transform:translateY(-50%);color:var(--dim);font-size:15px;pointer-events:none}
.f-input{width:100%;padding:12.5px 40px 12.5px 44px;border-radius:11px;border:1px solid var(--border);
  background:var(--surface-2);color:var(--text);font-family:inherit;font-size:13.5px;outline:none;transition:border-color .18s,box-shadow .18s}
.f-input:focus{border-color:var(--accent);box-shadow:0 0 0 3px var(--accent-soft)}
.eye{position:absolute;left:12px;top:50%;transform:translateY(-50%);background:none;border:none;
  color:var(--dim);cursor:pointer;font-size:16px;padding:4px;display:flex}
.eye:hover{color:var(--accent-hi)}
.f-err{color:var(--danger);font-size:11.5px;min-height:17px;margin-bottom:9px;display:flex;align-items:center;gap:6px}
.f-actions{display:flex;flex-direction:column;gap:9px}
.btn-submit{width:100%;padding:12.5px;border:none;border-radius:11px;cursor:pointer;
  font-family:inherit;font-size:13.5px;font-weight:700;color:#fff;
  background:linear-gradient(135deg,var(--btn-a),var(--btn-b));
  transition:filter .15s,transform .1s;display:flex;align-items:center;justify-content:center;gap:8px}
.btn-submit:hover{filter:brightness(1.08)}
.btn-submit:active{transform:scale(.99)}
.btn-submit:disabled{opacity:.65;cursor:default}
/* ستون نمایش (سمت چپ) */
.showcase{flex:1;display:none;position:relative;overflow:hidden;background:var(--surface)}
@media(min-width:900px){.showcase{display:block}}
.slide{position:absolute;inset:0;display:flex;flex-direction:column;justify-content:center;
  padding:0 8%;opacity:0;transition:opacity .7s ease;pointer-events:none}
.slide.active{opacity:1}
.slide .s-icon{width:58px;height:58px;border-radius:16px;background:var(--accent-soft);
  border:1px solid var(--border);color:var(--accent-hi);display:flex;align-items:center;justify-content:center;
  font-size:26px;margin-bottom:22px}
.slide h2{font-size:26px;font-weight:800;letter-spacing:-.02em;margin-bottom:12px}
.slide p{font-size:13.5px;color:var(--mid);line-height:2;max-width:430px}
.dots{position:absolute;bottom:34px;right:8%;display:flex;gap:8px}
.dots span{width:26px;height:4px;border-radius:4px;background:var(--border);overflow:hidden;position:relative;cursor:pointer}
.dots span.active{background:var(--accent)}
@media(max-width:899px){.side-form{flex:1 1 100%;max-width:none}}
</style>
</head>
<body>
<section class="side-form">
  <div class="form-top">
    <div class="brand">
      <div class="logo"><i class="ti ti-bolt"></i></div>
      <div>
        <div class="brand-name">WelfVita Gateway</div>
        <div class="brand-sub">Multi-Protocol Proxy Panel · PHP</div>
      </div>
    </div>
    <button class="theme-btn" onclick="toggleTheme()" title="تغییر تم"><i class="ti ti-sun" id="theme-icon"></i></button>
  </div>
  <div class="form-mid"><div>
    <form class="card" onsubmit="return doLogin(event)">
      <div class="card-head">
        <span class="ver mono">v9.2</span>
      </div>
      <div class="card-body">
        <span class="eyebrow"><span class="dot"></span> GATEWAY ONLINE</span>
        <h1>ورود به پنل</h1>
        <p class="sub">برای مدیریت کانفیگ‌ها، گروه‌های اشتراک و مانیتورینگ، رمز عبور پنل را وارد کنید.</p>
        <div class="hint">
          <i class="ti ti-key"></i>
          <span class="hint-label">رمز پیش‌فرض پنل</span>
          <span class="hint-val mono" onclick="prefill()">123456</span>
        </div>
        <div class="f-field">
          <i class="ti ti-lock"></i>
          <input class="f-input" id="pw" type="password" placeholder="رمز عبور پنل" autocomplete="current-password" autofocus>
          <button class="eye" type="button" onclick="togglePw(this)"><i class="ti ti-eye"></i></button>
        </div>
        <div class="f-err" id="err"></div>
        <div class="f-actions">
          <button class="btn-submit" id="btn" type="submit"><i class="ti ti-login-2"></i> ورود به داشبورد</button>
        </div>
      </div>
      <div class="card-foot">
        <span>WelfVita Gateway · PHP Edition</span>
        
      </div>
    </form>
  </div></div>
</section>
<aside class="showcase">
  <div class="slide active">
    <div class="s-icon"><i class="ti ti-plug-connected"></i></div>
    <h2>چندپروتکلی و یکپارچه</h2>
    <p>VLESS، Trojan و Shadowsocks در یک پنل واحد. هر کانفیگ با UUID اختصاصی، سهمیه‌ی ترافیک مستقل و لینک اشتراک آماده برای کلاینت‌ها.</p>
  </div>
  <div class="slide">
    <div class="s-icon"><i class="ti ti-users-group"></i></div>
    <h2>گروه اشتراک بسازید</h2>
    <p>کانفیگ‌ها را در گروه‌ها جمع کنید، رمز اختیاری بگذارید و یک URL واحد به مشتری بدهید — هم صفحه‌ی پروفایل حرفه‌ای، هم فید استاندارد Base64 برای کلاینت‌ها.</p>
  </div>
  <div class="slide">
    <div class="s-icon"><i class="ti ti-chart-area-line"></i></div>
    <h2>مانیتورینگ زنده</h2>
    <p>نمودار ترافیک ساعتی، اتصالات فعال، لاگ فعالیت و آمار سیستم — همه لحظه‌ای و بدون تازه‌سازی صفحه.</p>
  </div>
  <div class="dots" id="dots"><span class="active"></span><span></span><span></span></div>
</aside>
<script>
let isDark=localStorage.getItem('wv-login-theme')!=='light';
function applyTheme(dark){document.documentElement.setAttribute('data-theme',dark?'dark':'light');
  document.getElementById('theme-icon').className='ti '+(dark?'ti-sun':'ti-moon')}
function toggleTheme(){isDark=!isDark;localStorage.setItem('wv-login-theme',isDark?'dark':'light');applyTheme(isDark)}
applyTheme(isDark);
function togglePw(btn){const p=document.getElementById('pw');
  const show=p.type==='password';p.type=show?'text':'password';
  btn.innerHTML=show?'<i class="ti ti-eye-off"></i>':'<i class="ti ti-eye"></i>'}
function prefill(){document.getElementById('pw').value='123456';document.getElementById('pw').focus()}
async function doLogin(e){
  e.preventDefault();
  const err=document.getElementById('err'),btn=document.getElementById('btn');
  err.textContent='';btn.disabled=true;
  try{
    const r=await fetch('/api/login',{method:'POST',headers:{'Content-Type':'application/json'},
      body:JSON.stringify({password:document.getElementById('pw').value})});
    const d=await r.json().catch(()=>({}));
    if(r.ok){location.href='/dashboard';return false}
    err.textContent=d.detail||'ورود ناموفق بود';
  }catch(ex){err.textContent='خطا در ارتباط با سرور'}
  btn.disabled=false;
  return false;
}
// اسلایدشو
let cur=0,paused=false;
const slides=document.querySelectorAll('.slide'),dots=document.querySelectorAll('#dots span');
function go(i){slides[cur].classList.remove('active');dots[cur].classList.remove('active');
  cur=(i+slides.length)%slides.length;
  slides[cur].classList.add('active');dots[cur].classList.add('active')}
let timer=setInterval(()=>{if(!paused)go(cur+1)},7000);
dots.forEach((d,i)=>d.addEventListener('click',()=>{go(i);clearInterval(timer);timer=setInterval(()=>{if(!paused)go(cur+1)},7000)}));
document.querySelector('.showcase')?.addEventListener('mouseenter',()=>paused=true);
document.querySelector('.showcase')?.addEventListener('mouseleave',()=>paused=false);
document.addEventListener('visibilitychange',()=>paused=document.hidden);
</script>
</body>
</html>
