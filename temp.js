
    if (localStorage.getItem('zzz_theme') === 'dark' || (!('zzz_theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
        document.documentElement.classList.add('dark');
    } else {
        document.documentElement.classList.remove('dark');
    }
  
// ══════════════════════════════════════════════════════════════
//  EXPORT CSV
// ══════════════════════════════════════════════════════════════
async function exportUsersCSV(){
  showToast('Preparing CSV...','success');
  const data=await adminFetch('/api/admin/users.php');
  if(!data||!data.success){showToast('Export failed','error');return;}
  const users=data.data;
  const headers=['ID','Name','Email','Phone','Role','Verified','Active','City','State','Joined','Active Listings'];
  const rows=users.map(u=>[u.id,u.name,u.email,u.phone||'',u.role,u.is_verified?'Yes':'No',u.is_active?'Yes':'No',u.city||'',u.state||'',(u.created_at||'').substring(0,10),u.active_listings]);
  const csv=[headers,...rows].map(r=>r.map(v=>'"'+String(v).replace(/"/g,'""')+'"').join(',')).join('\n');
  const blob=new Blob([csv],{type:'text/csv'});
  const a=document.createElement('a');
  a.href=URL.createObjectURL(blob);
  a.download='zzz_users_'+new Date().toISOString().substring(0,10)+'.csv';
  document.body.appendChild(a);a.click();document.body.removeChild(a);
  logAction('EXPORT_CSV','Exported '+users.length+' users');
  showToast('Downloaded '+users.length+' users as CSV!','success');
}

// ══════════════════════════════════════════════════════════════
//  AI MODERATION RULES
// ══════════════════════════════════════════════════════════════
async function saveAIRules(){
  const keywords=document.getElementById('autoRejectKeywords')?.value||'';
  const r=await adminPost('/api/admin/settings.php',{settings:{ai_blacklist:keywords}});
  if(r&&r.success){showToast('Z.O.I Nexus rules deployed!','success');logAction('AI_RULES','Keyword blacklist updated');}
  else showToast(r?.error||'Error saving AI rules','error');
}

// ══════════════════════════════════════════════════════════════
//  DISMISS REPORT
// ══════════════════════════════════════════════════════════════
async function dismissReport(id){
  if(!id) return;
  const r=await adminPut('/api/admin/reports.php',{id,status:'dismissed'});
  if(r&&r.success){showToast('Report dismissed','success');loadReports();}
  else showToast(r?.error||'Error dismissing','error');
}

// ══════════════════════════════════════════════════════════════
//  TOGGLE COUPON
// ══════════════════════════════════════════════════════════════
async function toggleCoupon(code){
  const r=await adminPut('/api/admin/coupons.php',{code});
  if(r&&r.success){loadCoupons();showToast('Coupon status updated','success');}
  else showToast(r?.error||'Error','error');
}

