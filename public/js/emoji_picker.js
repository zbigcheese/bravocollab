/**
 * Tiny inline emoji picker — no external library. Curated set of common
 * emojis grouped roughly by use. Attach to any (textarea, button) pair:
 *
 *   EmojiPicker.attach(myTextarea, myButton);
 *
 * Click on the button opens a popup near it; click an emoji inserts it
 * at the textarea's caret. Close on outside click or Escape.
 */
const EmojiPicker = {
    GROUPS: [
        { label: 'Smileys', emojis: ['😀','😃','😄','😁','😆','😅','😂','🤣','😊','😇','🙂','🙃','😉','😌','😍','🥰','😘','😗','😙','😚','😋','😛','😜','🤪','😝','🤔','🤨','😐','😑','😶','🙄','😏','😒','😞','😔','😟','😕','🙁','☹️','😣','😖','😫','😩','🥺','😢','😭','😤','😠','😡','🤬','😱','😨','😰','😥','😓','🤗','🤩','😎','🥳','🤓','🥱'] },
        { label: 'Gestures', emojis: ['👍','👎','👌','✌️','🤞','🤟','🤘','🤙','👈','👉','👆','👇','☝️','✋','🤚','🖐️','🖖','👋','🤝','👏','🙌','👐','🙏','💪','🫶'] },
        { label: 'Hearts',   emojis: ['❤️','🧡','💛','💚','💙','💜','🖤','🤍','🤎','💔','❣️','💕','💞','💓','💗','💖','💘','💝'] },
        { label: 'Symbols',  emojis: ['🔥','⭐','✨','🎉','🎊','💯','✅','❌','❗','❓','💡','📌','📍','📎','🔗','🔒','🚀','⏰','📅','📆','⚠️','🛑','✔️','✖️'] },
        { label: 'Misc',     emojis: ['👀','💬','🗣️','🤐','🙈','🙉','🙊','🤷','🤦','🦄','🐶','🐱','🌟','🌈','☀️','☁️','🌧️','❄️','🍕','🍔','☕','🍻','🎂','🎁'] },
    ],

    _popup: null,
    _outsideHandler: null,
    _escHandler: null,

    attach(textarea, button) {
        if (!textarea || !button) return;
        button.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            this._toggle(textarea, button);
        });
    },

    _toggle(textarea, button) {
        if (this._popup) { this._close(); return; }
        this._open(textarea, button);
    },

    _open(textarea, button) {
        const popup = document.createElement('div');
        popup.className = 'emoji-picker-popup';
        popup.innerHTML = this.GROUPS.map(g => `
            <div class="emoji-picker-group">
                <div class="emoji-picker-label">${g.label}</div>
                <div class="emoji-picker-grid">
                    ${g.emojis.map(em => `<button type="button" class="emoji-pick-btn" data-emoji="${em}" title="${em}">${em}</button>`).join('')}
                </div>
            </div>
        `).join('');
        document.body.appendChild(popup);

        // Position above the button by default. If it would go off the top
        // of the viewport, flip below.
        const rect = button.getBoundingClientRect();
        popup.style.position = 'fixed';
        popup.style.left = Math.max(8, Math.min(rect.left, window.innerWidth - 320 - 8)) + 'px';
        // Try above first
        const popupRect = popup.getBoundingClientRect();
        if (rect.top - popupRect.height - 8 >= 8) {
            popup.style.top  = (rect.top - popupRect.height - 6) + 'px';
        } else {
            popup.style.top  = (rect.bottom + 6) + 'px';
        }

        popup.querySelectorAll('.emoji-pick-btn').forEach(btn => {
            // mousedown fires before the textarea blur, so the caret stays put.
            btn.addEventListener('mousedown', (e) => {
                e.preventDefault();
                e.stopPropagation();
                this._insertEmoji(textarea, btn.dataset.emoji);
                this._close();
            });
        });

        this._popup = popup;
        // Defer outside-click handler so the opening click doesn't immediately close it.
        setTimeout(() => {
            this._outsideHandler = (e) => {
                if (!popup.contains(e.target) && !button.contains(e.target)) {
                    this._close();
                }
            };
            document.addEventListener('click', this._outsideHandler);
            this._escHandler = (e) => { if (e.key === 'Escape') this._close(); };
            document.addEventListener('keydown', this._escHandler);
        }, 0);
    },

    _close() {
        if (this._popup) { this._popup.remove(); this._popup = null; }
        if (this._outsideHandler) { document.removeEventListener('click', this._outsideHandler); this._outsideHandler = null; }
        if (this._escHandler) { document.removeEventListener('keydown', this._escHandler); this._escHandler = null; }
    },

    _insertEmoji(textarea, emoji) {
        const start = textarea.selectionStart ?? textarea.value.length;
        const end   = textarea.selectionEnd   ?? textarea.value.length;
        const v = textarea.value;
        textarea.value = v.substring(0, start) + emoji + v.substring(end);
        const newPos = start + emoji.length;
        textarea.selectionStart = textarea.selectionEnd = newPos;
        textarea.focus();
        // Notify listeners (e.g. mention autocomplete) that the text changed.
        textarea.dispatchEvent(new Event('input', { bubbles: true }));
    },
};
