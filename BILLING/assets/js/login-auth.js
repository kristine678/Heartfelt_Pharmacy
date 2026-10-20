// Shared behaviour for every auth screen (Admin login, Staff login, Staff sign-up, Verify email,
// Forgot/Reset password). ONE file, so nothing is copy-pasted per page:
//
//   1. Eye button inside a password field toggles masked <-> visible.
//   2. Submit buttons lock while a form is being sent (no double login / double sign-up).
//   3. Live "Passwords match / do not match" under a confirm field.
//   4. 6-digit code boxes: auto-advance, backspace, arrow keys, paste, and auto-submit when full.
//   5. Toasts: the auth screens do not load assets/js/app.js, so window.showToast is provided here
//      with the SAME .toast markup/CSS/lifetime the POS pages use - one visual language only.
(() => {
    // ---- 5. toast ---------------------------------------------------------------------------
    if (typeof window.showToast !== 'function') {
        window.showToast = (message) => {
            if (!message) return;
            const existing = document.querySelector('.toast');
            if (existing) existing.remove();
            const toast = document.createElement('div');
            toast.className = 'toast';
            toast.setAttribute('role', 'status');
            toast.textContent = String(message);
            document.body.appendChild(toast);
            window.setTimeout(() => toast.remove(), 2600);
        };
    }
    // ---- 1. show / hide password -------------------------------------------------------------
    document.querySelectorAll('.password-toggle').forEach((button) => {
        button.addEventListener('click', () => {
            // The toggle lives in the same .password-field wrapper as the input it controls.
            const wrapper = button.closest('.password-field');
            const input = wrapper ? wrapper.querySelector('input') : null;
            if (!input) return;
            const reveal = input.type === 'password';
            input.type = reveal ? 'text' : 'password';
            button.setAttribute('aria-pressed', reveal ? 'true' : 'false');
            button.setAttribute('aria-label', reveal ? 'Hide password' : 'Show password');
            // Keep focus in the input so typing can continue straight away.
            input.focus({ preventScroll: true });
        });
    });

    // ---- 2. submit lock ----------------------------------------------------------------------
    document.querySelectorAll('form[data-auth-form]').forEach((form) => {
        form.addEventListener('submit', () => {
            const button = form.querySelector('button[type="submit"]');
            if (!button) return;
            button.disabled = true;
            button.classList.add('is-loading');
        });
    });

    // ---- 3. passwords match / do not match ---------------------------------------------------
    document.querySelectorAll('[data-password-match]').forEach((line) => {
        const otherId = line.dataset.matchWith;
        const other = otherId ? document.getElementById(otherId) : null;
        // The source input is whichever password input inside the same form points at this field.
        const form = line.closest('form') || document;
        const source = form.querySelector(`input[data-password-match-source="${otherId}"]`) || other;
        const label = line.dataset.matchLabel || 'Passwords';
        if (!other || !source) return;

        const update = () => {
            const a = String(source.value || '');
            const b = String(other.value || '');
            // Nothing to compare while either field is still empty - stay quiet instead of
            // shouting "do not match" at someone who has only typed one of the two fields.
            if (a === '' || b === '') {
                line.textContent = '';
                line.classList.remove('is-match', 'is-mismatch');
                return;
            }
            if (a === b) {
                line.textContent = `${label} match`;
                line.classList.add('is-match');
                line.classList.remove('is-mismatch');
            } else {
                line.textContent = `${label} do not match`;
                line.classList.add('is-mismatch');
                line.classList.remove('is-match');
            }
        };

        source.addEventListener('input', update);
        other.addEventListener('input', update);
        update();
    });

    // ---- 4. six-box code input ---------------------------------------------------------------
    document.querySelectorAll('[data-code-group]').forEach((group) => {
        const boxes = [...group.querySelectorAll('[data-code-box]')];
        const hidden = group.querySelector('input[type="hidden"][data-code-value]');
        if (!boxes.length || !hidden) return;
        const form = group.closest('form');
        const autoSubmit = group.dataset.autoSubmit === 'true';

        const sync = () => {
            hidden.value = boxes.map((box) => box.value.replace(/\D/g, '')).join('');
            boxes.forEach((box) => box.classList.toggle('is-filled', box.value !== ''));
        };

        const focusBox = (index) => {
            const box = boxes[Math.max(0, Math.min(index, boxes.length - 1))];
            if (box) {
                box.focus({ preventScroll: true });
                box.select();
            }
        };

        boxes.forEach((box, index) => {
            box.addEventListener('input', () => {
                // Numeric keypad on mobile, plus: keep only the first digit typed/pasted here.
                const digits = box.value.replace(/\D/g, '');
                box.value = digits.slice(-1) || '';
                sync();
                if (box.value !== '') {
                    if (index < boxes.length - 1) {
                        focusBox(index + 1);
                    } else if (autoSubmit && form && hidden.value.length === boxes.length) {
                        form.requestSubmit ? form.requestSubmit() : form.submit();
                    }
                }
            });

            box.addEventListener('keydown', (event) => {
                if (event.key === 'Backspace' && box.value === '' && index > 0) {
                    event.preventDefault();
                    const previous = boxes[index - 1];
                    previous.value = '';
                    sync();
                    focusBox(index - 1);
                }
                if (event.key === 'ArrowLeft') {
                    event.preventDefault();
                    focusBox(index - 1);
                }
                if (event.key === 'ArrowRight') {
                    event.preventDefault();
                    focusBox(index + 1);
                }
            });

            // Paste the whole code (with or without spaces) anywhere in the group.
            box.addEventListener('paste', (event) => {
                const text = (event.clipboardData || window.clipboardData).getData('text') || '';
                const digits = text.replace(/\D/g, '');
                if (!digits) return;
                event.preventDefault();
                digits.slice(0, boxes.length).split('').forEach((digit, offset) => {
                    if (boxes[index + offset]) {
                        boxes[index + offset].value = digit;
                    }
                });
                sync();
                focusBox(Math.min(index + digits.length, boxes.length - 1));
                if (autoSubmit && form && hidden.value.length === boxes.length) {
                    form.requestSubmit ? form.requestSubmit() : form.submit();
                }
            });

            box.addEventListener('focus', () => box.select());
        });

        sync();
        if (boxes.length) focusBox(0);
    });

    // Any server-rendered notice (success or error) is also surfaced as a toast, on top of the
    // inline message that stays on screen for accessibility.
    const notice = document.querySelector('.alert.alert-success, .alert.alert-error');
    if (notice && notice.textContent.trim() !== '') {
        window.setTimeout(() => window.showToast(notice.textContent.trim()), 120);
    }
})();
