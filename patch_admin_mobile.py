import re

with open('AdminConsoleMobile.html', 'r', encoding='utf-8') as f:
    content = f.read()

# 1. Add Manage Categories button
settings_insert = """      <div class="settings-row" onclick="openModal('listingModal')"><div class="s-icon"><span class="material-symbols-outlined mi">inventory_2</span></div><div class="s-body"><div class="s-title">Listing Config</div><div class="s-sub">Expiry, upload limits</div></div><span class="material-symbols-outlined" style="color:var(--muted);font-size:18px">chevron_right</span></div>
      <div class="settings-row" onclick="openModal('schemaModal')"><div class="s-icon" style="background:rgba(168,85,247,.15)"><span class="material-symbols-outlined mi" style="color:#a855f7">category</span></div><div class="s-body"><div class="s-title">Manage Categories</div><div class="s-sub">Add/Remove schema categories</div></div><span class="material-symbols-outlined" style="color:var(--muted);font-size:18px">chevron_right</span></div>"""

content = content.replace(
    '<div class="settings-row" onclick="openModal(\'listingModal\')"><div class="s-icon"><span class="material-symbols-outlined mi">inventory_2</span></div><div class="s-body"><div class="s-title">Listing Config</div><div class="s-sub">Expiry, upload limits</div></div><span class="material-symbols-outlined" style="color:var(--muted);font-size:18px">chevron_right</span></div>',
    settings_insert
)

# 2. Add Schema Modal HTML
modal_html = """
  <!-- Schema Modal -->
  <div class="modal-overlay" id="schemaModal">
    <div class="modal">
      <div class="modal-hdr">
        <h3>Manage Categories</h3>
        <button class="icon-btn" onclick="closeModal('schemaModal')"><span class="material-symbols-outlined">close</span></button>
      </div>
      <div class="modal-body" style="background:#f8fafc;">
        
        <div style="background:#fff;padding:12px;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,.05);margin-bottom:16px;">
          <h4 style="font-size:13px;font-weight:700;margin-bottom:8px;color:#334155;">Add New Category</h4>
          <input type="text" id="newCatId" placeholder="Category ID (e.g. books)" style="width:100%;padding:10px;border:1px solid #e2e8f0;border-radius:8px;font-size:14px;margin-bottom:8px;">
          <input type="text" id="newCatLabel" placeholder="Display Label (e.g. Books & Mags)" style="width:100%;padding:10px;border:1px solid #e2e8f0;border-radius:8px;font-size:14px;margin-bottom:8px;">
          <input type="text" id="newCatIcon" placeholder="Google Material Icon name (e.g. menu_book)" style="width:100%;padding:10px;border:1px solid #e2e8f0;border-radius:8px;font-size:14px;margin-bottom:12px;">
          <button onclick="addMobileCategory()" style="width:100%;background:var(--primary);color:#fff;border:none;padding:12px;border-radius:8px;font-weight:700;font-size:14px;">+ Add Category</button>
        </div>

        <h4 style="font-size:13px;font-weight:700;margin-bottom:8px;color:#334155;">Existing Categories</h4>
        <div id="mobileCatList" style="display:flex;flex-direction:column;gap:8px;"></div>
      </div>
    </div>
  </div>
"""

# Insert modal before <div id="toastBox">
content = content.replace('<div id="toastBox"></div>', modal_html + '\n  <div id="toastBox"></div>')

# 3. Add JS logic for schema
js_logic = """
  let mobileSchemaBrain = {categories:[], subcategories:[], fields:[]};
  
  async function loadMobileSchema() {
    try {
      const r = await fetch('/api/schema.php');
      const d = await r.json();
      if(d.success && d.data) {
         mobileSchemaBrain = d.data;
      }
    } catch(e) {}
  }

  window.renderMobileCategories = function() {
    const lst = document.getElementById('mobileCatList');
    if(!lst) return;
    lst.innerHTML = mobileSchemaBrain.categories.map(c => `
      <div style="display:flex;align-items:center;justify-content:space-between;background:#fff;padding:12px;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,.05);">
        <div style="display:flex;align-items:center;gap:12px;">
           <span class="material-symbols-outlined" style="color:#64748b;">${c.icon||'category'}</span>
           <div>
             <div style="font-weight:700;font-size:14px;color:#0f172a;">${c.label}</div>
             <div style="font-size:11px;color:#64748b;">ID: ${c.id}</div>
           </div>
        </div>
        <button onclick="deleteMobileCategory('${c.id}')" style="background:rgba(239,68,68,.1);color:#ef4444;border:none;width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;"><span class="material-symbols-outlined" style="font-size:18px;">delete</span></button>
      </div>
    `).join('');
  }

  async function saveMobileSchema() {
    try {
      const res = await fetch('/api/schema.php', {
        method: 'POST',
        headers:{'Content-Type':'application/json'},
        body: JSON.stringify(mobileSchemaBrain)
      });
      const d = await res.json();
      if(d.success) showToast('Schema updated successfully');
      else showToast('Error saving schema');
    } catch(e) { showToast('Network error'); }
  }

  window.addMobileCategory = function() {
    const id = document.getElementById('newCatId').value.trim();
    const lbl = document.getElementById('newCatLabel').value.trim();
    const ico = document.getElementById('newCatIcon').value.trim();
    if(!id || !lbl) return showToast('ID and Label required');
    if(mobileSchemaBrain.categories.find(c => c.id === id)) return showToast('ID already exists');
    
    mobileSchemaBrain.categories.push({
      id: id, label: lbl, icon: ico || 'category', color: 'text-gray-500 dark:text-gray-400'
    });
    
    document.getElementById('newCatId').value = '';
    document.getElementById('newCatLabel').value = '';
    document.getElementById('newCatIcon').value = '';
    window.renderMobileCategories();
    saveMobileSchema();
  };

  window.deleteMobileCategory = function(id) {
    if(!confirm('Delete this category?')) return;
    mobileSchemaBrain.categories = mobileSchemaBrain.categories.filter(c => c.id !== id);
    window.renderMobileCategories();
    saveMobileSchema();
  };

  // Intercept openModal to load schema when schemaModal opens
  const origOpenModal = window.openModal;
  window.openModal = async function(id) {
    if(id === 'schemaModal') {
      await loadMobileSchema();
      window.renderMobileCategories();
    }
    origOpenModal(id);
  };
"""

content = content.replace('// INIT', js_logic + '\n  // INIT')

with open('AdminConsoleMobile.html', 'w', encoding='utf-8') as f:
    f.write(content)
