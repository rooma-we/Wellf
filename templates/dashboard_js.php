<?php
// PANEL_LOAD_GUARD — جلوگیری از اجرای مستقیم این فایل از وب (دفاع عمیق، علاوه بر .htaccess)
if (!isset($GLOBALS['WV_PANEL'])) { http_response_code(403); exit('Forbidden'); }

// templates/dashboard_js.php — کل منطق سمت کاربر داشبورد (معادل اسکریپت DASHBOARD_HTML)
?>
<script>
// ═══════════ ابزارهای پایه ═══════════
function esc(s){return String(s||'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]))}
function toFa(n){return String(n).replace(/\d/g,d=>'۰۱۲۳۴۵۶۷۸۹'[d])}
function fmtB(b){if(!b||b===0)return '0 B';if(b<1024)return b+' B';if(b<1024**2)return (b/1024).toFixed(1)+' KB';if(b<1024**3)return (b/1024**2).toFixed(2)+' MB';return (b/1024**3).toFixed(2)+' GB'}
function toast(msg,type=''){const t=document.getElementById('toast');t.textContent=msg;t.className='toast show'+(type?' '+type:'');setTimeout(()=>t.classList.remove('show'),2600)}
async function api(url,opts={}){
  opts.headers=Object.assign({'Content-Type':'application/json'},opts.headers||{});
  const r=await fetch(url,opts);
  if(r.status===401){location.href='/login';throw new Error('unauthorized')}
  const d=await r.json().catch(()=>({}));
  if(!r.ok)throw new Error(d.detail||('HTTP '+r.status));
  return d;
}
function copyText(t,msg){navigator.clipboard.writeText(t).then(()=>toast(msg||'کپی شد','ok')).catch(()=>toast('کپی ناموفق بود','err'))}
function persianDate(iso){if(!iso)return '∞';try{return new Date(iso).toLocaleDateString('fa-IR')}catch(e){return '—'}}

// تم
let isDark=localStorage.getItem('wv-theme')!=='light';
function applyTheme(dark){document.documentElement.setAttribute('data-theme',dark?'dark':'light');
  const setIcon=(id)=>{const el=document.getElementById(id);if(el)el.className='ti '+(dark?'ti-sun':'ti-moon')};
  setIcon('theme-icon');setIcon('theme-icon-mob');
  const lbl=document.getElementById('theme-label');if(lbl)lbl.textContent=dark?'تم روشن':'تم تیره'}
function toggleTheme(){isDark=!isDark;localStorage.setItem('wv-theme',isDark?'dark':'light');applyTheme(isDark)}
applyTheme(isDark);

// منوی موبایل (همبرگر)
const sbEl=document.getElementById('sidebar'),ovEl=document.getElementById('nav-overlay'),btnMenu=document.getElementById('btn-menu');
function closeMenu(){sbEl.classList.remove('open');ovEl.classList.remove('show');if(btnMenu)btnMenu.setAttribute('aria-expanded','false')}
function openMenu(){sbEl.classList.add('open');ovEl.classList.add('show');if(btnMenu)btnMenu.setAttribute('aria-expanded','true')}
if(btnMenu){
  btnMenu.addEventListener('click',()=>sbEl.classList.contains('open')?closeMenu():openMenu());
  ovEl.addEventListener('click',closeMenu);
  document.addEventListener('keydown',e=>{if(e.key==='Escape')closeMenu()});
}

// ناوبری
let curPg='overview';
const REFRESHERS={overview:fetchStats,traffic:fetchStats,connections:loadConnections,logs:loadActivity,links:loadLinks,subs:loadSubs,backup:null,settings:loadSettings};
function navTo(pg){
  curPg=pg;
  document.querySelectorAll('.nav-item').forEach(n=>n.classList.toggle('active',n.dataset.pg===pg));
  document.querySelectorAll('.pg').forEach(p=>p.classList.toggle('active',p.id==='pg-'+pg));
  closeMenu();
  if(REFRESHERS[pg])REFRESHERS[pg]();
}
document.querySelectorAll('.nav-item').forEach(n=>n.addEventListener('click',()=>navTo(n.dataset.pg)));

// خروج
async function doLogout(){try{await api('/api/logout',{method:'POST'})}catch(e){}location.href='/login'}

// ═══════════ نمودارها ═══════════
let ch1=null,ch3=null;
function hourlyToPoints(hourly){
  const keys=Object.keys(hourly||{}).sort();
  return {labels:keys,values:keys.map(k=>Math.round((hourly[k]||0)/1024/1024*100)/100)};
}
function drawChart(ctx,labels,values,withAvg){
  if(!window.Chart)return null;
  const grad=ctx.createLinearGradient(0,0,0,240);
  grad.addColorStop(0,'rgba(59,130,246,.45)');grad.addColorStop(1,'rgba(59,130,246,0)');
  const ds=[{data:values,borderColor:'#3B82F6',backgroundColor:grad,fill:true,tension:.4,pointRadius:2,borderWidth:2}];
  if(withAvg&&values.length){
    const avg=values.reduce((a,b)=>a+b,0)/values.length;
    ds.push({data:values.map(()=>avg),borderColor:'rgba(245,158,11,.8)',borderDash:[6,5],pointRadius:0,borderWidth:1.5,fill:false});
  }
  return new Chart(ctx,{type:'line',data:{labels,datasets:ds},options:{
    responsive:true,maintainAspectRatio:false,
    plugins:{legend:{display:false},tooltip:{callbacks:{label:c=>c.datasetIndex===0?' '+c.parsed.y+' MB':'میانگین: '+c.parsed.y.toFixed(2)+' MB'}}},
    scales:{x:{grid:{display:false},ticks:{color:'#3D6B8E',font:{family:'Vazirmatn',size:10}}},
            y:{beginAtZero:true,grid:{color:'rgba(59,130,246,.07)'},ticks:{color:'#3D6B8E',font:{size:10},callback:v=>v+' MB'}}}
  }});
}

// ═══════════ آمار / نمای کلی ═══════════
async function fetchStats(){
  try{
    const d=await api('/stats');
    document.getElementById('mv-conn').textContent=toFa(d.active_connections);
    document.getElementById('mv-traffic').textContent=fmtB(Math.round(d.total_traffic_mb*1024*1024));
    document.getElementById('mv-links').textContent=toFa(d.links_count);
    document.getElementById('mv-links-sub').textContent=toFa(d.active_links)+' فعال · '+toFa(d.expired_links)+' منقضی';
    document.getElementById('mv-subs').textContent=toFa(d.subs_count);
    document.getElementById('nb-links').textContent=toFa(d.links_count);
    document.getElementById('uptime-chip').textContent='آپ‌تایم '+d.uptime;
    document.getElementById('tv-req').textContent=toFa(d.total_requests);
    document.getElementById('tv-err').textContent=toFa(d.total_errors);
    document.getElementById('tv-bytes').textContent=fmtB(Math.round(d.total_traffic_mb*1024*1024));
    document.getElementById('tv-up').textContent=d.uptime;
    // نمودار نمای کلی
    const p=hourlyToPoints(d.hourly);
    const c1=document.getElementById('ch1');
    if(c1){if(ch1)ch1.destroy();ch1=drawChart(c1.getContext('2d'),p.labels,p.values,false)}
    const c3=document.getElementById('ch3');
    if(c3&&curPg==='traffic'){if(ch3)ch3.destroy();ch3=drawChart(c3.getContext('2d'),p.labels,p.values,true)}
    // خطاها
    const el=document.getElementById('err-list');
    if(el){
      if(!(d.recent_errors||[]).length)el.innerHTML='<div class="empty"><i class="ti ti-check"></i>خطایی ثبت نشده</div>';
      else el.innerHTML=d.recent_errors.map(e=>`<div class="log-item"><span class="log-time">${esc((e.time||'').slice(0,16))}</span><span class="log-msg lv-err">${esc(e.error||e)}</span></div>`).join('');
    }
  }catch(e){}
}
async function loadSysRes(){
  try{
    const d=await api('/api/system');
    const box=document.getElementById('sys-rings');
    if(!box)return;
    const items=[];
    if(d.memory&&d.memory.percent!=null)items.push({l:'حافظه',v:d.memory.percent,c:'#8B5CF6'});
    if(d.disk&&d.disk.percent!=null)items.push({l:'دیسک',v:d.disk.percent,c:'#3B82F6'});
    if(d.cpu&&d.cpu.load_avg)items.push({l:'بار سیستم',v:Math.min(100,(d.cpu.load_avg[0]||0)*25),c:'#10B981'});
    if(!items.length){box.innerHTML='<div class="empty" style="padding:20px"><i class="ti ti-info-circle"></i>آمار منابع روی این هاست در دسترس نیست</div>';return}
    box.innerHTML=items.map(it=>{
      const r=30,c=2*Math.PI*r,off=c*(1-Math.min(100,it.v)/100);
      return `<div class="ring"><svg width="76" height="76"><circle cx="38" cy="38" r="${r}" fill="none" stroke="rgba(59,130,246,.12)" stroke-width="7"/>
      <circle cx="38" cy="38" r="${r}" fill="none" stroke="${it.c}" stroke-width="7" stroke-linecap="round" stroke-dasharray="${c}" stroke-dashoffset="${off}"/></svg>
      <div class="rv">${toFa(it.v.toFixed(0))}٪</div><div class="rl">${it.l}</div></div>`;
    }).join('');
  }catch(e){}
}
async function loadLocation(){
  try{
    const d=await api('/api/server/location');
    const box=document.getElementById('loc-box');
    if(!box)return;
    box.innerHTML=`<div style="display:flex;gap:14px;align-items:center;flex-wrap:wrap">
      <span class="chip chip-info"><i class="ti ti-map-pin"></i> ${esc(d.city||'?')}، ${esc(d.country||'?')}</span>
      <span class="mono" style="font-size:11px;color:var(--t2)" dir="ltr">${esc(d.ip||'')}</span></div>`;
  }catch(e){
    const box=document.getElementById('loc-box');
    if(box)box.innerHTML='<div class="empty"><i class="ti ti-wifi-off"></i>موقعیت سرور در دسترس نیست</div>';
  }
}

// ═══════════ اتصالات ═══════════
async function loadConnections(){
  try{
    const d=await api('/api/connections');
    document.getElementById('nb-conn').textContent=toFa(d.count);
    document.getElementById('conn-sub').textContent=toFa(d.count)+' اتصال فعال';
    const el=document.getElementById('conn-list');
    if(!d.connections.length){el.innerHTML='<div class="empty"><i class="ti ti-plug-off"></i>اتصالی وجود ندارد</div>';return}
    el.innerHTML=`<table class="tbl"><thead><tr><th>IP</th><th>برچسب</th><th>ترنسپورت</th><th>سشن‌ها</th><th>حجم</th><th>آخرین اتصال</th></tr></thead><tbody>`+
      d.connections.map(c=>`<tr>
        <td class="mono" dir="ltr">${esc(c.ip)}</td>
        <td>${esc(c.label)}</td>
        <td>${(c.transports||[]).map(t=>`<span class="chip chip-info">${esc(t)}</span>`).join(' ')}</td>
        <td>${toFa(c.sessions)}</td>
        <td class="mono">${esc(c.bytes_fmt)}</td>
        <td style="font-size:10.5px;color:var(--t3)">${persianDate(c.last_connected_at)}</td></tr>`).join('')+'</tbody></table>';
  }catch(e){}
}

// ═══════════ لاگ ═══════════
async function loadActivity(){
  try{
    const d=await api('/api/activity');
    const el=document.getElementById('log-list');
    if(!d.logs.length){el.innerHTML='<div class="empty"><i class="ti ti-history-off"></i>لاگی وجود ندارد</div>';return}
    const lvClass={ok:'lv-ok',err:'lv-err',warn:'lv-warn',info:'lv-info'};
    el.innerHTML=d.logs.map(l=>`<div class="log-item"><span class="log-time">${esc((l.time||'').slice(0,16))}</span>
      <span class="log-msg"><span class="${lvClass[l.level]||'lv-info'}">${esc(l.message)}</span></span>
      <span class="chip chip-info" style="flex-shrink:0">${esc(l.kind)}</span></div>`).join('');
  }catch(e){}
}

// ═══════════ کانفیگ‌ها ═══════════
let LINKS=[],SUBS=[];
const PROTO_LABELS={'vless-ws':'VLESS · WS','xhttp-packet-up':'VLESS · XHTTP','xhttp-stream-up':'VLESS · XHTTP-SU','trojan-ws':'Trojan · WS','trojan-xhttp-packet-up':'Trojan · XHTTP','trojan-xhttp-stream-up':'Trojan · XHTTP-SU','shadowsocks':'Shadowsocks','mtproto':'Telegram Proxy'};
function protoChip(p){const l=PROTO_LABELS[p]||p;
  if(p==='mtproto')return '<span class="chip chip-purple"><i class="ti ti-brand-telegram"></i> '+l+'</span>';
  if((p||'').startsWith('trojan'))return '<span class="chip chip-purple">'+l+'</span>';
  if((p||'').startsWith('shadowsocks'))return '<span class="chip chip-purple">'+l+'</span>';
  if((p||'').startsWith('xhttp'))return '<span class="chip chip-purple">'+l+'</span>';
  return '<span class="chip chip-info">'+l+'</span>'}
async function loadLinks(){
  try{
    const [dl,ds]=await Promise.all([api('/api/links'),api('/api/subs')]);
    LINKS=dl.links||[];SUBS=ds.subs||[];
    renderLinks();renderSubOptions();
  }catch(e){}
}
// دسترسی به لینک از طریق uid (بدون قرار دادن مقادیر داخل attributeهای HTML)
function linkByUid(uid){return LINKS.find(x=>x.uuid===uid)}
function copyLink(uid){const l=linkByUid(uid);if(l)copyText(l.vless_link,'لینک کپی شد')}
function copyLinkSub(uid){const l=linkByUid(uid);if(l)copyText(l.sub_url,'آدرس اشتراک کپی شد')}
function qrLink(uid){const l=linkByUid(uid);if(l)showQR(l.label,l.vless_link)}
function renderLinks(){
  const el=document.getElementById('links-list');
  document.getElementById('nb-links').textContent=toFa(LINKS.length);
  if(!LINKS.length){el.innerHTML='<div class="card"><div class="empty"><i class="ti ti-link-off"></i>هنوز کانفیگی ساخته نشده — دکمه «کانفیگ جدید» را بزنید</div></div>';return}
  el.innerHTML=LINKS.map(l=>{
    const pct=l.limit_bytes>0?Math.min(100,Math.round(l.used_bytes/l.limit_bytes*100)):0;
    const barCls=pct>=100?'err':pct>=80?'warn':'ok';
    return `<div class="cfg-row">
      <div class="cfg-row-top">
        <div>
          <div class="cfg-row-title">${l.active&&!l.expired?'<span class="dot" style="color:var(--green)"></span>':'<span class="dot" style="color:var(--red);animation:none"></span>'} ${esc(l.label)}
            ${l.is_default?'<span class="chip chip-info">پیش‌فرض</span>':''}</div>
          <div class="cfg-row-meta">${protoChip(l.protocol)}
            <span><i class="ti ti-calendar"></i> ${persianDate(l.created_at)}</span>
            ${l.expires_at?`<span><i class="ti ti-hourglass"></i> انقضا: ${persianDate(l.expires_at)}</span>`:'<span><i class="ti ti-infinity"></i> بدون انقضا</span>'}
          </div>
        </div>
        <div class="cfg-row-actions">
          <label class="switch" title="فعال/غیرفعال"><input type="checkbox" ${l.active?'checked':''} onchange="toggleLink('${l.uuid}',this.checked)"><span class="sw-f"></span></label>
          <button class="icon-btn" title="کپی لینک" onclick="copyLink('${l.uuid}')"><i class="ti ti-copy"></i></button>
          <button class="icon-btn" title="کپی آدرس اشتراک" onclick="copyLinkSub('${l.uuid}')"><i class="ti ti-rss"></i></button>
          <button class="icon-btn" title="QR" onclick="qrLink('${l.uuid}')"><i class="ti ti-qrcode"></i></button>
          <button class="icon-btn" title="ویرایش" onclick="openEditModal('${l.uuid}')"><i class="ti ti-pencil"></i></button>
          <button class="icon-btn danger" title="حذف" onclick="deleteLink('${l.uuid}')"><i class="ti ti-trash"></i></button>
        </div>
      </div>
      <div style="display:flex;align-items:center;gap:10px;margin-top:11px">
        <div class="bar" style="flex:1"><div class="bar-f ${barCls}" style="width:${l.limit_bytes>0?pct:0}%"></div></div>
        <span class="mono" style="font-size:10.5px;color:var(--t2);white-space:nowrap">${fmtB(l.used_bytes)}${l.limit_bytes>0?' / '+fmtB(l.limit_bytes):' / ∞'}</span>
        <button class="btn btn-sm btn-g" onclick="resetUsage('${l.uuid}')"><i class="ti ti-rotate"></i> ریست</button>
      </div>
    </div>`;
  }).join('');
}
async function toggleLink(uid,active){
  try{await api('/api/links/'+uid,{method:'PATCH',body:JSON.stringify({active})});
    const l=LINKS.find(x=>x.uuid===uid);if(l)l.active=active;
    toast(active?'کانفیگ فعال شد':'کانفیغ غیرفعال شد','ok')}
  catch(e){toast(e.message,'err');loadLinks()}
}
async function resetUsage(uid){
  try{await api('/api/links/'+uid,{method:'PATCH',body:JSON.stringify({reset_usage:true})});toast('مصرف ریست شد','ok');loadLinks()}
  catch(e){toast(e.message,'err')}
}
async function deleteLink(uid){
  if(!confirm('این کانفیگ برای همیشه حذف شود؟'))return;
  try{await api('/api/links/'+uid,{method:'DELETE'});toast('کانفیگ حذف شد','ok');loadLinks()}
  catch(e){toast(e.message,'err')}
}
function showQR(label,link){
  let m=document.getElementById('qr-modal');
  if(!m){
    m=document.createElement('div');m.id='qr-modal';m.className='modal-bg';
    m.innerHTML=`<div class="modal-v2" style="max-width:340px;text-align:center" onclick="event.stopPropagation()">
      <div class="m-body"><h3 style="font-size:14px;font-weight:800;margin-bottom:14px" id="qr-label"></h3>
      <img id="qr-img" style="width:100%;background:#fff;padding:10px;border-radius:14px" alt="QR">
      <button class="btn btn-g" style="margin-top:14px;width:100%;justify-content:center" onclick="document.getElementById('qr-modal').classList.remove('open')"><i class="ti ti-x"></i> بستن</button></div></div>`;
    m.addEventListener('click',()=>m.classList.remove('open'));
    document.body.appendChild(m);
  }
  m.querySelector('#qr-label').textContent=label;
  m.querySelector('#qr-img').src='https://api.qrserver.com/v1/create-qr-code/?size=280x280&data='+encodeURIComponent(link);
  m.classList.add('open');
}

// ═══════════ مودال ساخت/ویرایش کانفیگ ═══════════
// انتخاب‌گر دو مرحله‌ای مثل نسخه پایتون: خانواده پروتکل + ترنسپورت (همه با TLS)
let pickedProto='vless-ws';
const TRANSPORTS=[
  ['vless-ws','WS + TLS','ti-plug-connected'],
  ['xhttp-packet-up','XHTTP packet-up + TLS','ti-arrows-split-2'],
  ['xhttp-stream-up','XHTTP stream-up + TLS','ti-arrows-right'],
];
const TRANSPORTS_TROJAN=[
  ['trojan-ws','WS + TLS','ti-plug-connected'],
  ['trojan-xhttp-packet-up','XHTTP packet-up + TLS','ti-arrows-split-2'],
  ['trojan-xhttp-stream-up','XHTTP stream-up + TLS','ti-arrows-right'],
];
const PROTO_FAMILIES=[
  ['vless','VLESS','ti-plug-connected',TRANSPORTS],
  ['trojan','Trojan','ti-shield-lock',TRANSPORTS_TROJAN],
  ['shadowsocks','Shadowsocks','ti-shield-lock-filled',null],
  ['mtproto','MTProto','ti-brand-telegram',null],
];
const ALPN_OPTIONS=[['h2,http/1.1','h2 + http/1.1'],['h2','h2'],['http/1.1','http/1.1']];
const FP_OPTIONS=[['chrome','Chrome'],['firefox','Firefox'],['ios','iOS']];
let pickedFamily='vless',pickedAlpn='h2,http/1.1',pickedFp='chrome';

function transportValue(){
  if(pickedFamily==='vless')return pickedProto.startsWith('xhttp')?pickedProto:'vless-ws';
  if(pickedFamily==='trojan')return pickedProto.startsWith('trojan-xhttp')?pickedProto:'trojan-ws';
  return pickedFamily;
}
function transportCards(){
  const list=pickedFamily==='trojan'?TRANSPORTS_TROJAN:TRANSPORTS;
  return list.map(([v,l,ic])=>`<button type="button" class="pill ${pickedProto===v?'on':''}" data-v="${v}" onclick="pickTransport('${v}',this)"><i class="ti ${ic}"></i> ${l}</button>`).join('');
}
function familyCards(){
  return PROTO_FAMILIES.map(([v,l,ic])=>`<button type="button" class="proto-card ${pickedFamily===v?'on':''}" data-v="${v}" onclick="pickFamily('${v}')"><i class="ti ${ic}"></i><span>${l}</span></button>`).join('');
}
function pickFamily(v){
  pickedFamily=v;
  const fam=PROTO_FAMILIES.find(f=>f[0]===v);
  pickedProto=fam[3]?fam[3][0][0]:v; // اولین ترنسپورت خانواده یا خودش
  refreshProtoPicker();
}
function pickTransport(v,btn){
  pickedProto=v;
  btn.parentElement.querySelectorAll('.pill').forEach(p=>p.classList.toggle('on',p===btn));
}
function refreshProtoPicker(){
  const fc=document.getElementById('nl-family-cards');if(fc)fc.innerHTML=familyCards();
  const tc=document.getElementById('nl-transport-cards');if(tc)tc.innerHTML=(pickedFamily==='vless'||pickedFamily==='trojan')?transportCards():'';
  document.getElementById('nl-transport-row').style.display=(pickedFamily==='vless'||pickedFamily==='trojan')?'block':'none';
  document.getElementById('nl-tls-row').style.display=(pickedFamily==='vless'||pickedFamily==='trojan')?'block':'none';
  document.getElementById('nl-ss-row').style.display=pickedFamily==='shadowsocks'?'block':'none';
  document.getElementById('nl-mtp-row').style.display=pickedFamily==='mtproto'?'block':'none';
}
function pickAlpn(btn){pickedAlpn=btn.dataset.v;btn.parentElement.querySelectorAll('.pill').forEach(p=>p.classList.toggle('on',p===btn))}
function pickFp(btn){pickedFp=btn.dataset.v;btn.parentElement.querySelectorAll('.pill').forEach(p=>p.classList.toggle('on',p===btn))}
function openCreateModal(){
  pickedFamily='vless';pickedProto='vless-ws';pickedAlpn='h2,http/1.1';pickedFp='chrome';
  const html=`<div class="modal-bg open" id="modal-create" onclick="if(event.target===this)this.classList.remove('open')">
  <div class="modal-v2">
    <div class="m-head"><i class="ti ti-plus" style="color:var(--accent)"></i><h3>کانفیگ جدید</h3>
      <button class="m-close" onclick="document.getElementById('modal-create').remove()"><i class="ti ti-x"></i></button></div>
    <div class="m-body">
      <div class="f-row"><label class="f-label">نام (خالی = نام خودکار)</label><input class="f-in" id="nl-label" placeholder="مثلاً مشتری-۱"></div>
      <div class="f-row"><label class="f-label">گروه اشتراک</label><select class="f-in" id="nl-sub"><option value="">— بدون گروه —</option>${SUBS.map(s=>`<option value="${s.sub_id}">${esc(s.name)}</option>`).join('')}</select></div>
      <div class="f-row"><label class="f-label">پروتکل</label><div class="pill-row" id="nl-family-cards">${familyCards()}</div></div>
      <div class="f-row" id="nl-transport-row"><label class="f-label">ترنسپورت (TLS 443)</label><div class="pill-row" id="nl-transport-cards">${transportCards()}</div></div>
      <div class="f-row" id="nl-tls-row">
        <label class="f-label">تنظیمات TLS</label>
        <div class="f-grid2">
          <div><label class="f-label" style="font-size:10px">ALPN</label><div class="pill-row">
            ${ALPN_OPTIONS.map(([v,l])=>`<button type="button" class="pill ${v===pickedAlpn?'on':''}" data-v="${v}" onclick="pickAlpn(this)">${l}</button>`).join('')}
          </div></div>
          <div><label class="f-label" style="font-size:10px">انگشت نگار TLS (fp)</label><div class="pill-row">
            ${FP_OPTIONS.map(([v,l])=>`<button type="button" class="pill ${v===pickedFp?'on':''}" data-v="${v}" onclick="pickFp(this)">${l}</button>`).join('')}
          </div></div>
        </div>
      </div>
      <div class="f-row" id="nl-ss-row" style="display:none"><label class="f-label">رمزنگاری Shadowsocks</label>
        <div class="pill-row"><button type="button" class="pill on" data-c="chacha20-ietf-poly1305" onclick="pickCipher(this)">chacha20-ietf-poly1305</button>
        <button type="button" class="pill" data-c="aes-256-gcm" onclick="pickCipher(this)">aes-256-gcm</button></div></div>
      <div class="f-row" id="nl-mtp-row" style="display:none">
        <label class="f-label">آدرس عمومی MTProto (پروکسی تلگرام)</label>
        <div class="f-grid2">
          <input class="f-in" id="nl-mtp-host" placeholder="دامنه — مثلاً proxy.example.net" dir="ltr">
          <input class="f-in" id="nl-mtp-port" type="number" min="1" max="65535" placeholder="پورت" dir="ltr">
        </div></div>
      <div class="f-grid2">
        <div class="f-row"><label class="f-label">سهمیه ترافیک</label>
          <div style="display:flex;gap:6px"><input class="f-in" id="nl-limit" type="number" min="0" step="any" placeholder="0=نامحدود">
          <select class="f-in" id="nl-limit-unit" style="width:90px"><option>GB</option><option>MB</option></select></div></div>
        <div class="f-row"><label class="f-label">انقضا (روز)</label><input class="f-in" id="nl-exp" type="number" min="0" placeholder="0=بدون انقضا"></div>
      </div>
      <div class="f-row pill-row"><button type="button" class="pill" onclick="qp(this,500,'MB')">500MB</button><button type="button" class="pill" onclick="qp(this,1,'GB')">1GB</button><button type="button" class="pill" onclick="qp(this,5,'GB')">5GB</button><button type="button" class="pill" onclick="qp(this,10,'GB')">10GB</button><button type="button" class="pill" onclick="qp(this,50,'GB')">50GB</button><button type="button" class="pill" onclick="qp(this,7,null,7)">۷ روز</button><button type="button" class="pill" onclick="qp(this,30,null,30)">۳۰ روز</button></div>
      <div class="f-row"><label class="f-label">یادداشت (عمومی)</label><input class="f-in" id="nl-note" maxlength="200"></div>
    </div>
    <div class="m-foot"><button class="btn btn-p" style="flex:1;justify-content:center" onclick="createLink()"><i class="ti ti-plus"></i> ساخت کانفیگ</button>
    <button class="btn btn-g" onclick="document.getElementById('modal-create').remove()">انصراف</button></div>
  </div></div>`;
  document.getElementById('modals-root').insertAdjacentHTML('beforeend',html);
  refreshProtoPicker();
}
let pickedCipher='chacha20-ietf-poly1305';
function pickCipher(btn){pickedCipher=btn.dataset.c;
  btn.parentElement.querySelectorAll('.pill').forEach(p=>p.classList.toggle('on',p===btn))}
function qp(btn,v,unit,days){if(unit){document.getElementById('nl-limit').value=v;document.getElementById('nl-limit-unit').value=unit}
  if(days!=null)document.getElementById('nl-exp').value=days}
async function createLink(){
  const body={
    label:document.getElementById('nl-label').value,
    sub_id:document.getElementById('nl-sub').value||null,
    protocol:transportValue(),
    limit_value:parseFloat(document.getElementById('nl-limit').value)||0,
    limit_unit:document.getElementById('nl-limit-unit').value,
    expires_days:parseInt(document.getElementById('nl-exp').value)||0,
    note:document.getElementById('nl-note').value,
    ss_cipher:pickedCipher,
    alpn:pickedAlpn,
    fingerprint:pickedFp,
  };
  if(pickedFamily==='mtproto'){
    body.mtproto_public_host=document.getElementById('nl-mtp-host').value.trim();
    body.mtproto_public_port=parseInt(document.getElementById('nl-mtp-port').value)||0;
  }
  try{
    const d=await api('/api/links',{method:'POST',body:JSON.stringify(body)});
    document.getElementById('modal-create')?.remove();
    toast('کانفیگ «'+d.label+'» ساخته شد','ok');
    loadLinks();
  }catch(e){toast(e.message,'err')}
}
function openEditModal(uid){
  const l=LINKS.find(x=>x.uuid===uid);if(!l)return;
  const lv=l.limit_bytes>0?(l.limit_bytes>=1024**3?(l.limit_bytes/1024**3):Math.round(l.limit_bytes/1024**2*10)/10):0;
  const lu=l.limit_bytes>=1024**3?'GB':'MB';
  const ed=l.expires_at?Math.max(0,Math.ceil((new Date(l.expires_at)-new Date())/86400000)):0;
  const html=`<div class="modal-bg open" id="modal-edit" onclick="if(event.target===this)this.classList.remove('open')">
  <div class="modal-v2">
    <div class="m-head"><i class="ti ti-pencil" style="color:var(--accent)"></i><h3>ویرایش «${esc(l.label)}»</h3>
      <button class="m-close" onclick="document.getElementById('modal-edit').remove()"><i class="ti ti-x"></i></button></div>
    <div class="m-body">
      <div class="f-row"><label class="f-label">نام</label><input class="f-in" id="el-label" value="${esc(l.label)}"></div>
      <div class="f-grid2">
        <div class="f-row"><label class="f-label">سهمیه (0=نامحدود)</label>
          <div style="display:flex;gap:6px"><input class="f-in" id="el-limit" type="number" min="0" step="any" value="${lv||0}">
          <select class="f-in" id="el-limit-unit" style="width:90px"><option ${lu==='GB'?'selected':''}>GB</option><option ${lu==='MB'?'selected':''}>MB</option></select></div></div>
        <div class="f-row"><label class="f-label">انقضا (روز از الان)</label><input class="f-in" id="el-exp" type="number" min="0" value="${ed}"></div>
      </div>
      <div class="f-row"><label class="f-label">گروه اشتراک</label><select class="f-in" id="el-sub"><option value="">— بدون گروه —</option>
        ${SUBS.map(s=>`<option value="${s.sub_id}" ${l.sub_id===s.sub_id?'selected':''}>${esc(s.name)}</option>`).join('')}</select></div>
      <div class="f-row"><label class="f-label">یادداشت</label><input class="f-in" id="el-note" maxlength="200" value="${esc(l.note||'')}"></div>
      <div class="f-row"><label class="f-label">لینک کانفیگ</label><div class="link-box" dir="ltr">${esc(l.vless_link)}</div></div>
    </div>
    <div class="m-foot"><button class="btn btn-p" style="flex:1;justify-content:center" onclick="saveEdit('${uid}')"><i class="ti ti-device-floppy"></i> ذخیره</button>
    <button class="btn btn-g" onclick="document.getElementById('modal-edit').remove()">انصراف</button></div>
  </div></div>`;
  document.getElementById('modals-root').insertAdjacentHTML('beforeend',html);
}
async function saveEdit(uid){
  const body={
    label:document.getElementById('el-label').value,
    note:document.getElementById('el-note').value,
    limit_value:parseFloat(document.getElementById('el-limit').value)||0,
    limit_unit:document.getElementById('el-limit-unit').value,
    expires_days:parseInt(document.getElementById('el-exp').value)||0,
    sub_id:document.getElementById('el-sub').value||null,
  };
  try{await api('/api/links/'+uid,{method:'PATCH',body:JSON.stringify(body)});
    document.getElementById('modal-edit')?.remove();toast('ذخیره شد','ok');loadLinks()}
  catch(e){toast(e.message,'err')}
}

// ═══════════ گروه اشتراک ═══════════
function renderSubOptions(){/* برای مودال‌ها کافی است SUBS باشد */}
async function loadSubs(){
  try{
    const d=await api('/api/subs');
    SUBS=d.subs||[];
    const el=document.getElementById('subs-list');
    if(!SUBS.length){el.innerHTML='<div class="card"><div class="empty"><i class="ti ti-users-group"></i>گروهی ساخته نشده — اولین گروه را بسازید</div></div>';return}
    el.innerHTML=SUBS.map(s=>`<div class="sub-row">
      <div style="flex:1;min-width:180px">
        <div style="font-size:13.5px;font-weight:800">${esc(s.name)} ${s.has_password?'<span class="chip chip-warn"><i class="ti ti-lock"></i> رمزدار</span>':''}</div>
        <div style="font-size:10.5px;color:var(--t3);margin-top:4px">${toFa(s.links_count)} کانفیگ · ${toFa(s.active_count)} فعال · ${esc(s.total_used_fmt)}</div>
      </div>
      <div class="cfg-row-actions">
        <button class="btn btn-sm btn-g" onclick="copyText('${esc(s.sub_url)}','لینک اشتراک گروه کپی شد')"><i class="ti ti-rss"></i> لینک اشتراک</button>
        <button class="btn btn-sm btn-g" onclick="copyText('${esc(s.public_url)}','لینک صفحه کپی شد')"><i class="ti ti-world"></i> صفحه عمومی</button>
        <button class="btn btn-sm btn-g" onclick="openSubManage('${s.sub_id}')"><i class="ti ti-settings"></i> مدیریت</button>
        <button class="icon-btn danger" onclick="deleteSub('${s.sub_id}')"><i class="ti ti-trash"></i></button>
      </div></div>`).join('');
  }catch(e){}
}
function openCreateSubModal(){
  const html=`<div class="modal-bg open" id="modal-csub" onclick="if(event.target===this)this.classList.remove('open')">
  <div class="modal-v2">
    <div class="m-head"><i class="ti ti-users-group" style="color:var(--accent)"></i><h3>گروه اشتراک جدید</h3>
      <button class="m-close" onclick="document.getElementById('modal-csub').remove()"><i class="ti ti-x"></i></button></div>
    <div class="m-body">
      <div class="f-row"><label class="f-label">نام گروه</label><input class="f-in" id="cs-name"></div>
      <div class="f-row"><label class="f-label">توضیح (عمومی)</label><input class="f-in" id="cs-desc" maxlength="200"></div>
      <div class="f-row"><label class="f-label">رمز اختیاری</label><input class="f-in" id="cs-pw" type="password"></div>
    </div>
    <div class="m-foot"><button class="btn btn-p" style="flex:1;justify-content:center" onclick="createSub()"><i class="ti ti-plus"></i> ساخت گروه</button>
    <button class="btn btn-g" onclick="document.getElementById('modal-csub').remove()">انصراف</button></div>
  </div></div>`;
  document.getElementById('modals-root').insertAdjacentHTML('beforeend',html);
}
async function createSub(){
  try{
    const d=await api('/api/subs',{method:'POST',body:JSON.stringify({
      name:document.getElementById('cs-name').value,
      desc:document.getElementById('cs-desc').value,
      password:document.getElementById('cs-pw').value})});
    document.getElementById('modal-csub')?.remove();
    toast('گروه «'+d.name+'» ساخته شد','ok');loadSubs();
  }catch(e){toast(e.message,'err')}
}
async function deleteSub(id){
  if(!confirm('این گروه حذف شود؟ (کانفیگ‌ها حذف نمی‌شوند)'))return;
  try{await api('/api/subs/'+id,{method:'DELETE'});toast('گروه حذف شد','ok');loadSubs()}catch(e){toast(e.message,'err')}
}
function openSubManage(id){
  const s=SUBS.find(x=>x.sub_id===id);if(!s)return;
  const inGroup=new Set(s.link_ids||[]);
  const html=`<div class="modal-bg open" id="modal-msub" onclick="if(event.target===this)this.classList.remove('open')">
  <div class="modal-v2">
    <div class="m-head"><i class="ti ti-settings" style="color:var(--accent)"></i><h3>مدیریت «${esc(s.name)}»</h3>
      <button class="m-close" onclick="document.getElementById('modal-msub').remove()"><i class="ti ti-x"></i></button></div>
    <div class="m-body">
      <div class="f-row"><label class="f-label">نام</label><input class="f-in" id="ms-name" value="${esc(s.name)}"></div>
      <div class="f-row"><label class="f-label">توضیح</label><input class="f-in" id="ms-desc" maxlength="200" value="${esc(s.desc||'')}"></div>
      <div class="f-row"><label class="f-label">رمز جدید (خالی=بدون تغییر، space=حذف رمز)</label><input class="f-in" id="ms-pw"></div>
      <div class="f-row"><label class="f-label">کانفیگ‌های عضو</label>
        <div style="max-height:240px;overflow-y:auto">${LINKS.map(l=>`
          <label style="display:flex;align-items:center;gap:9px;padding:8px 10px;border:1px solid var(--border);border-radius:10px;margin-bottom:6px;cursor:pointer;font-size:12px">
            <input type="checkbox" class="ms-link" value="${l.uuid}" ${inGroup.has(l.uuid)?'checked':''}>
            <span style="flex:1">${esc(l.label)}</span>${protoChip(l.protocol)}</label>`).join('')||'<div class="empty" style="padding:16px">کانفیگی وجود ندارد</div>'}</div></div>
    </div>
    <div class="m-foot"><button class="btn btn-p" style="flex:1;justify-content:center" onclick="saveSub('${id}')"><i class="ti ti-device-floppy"></i> ذخیره</button>
    <button class="btn btn-g" onclick="document.getElementById('modal-msub').remove()">انصراف</button></div>
  </div></div>`;
  document.getElementById('modals-root').insertAdjacentHTML('beforeend',html);
}
async function saveSub(id){
  const link_ids=[...document.querySelectorAll('.ms-link:checked')].map(c=>c.value);
  const body={name:document.getElementById('ms-name').value,desc:document.getElementById('ms-desc').value,link_ids};
  const pw=document.getElementById('ms-pw').value;
  if(pw.trim()===''&&pw.length>0)body.password='';
  else if(pw.length>0)body.password=pw;
  try{await api('/api/subs/'+id,{method:'PATCH',body:JSON.stringify(body)});
    document.getElementById('modal-msub')?.remove();toast('گروه ذخیره شد','ok');loadSubs()}
  catch(e){toast(e.message,'err')}
}

// ═══════════ بکاپ ═══════════
function doExport(){location.href='/api/backup/export'}
async function doImport(){
  const f=document.getElementById('import-file').files[0];
  if(!f){toast('ابتدا فایل بکاپ را انتخاب کنید','err');return}
  if(!confirm('داده‌های فعلی با فایل بکاپ جایگزین شوند؟'))return;
  try{
    const text=await f.text();
    const data=JSON.parse(text);
    const d=await api('/api/backup/import',{method:'POST',body:JSON.stringify({data,keep_current_password:document.getElementById('keep-pw').checked})});
    toast('بازیابی شد: '+toFa(d.links_count)+' کانفیگ، '+toFa(d.subs_count)+' گروه','ok');
    loadLinks();loadSubs();fetchStats();
  }catch(e){toast('بازیابی ناموفق: '+e.message,'err')}
}

// ═══════════ تنظیمات ═══════════
async function loadSettings(){
  try{
    const d=await api('/api/settings/logging');
    document.getElementById('log-disable').checked=!!d.disabled;
  }catch(e){}
}
async function saveLogging(disabled){
  try{await api('/api/settings/logging',{method:'POST',body:JSON.stringify({disabled})});toast(disabled?'ثبت لاگ خاموش شد':'ثبت لاگ روشن شد','ok')}
  catch(e){toast(e.message,'err')}
}
async function doChangePw(){
  try{
    await api('/api/change-password',{method:'POST',body:JSON.stringify({
      current_password:document.getElementById('cur-pw').value,
      new_password:document.getElementById('new-pw').value})});
    toast('رمز عبور تغییر کرد','ok');
    document.getElementById('cur-pw').value='';document.getElementById('new-pw').value='';
  }catch(e){toast(e.message,'err')}
}

// ═══════════ پولینگ ═══════════
fetchStats();loadSysRes();loadLocation();loadLinks();loadSubs();loadSettings();
setInterval(()=>{if(curPg==='overview'||curPg==='traffic')fetchStats()},2000);
setInterval(()=>{if(curPg==='overview')loadSysRes()},1500);
setInterval(()=>{if(curPg==='connections')loadConnections()},5000);
setInterval(()=>{if(curPg==='logs')loadActivity()},5000);
setInterval(()=>{if(curPg==='links')loadLinks()},6000);
setInterval(()=>{if(curPg==='subs')loadSubs()},6000);
setInterval(loadLocation,300000);
</script>
