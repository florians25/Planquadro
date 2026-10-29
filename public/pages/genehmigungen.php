<?php
$pageTitle = 'Genehmigungen';
require_once __DIR__ . '/../includes/header.php';
if (!canEdit()) { header('Location: dashboard'); exit; }
$pam = planAreaMap();
$approvalEnabled = getSetting('approval_system_enabled','0') === '1';
?>

<?php if (!$approvalEnabled): ?>
<div class="card mb"><div class="cb" style="text-align:center;padding:2.5rem">
<svg viewBox="0 0 20 20" fill="currentColor" style="width:48px;height:48px;color:var(--bd2);margin-bottom:.5rem"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>
<p style="font-size:1rem;font-weight:600;margin-bottom:.25rem">Beantragungssystem deaktiviert</p>
<p class="muted"><a href="einstellungen">In Einstellungen aktivieren</a></p>
</div></div>
<?php endif; ?>

<style>
/* Anträge Rework */
.ap-hero{display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem;flex-wrap:wrap;gap:.75rem}
.ap-hero-left h1{font-size:1.4rem;font-weight:800;letter-spacing:-.02em;margin:0}
.ap-hero-left p{font-size:.8rem;color:var(--mu);margin-top:.15rem}
.ap-stats{display:flex;gap:.5rem}
.ap-stat{display:flex;flex-direction:column;align-items:center;padding:.5rem 1.2rem;border-radius:var(--r2);cursor:pointer;border:1.5px solid var(--bd);background:var(--card);min-width:80px}
.ap-stat:hover{transform:translateY(-1px);box-shadow:var(--sh2)}
.ap-stat.active{box-shadow:0 0 0 2px rgba(59,130,246,.2);background:#fafbff}
.ap-stat-num{font-size:1.5rem;font-weight:800;line-height:1}
.ap-stat-lbl{font-size:.65rem;font-weight:600;color:var(--mu);margin-top:.15rem;text-transform:uppercase;letter-spacing:.03em}
.ap-stat-pending .ap-stat-num{color:#d97706}
.ap-stat-pending{border-color:#fbbf24}
.ap-stat-approved .ap-stat-num{color:#16a34a}
.ap-stat-approved{border-color:#86efac}
.ap-stat-rejected .ap-stat-num{color:#dc2626}
.ap-stat-rejected{border-color:#fca5a5}
/* Toolbar */
.ap-toolbar{display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;margin-bottom:1rem;padding:.55rem .85rem;background:var(--card);border:1px solid var(--bd);border-radius:var(--r2);box-shadow:var(--sh)}
.ap-toolbar .sel{height:30px;font-size:.78rem;padding:.2rem 1.6rem .2rem .5rem;min-width:140px}
.ap-toolbar .inp-filter{height:30px;font-size:.78rem;padding:.2rem .5rem}
.ap-batch{display:flex;gap:.3rem;align-items:center;margin-left:auto}
/* User cards */
.ap-user{background:var(--card);border:1px solid var(--bd);border-radius:var(--r2);margin-bottom:.75rem;box-shadow:var(--sh);overflow:hidden}
.ap-user:hover{box-shadow:var(--sh2);border-color:var(--bd2)}
.ap-user-head{display:flex;align-items:center;gap:.65rem;padding:.75rem 1rem;border-bottom:1px solid var(--bd);background:#fafbfc}
.ap-avatar{width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.65rem;font-weight:800;color:#fff;flex-shrink:0;letter-spacing:.02em}
.ap-user-info{flex:1;min-width:0}
.ap-user-name{font-size:.9rem;font-weight:700}
.ap-user-role{font-size:.68rem;color:var(--mu)}
.ap-user-meta{display:flex;align-items:center;gap:.4rem;flex-shrink:0}
.ap-body{padding:.65rem 1rem}
/* Timeline blocks */
.ap-block{display:flex;align-items:stretch;gap:.6rem;margin-bottom:.5rem}
.ap-block:last-child{margin-bottom:0}
.ap-block-line{width:3px;border-radius:2px;flex-shrink:0}
.ap-block-content{flex:1;min-width:0}
.ap-block-head{display:flex;align-items:center;gap:.4rem;flex-wrap:wrap}
.ap-block-label{display:inline-block;padding:.15rem .45rem;border-radius:4px;font-size:.68rem;font-weight:700}
.ap-block-date{font-size:.8rem;font-weight:600}
.ap-block-cnt{font-size:.68rem;color:var(--mu)}
.ap-block-remark{font-size:.7rem;color:var(--mu);margin-top:.1rem;font-style:italic}
.ap-block-rej{font-size:.7rem;color:var(--err);margin-top:.1rem;font-weight:600}
/* Footer */
.ap-foot{display:flex;align-items:center;gap:.4rem;padding:.55rem 1rem;border-top:1px solid var(--bd);background:#fafbfc}
.ap-foot-sel{margin-right:auto}
.ap-foot-info{font-size:.72rem;color:var(--mu);margin-right:auto}
/* Empty state */
.ap-empty{text-align:center;padding:3rem 1rem}
.ap-empty svg{width:56px;height:56px;color:var(--bd2);margin-bottom:.65rem}
.ap-empty p{font-size:.9rem;color:var(--mu)}
/* Detail modal blocks */
.apd-block{border:1px solid var(--bd);border-radius:var(--r2);margin-bottom:.6rem;overflow:hidden}
.apd-block-head{display:flex;align-items:center;gap:.5rem;padding:.5rem .75rem;background:#f8fafc;border-bottom:1px solid var(--bd);flex-wrap:wrap}
.apd-day{display:flex;align-items:center;gap:.45rem;padding:.4rem .75rem;border-bottom:1px solid var(--bd);font-size:.8rem}
.apd-day:last-child{border-bottom:none}
.apd-day:hover{background:rgba(59,130,246,.03)}
.apd-day-wd{color:var(--mu);font-size:.72rem;min-width:24px;font-weight:600}
.apd-day-date{font-weight:600;min-width:80px}
.apd-day-acts{margin-left:auto;display:flex;gap:.25rem}
.apd-block-foot{display:flex;gap:.35rem;padding:.4rem .75rem;background:#f8fafc;border-top:1px solid var(--bd)}
/* Week overview in detail */
.apd-week{margin-bottom:.75rem}
.apd-week-title{font-size:.68rem;font-weight:700;color:var(--mu);text-transform:uppercase;letter-spacing:.04em;margin-bottom:.3rem}
.apd-week-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(48px,1fr));gap:3px}
.apd-week-cell{text-align:center;border-radius:6px;padding:.3rem .2rem;border:1px solid var(--bd);font-size:.72rem}
.apd-week-cell-head{font-size:.6rem;color:var(--mu);font-weight:700}
.apd-week-cell-cur{background:#fff;min-height:28px;display:flex;align-items:center;justify-content:center;font-weight:700}
.apd-week-cell-req{min-height:28px;display:flex;align-items:center;justify-content:center;font-weight:700;border-radius:4px;outline:2px dashed #39ff14;outline-offset:-1px}
@media screen and (max-width:600px){.ap-stats{flex-wrap:wrap}.ap-stat{min-width:70px;padding:.4rem .7rem}.ap-hero{flex-direction:column;align-items:flex-start}}
</style>

<!-- Hero -->
<div class="ap-hero">
<div class="ap-hero-left"><h1>Änderungsanträge</h1><p>Übersicht und Verwaltung aller Mitarbeiter-Anträge</p></div>
<div class="ap-stats">
<div class="ap-stat ap-stat-pending active" onclick="setStatus('pending')"><div class="ap-stat-num" id="csP">–</div><div class="ap-stat-lbl">Offen</div></div>
<div class="ap-stat ap-stat-approved" onclick="setStatus('approved')"><div class="ap-stat-num" id="csA">–</div><div class="ap-stat-lbl">Genehmigt</div></div>
<div class="ap-stat ap-stat-rejected" onclick="setStatus('rejected')"><div class="ap-stat-num" id="csR">–</div><div class="ap-stat-lbl">Abgelehnt</div></div>
</div>
</div>

<!-- Toolbar -->
<div class="ap-toolbar">
<select id="statusFilter" class="sel" onchange="loadRequests()">
<option value="pending">Offene Anträge</option>
<option value="approved">Genehmigte</option>
<option value="rejected">Abgelehnte</option>
</select>
<input type="text" id="crSearch" class="inp-filter" placeholder="Suchen..." oninput="filterCards()" style="max-width:180px">
<div class="ap-batch" id="batchBar">
<label style="font-size:.75rem;cursor:pointer;display:flex;align-items:center;gap:.25rem"><input type="checkbox" id="batchAll" onchange="toggleAll()"> Alle</label>
<span class="muted" style="font-size:.7rem" id="batchCnt">0</span>
<button class="btn btn-xs btn-approve" onclick="batchAct('approve')" id="btnBA" disabled>Genehmigen</button>
<button class="btn btn-xs btn-outline btn-danger-text" onclick="batchAct('reject')" id="btnBR" disabled>Ablehnen</button>
</div>
</div>

<div id="reqList"><p class="muted" style="padding:2rem;text-align:center">Laden...</p></div>

<!-- Detail Modal -->
<div class="modal-bg" id="detMod"><div class="modal modal-lg"><div class="mh"><h3 id="detTitle">Antrag prüfen</h3><button class="mx" onclick="cMod('detMod')">&times;</button></div><div class="mb" style="max-height:65vh;overflow-y:auto">
<div id="detUser" style="margin-bottom:.65rem"></div>
<div id="detWeek"></div>
<div id="detItems"></div>
</div><div class="mf" id="detFooter">
<button class="btn btn-outline btn-danger-text" id="detRejectBtn">Alle ablehnen</button>
<button class="btn btn-outline" onclick="cMod('detMod')">Schließen</button>
<button class="btn btn-approve" id="detApproveBtn">Alle genehmigen</button>
</div></div></div>

<!-- Reject Modal -->
<div class="modal-bg" id="rejMod"><div class="modal modal-sm"><div class="mh"><h3>Ablehnen</h3><button class="mx" onclick="cMod('rejMod')">&times;</button></div><div class="mb"><p class="muted" id="rejInfo"></p>
<div class="fg"><label>Begründung (optional)</label><textarea id="rejReason" rows="3" placeholder="Warum?"></textarea></div></div>
<div class="mf"><button class="btn btn-outline" onclick="cMod('rejMod')">Abbrechen</button><button class="btn btn-danger" onclick="doReject()">Ablehnen</button></div></div></div>

<script>
var AREAS=<?php echo json_encode($pam);?>;
var AN={krank_halb:'Krank \u00bd',krank_ganz:'Krank o.A.',krank_attest:'Krank m.A.',urlaub_halb:'Urlaub \u00bd',urlaub_ganz:'Urlaub (b.)',urlaub_genehmigt:'Urlaub (g.)',gleitzeit:'Gleitzeit',gesperrt:'Gesperrt'};
var AC={krank_halb:'#ef4444',krank_ganz:'#dc2626',krank_attest:'#16a34a',urlaub_halb:'#fbbf24',urlaub_ganz:'#dc2626',urlaub_genehmigt:'#16a34a',gleitzeit:'#8b5cf6',gesperrt:'#94a3b8'};
var AF={urlaub_halb:'#000'};
var RC={pending:'#f59e0b',approved:'#16a34a',rejected:'#dc2626'};
var allData=[],rejIds=[];
var ROLE_COLORS={manager:'#2563eb',teamleiter:'#4f46e5',mitarbeiter:'#64748b',verwaltung:'#d97706',lager:'#059669',azubi_1:'#16a34a',azubi_2:'#16a34a',azubi_3:'#16a34a',admin:'#dc2626'};

function setStatus(s){document.getElementById('statusFilter').value=s;loadRequests();}
function avatarBg(role){return ROLE_COLORS[role]||'#64748b';}
function initials(fn,ln){return((fn||'').charAt(0)+(ln||'').charAt(0)).toUpperCase();}

function loadRequests(){
    var st=document.getElementById('statusFilter').value,el=document.getElementById('reqList');
    el.innerHTML='<p class="muted" style="padding:2rem;text-align:center">Laden...</p>';
    document.getElementById('batchBar').style.display=(st==='pending')?'flex':'none';
    /* Stats */
    fetch(getApiBase()+'api/change_requests',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'dashboard_stats'})})
    .then(function(r){return r.json();}).then(function(d){
        if(!d.success)return;
        document.getElementById('csP').textContent=d.to_approve||0;
        document.getElementById('csA').textContent=d.total_approved||0;
        document.getElementById('csR').textContent=d.total_rejected||0;
        document.querySelectorAll('.ap-stat').forEach(function(s){s.classList.remove('active');});
        var m={pending:'.ap-stat-pending',approved:'.ap-stat-approved',rejected:'.ap-stat-rejected'};
        var a=document.querySelector(m[st]);if(a)a.classList.add('active');
    }).catch(function(){});
    /* Data */
    fetch(getApiBase()+'api/change_requests',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'list',status:st})})
    .then(function(r){return r.json();}).then(function(d){
        if(!d.success){el.innerHTML='<p class="txt-d">Fehler</p>';return;}
        allData=d.requests||[];
        if(!allData.length){el.innerHTML='<div class="ap-empty"><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg><p>Keine '+(st==='pending'?'offenen':st==='approved'?'genehmigten':'abgelehnten')+' Antr\u00e4ge</p></div>';return;}
        renderCards(allData,st);
    }).catch(function(e){el.innerHTML='<p class="txt-d">'+esc(e.message)+'</p>';});
}

function renderCards(reqs,st){
    var el=document.getElementById('reqList'),byUser={};
    reqs.forEach(function(r){if(!byUser[r.user_id])byUser[r.user_id]={u:r,items:[]};byUser[r.user_id].items.push(r);});
    var html='';
    for(var uid in byUser){
        var g=byUser[uid],u=g.u,ranges=mergeRanges(g.items);
        html+='<div class="ap-user" data-name="'+esc((u.last_name+' '+u.first_name).toLowerCase())+'">';
        /* Head */
        html+='<div class="ap-user-head">';
        html+='<div class="ap-avatar" style="background:'+avatarBg(u.role)+'">'+initials(u.first_name,u.last_name)+'</div>';
        html+='<div class="ap-user-info"><div class="ap-user-name">'+esc(u.last_name+', '+u.first_name)+'</div><div class="ap-user-role">'+esc(rn(u.role))+' – '+g.items.length+' Tag(e)</div></div>';
        html+='<div class="ap-user-meta"><button class="btn btn-xs btn-outline" onclick="openDetail('+uid+')">Details</button></div>';
        html+='</div>';
        /* Body: timeline blocks */
        html+='<div class="ap-body">';
        ranges.forEach(function(rng){
            var ds=fd(rng.items[0].plan_date);if(rng.items.length>1)ds=fd(rng.items[0].plan_date)+' \u2013 '+fd(rng.items[rng.items.length-1].plan_date);
            html+='<div class="ap-block">';
            html+='<div class="ap-block-line" style="background:'+rng.bg+'"></div>';
            html+='<div class="ap-block-content">';
            html+='<div class="ap-block-head">';
            html+='<span class="ap-block-label" style="background:'+rng.bg+';color:'+rng.fg+'">'+esc(rng.label)+'</span>';
            html+='<span class="ap-block-date">'+ds+'</span>';
            html+='<span class="ap-block-cnt">'+rng.items.length+' Tag(e)</span>';
            html+='</div>';
            if(rng.items[0].remarks)html+='<div class="ap-block-remark">'+esc(rng.items[0].remarks)+'</div>';
            if(st==='rejected'&&rng.items[0].rejection_reason)html+='<div class="ap-block-rej">Grund: '+esc(rng.items[0].rejection_reason)+'</div>';
            html+='</div></div>';
        });
        html+='</div>';
        /* Footer */
        if(st==='pending'){
            var ids=g.items.map(function(r){return r.id;});
            html+='<div class="ap-foot">';
            html+='<label class="ap-foot-sel"><input type="checkbox" class="cr-cb" value="'+ids.join(',')+'" onchange="updBatch()"></label>';
            html+='<button class="btn btn-xs btn-approve" onclick="doApprove(['+ids.join(',')+'])">Genehmigen</button>';
            html+='<button class="btn btn-xs btn-outline btn-danger-text" onclick="startReject(['+ids.join(',')+'])">Ablehnen</button>';
            html+='</div>';
        }
        if(st!=='pending'&&u.app_first){html+='<div class="ap-foot"><span class="ap-foot-info">Bearbeitet von '+esc(u.app_first+' '+u.app_last)+'</span></div>';}
        html+='</div>';
    }
    el.innerHTML=html;
}

function mergeRanges(items){items.sort(function(a,b){return a.plan_date.localeCompare(b.plan_date);});var ranges=[],cur=null;items.forEach(function(r){var key=(r.area_code||'')+'|'+(r.absence_type||'');var lb='',bg='',fg='#fff';if(r.area_code){lb=r.area_code;bg=r.area_color||'';fg=r.area_fg||'#fff';}else if(r.absence_type){lb=AN[r.absence_type]||r.absence_type;bg=AC[r.absence_type]||'#ef4444';fg=AF[r.absence_type]||'#fff';}if(cur&&cur.key===key&&isNext(cur.last,r.plan_date)){cur.items.push(r);cur.last=r.plan_date;}else{if(cur)ranges.push(cur);cur={key:key,label:lb,bg:bg,fg:fg,items:[r],last:r.plan_date};}});if(cur)ranges.push(cur);return ranges;}
function isNext(d1,d2){var a=new Date(d1+'T12:00:00'),b=new Date(d2+'T12:00:00'),diff=(b-a)/864e5;return diff>=1&&diff<=3;}
function fd(d){var p=d.split('-');return p[2]+'.'+p[1]+'.'+p[0];}
function rn(r){return{admin:'Admin',mitarbeiter:'Techniker',teamleiter:'Teamleiter',manager:'Manager',verwaltung:'Verwaltung',lager:'Lager',azubi_1:'Azubi 1',azubi_2:'Azubi 2',azubi_3:'Azubi 3'}[r]||r;}
function gwd(d){return['So','Mo','Di','Mi','Do','Fr','Sa'][new Date(d+'T12:00:00').getDay()];}

/* Detail Modal */
function openDetail(uid){
    var reqs=allData.filter(function(r){return r.user_id==uid;});if(!reqs.length)return;
    var u=reqs[0],st=document.getElementById('statusFilter').value,isPending=(st==='pending');
    document.getElementById('detTitle').textContent=u.last_name+', '+u.first_name;
    document.getElementById('detUser').innerHTML='<div style="display:flex;align-items:center;gap:.55rem"><div class="ap-avatar" style="background:'+avatarBg(u.role)+';width:32px;height:32px;font-size:.6rem">'+initials(u.first_name,u.last_name)+'</div><div><b style="font-size:.88rem">'+esc(u.last_name+', '+u.first_name)+'</b><div style="font-size:.72rem;color:var(--mu)">'+esc(rn(u.role))+' – '+reqs.length+' Tag(e)</div></div></div>';
    /* Blocks with individual days */
    var ranges=mergeRanges(reqs),dh='';
    ranges.forEach(function(rng){
        var ds=fd(rng.items[0].plan_date);if(rng.items.length>1)ds=fd(rng.items[0].plan_date)+' \u2013 '+fd(rng.items[rng.items.length-1].plan_date);
        dh+='<div class="apd-block">';
        dh+='<div class="apd-block-head"><span class="ap-block-label" style="background:'+rng.bg+';color:'+rng.fg+'">'+esc(rng.label)+'</span><b style="font-size:.84rem">'+ds+'</b><span class="muted" style="font-size:.7rem">('+rng.items.length+')</span>';
        if(rng.items[0].remarks)dh+='<span class="muted" style="font-size:.7rem;margin-left:auto;font-style:italic">'+esc(rng.items[0].remarks)+'</span>';
        dh+='</div>';
        rng.items.forEach(function(item){
            dh+='<div class="apd-day"><span class="apd-day-wd">'+gwd(item.plan_date)+'</span><span class="apd-day-date">'+fd(item.plan_date)+'</span>';
            dh+='<span class="ap-block-label" style="background:'+rng.bg+';color:'+rng.fg+';font-size:.6rem;padding:.05rem .25rem">'+esc(rng.label)+'</span>';
            if(item.remarks)dh+='<span class="muted" style="font-size:.7rem;max-width:100px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="'+esc(item.remarks)+'">'+esc(item.remarks)+'</span>';
            if(isPending)dh+='<span class="apd-day-acts"><button class="btn btn-xs btn-approve" onclick="doApproveSingle('+item.id+')" title="Genehmigen">Ja</button><button class="btn btn-xs btn-outline btn-danger-text" onclick="doRejectSingle('+item.id+')" title="Ablehnen">Nein</button></span>';
            if(st==='rejected'&&item.rejection_reason)dh+='<span style="font-size:.68rem;color:var(--err);margin-left:auto">'+esc(item.rejection_reason)+'</span>';
            dh+='</div>';
        });
        if(isPending&&rng.items.length>1){var bids=rng.items.map(function(i){return i.id;});dh+='<div class="apd-block-foot"><button class="btn btn-xs btn-approve" onclick="doApprove(['+bids.join(',')+'])">Block ('+rng.items.length+')</button><button class="btn btn-xs btn-outline btn-danger-text" onclick="startReject(['+bids.join(',')+'])">Ablehnen</button></div>';}
        dh+='</div>';
    });
    document.getElementById('detItems').innerHTML=dh;
    /* Week context */
    var wel=document.getElementById('detWeek');wel.innerHTML='';
    fetch(getApiBase()+'api/change_requests',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'week_context',user_id:parseInt(uid),date:reqs[0].plan_date})})
    .then(function(r){return r.json();}).then(function(d){
        if(!d.success){wel.innerHTML='';return;}
        var reqMap={};reqs.forEach(function(rr){var lb='',bg='',fg='#fff';if(rr.area_code){lb=rr.area_code;bg=rr.area_color;fg=rr.area_fg||'#fff';}else if(rr.absence_type){lb=AN[rr.absence_type]||'';bg=AC[rr.absence_type]||'';fg=AF[rr.absence_type]||'#fff';}reqMap[rr.plan_date]={l:lb,b:bg,f:fg};});
        var wh='<div class="apd-week"><div class="apd-week-title">Wochenkontext</div><div class="apd-week-grid">';
        d.cells.forEach(function(c){var isReq=!!reqMap[c.date];wh+='<div><div class="apd-week-cell apd-week-cell-head" style="'+(isReq?'background:#fef3c7':'')+'">'+gwd(c.date)+' '+parseInt(c.date.split('-')[2])+'.</div>';
        wh+='<div class="apd-week-cell apd-week-cell-cur" style="'+(c.bg?'background:'+c.bg+';color:'+(c.fg||'#fff'):'')+'">'+esc(c.label||'\u2013')+'</div>';
        var rq=reqMap[c.date];wh+='<div class="apd-week-cell '+(rq?'apd-week-cell-req':'')+'" style="'+(rq?'background:'+rq.b+';color:'+rq.f:'opacity:.15')+'">'+esc(rq?rq.l:'\u2013')+'</div></div>';});
        wh+='</div></div>';wel.innerHTML=wh;
    }).catch(function(){});
    var allIds=reqs.map(function(r){return r.id;});
    document.getElementById('detApproveBtn').style.display=isPending?'':'none';
    document.getElementById('detRejectBtn').style.display=isPending?'':'none';
    document.getElementById('detApproveBtn').onclick=function(){cMod('detMod');doApprove(allIds);};
    document.getElementById('detRejectBtn').onclick=function(){cMod('detMod');startReject(allIds);};
    oMod('detMod');
}

function doApproveSingle(id){epConfirm('Tag genehmigen?',function(){fetch(getApiBase()+'api/change_requests',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'approve_batch',ids:[id]})}).then(function(r){return r.json();}).then(function(d){if(d.success){toast(d.msg,'success');cMod('detMod');loadRequests();}else toast(d.error||'Fehler','error');}).catch(function(e){toast(e.message,'error');});});}
function doRejectSingle(id){rejIds=[id];document.getElementById('rejInfo').textContent='1 Tag ablehnen';document.getElementById('rejReason').value='';oMod('rejMod');}
function doApprove(ids){epConfirm(ids.length+' genehmigen?',function(){fetch(getApiBase()+'api/change_requests',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'approve_batch',ids:ids})}).then(function(r){return r.json();}).then(function(d){if(d.success){toast(d.msg,'success');cMod('detMod');loadRequests();}else toast(d.error||'Fehler','error');}).catch(function(e){toast(e.message,'error');});});}
function startReject(ids){rejIds=ids;document.getElementById('rejInfo').textContent=ids.length+' ablehnen';document.getElementById('rejReason').value='';oMod('rejMod');}
function doReject(){fetch(getApiBase()+'api/change_requests',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'reject_batch',ids:rejIds,reason:document.getElementById('rejReason').value.trim()})}).then(function(r){return r.json();}).then(function(d){if(d.success){toast(d.msg,'success');cMod('rejMod');cMod('detMod');loadRequests();}else toast(d.error||'Fehler','error');}).catch(function(e){toast(e.message,'error');});}
function toggleAll(){var c=document.getElementById('batchAll').checked;document.querySelectorAll('.cr-cb').forEach(function(cb){cb.checked=c;});updBatch();}
function updBatch(){var n=0;document.querySelectorAll('.cr-cb:checked').forEach(function(){n++;});document.getElementById('batchCnt').textContent=n;document.getElementById('btnBA').disabled=(n===0);document.getElementById('btnBR').disabled=(n===0);}
function batchAct(type){var ids=[];document.querySelectorAll('.cr-cb:checked').forEach(function(cb){cb.value.split(',').forEach(function(v){ids.push(parseInt(v));});});if(!ids.length)return;if(type==='approve')doApprove(ids);else startReject(ids);}
function filterCards(){var q=(document.getElementById('crSearch').value||'').toLowerCase();document.querySelectorAll('.ap-user').forEach(function(c){c.style.display=(!q||c.dataset.name.indexOf(q)!==-1)?'':'none';});}
document.addEventListener('DOMContentLoaded',loadRequests);
</script>
<?php require_once __DIR__.'/../includes/footer.php'; ?>
