// FIX (Bugs 1–4, 6, 8): one script powers the shared data layer, the POS charge flow,
// the animated background pause and the pink confirm modal used across dashboard pages.
document.addEventListener('DOMContentLoaded', () => {
    // FIX (Bugs 3/4): single shared keys — medicines/categories/orders are ONE data source everywhere.
    const STORAGE_KEYS = {
        medicines: 'medicines',
        categories: 'categories',
        settings: 'settings',
        orders: 'orders',
        currentOrder: 'currentOrder'
    };

    // FIX (Bugs 3/4): authoritative snapshot flag — cache now mirrors the DB instead of shadowing it.
    let hasServerMedicines = false;

    const menuCardQuantities = {};

    function readJSON(key, fallback) {
        try {
            const raw = window.localStorage.getItem(key);
            if (raw === null || raw === undefined || raw === '') {
                return fallback;
            }
            return JSON.parse(raw);
        } catch (error) {
            console.warn('LocalStorage read failed for', key, error);
            return fallback;
        }
    }

    function writeJSON(key, value) {
        try {
            window.localStorage.setItem(key, JSON.stringify(value));
            window.dispatchEvent(new CustomEvent('pinkStorageChanged', { detail: { key } }));
        } catch (error) {
            console.warn('LocalStorage write failed for', key, error);
        }
    }

    // FIX (Bugs 3/4): server data is re-applied on EVERY page load and after every charge,
    // so a stale localStorage cache can never hide a newly added medicine or old stock again.
    function hydrateFromServer() {
        if (Array.isArray(window.__initialMedicines) && window.__initialMedicines.length) {
            writeJSON(STORAGE_KEYS.medicines, window.__initialMedicines);
            hasServerMedicines = true;
        } else if (Array.isArray(window.__initialMedicines)) {
            // FIX (Bug 3): an empty server list is still authoritative (e.g. all items were deleted).
            writeJSON(STORAGE_KEYS.medicines, []);
            hasServerMedicines = true;
        }
        if (Array.isArray(window.__initialCategories)) {
            writeJSON(STORAGE_KEYS.categories, window.__initialCategories);
        }
        if (window.__initialSettings && typeof window.__initialSettings === 'object') {
            writeJSON(STORAGE_KEYS.settings, window.__initialSettings);
        }
    }

    function getMedicines() {
        const list = readJSON(STORAGE_KEYS.medicines, null);
        if (Array.isArray(list)) {
            return list;
        }
        if (Array.isArray(window.__initialMedicines)) {
            return window.__initialMedicines;
        }
        return [];
    }

    function getCategories() {
        const list = readJSON(STORAGE_KEYS.categories, null);
        return Array.isArray(list) ? list : [];
    }

    function getSettings() {
        const settings = readJSON(STORAGE_KEYS.settings, null);
        return settings && typeof settings === 'object' ? settings : {};
    }

    function getOrders() {
        const list = readJSON(STORAGE_KEYS.orders, []);
        return Array.isArray(list) ? list : [];
    }

    function saveOrders(list) {
        if (!Array.isArray(list)) {
            return;
        }
        writeJSON(STORAGE_KEYS.orders, list);
    }

    // FIX (Bug 4): single shared medicine writer — every stock update goes through here.
    function saveMedicines(list) {
        if (!Array.isArray(list)) {
            return;
        }
        writeJSON(STORAGE_KEYS.medicines, list);
    }

    // FIX (Bug 4): reduceStock(medicineId, qty) — the ONE stock-deduction function (DB twin: reduceStock() in config/db.php).
    function reduceStock(medicineId, quantity) {
        const medicines = getMedicines();
        const target = medicines.find((entry) => String(entry.id) === String(medicineId));
        if (!target) {
            return false;
        }

        const currentQty = Number(target.quantity ?? target.stock ?? 0);
        const requestedQty = Number(quantity || 0);
        const nextQty = Math.max(0, currentQty - requestedQty);

        target.quantity = nextQty;
        target.stock = nextQty;
        saveMedicines(medicines);
        return true;
    }

    function toInt(value, fallback = 0) {
        const number = Number(value);
        return Number.isFinite(number) ? number : fallback;
    }

    function normalizeCategory(value) {
        const cleaned = String(value || '').trim();
        return cleaned || 'Uncategorized';
    }

    function categoryKey(value) {
        return String(value || '').trim().toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') || 'uncategorized';
    }

    function calculateNextOrderId() {
        const list = getOrders();
        return String(Math.max(0, ...list.map((order) => Number(order.id) || 0)) + 1);
    }

    function getCurrentOrder() {
        const current = readJSON(STORAGE_KEYS.currentOrder, null);
        if (current && current.id && Array.isArray(current.items)) {
            return current;
        }
        const nextOrder = {
            id: calculateNextOrderId(),
            items: [],
            discountType: 'none',
            idNumber: '',
            amountReceived: '',
            change: 0,
            // FIX (Task 3): optional customer email lives on the draft order like the other fields.
            email: '',
            status: 'draft'
        };
        writeJSON(STORAGE_KEYS.currentOrder, nextOrder);
        return nextOrder;
    }

    function saveCurrentOrder(order) {
        if (!order || !order.id) {
            return;
        }
        writeJSON(STORAGE_KEYS.currentOrder, order);
    }

    function getMedicineStock(medicine) {
        if (!medicine || typeof medicine !== 'object') {
            return 0;
        }
        return Math.max(0, toInt(medicine.quantity ?? medicine.stock ?? 0, 0));
    }

    // FIX (Task 3): optional action link — the post-charge toast can offer "View receipt" (a real user
    // click, so browsers never treat the new tab as a blocked popup). Actions keep the toast visible
    // long enough to actually be clicked.
    function showToast(message, action) {
        const current = document.querySelector('.toast');
        if (current) {
            current.remove();
        }
        const toast = document.createElement('div');
        toast.className = 'toast';
        toast.textContent = message;
        if (action && action.href) {
            const link = document.createElement('a');
            link.className = 'toast-action';
            link.href = action.href;
            link.target = '_blank';
            link.rel = 'noopener';
            link.textContent = action.label || 'View';
            toast.appendChild(link);
        }
        document.body.appendChild(toast);
        window.setTimeout(() => {
            toast.remove();
        }, action && action.href ? 8000 : 2200);
    }

    // FIX (Bug 1): pink inline message helpers — validation failures show a specific message
    // instead of the button silently doing nothing.
    function showChargeMessage(text) {
        const box = document.getElementById('chargeMessage');
        if (!box) {
            showToast(text);
            return;
        }
        box.textContent = text;
        box.classList.remove('hidden');
        box.classList.remove('amount-pop');
        void box.offsetWidth;
        box.classList.add('amount-pop');
    }

    function clearChargeMessage() {
        const box = document.getElementById('chargeMessage');
        if (box) {
            box.textContent = '';
            box.classList.add('hidden');
        }
    }

    // FIX (Task 3): shape check for the optional Email field — the server re-validates the same rule.
    function isValidEmail(value) {
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(value || '').trim());
    }

    // FIX (Task 3): pink inline hint while an email is typed; hidden for empty (optional) values.
    function updateEmailWarning(value) {
        const warning = document.getElementById('emailWarning');
        if (!warning) {
            return;
        }
        const trimmed = String(value || '').trim();
        warning.classList.toggle('hidden', trimmed === '' || isValidEmail(trimmed));
    }

    // FIX (Bug 6): reusable pink confirm modal; onConfirm receives true/false.
    function confirmAction({ title, message, confirmLabel = 'Confirm', requireText = '', onConfirm }) {
        const existing = document.getElementById('pink-confirm-modal');
        if (existing) {
            existing.remove();
        }
        const modal = document.createElement('div');
        modal.id = 'pink-confirm-modal';
        modal.className = 'pink-modal';
        modal.innerHTML = `
            <div class="pink-modal-card">
                <h3>${title}</h3>
                <p>${message}</p>
                ${requireText ? `<input id="pink-confirm-input" class="soft-search" type="text" placeholder="Type ${requireText} to confirm" autocomplete="off" />` : ''}
                <div class="pink-modal-actions">
                    <button type="button" class="btn btn-secondary" data-modal-action="cancel">Cancel</button>
                    <button type="button" class="btn btn-danger" data-modal-action="confirm" ${requireText ? 'disabled' : ''}>${confirmLabel}</button>
                </div>
            </div>
        `;
        document.body.appendChild(modal);
        modal.classList.add('visible');

        const confirmBtn = modal.querySelector('[data-modal-action="confirm"]');
        const typedInput = modal.querySelector('#pink-confirm-input');
        if (typedInput && confirmBtn) {
            typedInput.addEventListener('input', () => {
                confirmBtn.disabled = typedInput.value.trim() !== requireText;
            });
        }

        const closeModal = () => {
            document.removeEventListener('keydown', onKeydown, true);
            modal.classList.remove('visible');
            window.setTimeout(() => modal.remove(), 200);
        };

        // FIX: Esc closes the dialog as "Cancel" (matches the alert pop-up behavior), and the
        // listener is removed on close so repeated dialogs never stack up handlers.
        const onKeydown = (event) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                closeModal();
                if (onConfirm) onConfirm(false);
            }
        };
        document.addEventListener('keydown', onKeydown, true);

        modal.addEventListener('click', (event) => {
            if (event.target === modal) {
                closeModal();
                if (onConfirm) onConfirm(false);
            }
        });
        modal.querySelectorAll('[data-modal-action]').forEach((btn) => {
            btn.addEventListener('click', () => {
                const action = btn.dataset.modalAction;
                closeModal();
                if (onConfirm) onConfirm(action === 'confirm');
            });
        });
        if (typedInput) {
            window.setTimeout(() => typedInput.focus(), 60);
        }
    }

    // FIX (Bug 6): expose the pink confirm modal so page-level scripts (Settings, Bills) reuse it.
    window.confirmAction = confirmAction;
    // FIX (Task 3): expose the pink toast so server pages (Settings) can confirm actions
    // (e.g. "2 medicines moved to Pain Relief") without duplicating the toast code.
    window.showToast = showToast;

    function updateMenuCardQty(medicineId, delta) {
        const current = getCurrentOrder();
        const medicine = getMedicines().find((entry) => String(entry.id) === String(medicineId));
        if (!medicine) {
            return;
        }

        const stock = getMedicineStock(medicine);
        const previous = Number(menuCardQuantities[String(medicineId)] ?? 1);
        const next = Math.min(Math.max(previous + delta, 1), stock || 1);
        menuCardQuantities[String(medicineId)] = next;
        renderMenu();
        if (current && current.items && current.items.length) {
            renderOrderPanel();
        }
    }

    function renderMenu() {
        const searchInput = document.getElementById('searchInput');
        const chipRow = document.querySelector('.chip-row');
        const menuGrid = document.getElementById('menuGrid');
        if (!menuGrid && !chipRow) {
            return;
        }

        const medicines = getMedicines();
        const categories = getCategories();
        const query = searchInput ? searchInput.value.trim().toLowerCase() : '';
        const activeCategory = window.__selectedCategory || 'all';
        const currentOrder = getCurrentOrder();

        const categoryCounts = new Map();
        medicines.forEach((medicine) => {
            const categoryName = normalizeCategory(medicine.category || 'Uncategorized');
            categoryCounts.set(categoryName, (categoryCounts.get(categoryName) || 0) + 1);
        });

        if (chipRow) {
            // FIX (Bug 2): chips render live from the shared categories list + actual medicine counts,
            // so a deleted category's chip disappears immediately and reassigned medicines
            // show under "Uncategorized" with the correct count.
            const values = [{ id: 'all', name: 'All', count: medicines.length }];
            categories.forEach((category) => {
                const name = normalizeCategory(category.name || 'Uncategorized');
                values.push({ id: categoryKey(name), name, count: categoryCounts.get(name) || 0 });
            });
            const uncategorizedCount = categoryCounts.get('Uncategorized') || 0;
            if (uncategorizedCount > 0) {
                values.push({ id: 'uncategorized', name: 'Uncategorized', count: uncategorizedCount });
            }

            const unique = [];
            values.forEach((chip) => {
                if (!unique.some((entry) => entry.id === chip.id)) {
                    unique.push(chip);
                }
            });

            chipRow.innerHTML = unique.map((chip) => {
                const isActive = activeCategory === chip.id || (activeCategory === 'all' && chip.id === 'all');
                return `<button type="button" class="chip ${isActive ? 'active' : ''}" data-category="${chip.id}">${chip.name} (${chip.count})</button>`;
            }).join('');

            chipRow.querySelectorAll('.chip').forEach((chip) => {
                chip.addEventListener('click', () => {
                    const nextCategory = chip.dataset.category || 'all';
                    window.__selectedCategory = nextCategory;
                    renderMenu();
                });
            });
        }

        if (menuGrid) {
            // FIX (Bug 1): out-of-stock items STAY on the grid with an "Out of Stock" badge and a
            // disabled ADD button (unselectable), instead of silently vanishing from the Menu.
            const visible = medicines.filter((medicine) => {
                const category = normalizeCategory(medicine.category || 'Uncategorized');
                const matchesCategory = activeCategory === 'all'
                    || categoryKey(category) === String(activeCategory)
                    || (activeCategory === 'uncategorized' && category === 'Uncategorized');
                const searchText = `${medicine.name || ''} ${category}`.toLowerCase();
                const matchesQuery = !query || searchText.includes(query);
                return matchesCategory && matchesQuery;
            });

            if (!visible.length) {
                menuGrid.innerHTML = '<div class="empty-state">No matching medicine found.</div>';
                return;
            }

            menuGrid.innerHTML = visible.map((medicine) => {
                const stock = getMedicineStock(medicine);
                const outOfStock = stock <= 0;
                const lowStock = stock > 0 && stock <= 10;
                const price = Number(medicine.price || 0);
                const categoryName = normalizeCategory(medicine.category || 'Uncategorized');
                const cardQty = Math.max(1, Math.min(Number(menuCardQuantities[String(medicine.id)] || 1), stock || 1));
                menuCardQuantities[String(medicine.id)] = cardQty;
                const inBillQty = currentOrder.items.reduce((sum, item) => {
                    return sum + (String(item.medicineId) === String(medicine.id) ? Number(item.qty || 0) : 0);
                }, 0);
                const imageMarkup = medicine.image
                    ? `<img src="${medicine.image}" alt="${medicine.name || 'Medicine'}" class="menu-card-image">`
                    : '<div class="pink-image-placeholder"><i class="fa-solid fa-capsules"></i></div>';

                return `
                    <article class="menu-card ${outOfStock ? 'is-out-of-stock' : ''}" data-category="${categoryKey(categoryName)}" data-medicine-id="${String(medicine.id)}">
                        <div class="menu-card-top">
                            ${imageMarkup}
                            <span class="pill">${categoryName}</span>
                        </div>
                        <div class="menu-card-body">
                            <h3>${medicine.name || 'Medicine'}</h3>
                            <p class="muted">Batch: ${medicine.batch_number || medicine.batch || 'N/A'}</p>
                            <p class="muted">Stock: ${stock} · Exp: ${medicine.expiration_date || medicine.expiry || 'N/A'}</p>
                            <div class="menu-card-meta">
                                <strong>${formatCurrency(price)}</strong>
                                ${inBillQty > 0 ? `<span class="in-bill-badge">In bill: ${inBillQty}</span>` : ''}
                            </div>
                            ${lowStock ? '<span class="pink-badge pink-badge-alt">Low stock</span>' : ''}
                            ${outOfStock ? '<span class="pink-badge is-out-badge">Out of Stock</span>' : ''}
                            <div class="qty-control-row">
                                <button type="button" class="qty-action" data-action="decrement" data-medicine-id="${String(medicine.id)}" ${outOfStock || cardQty <= 1 ? 'disabled' : ''}>-</button>
                                <input class="menu-card-qty-input" type="number" min="1" max="${stock || 1}" value="${cardQty}" data-medicine-id="${String(medicine.id)}" ${outOfStock ? 'disabled' : ''}>
                                <button type="button" class="qty-action" data-action="increment" data-medicine-id="${String(medicine.id)}" ${outOfStock || cardQty >= stock ? 'disabled' : ''}>+</button>
                            </div>
                            <button type="button" class="btn btn-primary add-item-button" data-medicine-id="${String(medicine.id)}" ${outOfStock ? 'disabled' : ''}>${outOfStock ? 'OUT OF STOCK' : 'ADD'}</button>
                        </div>
                    </article>
                `;
            }).join('');
        }
    }

    function formatCurrency(value) {
        const amount = Number(value || 0);
        return `₱${amount.toFixed(2)}`;
    }

    function addMedicineToOrder(medicineId, quantity) {
        const medicine = getMedicines().find((entry) => String(entry.id) === String(medicineId));
        if (!medicine) {
            return;
        }

        const currentOrder = getCurrentOrder();
        const stock = getMedicineStock(medicine);
        const requestedQty = Math.max(1, Math.min(toInt(quantity, Number(menuCardQuantities[String(medicineId)] || 1), 10), stock || 1));

        if (stock <= 0) {
            showToast('Out of stock.');
            return;
        }

        const existing = currentOrder.items.find((item) => String(item.medicineId) === String(medicineId));
        const nextQty = existing ? Number(existing.qty || 0) + requestedQty : requestedQty;
        if (nextQty > stock) {
            showToast('Stock cap reached.');
            return;
        }

        if (existing) {
            existing.qty = nextQty;
        } else {
            currentOrder.items.push({
                medicineId: String(medicineId),
                name: medicine.name || 'Medicine',
                price: Number(medicine.price || 0),
                qty: requestedQty,
                image: medicine.image || ''
            });
        }

        menuCardQuantities[String(medicineId)] = 1;
        saveCurrentOrder(currentOrder);
        clearChargeMessage();
        renderMenu();
        renderOrderPanel();
        showToast(`${medicine.name || 'Item'} added to bill.`);
    }

    function updateOrderItemQty(medicineId, delta) {
        const order = getCurrentOrder();
        const item = order.items.find((entry) => String(entry.medicineId) === String(medicineId));
        if (!item) {
            return;
        }

        const medicine = getMedicines().find((entry) => String(entry.id) === String(medicineId));
        const stock = medicine ? getMedicineStock(medicine) : 0;
        const nextQty = Number(item.qty || 0) + delta;

        if (nextQty <= 0) {
            order.items = order.items.filter((entry) => String(entry.medicineId) !== String(medicineId));
            saveCurrentOrder(order);
            renderMenu();
            renderOrderPanel();
            return;
        }

        if (stock > 0 && nextQty > stock) {
            showToast('Stock cap reached.');
            return;
        }

        item.qty = nextQty;
        saveCurrentOrder(order);
        renderMenu();
        renderOrderPanel();
    }

    function applyPaymentState() {
        const order = getCurrentOrder();
        const totalValue = Number(order.total || 0);
        const input = document.getElementById('amountReceivedInput');
        const chargeButton = document.getElementById('chargeBtn');
        const warning = document.getElementById('amountWarning');
        const changeEl = document.getElementById('changeValue');
        const amountReceived = input ? Number(input.value || 0) : Number(order.amountReceived || 0);
        const validAmount = Number.isFinite(amountReceived) ? amountReceived : 0;

        order.amountReceived = validAmount;
        order.change = validAmount - totalValue;
        saveCurrentOrder(order);

        if (input) {
            input.value = validAmount > 0 ? String(validAmount) : '';
        }

        if (changeEl) {
            changeEl.textContent = formatCurrency(order.change);
        }

        // FIX (Bug 1): the warning row now explains the exact shortfall instead of a bare "Insufficient amount".
        const hasInsufficient = order.items.length > 0 && validAmount > 0 && validAmount < totalValue;
        if (warning) {
            warning.textContent = `Insufficient amount — add ${formatCurrency(totalValue - validAmount)} more.`;
            warning.classList.toggle('hidden', !hasInsufficient);
        }

        if (chargeButton) {
            // FIX (Bug 1): button stays ENABLED whenever there is at least one item; click validates
            // and explains itself. It is only disabled while the panel is empty.
            chargeButton.disabled = !(order.items.length > 0);
            chargeButton.setAttribute('aria-disabled', chargeButton.disabled ? 'true' : 'false');
            chargeButton.innerHTML = `<i class="fa-solid fa-wallet"></i><span>${order.items.length ? `Charge ${formatCurrency(totalValue)}` : 'Charge'}</span>`;
        }
    }

    function renderOrderPanel() {
        const order = getCurrentOrder();
        const label = document.getElementById('currentOrderLabel');
        if (label) {
            label.textContent = `#${order.id}`;
        }

        const list = document.getElementById('orderItemsList');
        if (list) {
            if (!order.items.length) {
                list.innerHTML = '<div class="empty-state">No items yet. Tap a medicine to add it.</div>';
            } else {
                list.innerHTML = order.items.map((item) => {
                    const qty = Number(item.qty || 0);
                    const lineTotal = (Number(item.price || 0) * qty).toFixed(2);
                    return `
                        <div class="order-item" data-medicine-id="${String(item.medicineId)}">
                            <div class="order-item-left">
                                <img class="order-thumb" src="${item.image || ''}" alt="${item.name || 'Medicine'}" />
                                <div class="order-item-info">
                                    <span class="order-item-name">${item.name || 'Medicine'}</span>
                                    <div class="qty-stepper">
                                        <button type="button" class="qty-stepper-btn" data-action="minus" data-medicine-id="${String(item.medicineId)}">-</button>
                                        <span class="qty-stepper-value">${qty}</span>
                                        <button type="button" class="qty-stepper-btn" data-action="plus" data-medicine-id="${String(item.medicineId)}">+</button>
                                    </div>
                                </div>
                            </div>
                            <div class="order-item-right">
                                <span class="order-price">${formatCurrency(lineTotal)}</span>
                                <button type="button" class="order-item-remove" data-remove-medicine-id="${String(item.medicineId)}" aria-label="Remove item">×</button>
                            </div>
                        </div>
                    `;
                }).join('');
            }
        }

        const discountRow = document.getElementById('discountRow');
        if (discountRow) {
            const chips = [
                { value: 'none', label: 'None' },
                { value: 'student', label: 'Student' },
                { value: 'pwd', label: 'PWD' },
                { value: 'senior', label: 'Senior' }
            ];
            discountRow.innerHTML = chips.map((chip) => {
                const isActive = (order.discountType || 'none') === chip.value;
                return `<button type="button" class="discount-chip ${isActive ? 'active' : ''}" data-discount="${chip.value}">${chip.label}</button>`;
            }).join('');

            discountRow.querySelectorAll('.discount-chip').forEach((chip) => {
                chip.addEventListener('click', () => {
                    const selected = chip.dataset.discount || 'none';
                    order.discountType = (order.discountType === selected) ? 'none' : selected;
                    if (order.discountType === 'none') {
                        order.idNumber = '';
                    }
                    saveCurrentOrder(order);
                    renderOrderPanel();
                });
            });
        }

        const discountWrap = document.getElementById('discountIdWrap');
        // FIX (Task 3): keep the optional Email field in sync with the draft order. The input is
        // skipped while focused so typing is never interrupted by a re-render.
        const emailInput = document.getElementById('customerEmailInput');
        if (emailInput) {
            if (document.activeElement !== emailInput) {
                emailInput.value = order.email || '';
            }
            updateEmailWarning(emailInput.value);
        }

        const idInput = document.getElementById('discountIdInput');
        const hasDiscount = order.discountType && order.discountType !== 'none';
        if (discountWrap) {
            discountWrap.classList.toggle('hidden', !hasDiscount);
        }
        if (idInput) {
            idInput.value = order.idNumber || '';
            idInput.disabled = !hasDiscount;
            idInput.placeholder = hasDiscount ? 'Enter ID number' : 'Select a discount first';
        }

        const settings = getSettings();
        const subtotalValue = order.items.reduce((sum, item) => sum + (Number(item.price || 0) * Number(item.qty || 0)), 0);
        const discountPercent = order.discountType === 'student'
            ? Number(settings.student_discount_rate ?? settings.studentDiscountRate ?? 0)
            : order.discountType === 'senior'
                ? Number(settings.senior_discount_rate ?? settings.seniorDiscountRate ?? 0)
                : order.discountType === 'pwd'
                    ? Number(settings.pwd_discount_rate ?? settings.pwdDiscountRate ?? 0)
                    : 0;
        const discountAmount = subtotalValue * (discountPercent / 100);
        const serviceCharge = subtotalValue * (Number(settings.service_charge_rate ?? settings.serviceChargeRate ?? 0) / 100);
        const removeVat = settings.remove_vat_for_discounted ?? settings.removeVatForDiscounted ?? false;
        const taxRate = (order.discountType === 'senior' || order.discountType === 'pwd') && removeVat ? 0 : Number(settings.tax_rate ?? settings.taxRate ?? 0);
        const taxValue = (subtotalValue - discountAmount + serviceCharge) * (taxRate / 100);
        const totalValue = subtotalValue - discountAmount + serviceCharge + taxValue;

        order.subtotal = Number(subtotalValue.toFixed(2));
        order.discountAmount = Number(discountAmount.toFixed(2));
        order.serviceCharge = Number(serviceCharge.toFixed(2));
        order.tax = Number(taxValue.toFixed(2));
        order.total = Number(totalValue.toFixed(2));
        saveCurrentOrder(order);

        const subtotalEl = document.getElementById('subtotalValue');
        const discountEl = document.getElementById('discountValue');
        const serviceEl = document.getElementById('serviceValue');
        const taxEl = document.getElementById('taxValue');
        const totalEl = document.getElementById('totalValue');
        const amountInputEl = document.getElementById('amountReceivedInput');
        const changeEl = document.getElementById('changeValue');

        if (subtotalEl) subtotalEl.textContent = formatCurrency(subtotalValue);
        if (discountEl) discountEl.textContent = `- ${formatCurrency(discountAmount)}`;
        if (serviceEl) serviceEl.textContent = formatCurrency(serviceCharge);
        if (taxEl) taxEl.textContent = formatCurrency(taxValue);
        if (totalEl) totalEl.textContent = formatCurrency(totalValue);
        if (amountInputEl && !amountInputEl.value) {
            amountInputEl.value = order.amountReceived || '';
        }
        if (changeEl) {
            changeEl.textContent = formatCurrency(Number(order.amountReceived || 0) - totalValue);
        }

        if (list) {
            list.querySelectorAll('.qty-stepper-btn').forEach((btn) => {
                btn.addEventListener('click', () => {
                    const delta = btn.dataset.action === 'plus' ? 1 : -1;
                    updateOrderItemQty(btn.dataset.medicineId, delta);
                });
            });

            list.querySelectorAll('[data-remove-medicine-id]').forEach((btn) => {
                btn.addEventListener('click', () => {
                    const medicineId = btn.dataset.removeMedicineId;
                    const current = getCurrentOrder();
                    current.items = current.items.filter((item) => String(item.medicineId) !== String(medicineId));
                    saveCurrentOrder(current);
                    renderMenu();
                    renderOrderPanel();
                });
            });
        }

        const totalValueEl = document.getElementById('totalValue');
        if (totalValueEl) {
            totalValueEl.classList.remove('amount-pop');
            void totalValueEl.offsetWidth;
            totalValueEl.classList.add('amount-pop');
        }

        applyPaymentState();
    }

    function startNextOrder() {
        const nextId = String(Math.max(Number(getCurrentOrder().id || 0), ...getOrders().map((order) => Number(order.id) || 0)) + 1);
        const order = { id: nextId, items: [], discountType: 'none', idNumber: '', amountReceived: '', change: 0, status: 'draft' };
        saveCurrentOrder(order);
        renderMenu();
        renderOrderPanel();
    }

    function handleCreateNewOrder() {
        const current = getCurrentOrder();
        if (current.items && current.items.length) {
            const modal = document.getElementById('discard-order-modal');
            if (modal) {
                modal.classList.add('visible');
                return;
            }
            showToast('Discard current order?');
        }
        startNextOrder();
    }

    function confirmDiscard() {
        const modal = document.getElementById('discard-order-modal');
        if (modal) {
            modal.classList.remove('visible');
        }
        startNextOrder();
    }

    function cancelDiscard() {
        const modal = document.getElementById('discard-order-modal');
        if (modal) {
            modal.classList.remove('visible');
        }
    }

    function ensureDiscardModal() {
        if (document.getElementById('discard-order-modal')) {
            return;
        }
        const modal = document.createElement('div');
        modal.id = 'discard-order-modal';
        modal.className = 'pink-modal';
        modal.innerHTML = `
            <div class="pink-modal-card">
                <h3>Discard current order?</h3>
                <p>This will clear the current bill and start a fresh order.</p>
                <div class="pink-modal-actions">
                    <button type="button" class="btn btn-secondary" data-modal-action="cancel">Cancel</button>
                    <button type="button" class="btn btn-primary" data-modal-action="confirm">Discard</button>
                </div>
            </div>
        `;
        document.body.appendChild(modal);

        modal.addEventListener('click', (event) => {
            if (event.target === modal) {
                cancelDiscard();
            }
        });

        modal.querySelectorAll('[data-modal-action]').forEach((btn) => {
            btn.addEventListener('click', () => {
                const action = btn.dataset.modalAction;
                if (action === 'confirm') {
                    confirmDiscard();
                } else {
                    cancelDiscard();
                }
            });
        });
    }

    function handleCharge() {
        const currentOrder = getCurrentOrder();

        // FIX (Bug 1): specific inline pink message for whichever check fails (item → amount → ID).
        if (!currentOrder.items.length) {
            showChargeMessage('Add at least one medicine to the order before charging.');
            return;
        }

        const amountReceived = Number(currentOrder.amountReceived || 0);
        const totalValue = Number(currentOrder.total || 0);
        if (!Number.isFinite(amountReceived) || amountReceived <= 0 || amountReceived < totalValue) {
            showChargeMessage(`Amount received (₱${Number(currentOrder.amountReceived || 0).toFixed(2)}) is less than the total (${formatCurrency(totalValue)}).`);
            return;
        }

        if ((currentOrder.discountType || 'none') !== 'none' && !(currentOrder.idNumber || '').trim()) {
            showChargeMessage('Enter an ID number for the selected discount type.');
            return;
        }

        // FIX (Task 3): the email is optional, but if one was typed it must be valid before charging.
        const customerEmail = String(currentOrder.email || '').trim();
        if (customerEmail !== '' && !isValidEmail(customerEmail)) {
            showChargeMessage('Enter a valid email address (or clear the Email field) before charging.');
            return;
        }

        clearChargeMessage();

        // FIX (Bugs 1/4): compute all totals from the order state — subtotal, discount, service charge,
        // tax, total, amount received and change travel together to the server.
        const payload = {
            order: {
                customer_id: 0,
                subtotal: Number(currentOrder.subtotal ?? currentOrder.items.reduce((sum, item) => sum + (Number(item.price || 0) * Number(item.qty || 0)), 0).toFixed(2)),
                discount_amount: Number((currentOrder.discountAmount || 0).toFixed(2)),
                discountType: currentOrder.discountType || 'none',
                idNumber: currentOrder.idNumber || '',
                service_charge: Number((currentOrder.serviceCharge || 0).toFixed(2)),
                tax: Number((currentOrder.tax || 0).toFixed(2)),
                total: Number(totalValue.toFixed(2)),
                amountReceived: Number(amountReceived.toFixed(2)),
                change: Number((amountReceived - totalValue).toFixed(2)),
                // FIX (Task 3): saved on the order so the receipt can email the customer later.
                customer_email: customerEmail,
                items: currentOrder.items.map((item) => ({
                    medicine_id: Number(item.medicineId || 0),
                    quantity: Number(item.qty || 0),
                    name: item.name || 'Medicine',
                    price: Number(item.price || 0),
                    image: item.image || ''
                }))
            }
        };

        // FIX (Bug 1): prevent double-charging while a charge request is in flight.
        const chargeButton = document.getElementById('chargeBtn');
        if (chargeButton) {
            chargeButton.disabled = true;
        }

        // FIX (Bug 1): ONE atomic flow — the server transaction (order + items + stock) is the single
        // source of truth. Nothing is saved locally until the transaction commits, so a failed charge
        // can never half-save an order or burn stock. localStorage updates happen only AFTER success,
        // and writeJSON() dispatches 'pinkStorageChanged' + the native 'storage' event so History,
        // Inventory and the catalog react without a manual refresh.
        fetch('charge.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
            .then(async (response) => {
                let json = null;
                try {
                    json = await response.json();
                } catch (parseError) {
                    json = null;
                }
                if (!response.ok || !json || !json.success) {
                    const failure = new Error((json && json.message) || `Charge failed (HTTP ${response.status})`);
                    // FIX (Menu Rule 3): carry the payload so the catch can drop cart lines the
                    // server flagged as expired while they sat in the order.
                    failure.payload = json;
                    throw failure;
                }

                // FIX (Bug 1): transaction committed — replace the shared medicines snapshot with the
                // DB's fresh stock so Menu, Inventory and the public catalog all match instantly.
                if (Array.isArray(json.medicines)) {
                    saveMedicines(json.medicines);
                    hasServerMedicines = true;
                }

                // FIX (Bug 1): the SAME order record feeds History through the shared
                // orders key — saved only after the server commit, so a rollback leaves no phantom bill.
                const paidOrder = {
                    id: String(json.order_id || calculateNextOrderId()),
                    items: currentOrder.items.map((item) => {
                        const savedItem = Array.isArray(json.items)
                            ? json.items.find((entry) => String(entry.medicine_id) === String(item.medicineId)
                                || String(entry.name || '').trim().toLowerCase() === String(item.name || '').trim().toLowerCase())
                            : null;
                        return savedItem
                            ? { ...item, name: savedItem.name, price: Number(savedItem.price), qty: Number(savedItem.quantity) }
                            : { ...item };
                    }),
                    discountType: currentOrder.discountType || 'none',
                    idNumber: currentOrder.idNumber || '',
                    subtotal: Number(json.totals?.subtotal ?? payload.order.subtotal),
                    discountAmount: Number(json.totals?.discount ?? currentOrder.discountAmount ?? 0),
                    serviceCharge: Number(json.totals?.service_charge ?? currentOrder.serviceCharge ?? 0),
                    tax: Number(json.totals?.tax ?? currentOrder.tax ?? 0),
                    total: Number(json.totals?.total ?? totalValue),
                    amountReceived: Number(json.totals?.amount_received ?? amountReceived),
                    change: Number(json.totals?.change ?? (amountReceived - totalValue)),
                    // FIX (Task 3): mirror the saved email into the local order cache as well.
                    email: customerEmail,
                    status: 'paid',
                    paidAt: new Date().toISOString()
                };
                saveOrders([...getOrders(), paidOrder]);

                // FIX (Bug 1): reset the Order Details panel to a fresh empty order.
                const nextOrder = {
                    id: String(Number(paidOrder.id) + 1),
                    items: [],
                    discountType: 'none',
                    idNumber: '',
                    amountReceived: '',
                    change: 0,
                    // FIX (Task 3): clear the email for the next customer.
                    email: '',
                    status: 'draft'
                };
                saveCurrentOrder(nextOrder);
                renderMenu();
                renderOrderPanel();
                // FIX (Task 3): the receipt for the order that was just charged is one click away.
                showToast(`Payment received — change ${formatCurrency(Number(json.totals?.change ?? (amountReceived - totalValue)))}.`, {
                    href: 'print-bill.php?order_id=' + encodeURIComponent(paidOrder.id),
                    label: 'View receipt'
                });
            })
            .catch((error) => {
                // FIX (Bug 1): rollback path — nothing was saved anywhere; the panel keeps its items
                // and the pink inline message explains what happened.
                // FIX (Menu Rule 3): an item that expired after it was carted is removed here so
                // the cashier sees exactly which batch went bad and can re-checkout immediately.
                const payload = error && error.payload;
                if (payload && payload.code === 'expired_items' && Array.isArray(payload.expired)) {
                    removeExpiredCartItems(payload.expired);
                }
                showChargeMessage(error.message || 'Charge failed — please try again.');
            })
            .finally(() => {
                // FIX (Bug 1): re-enable Charge unless the panel is genuinely empty.
                if (chargeButton) {
                    chargeButton.disabled = !(getCurrentOrder().items.length > 0);
                }
            });
    }

    function bindControls() {
        const searchInput = document.getElementById('searchInput');
        if (searchInput) {
            searchInput.addEventListener('input', renderMenu);
        }

        const menuGrid = document.getElementById('menuGrid');
        if (menuGrid) {
            menuGrid.addEventListener('click', (event) => {
                const actionTarget = event.target.closest('[data-action]');
                if (actionTarget) {
                    const action = actionTarget.dataset.action;
                    const medicineId = actionTarget.dataset.medicineId;
                    if (action === 'increment') {
                        updateMenuCardQty(medicineId, 1);
                    }
                    if (action === 'decrement') {
                        updateMenuCardQty(medicineId, -1);
                    }
                    return;
                }

                const addButton = event.target.closest('.add-item-button');
                if (addButton) {
                    const medicineId = addButton.dataset.medicineId;
                    const card = addButton.closest('.menu-card');
                    const qtyInput = card ? card.querySelector('.menu-card-qty-input') : null;
                    const requestedQty = qtyInput ? Number(qtyInput.value || 1) : 1;
                    addMedicineToOrder(medicineId, requestedQty);
                    return;
                }
            });

            menuGrid.addEventListener('input', (event) => {
                const input = event.target.closest('.menu-card-qty-input');
                if (!input) {
                    return;
                }
                const medicineId = input.dataset.medicineId;
                const min = Number(input.min || 1);
                const max = Number(input.max || 1);
                const value = Math.min(Math.max(Number(input.value || 1), min), max);
                menuCardQuantities[String(medicineId)] = value;
                renderMenu();
            });
        }

        const createButton = document.getElementById('createNewOrderBtn');
        if (createButton) {
            createButton.addEventListener('click', (event) => {
                event.preventDefault();
                ensureDiscardModal();
                handleCreateNewOrder();
            });
        }

        // FIX (Bug 1): real click handler on #chargeBtn (id matches menu.php) — delegation is used
        // for dynamically re-rendered controls; this button is static so a direct bind is correct.
        const chargeButton = document.getElementById('chargeBtn');
        if (chargeButton) {
            chargeButton.addEventListener('click', (event) => {
                event.preventDefault();
                handleCharge();
            });
        }

        const amountInput = document.getElementById('amountReceivedInput');
        if (amountInput) {
            amountInput.addEventListener('input', () => {
                const order = getCurrentOrder();
                order.amountReceived = Number(amountInput.value || 0);
                saveCurrentOrder(order);
                applyPaymentState();
            });
        }

        document.querySelectorAll('.quick-amount-btn').forEach((button) => {
            button.addEventListener('click', () => {
                const amountInputField = document.getElementById('amountReceivedInput');
                if (!amountInputField) {
                    return;
                }
                const amount = button.dataset.amount === 'exact' ? Number(getCurrentOrder().total || 0) : Number(button.dataset.amount || 0);
                amountInputField.value = String(amount);
                const order = getCurrentOrder();
                order.amountReceived = amount;
                saveCurrentOrder(order);
                applyPaymentState();
            });
        });

        // FIX (Task 3): optional customer email — stored on the draft order as it is typed.
        const customerEmailInput = document.getElementById('customerEmailInput');
        if (customerEmailInput) {
            customerEmailInput.addEventListener('input', () => {
                const order = getCurrentOrder();
                order.email = customerEmailInput.value.trim();
                saveCurrentOrder(order);
                updateEmailWarning(customerEmailInput.value);
            });
        }

        const discountInput = document.getElementById('discountIdInput');
        if (discountInput) {
            discountInput.addEventListener('input', (event) => {
                const current = getCurrentOrder();
                current.idNumber = event.target.value;
                saveCurrentOrder(current);
                renderOrderPanel();
            });
        }

        const customerForm = document.getElementById('customerForm');
        if (customerForm) {
            customerForm.addEventListener('submit', (event) => {
                event.preventDefault();
                handleCreateNewOrder();
            });
        }

        window.addEventListener('storage', () => {
            renderMenu();
            renderOrderPanel();
        });
    }

    // FIX (Bug 2): tab-hidden animation pause now lives in ONE shared snippet (assets/js/bg-pause.js),
    // loaded by includes/footer.php on every staff page — the duplicate listener here was removed.

    // FIX (Menu Rule 3): drop cart lines the server flagged as expired (charge rolled back),
    // then re-render the panel so the removed lines disappear from the Order Details.
    function removeExpiredCartItems(expiredList) {
        const order = getCurrentOrder();
        if (!order || !Array.isArray(order.items) || !order.items.length) {
            return;
        }
        const expiredKeys = expiredList.map((entry) => String(entry.name || '').trim().toLowerCase());
        const kept = order.items.filter((item) => expiredKeys.indexOf(String(item.name || '').trim().toLowerCase()) === -1);
        if (kept.length !== order.items.length) {
            order.items = kept;
            saveCurrentOrder(order);
            renderOrderPanel();
        }
    }

    // FIX (Menu Rule 5): 5-minute poll of the sellable inventory. The server has already
    // filtered expired batches, so a batch that crossed midnight disappears from the grid and
    // the cart refreshes itself — no manual reload, and checkout still re-validates server-side.
    function refreshMenuInventory() {
        if (!window.isPosPage) {
            return;
        }
        fetch('api/menu_data.php', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then((response) => (response.ok ? response.json() : null))
            .then((data) => {
                if (!data || !data.ok || !Array.isArray(data.medicines)) {
                    return;
                }
                saveMedicines(data.medicines);
                hasServerMedicines = true;
                if (Array.isArray(data.categories)) {
                    writeJSON(STORAGE_KEYS.categories, data.categories);
                }
                pruneUnsellableCartItems(data.medicines);
                renderMenu();
            })
            .catch(() => {
                // A failed poll keeps the current snapshot; the next tick retries.
            });
    }

    // A medicine missing from the sellable list has no non-expired batches left (or was
    // deleted) — remove it from the open order and say so, per Rule 3's clear message.
    function pruneUnsellableCartItems(medicines) {
        const order = getCurrentOrder();
        if (!order || !Array.isArray(order.items) || !order.items.length) {
            return;
        }
        const sellableKeys = new Set(medicines.map((medicine) => String(medicine.name || '').trim().toLowerCase()));
        const removed = [];
        order.items = order.items.filter((item) => {
            const key = String(item.name || '').trim().toLowerCase();
            if (sellableKeys.has(key)) {
                return true;
            }
            removed.push(item);
            return false;
        });
        if (!removed.length) {
            return;
        }
        saveCurrentOrder(order);
        const message = removed
            .map((item) => `${item.name || 'An item'} has expired and was removed from your order.`)
            .join(' ');
        showChargeMessage(message);
        showToast(message);
        renderOrderPanel();
    }

    hydrateFromServer();

    // FIX (Bug 1): render the POS only where its markup exists; bindControls() runs on every page.
    if (window.isPosPage) {
        renderMenu();
        renderOrderPanel();
        // FIX (Menu Rule 5): keep the Menu fresh without a reload — every 5 minutes the sellable
        // stock is re-polled, expired items leave the grid, and cart lines that expired at
        // midnight are pruned with an explanation.
        window.setInterval(refreshMenuInventory, 5 * 60 * 1000);
    }
    bindControls();

    const alerts = document.querySelectorAll('.alert');
    alerts.forEach((alert) => {
        if (alert.closest('.login-card')) {
            return;
        }
        window.setTimeout(() => {
            alert.style.transition = 'opacity 0.5s ease';
            alert.style.opacity = '0';
            window.setTimeout(() => alert.remove(), 500);
        }, 5000);
    });
});
