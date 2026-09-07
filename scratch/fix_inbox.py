import os
import re

base_dir = "d:/zipzapzoi/ZIpZapZoi Codes/"

def fix_inbox():
    path = os.path.join(base_dir, "Inbox.html")
    with open(path, "r", encoding="utf-8") as f:
        content = f.read()

    # Gen Z features:
    # 1. typing indicator CSS
    typing_css = """
    /* Gen Z Typing Indicator CSS */
    .typing-dots {
      display: none;
      align-items: center;
      gap: 4px;
      margin-left: 10px;
    }
    .typing-dots span {
      width: 6px;
      height: 6px;
      background-color: #019863;
      border-radius: 50%;
      animation: typing 1.4s infinite ease-in-out both;
    }
    .typing-dots span:nth-child(1) { animation-delay: -0.32s; }
    .typing-dots span:nth-child(2) { animation-delay: -0.16s; }
    @keyframes typing {
      0%, 80%, 100% { transform: scale(0); }
      40% { transform: scale(1); }
    }
    #msgInput:focus + .typing-dots, #msgInput:active + .typing-dots {
      display: flex;
    }
    """
    if "typing-dots" not in content:
        content = content.replace("</style>", typing_css + "\n  </style>")

    # Add typing dots element
    typing_dots_html = '<div class="typing-dots" id="typingDots"><span></span><span></span><span></span></div>'
    if "typingDots" not in content:
        content = content.replace('id="msgInput"', f'id="msgInput" oninput="showTyping()"')
        # We need to show typing indicator when typing in input
        # The prompt says CSS only - just show dots when user is typing in input.
        # But maybe we can use JS to toggle it, or :focus/:not(:placeholder-shown) + .typing-dots
        # Let's use `:not(:placeholder-shown)` if possible.
        typing_css_updated = """
    /* Gen Z Typing Indicator CSS */
    .typing-dots-container { position: absolute; left: 10px; bottom: 60px; display: none; }
    #msgInput:not(:placeholder-shown) ~ .typing-dots-container {
      display: flex;
    }
    .typing-dots {
      display: flex;
      align-items: center;
      gap: 4px;
      padding: 10px 14px;
      background: #f1f5f9;
      border-radius: 18px 18px 18px 4px;
      width: fit-content;
    }
    html.dark .typing-dots { background: #334155; }
    .typing-dots span {
      width: 6px;
      height: 6px;
      background-color: #019863;
      border-radius: 50%;
      animation: typing 1.4s infinite ease-in-out both;
    }
    .typing-dots span:nth-child(1) { animation-delay: -0.32s; }
    .typing-dots span:nth-child(2) { animation-delay: -0.16s; }
    @keyframes typing {
      0%, 80%, 100% { transform: scale(0); }
      40% { transform: scale(1); }
    }
    """
        content = re.sub(r'/\* Gen Z Typing Indicator CSS \*/.*?</style>', typing_css_updated + '\n  </style>', content, flags=re.DOTALL)
        if "typing-dots-container" not in content:
            content = content.replace("</style>", typing_css_updated + "\n  </style>")

        input_area = '<div class="typing-dots-container"><div class="typing-dots"><span></span><span></span><span></span></div></div>'
        content = content.replace('<div class="bg-white dark:bg-card-dark p-3 border-t', input_area + '\n        <div class="bg-white dark:bg-card-dark p-3 border-t')

    # Emoji reactions
    if "addReaction(" not in content:
        reaction_js = """
    function addReaction(msgId, emoji) {
        let reactions = JSON.parse(localStorage.getItem('zzz_reactions') || '{}');
        if(!reactions[msgId]) reactions[msgId] = [];
        if(!reactions[msgId].includes(emoji)) {
            reactions[msgId].push(emoji);
            localStorage.setItem('zzz_reactions', JSON.stringify(reactions));
            const msgDiv = document.getElementById('msg-'+msgId);
            if(msgDiv) {
                let rDiv = msgDiv.querySelector('.reactions-container');
                if(!rDiv) {
                    rDiv = document.createElement('div');
                    rDiv.className = 'reactions-container flex gap-1 mt-1';
                    msgDiv.querySelector('.msg-bubble').appendChild(rDiv);
                }
                rDiv.innerHTML += '<span class="text-xs bg-gray-100 dark:bg-gray-800 rounded-full px-1">' + emoji + '</span>';
            }
        }
    }
    function toggleReactionMenu(msgId) {
        const menu = document.getElementById('react-menu-'+msgId);
        if(menu) {
            menu.classList.toggle('hidden');
        }
    }
        """
        content = content.replace("function esc(s) {", reaction_js + "\n    function esc(s) {")

        append_message_fix = """
    function appendMessage(m, scrollToBottom = true) {
      const feed   = document.getElementById('messagesFeed');
      if (feed.querySelector('.py-20')) {
        feed.innerHTML = '';
      }
      const isMe = String(m.from_user_id) === String(currentUser.id);
      const side = isMe
        ? 'bg-bubble-me dark:bg-primary text-gray-800 dark:text-white rounded-tr-none'
        : 'bg-white dark:bg-card-dark text-gray-800 dark:text-white rounded-tl-none';
      const time = new Date(m.created_at).toLocaleTimeString([], { hour:'2-digit', minute:'2-digit' });
      
      const reactions = JSON.parse(localStorage.getItem('zzz_reactions') || '{}')[m.id] || [];
      const reactionsHtml = reactions.map(e => '<span class="text-xs bg-gray-100 dark:bg-gray-800 rounded-full px-1">'+e+'</span>').join('');
      
      const reactMenu = `
        <div id="react-menu-${m.id}" class="hidden absolute top-0 ${isMe?'right-full mr-2':'left-full ml-2'} bg-white dark:bg-gray-800 rounded-full shadow border px-2 py-1 flex gap-1 z-50">
            <button onclick="addReaction('${m.id}', '👍')">👍</button>
            <button onclick="addReaction('${m.id}', '❤️')">❤️</button>
            <button onclick="addReaction('${m.id}', '😂')">😂</button>
            <button onclick="addReaction('${m.id}', '😮')">😮</button>
        </div>
      `;

      const div = document.createElement('div');
      div.className = 'flex w-full relative ' + (isMe?'justify-end':'justify-start') + ' slide-in';
      div.id = 'msg-' + m.id;
      div.innerHTML = '<div class="msg-bubble px-4 py-2 rounded-xl shadow-sm text-sm ' + side + '" ondblclick="toggleReactionMenu(\\''+m.id+'\\')">' +
        sanitizeInput(m.body || '') +
        (reactionsHtml ? '<div class="reactions-container flex gap-1 mt-1">' + reactionsHtml + '</div>' : '') +
        '<div class="msg-time ' + (isMe?'text-green-800 dark:text-green-100':'text-gray-400') + '">' + time +
        (isMe ? ' <span class="material-symbols-outlined text-[10px] text-blue-500 align-middle ml-1">done_all</span>' : '') +
        '</div>' + reactMenu + '</div>';
      feed.appendChild(div);
      if (scrollToBottom) feed.scrollTop = feed.scrollHeight;
    }
        """
        # Replacing old appendMessage
        content = re.sub(r'function appendMessage\(m, scrollToBottom = true\) \{.*?(?=// ─)', append_message_fix, content, flags=re.DOTALL)
        
    with open(path, "w", encoding="utf-8") as f:
        f.write(content)
    print("Fixed Inbox.html")

fix_inbox()
