/**
 * Zoi AI Widget — Floating chat assistant for ZipZapZoi website
 * Synced with the Android app's Zoi AI feature
 * Powered by Gemini 1.5 Flash via secure PHP proxy (/api/zoi_ai.php)
 *
 * Drop-in: <script src="js/zoi-ai-widget.js" defer></script>
 */
(function () {
  'use strict';

  // ── Config ────────────────────────────────────────────────────────────────
  const API_ENDPOINT = '/api/zoi_ai.php';
  const STORAGE_KEY  = 'zoi_ai_chat_v2';
  const MAX_HISTORY  = 40;

  const MODES = [
    { id: 'general',     emoji: '🧠', label: 'General AI',       hint: 'Ask me anything — science, math, coding, history...',    color: '#7C3AED' },
    { id: 'classifieds', emoji: '🏪', label: 'Classifieds',       hint: 'Help with ZipZapZoi buying & selling...',                color: '#2563EB' },
    { id: 'ad_writer',   emoji: '✍️', label: 'Ad Writer',         hint: 'Describe your item and I\'ll write a perfect ad...',     color: '#059669' },
    { id: 'price_advisor',emoji: '💰',label: 'Price Advisor',     hint: 'Tell me your item and I\'ll estimate market price...',   color: '#D97706' },
    { id: 'negotiation', emoji: '🤝', label: 'Negotiation Coach', hint: 'Scripts and tactics for buying/selling negotiations...', color: '#DC2626' },
  ];

  const QUICK_ACTIONS = {
    general:      ['🧮 Solve: 3x² + 5x - 2 = 0', '💻 Write a Python web scraper', '🌍 Explain quantum entanglement', '📝 Write a professional email'],
    classifieds:  ['How do I post my first ad?', 'Which plan should I choose?', 'How to avoid scams?', 'Best categories to sell fast?'],
    ad_writer:    ['iPhone 13, 128GB, 1yr old, ₹45,000', 'Honda Activa 6G 2022, 15k km', 'Samsung 43" 4K Smart TV, 2yrs old'],
    price_advisor:['What\'s my iPhone 12 worth now?', 'Price for 2019 Maruti Swift?', 'Value of a 5yr old MacBook Pro?'],
    negotiation:  ['How to counter a lowball offer?', 'Buyer negotiation scripts', 'When to walk away from a deal?'],
  };

  // ── State ─────────────────────────────────────────────────────────────────
  let isOpen    = false;
  let isLoading = false;
  let currentMode = MODES[0];
  let messages  = [];
  let attachedImage = null; // { base64, mimeType, preview }

  // Load persisted history
  try {
    const saved = localStorage.getItem(STORAGE_KEY);
    if (saved) messages = JSON.parse(saved);
  } catch (_) {}

  // ── Inject CSS ────────────────────────────────────────────────────────────
  const style = document.createElement('style');
  style.textContent = `
    #zai-fab { position:fixed; bottom:24px; right:24px; z-index:9999; width:56px; height:56px;
      border-radius:50%; background:linear-gradient(135deg,#7C3AED,#4F46E5); border:none; cursor:pointer;
      box-shadow:0 4px 20px rgba(124,58,237,.5); display:flex; align-items:center; justify-content:center;
      font-size:24px; transition:transform .2s; color:#fff; }
    #zai-fab:hover { transform:scale(1.1); }
    #zai-fab .zai-badge { position:absolute; top:-4px; right:-4px; background:#ef4444; color:#fff;
      border-radius:50%; width:18px; height:18px; font-size:11px; display:flex; align-items:center;
      justify-content:center; font-weight:700; display:none; }
    #zai-panel { position:fixed; bottom:92px; right:24px; z-index:9998; width:380px; max-width:calc(100vw - 32px);
      height:560px; max-height:calc(100vh - 120px); background:#fff; border-radius:20px;
      box-shadow:0 20px 60px rgba(0,0,0,.2); display:flex; flex-direction:column; overflow:hidden;
      transform:scale(0) translateY(20px); transform-origin:bottom right; opacity:0;
      transition:transform .25s cubic-bezier(.34,1.56,.64,1), opacity .2s; }
    #zai-panel.open { transform:scale(1) translateY(0); opacity:1; }
    @media (prefers-color-scheme:dark) {
      #zai-panel { background:#1e1b2e; color:#e2e8f0; }
      .zai-msg-ai .zai-bubble { background:#2d2b45; color:#e2e8f0; }
      .zai-input-row { background:#2d2b45; }
      #zai-input { background:#1e1b2e; color:#e2e8f0; border-color:#4a4570; }
      .zai-mode-btn { background:#2d2b45; border-color:#4a4570; color:#e2e8f0; }
    }
    .dark #zai-panel { background:#1e1b2e; color:#e2e8f0; }
    .dark .zai-msg-ai .zai-bubble { background:#2d2b45; color:#e2e8f0; }
    .dark .zai-input-row { background:#2d2b45; border-color:#4a4570; }
    .dark #zai-input { background:#1e1b2e; color:#e2e8f0; border-color:#4a4570; }
    #zai-header { padding:14px 16px; background:linear-gradient(135deg,#7C3AED,#4F46E5); color:#fff;
      display:flex; align-items:center; gap:10px; flex-shrink:0; }
    #zai-header .zai-avatar { width:38px; height:38px; border-radius:50%; background:rgba(255,255,255,.2);
      display:flex; align-items:center; justify-content:center; font-size:20px; flex-shrink:0; }
    #zai-header .zai-title { flex:1; }
    #zai-header .zai-title h3 { margin:0; font-size:15px; font-weight:700; }
    #zai-header .zai-title p { margin:0; font-size:11px; opacity:.8; }
    #zai-header .zai-close { background:none; border:none; color:#fff; cursor:pointer; font-size:20px;
      padding:4px; opacity:.8; transition:opacity .15s; }
    #zai-header .zai-close:hover { opacity:1; }
    #zai-mode-bar { display:flex; gap:6px; padding:8px 12px; overflow-x:auto; flex-shrink:0;
      border-bottom:1px solid #f0f0f0; scrollbar-width:none; }
    #zai-mode-bar::-webkit-scrollbar { display:none; }
    .zai-mode-chip { border:none; padding:4px 10px; border-radius:20px; font-size:11px; cursor:pointer;
      white-space:nowrap; font-weight:600; transition:all .15s; flex-shrink:0; }
    #zai-messages { flex:1; overflow-y:auto; padding:12px; display:flex; flex-direction:column; gap:8px;
      scrollbar-width:thin; }
    .zai-msg { display:flex; gap:8px; max-width:100%; }
    .zai-msg-user { flex-direction:row-reverse; }
    .zai-msg-user .zai-bubble { border-radius:18px 18px 4px 18px; color:#fff; }
    .zai-msg-ai .zai-bubble { border-radius:18px 18px 18px 4px; background:#f3f4f6; color:#1a1a2e; }
    .zai-bubble { padding:10px 14px; font-size:13.5px; line-height:1.55; max-width:280px;
      word-break:break-word; white-space:pre-wrap; }
    .zai-avatar-sm { width:28px; height:28px; border-radius:50%; background:linear-gradient(135deg,#7C3AED,#4F46E5);
      display:flex; align-items:center; justify-content:center; font-size:14px; flex-shrink:0; align-self:flex-end; }
    .zai-time { font-size:10px; opacity:.45; margin-top:2px; }
    .zai-msg-user .zai-time { text-align:right; }
    .zai-empty { text-align:center; padding:16px 12px; }
    .zai-empty .zai-hero { font-size:48px; margin-bottom:8px; }
    .zai-quick { display:flex; flex-direction:column; gap:6px; margin-top:12px; }
    .zai-quick-btn { background:#f8f4ff; border:1px solid #e0d4ff; border-radius:10px; padding:8px 12px;
      font-size:12px; cursor:pointer; text-align:left; color:#4c1d95; transition:background .15s; }
    .zai-quick-btn:hover { background:#ede9fe; }
    .dark .zai-quick-btn { background:#2d2b45; border-color:#4a4570; color:#c4b5fd; }
    .zai-typing { display:flex; gap:4px; align-items:center; padding:10px 14px; }
    .zai-dot { width:7px; height:7px; border-radius:50%; background:#7C3AED; animation:zai-bounce .6s infinite; }
    .zai-dot:nth-child(2) { animation-delay:.15s; } .zai-dot:nth-child(3) { animation-delay:.3s; }
    @keyframes zai-bounce { 0%,100%{transform:translateY(0)} 50%{transform:translateY(-6px)} }
    .zai-input-row { display:flex; gap:6px; padding:10px 12px; border-top:1px solid #f0f0f0;
      flex-shrink:0; align-items:flex-end; }
    #zai-input { flex:1; border:1.5px solid #e5e7eb; border-radius:14px; padding:8px 12px; font-size:13.5px;
      resize:none; outline:none; max-height:100px; font-family:inherit; transition:border-color .15s; }
    #zai-input:focus { border-color:#7C3AED; }
    .zai-btn { background:none; border:none; cursor:pointer; padding:6px; border-radius:8px;
      display:flex; align-items:center; justify-content:center; font-size:18px; transition:all .15s; flex-shrink:0; }
    .zai-btn:hover { background:#f3f0ff; }
    .zai-send { background:linear-gradient(135deg,#7C3AED,#4F46E5); color:#fff; width:36px; height:36px;
      border-radius:50%; border:none; cursor:pointer; display:flex; align-items:center; justify-content:center;
      font-size:16px; transition:transform .15s, opacity .15s; flex-shrink:0; }
    .zai-send:hover:not(:disabled) { transform:scale(1.1); }
    .zai-send:disabled { opacity:.4; cursor:default; }
    .zai-img-preview { background:#f3f0ff; padding:6px; border-radius:10px; display:flex;
      align-items:center; gap:8px; margin:0 12px 0; flex-shrink:0; }
    .zai-img-preview img { width:40px; height:40px; border-radius:6px; object-fit:cover; }
    .zai-img-preview span { font-size:12px; flex:1; color:#4c1d95; }
    .zai-img-preview button { background:none; border:none; cursor:pointer; color:#7C3AED; font-size:16px; }
    .zai-error .zai-bubble { background:#fee2e2; color:#991b1b; }
    .zai-copy-tip { font-size:10px; opacity:.4; margin-top:2px; display:none; }
    .zai-bubble:hover + .zai-copy-tip { display:block; }
  `;
  document.head.appendChild(style);

  // ── Build HTML ────────────────────────────────────────────────────────────
  const wrapper = document.createElement('div');
  wrapper.innerHTML = `
    <button id="zai-fab" title="Chat with Zoi AI" aria-label="Zoi AI">
      ✨<span class="zai-badge" id="zai-badge">!</span>
    </button>
    <div id="zai-panel" role="dialog" aria-label="Zoi AI Chat" aria-modal="true">
      <div id="zai-header">
        <div class="zai-avatar" id="zai-header-emoji">🧠</div>
        <div class="zai-title">
          <h3>Zoi AI ✨</h3>
          <p id="zai-mode-label">General AI Mode</p>
        </div>
        <button class="zai-close" id="zai-clear" title="Clear conversation">🗑️</button>
        <button class="zai-close" id="zai-close" title="Close" aria-label="Close Zoi AI">✕</button>
      </div>
      <div id="zai-mode-bar"></div>
      <div id="zai-img-preview" style="display:none" class="zai-img-preview">
        <img id="zai-img-thumb" src="" alt=""/>
        <span>Image attached</span>
        <button id="zai-img-remove" title="Remove image">✕</button>
      </div>
      <div id="zai-messages"></div>
      <div class="zai-input-row">
        <label class="zai-btn" title="Attach image" style="cursor:pointer">
          📎<input type="file" accept="image/*" id="zai-img-input" style="display:none"/>
        </label>
        <button class="zai-btn" id="zai-mic" title="Voice input (click & speak)">🎤</button>
        <textarea id="zai-input" placeholder="Ask Zoi AI anything..." rows="1"></textarea>
        <button class="zai-send" id="zai-send" disabled title="Send">➤</button>
      </div>
    </div>
  `;
  document.body.appendChild(wrapper);

  // ── Refs ──────────────────────────────────────────────────────────────────
  const fab        = document.getElementById('zai-fab');
  const panel      = document.getElementById('zai-panel');
  const closeBtn   = document.getElementById('zai-close');
  const clearBtn   = document.getElementById('zai-clear');
  const msgBox     = document.getElementById('zai-messages');
  const input      = document.getElementById('zai-input');
  const sendBtn    = document.getElementById('zai-send');
  const modeBar    = document.getElementById('zai-mode-bar');
  const modeLabel  = document.getElementById('zai-mode-label');
  const headerEmoji= document.getElementById('zai-header-emoji');
  const micBtn     = document.getElementById('zai-mic');
  const imgInput   = document.getElementById('zai-img-input');
  const imgPreview = document.getElementById('zai-img-preview');
  const imgThumb   = document.getElementById('zai-img-thumb');
  const imgRemove  = document.getElementById('zai-img-remove');

  // ── Mode bar ──────────────────────────────────────────────────────────────
  MODES.forEach(mode => {
    const btn = document.createElement('button');
    btn.className = 'zai-mode-chip';
    btn.textContent = mode.emoji + ' ' + mode.label;
    btn.style.background = mode === currentMode ? mode.color : '#f3f4f6';
    btn.style.color = mode === currentMode ? '#fff' : '#374151';
    btn.onclick = () => switchMode(mode);
    btn.dataset.modeId = mode.id;
    modeBar.appendChild(btn);
  });

  function switchMode(mode) {
    currentMode = mode;
    headerEmoji.textContent = mode.emoji;
    modeLabel.textContent = mode.label + ' Mode';
    modeBar.querySelectorAll('.zai-mode-chip').forEach(b => {
      const m = MODES.find(m => m.id === b.dataset.modeId);
      b.style.background = m.id === mode.id ? mode.color : '#f3f4f6';
      b.style.color = m.id === mode.id ? '#fff' : '#374151';
    });
    input.placeholder = mode.hint;
    addMessage({ role: 'ai', text: `Switched to **${mode.label}** mode ${mode.emoji}. ${mode.hint}` });
  }

  // ── Toggle panel ──────────────────────────────────────────────────────────
  function toggle() {
    isOpen = !isOpen;
    panel.classList.toggle('open', isOpen);
    fab.innerHTML = isOpen ? '✕<span class="zai-badge" id="zai-badge">!</span>' : '✨<span class="zai-badge" id="zai-badge">!</span>';
    if (isOpen) { renderAllMessages(); scrollBottom(); input.focus(); }
  }
  fab.onclick = toggle;
  closeBtn.onclick = () => { isOpen = false; panel.classList.remove('open'); fab.innerHTML = '✨<span class="zai-badge" id="zai-badge">!</span>'; };
  clearBtn.onclick = () => {
    if (!confirm('Clear Zoi AI conversation history?')) return;
    messages = []; localStorage.removeItem(STORAGE_KEY);
    renderAllMessages();
  };

  // ── Render ────────────────────────────────────────────────────────────────
  function renderAllMessages() {
    msgBox.innerHTML = '';
    if (!messages.length) {
      renderEmpty();
    } else {
      messages.forEach(m => renderBubble(m, false));
    }
    scrollBottom();
  }

  function renderEmpty() {
    const qs = (QUICK_ACTIONS[currentMode.id] || []).map(q =>
      `<button class="zai-quick-btn" onclick="window.zoiAiSend('${q.replace(/'/g, "\\'")}')">${q}</button>`
    ).join('');
    msgBox.innerHTML = `
      <div class="zai-empty">
        <div class="zai-hero">${currentMode.emoji}</div>
        <strong>Zoi AI — ${currentMode.label}</strong>
        <p style="font-size:12px;opacity:.6;margin:6px 0 0">${currentMode.hint}</p>
        <div class="zai-quick">${qs}</div>
      </div>`;
  }

  function renderBubble(msg, animate) {
    const isUser = msg.role === 'user';
    const isError = msg.error;
    const div = document.createElement('div');
    div.className = `zai-msg ${isUser ? 'zai-msg-user' : 'zai-msg-ai'} ${isError ? 'zai-error' : ''}`;
    div.dataset.msgId = msg.id;

    const bubble = document.createElement('div');
    bubble.className = 'zai-bubble';
    bubble.style.background = isUser ? currentMode.color : '';
    bubble.textContent = msg.text;

    const time = document.createElement('div');
    time.className = 'zai-time';
    time.textContent = formatTime(msg.ts);

    const inner = document.createElement('div');
    if (!isUser) {
      const av = document.createElement('div');
      av.className = 'zai-avatar-sm';
      av.textContent = currentMode.emoji;
      div.appendChild(av);
    }
    inner.appendChild(bubble);
    inner.appendChild(time);
    div.appendChild(inner);

    if (animate) { div.style.opacity = '0'; div.style.transform = 'translateY(10px)'; }
    msgBox.appendChild(div);
    if (animate) requestAnimationFrame(() => {
      div.style.transition = 'opacity .2s, transform .2s';
      div.style.opacity = '1'; div.style.transform = 'translateY(0)';
    });
    return div;
  }

  function addMessage(msg) {
    const full = { id: Date.now() + Math.random(), ts: Date.now(), ...msg };
    messages.push(full);
    if (messages.length > MAX_HISTORY) messages.shift();
    saveHistory();
    if (isOpen) { renderBubble(full, true); scrollBottom(); }
  }

  function scrollBottom() {
    requestAnimationFrame(() => { msgBox.scrollTop = msgBox.scrollHeight; });
  }

  function saveHistory() {
    try { localStorage.setItem(STORAGE_KEY, JSON.stringify(messages)); } catch (_) {}
  }

  function formatTime(ts) {
    return new Date(ts).toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit' });
  }

  // ── Send message ──────────────────────────────────────────────────────────
  window.zoiAiSend = function(text) { input.value = text; sendMessage(); };

  function sendMessage() {
    const text = input.value.trim();
    if ((!text && !attachedImage) || isLoading) return;

    addMessage({ role: 'user', text: text || '📷 Image attached' });
    input.value = '';
    input.style.height = 'auto';
    sendBtn.disabled = true;

    // Show typing
    const typingEl = document.createElement('div');
    typingEl.className = 'zai-msg zai-msg-ai';
    typingEl.id = 'zai-typing';
    typingEl.innerHTML = `<div class="zai-avatar-sm">${currentMode.emoji}</div>
      <div class="zai-bubble zai-typing"><div class="zai-dot"></div><div class="zai-dot"></div><div class="zai-dot"></div></div>`;
    if (isOpen) { msgBox.appendChild(typingEl); scrollBottom(); }

    isLoading = true;

    const payload = {
      messages: messages.slice(-20).map(m => ({ role: m.role === 'user' ? 'user' : 'model', text: m.text })),
      mode: currentMode.id,
    };
    if (attachedImage) {
      payload.image    = attachedImage.base64;
      payload.mimeType = attachedImage.mimeType;
      clearAttachedImage();
    }

    fetch(API_ENDPOINT, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    })
    .then(r => r.json())
    .then(data => {
      const typingDom = document.getElementById('zai-typing');
      if (typingDom) typingDom.remove();
      if (data.success && data.data.reply) {
        addMessage({ role: 'ai', text: data.data.reply });
      } else {
        addMessage({ role: 'ai', text: '❌ ' + (data.message || 'Something went wrong. Please try again.'), error: true });
      }
    })
    .catch(err => {
      const typingDom = document.getElementById('zai-typing');
      if (typingDom) typingDom.remove();
      addMessage({ role: 'ai', text: '📡 Connection error. Please check your internet and try again.', error: true });
    })
    .finally(() => {
      isLoading = false;
      sendBtn.disabled = !input.value.trim();
      scrollBottom();
    });
  }

  sendBtn.onclick = sendMessage;
  input.addEventListener('input', function () {
    this.style.height = 'auto';
    this.style.height = Math.min(this.scrollHeight, 100) + 'px';
    sendBtn.disabled = !this.value.trim() && !attachedImage;
  });
  input.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); }
  });

  // ── Image attach ──────────────────────────────────────────────────────────
  imgInput.onchange = function () {
    const file = this.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = function (e) {
      const dataUrl = e.target.result;
      const base64  = dataUrl.split(',')[1];
      attachedImage = { base64, mimeType: file.type, preview: dataUrl };
      imgThumb.src  = dataUrl;
      imgPreview.style.display = 'flex';
      sendBtn.disabled = false;
    };
    reader.readAsDataURL(file);
    this.value = '';
  };
  imgRemove.onclick = clearAttachedImage;
  function clearAttachedImage() {
    attachedImage = null;
    imgPreview.style.display = 'none';
    sendBtn.disabled = !input.value.trim();
  }

  // ── Voice input (Web Speech API) ──────────────────────────────────────────
  let recognition = null;
  if ('webkitSpeechRecognition' in window || 'SpeechRecognition' in window) {
    const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
    recognition = new SR();
    recognition.lang = 'en-IN';
    recognition.interimResults = false;
    recognition.maxAlternatives = 1;
    recognition.onresult = (e) => {
      input.value = e.results[0][0].transcript;
      input.dispatchEvent(new Event('input'));
      micBtn.textContent = '🎤';
      micBtn.title = 'Voice input';
    };
    recognition.onerror = () => { micBtn.textContent = '🎤'; };
    recognition.onend   = () => { micBtn.textContent = '🎤'; };
    micBtn.onclick = () => {
      if (micBtn.textContent === '🔴') { recognition.stop(); micBtn.textContent = '🎤'; }
      else { recognition.start(); micBtn.textContent = '🔴'; micBtn.title = 'Listening... click to stop'; }
    };
  } else {
    micBtn.style.display = 'none';
  }

  // ── Close on outside click ─────────────────────────────────────────────────
  document.addEventListener('click', (e) => {
    if (isOpen && !panel.contains(e.target) && e.target !== fab) {
      isOpen = false; panel.classList.remove('open');
      fab.innerHTML = '✨<span class="zai-badge" id="zai-badge">!</span>';
    }
  });

  // Initial render if panel is opened
  console.log('✨ Zoi AI widget loaded');
})();
