import os
import re

base_dir = "d:/zipzapzoi/ZIpZapZoi Codes/"

def fix_profile():
    path = os.path.join(base_dir, "profile.html")
    with open(path, "r", encoding="utf-8") as f:
        content = f.read()

    # 1. Auth Guard & userId
    content = re.sub(
        r"const userId = urlParams\.get\('id'\) \|\| urlParams\.get\('sellerId'\);\s*if \(!userId\) \{[\s\S]*?\}",
        """let userId = urlParams.get('id') || urlParams.get('sellerId');
if (!userId) {
  if (!currentUser) {
    window.location.href = "Login Page.html";
  } else {
    userId = 'me';
  }
}""",
        content
    )
    
    # 2. Add Edit Profile Modal if missing
    if "id=\"editProfileModal\"" not in content:
        modal_html = """
<!-- Edit Profile Modal -->
<div id="editProfileModal" class="hidden fixed inset-0 bg-black/60 backdrop-blur-sm z-[100] flex items-center justify-center p-4">
  <div class="bg-white dark:bg-card-dark w-full max-w-md rounded-3xl shadow-bouncy overflow-hidden transform scale-95 transition-all duration-200" id="editProfileContent">
    <div class="p-6 border-b border-gray-100 dark:border-gray-800 flex justify-between items-center">
      <h3 class="font-bold text-xl text-gray-800 dark:text-white">Edit Profile</h3>
      <button onclick="closeEditProfile()" class="text-gray-400 hover:text-red-500 transition-colors"><span class="material-symbols-outlined">close</span></button>
    </div>
    <div class="p-6 space-y-4 text-sm text-gray-600 dark:text-gray-300">
      <div class="flex justify-center mb-4">
        <label for="editAvatarInput" class="cursor-pointer relative">
          <img id="editAvatarPreview" src="https://placehold.co/96x96" class="w-24 h-24 rounded-full object-cover border-4 border-gray-100">
          <div class="absolute inset-0 bg-black/50 rounded-full flex items-center justify-center opacity-0 hover:opacity-100 transition-opacity">
             <span class="material-symbols-outlined text-white">edit</span>
          </div>
          <input type="file" id="editAvatarInput" class="hidden" accept="image/*" onchange="handleAvatarSelect(event)">
        </label>
      </div>
      <input type="text" id="editName" placeholder="Full Name" class="w-full bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl px-4 py-3 focus:outline-none focus:ring-2 focus:ring-primary/50 text-gray-800 dark:text-white">
      <input type="text" id="editCity" placeholder="City" class="w-full bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl px-4 py-3 focus:outline-none focus:ring-2 focus:ring-primary/50 text-gray-800 dark:text-white">
      <input type="text" id="editPhone" placeholder="Phone" class="w-full bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl px-4 py-3 focus:outline-none focus:ring-2 focus:ring-primary/50 text-gray-800 dark:text-white">
      <button onclick="saveProfile()" class="w-full bg-primary hover:bg-primary/90 text-white font-bold py-3.5 rounded-xl shadow-lg transition-transform active:scale-95">Save Changes</button>
    </div>
  </div>
</div>
"""
        content = content.replace("<!-- ===== MODALS ===== -->", "<!-- ===== MODALS ===== -->\n" + modal_html)
    
    # 3. Edit Profile save via PUT /api/users.php
    content = content.replace("fetch('/api/users.php?action=update_profile', {", "fetch('/api/users.php', {")
    content = content.replace("method: 'POST',", "method: 'PUT',")
    
    # 4. Add Tabs (Favorites, Offers, Notifications)
    tabs_html = """
  <div class="flex gap-6 border-b border-gray-200 dark:border-gray-700 mb-8 px-1">
    <button id="tab-listings" class="pb-3 font-bold tab-active" onclick="switchTab('listings')">Active Listings</button>
    <button id="tab-reviews" class="pb-3 font-bold tab-inactive" onclick="switchTab('reviews')">Reviews</button>
    <button id="tab-about" class="pb-3 font-bold tab-inactive" onclick="switchTab('about')">About</button>
    <a href="favorites.html" class="pb-3 font-bold tab-inactive hidden" id="tab-favorites">Favorites</a>
    <a href="Inbox.html" class="pb-3 font-bold tab-inactive hidden" id="tab-offers">Offers</a>
    <a href="dashboard.html" class="pb-3 font-bold tab-inactive hidden" id="tab-notifications">Notifications</a>
  </div>
"""
    content = re.sub(
        r'<div class="flex gap-6 border-b border-gray-200 dark:border-gray-700 mb-8 px-1">[\s\S]*?</div>',
        tabs_html,
        content
    )
    
    content = content.replace(
        "if (String(currentUser.id) !== String(userId)) {",
        """if (String(currentUser.id) !== String(userId) && userId !== 'me') {"""
    )
    
    content = content.replace(
        "// Avatar + Review button\nif (currentUser) {",
        """// Avatar + Review button
if (currentUser) {
if (userId === 'me' || String(currentUser.id) === String(userId)) {
    document.getElementById('tab-favorites')?.classList.remove('hidden');
    document.getElementById('tab-offers')?.classList.remove('hidden');
    document.getElementById('tab-notifications')?.classList.remove('hidden');
}"""
    )
    
    # 5. Shareable profile link
    content = content.replace(
        "const url = window.location.href;",
        "const url = window.location.origin + window.location.pathname + '?seller=' + (userId === 'me' ? currentUser.id : userId);"
    )
    
    # 6. Gen Z Seller Reputation badge
    score_js = """
    let score = 0;
    if (user.trusted_seller) score += 50;
    if (user.total_listings) score += user.total_listings * 5;
    if (user.avg_rating) score += user.avg_rating * 10;
    
    let repIcon = '⭐';
    if (score > 100) repIcon = '🔥';
    if (user.is_verified) repIcon = '✅';
    badges.push({ icon: repIcon, label: `Rep Score: ${score}`, color: 'bg-orange-100 text-orange-800 dark:bg-orange-900/30 dark:text-orange-400 font-black' });
    """
    content = content.replace(
        "if (user.is_verified) badges.push({ icon: '✅', label: 'Verified', color: 'bg-teal-100 text-teal-800 dark:bg-teal-900/30 dark:text-teal-400' });",
        "if (user.is_verified) badges.push({ icon: '✅', label: 'Verified', color: 'bg-teal-100 text-teal-800 dark:bg-teal-900/30 dark:text-teal-400' });\n" + score_js
    )
    
    with open(path, "w", encoding="utf-8") as f:
        f.write(content)
    print("Fixed profile.html")

def fix_inbox():
    path = os.path.join(base_dir, "Inbox.html")
    with open(path, "r", encoding="utf-8") as f:
        content = f.read()

    # Need to check inbox features:
    # 1. Loads conversations from GET /api/messages.php?conversations=1
    # 2. Clicking conversation loads GET /api/messages.php?with=X
    # 3. Send calls POST /api/messages.php with { to_user_id, body, listing_id, subject }
    # 4. Auto-scroll to bottom on new messages
    # 5. Poll for new messages every 5 seconds
    # 6. Mark messages as read via PUT /api/messages.php?id=X
    # 7. Show unread count badge
    # 8. Auth guard
    # 9. Gen Z emoji reactions -> local storage
    # 10. Typing indicator animation CSS

    # we will inject code directly into inbox if needed. Let's do that next.
    pass

fix_profile()
