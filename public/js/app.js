/**
 * Sistema POS Supermercado - Cliente JavaScript Principal
 * Compatible con lectores láser de código de barras y navegación por teclado (F1-F12)
 */

// Estado global de la aplicación
const state = {
    user: null,
    activeTab: 'pos',
    cashSession: null,
    cart: [],
    selectedItemIndex: 0,
    selectedClient: { id: 1, nombre: 'Consumidor Final', nit: '', nrc: '', dui: '', aplica_credito: false },
    heldSales: [],
    productsCache: [],
    targetCloseSessionId: null
};

// ==========================================
// NOTIFICACIONES TOAST (No bloqueantes)
// ==========================================
function showToast(message, type = 'info') {
    let container = document.getElementById('toastContainer');
    if (!container) {
        container = document.createElement('div');
        container.id = 'toastContainer';
        container.className = 'toast-container';
        document.body.appendChild(container);
    }
    const svgIcons = {
        success: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>',
        error: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>',
        warning: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
        info: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>'
    };
    const toast = document.createElement('div');
    toast.className = `toast-item toast-${type}`;
    toast.innerHTML = `<span class="toast-icon">${svgIcons[type] || svgIcons.info}</span><span class="toast-message">${escapeHtml(String(message))}</span>`;
    container.appendChild(toast);
    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transform = 'translateY(-10px) scale(0.95)';
        setTimeout(() => toast.remove(), 300);
    }, 4000);
}
window.alert = (msg) => showToast(msg, 'warning');

function formatDateTime(dtStr) {
    if (!dtStr) return '—';
    try {
        const d = new Date(String(dtStr).replace(' ', 'T'));
        if (isNaN(d.getTime())) return String(dtStr).substring(0, 16);
        return d.toLocaleDateString('es-SV', { day: '2-digit', month: 'short' }) + ' ' +
               d.toLocaleTimeString('es-SV', { hour: '2-digit', minute: '2-digit', hour12: true });
    } catch {
        return String(dtStr).substring(0, 16);
    }
}


// ==========================================
// INICIALIZACIÓN
// ==========================================
document.addEventListener('DOMContentLoaded', () => {
    initClock();
    initAuth();
    initNavigation();
    initKeyboardShortcuts();
    initPos();
    initCashRegister();
    initSwitchUser();
    initSalesHistory();
    initPurchases();
    initCatalog();
    initUsers();
    initInventoryModule();
    initAccountingModule();
    initReportsModule();
    initDteModule();
});

// Reloj en tiempo real
function initClock() {
    const clockEl = document.getElementById('liveClock');
    setInterval(() => {
        const now = new Date();
        clockEl.textContent = now.toLocaleTimeString('es-SV', { hour12: false });
    }, 1000);
}

// ==========================================
// AUTENTICACIÓN
// ==========================================
function initAuth() {
    const loginForm = document.getElementById('loginForm');
    const btnLogout = document.getElementById('btnLogout');

    // Verificar sesión activa
    fetch('/api/auth/me')
        .then(r => r.json())
        .then(data => {
            if (data.authenticated && data.user) {
                setAuthenticatedUser(data.user);
            }
        });

    if (loginForm) {
        loginForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const username = document.getElementById('username').value.trim();
            const password = document.getElementById('password').value;
            const errEl = document.getElementById('loginError');
            errEl.classList.add('hidden');

            try {
                const res = await fetch('/api/auth/login', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ username, password })
                });
                const data = await res.json();
                if (data.ok) {
                    setAuthenticatedUser(data.user);
                    window.location.reload();
                } else {
                    errEl.textContent = data.error || 'Error al iniciar sesión';
                    errEl.classList.remove('hidden');
                }
            } catch (err) {
                errEl.textContent = 'Error de conexión con el servidor local';
                errEl.classList.remove('hidden');
            }
        });
    }

    const btnHeaderLogout = document.getElementById('btnHeaderLogout');
    if (btnLogout) {
        btnLogout.addEventListener('click', performLogout);
    }
    if (btnHeaderLogout) {
        btnHeaderLogout.addEventListener('click', performLogout);
    }
}

async function performLogout() {
    try {
        await fetch('/api/auth/logout', { method: 'POST' });
    } catch (e) {}
    try {
        await fetch('/api/auth/logout', { method: 'GET' });
    } catch (e) {}
    document.cookie = "PHPSESSID=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;";
    state.user = null;
    window.location.href = '/logout';
}

function quickFill(user, pass) {
    document.getElementById('username').value = user;
    document.getElementById('password').value = pass;
    document.getElementById('loginForm').dispatchEvent(new Event('submit'));
}

function setAuthenticatedUser(user) {
    state.user = user;
    document.body.className = 'logged-in';
    document.getElementById('loginView').classList.add('hidden');
    document.getElementById('appView').classList.remove('hidden');

    document.getElementById('userDisplayName').textContent = user.nombre_completo;
    document.getElementById('userDisplayRole').textContent = user.rol;
    document.getElementById('userInitial').textContent = user.nombre_completo.charAt(0).toUpperCase();

    // Aplicar permisos estrictos de navegación según el rol
    applyRolePermissions(user);

    // Cargar estado inicial de caja
    checkCashSession();

    // Solo roles administrativos y contables cargan métricas del Dashboard
    if (['ADMIN', 'GERENTE', 'CONTADOR'].includes(user.rol)) {
        loadDashboard();
    }
}

function applyRolePermissions(user) {
    if (!user) return;
    const role = user.rol || 'CAJERO';
    const rolePermissions = {
        ADMIN: ['pos', 'cash', 'sales-history', 'inventory', 'purchases', 'catalog', 'users', 'dashboard', 'billing', 'accounting'],
        GERENTE: ['pos', 'cash', 'sales-history', 'inventory', 'purchases', 'catalog', 'dashboard', 'billing'],
        CAJERO: ['pos', 'cash'],
        BODEGUERO: ['inventory', 'purchases', 'catalog'],
        CONTADOR: ['accounting', 'billing', 'dashboard', 'sales-history']
    };

    const allowed = rolePermissions[role] || ['pos', 'cash'];

    document.querySelectorAll('.nav-item').forEach(item => {
        const tab = item.dataset.tab;
        if (allowed.includes(tab)) {
            item.style.display = '';
        } else {
            item.style.display = 'none';
        }
    });

    // Si el tab actual no está permitido para este rol, redirigir automáticamente al primer tab autorizado
    if (!allowed.includes(state.activeTab)) {
        switchTab(allowed[0] || 'pos');
    }
}

// ==========================================
// NAVEGACIÓN POR TABS
// ==========================================
function initNavigation() {
    const navItems = document.querySelectorAll('.nav-item');
    navItems.forEach(item => {
        item.addEventListener('click', () => {
            const tabName = item.dataset.tab;
            switchTab(tabName);
        });
    });
}

function switchTab(tabName) {
    // Validar autorización de rol antes de cambiar de tab
    if (state.user) {
        const role = state.user.rol || 'CAJERO';
        const rolePermissions = {
            ADMIN: ['pos', 'cash', 'sales-history', 'inventory', 'purchases', 'catalog', 'users', 'dashboard', 'billing', 'accounting'],
            GERENTE: ['pos', 'cash', 'sales-history', 'inventory', 'purchases', 'catalog', 'dashboard', 'billing'],
            CAJERO: ['pos', 'cash'],
            BODEGUERO: ['inventory', 'purchases', 'catalog'],
            CONTADOR: ['accounting', 'billing', 'dashboard', 'sales-history']
        };
        const allowed = rolePermissions[role] || ['pos', 'cash'];
        if (!allowed.includes(tabName)) {
            showToast(`Acceso denegado: El rol ${role} no tiene permisos para acceder al módulo "${tabName}".`, 'warning');
            return;
        }
    }

    state.activeTab = tabName;
    document.querySelectorAll('.nav-item').forEach(i => {
        i.classList.toggle('active', i.dataset.tab === tabName);
    });
    document.querySelectorAll('.tab-pane').forEach(p => {
        p.classList.toggle('active', p.id === `tab-${tabName}`);
    });

    const titles = {
        pos: 'Punto de Venta (POS)',
        cash: 'Control de Caja y Turnos',
        'sales-history': 'Historial y Auditoría de Ventas',
        inventory: 'Inventario & Kardex',
        purchases: 'Compras y Abastecimiento de Mercaderías',
        catalog: 'Maestros de Catálogo (Categorías, Marcas, Proveedores)',
        users: 'Control de Usuarios y Roles',
        dashboard: 'Dashboard & Métricas',
        billing: 'Facturación Electrónica DTE',
        accounting: 'Contabilidad & Libros de IVA'
    };
    document.getElementById('pageTitle').textContent = titles[tabName] || 'POS';

    if (tabName === 'pos') {
        checkCashSession();
        if (state.cashSession) {
            setTimeout(() => document.getElementById('barcodeInput')?.focus(), 100);
        }
    } else if (tabName === 'cash') {
        checkCashSession();
    } else if (tabName === 'sales-history') {
        loadSalesHistory();
    } else if (tabName === 'inventory') {
        loadInventory();
    } else if (tabName === 'purchases') {
        loadPurchases();
    } else if (tabName === 'catalog') {
        loadCatalog();
    } else if (tabName === 'users') {
        loadUsers();
    } else if (tabName === 'dashboard') {
        loadDashboard();
    } else if (tabName === 'billing') {
        loadDtes();
    } else if (tabName === 'accounting') {
        loadAccounting();
    }
}

// ==========================================
// ATAJOS DE TECLADO (F1-F12) Y ESCÁNER
// ==========================================
function initKeyboardShortcuts() {
    window.addEventListener('keydown', (e) => {
        // Si hay un modal abierto y presiona Esc
        if (e.key === 'Escape') {
            closeAllModals();
            return;
        }

        // Atajos solo cuando estamos en pestaña POS
        if (state.activeTab === 'pos') {
            if (!state.cashSession) {
                if (['F1', 'F2', 'F3', 'F4', 'F8', 'F9'].includes(e.key)) {
                    e.preventDefault();
                    showToast('Caja cerrada. Debes abrir un turno para usar el POS.', 'warning');
                    openModal('modalOpenCash');
                    return;
                }
            }

            if (e.key === 'F1') {
                e.preventDefault();
                openModal('modalSearchProduct');
                document.getElementById('modalProductQuery').focus();
            } else if (e.key === 'F2') {
                e.preventDefault();
                promptChangeQuantity();
            } else if (e.key === 'F3') {
                e.preventDefault();
                promptItemDiscount();
            } else if (e.key === 'F4') {
                e.preventDefault();
                if (state.cart.length > 0) {
                    openCheckoutModal();
                } else {
                    showToast('El carrito está vacío', 'warning');
                }
            } else if (e.key === 'F8') {
                e.preventDefault();
                toggleHoldSale();
            } else if (e.key === 'F9') {
                e.preventDefault();
                promptSelectClient();
            }
        }
    });

    // Configurar cierres de modales por data-close
    document.querySelectorAll('[data-close]').forEach(btn => {
        btn.addEventListener('click', () => {
            const modalId = btn.dataset.close;
            closeModal(modalId);
        });
    });

    // Delegación universal de clic para data-close
    document.addEventListener('click', (e) => {
        const closeBtn = e.target.closest('[data-close]');
        if (closeBtn) {
            e.preventDefault();
            closeModal(closeBtn.dataset.close);
        }
    });

    // Delegación universal de clic para logout
    document.addEventListener('click', (e) => {
        if (e.target.closest('#btnLogout') || e.target.closest('#btnHeaderLogout') || e.target.closest('.action-logout')) {
            e.preventDefault();
            performLogout();
        }
    });
}

function openModal(id) {
    const el = document.getElementById(id);
    if (el) {
        el.classList.remove('hidden');
        el.style.display = 'flex';
        if (id === 'modalOpenCash') {
            const userRole = state.user?.rol || 'CAJERO';
            const supervisorAuthGroup = document.getElementById('openSupervisorAuthGroup');
            const cajeroGroup = document.getElementById('openCajeroGroup');
            const pinInp = document.getElementById('openCashSupervisorPin');
            if (pinInp) pinInp.value = '';
            if (userRole === 'CAJERO') {
                if (supervisorAuthGroup) supervisorAuthGroup.classList.remove('hidden');
                if (cajeroGroup) cajeroGroup.classList.add('hidden');
            } else {
                if (supervisorAuthGroup) supervisorAuthGroup.classList.add('hidden');
                if (cajeroGroup) cajeroGroup.classList.remove('hidden');
            }
        }
    }
}

function closeModal(id) {
    const el = document.getElementById(id);
    if (el) {
        el.classList.add('hidden');
        el.style.display = 'none';
    }
    if (state.activeTab === 'pos') {
        setTimeout(() => document.getElementById('barcodeInput')?.focus(), 100);
    }
}

function closeAllModals() {
    document.querySelectorAll('.modal-backdrop').forEach(m => {
        m.classList.add('hidden');
        m.style.display = 'none';
    });
    if (state.activeTab === 'pos') {
        document.getElementById('barcodeInput')?.focus();
    }
}

// ==========================================
// MÓDULO PUNTO DE VENTA (POS)
// ==========================================
function initPos() {
    const barcodeInput = document.getElementById('barcodeInput');
    const modalProductQuery = document.getElementById('modalProductQuery');
    const btnOpenSearch = document.getElementById('btnOpenSearchModal');
    const btnPayNow = document.getElementById('btnPayNow');
    const btnClearCart = document.getElementById('btnClearCart');
    const btnHoldSale = document.getElementById('btnHoldSale');
    const btnChangeClient = document.getElementById('btnChangeClient');

    // Lector de barras (detecta sufijo Enter)
    barcodeInput.addEventListener('keydown', async (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            const code = barcodeInput.value.trim();
            if (code !== '') {
                await addProductByBarcodeOrSearch(code);
                barcodeInput.value = '';
            }
        }
    });

    // Botón de modal de búsqueda F1
    btnOpenSearch.addEventListener('click', () => {
        openModal('modalSearchProduct');
        modalProductQuery.focus();
    });

    // Búsqueda predictiva modal F1
    let searchDebounce;
    modalProductQuery.addEventListener('input', () => {
        clearTimeout(searchDebounce);
        searchDebounce = setTimeout(async () => {
            const q = modalProductQuery.value.trim();
            if (q.length >= 2) {
                const res = await fetch(`/api/pos/search-products?q=${encodeURIComponent(q)}`);
                const data = await res.json();
                renderProductSearchResults(data.products || []);
            } else {
                document.getElementById('modalProductResults').innerHTML = '';
            }
        }, 150);
    });

    btnPayNow.addEventListener('click', () => {
        if (!state.cashSession) {
            showToast('Caja cerrada: Debes abrir un turno para procesar cobros.', 'warning');
            openModal('modalOpenCash');
            return;
        }
        if (state.cart.length === 0) {
            showToast('Agrega al menos un producto al carrito', 'warning');
            return;
        }
        openCheckoutModal();
    });

    btnClearCart.addEventListener('click', () => {
        if (state.cart.length > 0 && confirm('¿Deseas vaciar el carrito actual?')) {
            state.cart = [];
            renderCart();
        }
    });

    btnHoldSale.addEventListener('click', () => {
        toggleHoldSale();
    });

    btnChangeClient.addEventListener('click', () => {
        promptSelectClient();
    });

    initCheckoutModalEvents();
}

// Búsqueda y adición de producto por código de barra
async function addProductByBarcodeOrSearch(code) {
    if (!state.cashSession) {
        showToast('Caja cerrada. Debes abrir un turno para ingresar productos.', 'warning');
        openModal('modalOpenCash');
        return;
    }
    try {
        const res = await fetch(`/api/pos/search-products?q=${encodeURIComponent(code)}`);
        const data = await res.json();
        const prods = data.products || [];

        if (prods.length === 0) {
            alert(`No se encontró ningún producto con el código: ${code}`);
            return;
        }

        // Si hay una coincidencia exacta de código de barra o un solo resultado
        const exact = prods.find(p => p.codigos && p.codigos.includes(code)) || prods[0];
        addToCart(exact);
    } catch (err) {
        console.error(err);
        alert('Error al consultar producto');
    }
}

function renderProductSearchResults(products) {
    const list = document.getElementById('modalProductResults');
    if (products.length === 0) {
        list.innerHTML = '<div class="p-3 text-muted text-center">No se encontraron productos coincidentes.</div>';
        return;
    }

    list.innerHTML = products.map(p => `
        <div class="search-result-item" onclick="selectProductFromSearch(${p.id})">
            <div>
                <strong>${escapeHtml(p.nombre)}</strong>
                <span class="text-muted"> (${p.presentacion || p.unidad_medida})</span>
                <div class="font-mono text-cyan" style="font-size: 0.75rem;">Cod: ${p.codigo_interno} | Stock: ${p.stock_actual}</div>
            </div>
            <div class="text-right">
                <span class="font-mono" style="font-size: 1.25rem; font-weight: 700; color: #10b981;">$${Number(p.precio_venta).toFixed(2)}</span>
            </div>
        </div>
    `).join('');

    state.productsCache = products;
}

function selectProductFromSearch(prodId) {
    if (!state.cashSession) {
        showToast('Caja cerrada: Debes abrir un turno para ingresar productos.', 'warning');
        openModal('modalOpenCash');
        return;
    }
    const prod = state.productsCache.find(p => p.id === prodId);
    if (prod) {
        addToCart(prod);
        closeModal('modalSearchProduct');
    }
}

// Carrito de compras
function addToCart(product) {
    if (!state.cashSession) {
        showToast('Caja cerrada: No se pueden agregar productos sin un turno de caja abierto.', 'warning');
        openModal('modalOpenCash');
        return;
    }
    // Si ya existe en el carrito, sumar 1
    const existingIndex = state.cart.findIndex(i => i.product.id === product.id);

    if (existingIndex >= 0) {
        state.cart[existingIndex].quantity += 1;
        state.selectedItemIndex = existingIndex;
    } else {
        state.cart.push({
            product: product,
            quantity: 1.0,
            price: Number(product.precio_venta),
            discount: 0.00
        });
        state.selectedItemIndex = state.cart.length - 1;
    }

    renderCart();
}

function renderCart() {
    const tbody = document.getElementById('cartTableBody');
    if (state.cart.length === 0) {
        tbody.innerHTML = `
            <tr class="empty-row">
                <td colspan="6">
                    <div class="empty-cart-state">
                        <span class="empty-icon">🛒</span>
                        <h4>Carrito vacío</h4>
                        <p>Pasa un producto por el lector láser o presiona <kbd>F1</kbd> para buscar en el catálogo.</p>
                    </div>
                </td>
            </tr>
        `;
        updateCartTotals(0, 0, 0);
        return;
    }

    let subtotalGravado = 0;
    let totalDescuentos = 0;

    tbody.innerHTML = state.cart.map((item, idx) => {
        const lineTotal = (item.quantity * item.price) - item.discount;
        subtotalGravado += lineTotal;
        totalDescuentos += item.discount;

        const isSelected = idx === state.selectedItemIndex ? 'class="selected-line"' : '';

        return `
            <tr ${isSelected} onclick="selectCartItem(${idx})">
                <td class="font-mono text-muted">${idx + 1}</td>
                <td>
                    <strong>${escapeHtml(item.product.nombre)}</strong>
                    <div class="text-muted" style="font-size: 0.75rem;">${escapeHtml(item.product.presentacion || '')}</div>
                </td>
                <td class="text-right font-mono">$${item.price.toFixed(2)}</td>
                <td class="text-center">
                    <span class="qty-badge">${item.quantity}</span>
                </td>
                <td class="text-right font-mono" style="font-weight: 700; color: #10b981;">$${lineTotal.toFixed(2)}</td>
                <td class="text-center">
                    <button class="btn btn-link text-danger" onclick="removeCartItem(${idx}); event.stopPropagation();" title="Eliminar ítem">✕</button>
                </td>
            </tr>
        `;
    }).join('');

    updateCartTotals(subtotalGravado, totalDescuentos);
}

function updateCartTotals(totalGravado, descuentos) {
    const totalPagar = Math.max(0, totalGravado);
    // IVA 13% incluido en precio al consumidor final
    const baseGravada = totalPagar / 1.13;
    const iva = totalPagar - baseGravada;

    document.getElementById('summarySubtotal').textContent = `$${baseGravada.toFixed(2)}`;
    document.getElementById('summaryIva').textContent = `$${iva.toFixed(2)}`;
    document.getElementById('summaryDiscount').textContent = `-$${descuentos.toFixed(2)}`;
    document.getElementById('summaryGrandTotal').textContent = `$${totalPagar.toFixed(2)}`;
    document.getElementById('modalPayTotalAmount').textContent = `$${totalPagar.toFixed(2)}`;
}

function selectCartItem(index) {
    state.selectedItemIndex = index;
    renderCart();
}

function removeCartItem(index) {
    state.cart.splice(index, 1);
    if (state.selectedItemIndex >= state.cart.length) {
        state.selectedItemIndex = Math.max(0, state.cart.length - 1);
    }
    renderCart();
}

function promptChangeQuantity() {
    if (state.cart.length === 0) return;
    const item = state.cart[state.selectedItemIndex];
    const newQtyStr = prompt(`Modificar cantidad para "${item.product.nombre}":`, item.quantity);
    if (newQtyStr !== null) {
        const newQty = parseFloat(newQtyStr);
        if (!isNaN(newQty) && newQty > 0 && newQty <= 5000) {
            item.quantity = newQty;
            renderCart();
        } else {
            showToast('Cantidad inválida. Debe estar entre 0.01 y 5,000 unidades.', 'warning');
        }
    }
}

function promptItemDiscount() {
    if (state.cart.length === 0) return;
    const item = state.cart[state.selectedItemIndex];
    const lineTotal = item.quantity * item.price;
    const descStr = prompt(`Descuento en $ para "${item.product.nombre}" (Máx: $${lineTotal.toFixed(2)}):`, item.discount);
    if (descStr !== null) {
        const desc = parseFloat(descStr);
        if (!isNaN(desc) && desc >= 0 && desc < lineTotal) {
            item.discount = desc;
            renderCart();
        } else {
            showToast('Descuento inválido. No puede ser negativo ni igual o superior al total de la línea.', 'warning');
        }
    }
}

function toggleHoldSale() {
    if (state.cart.length > 0) {
        state.heldSales.push([...state.cart]);
        state.cart = [];
        renderCart();
        alert('Venta puesta en espera (Hold). Puedes atender al siguiente cliente.');
    } else if (state.heldSales.length > 0) {
        state.cart = state.heldSales.pop();
        renderCart();
        alert('Venta en espera recuperada al carrito.');
    } else {
        alert('No hay ventas en espera para recuperar.');
    }
    document.getElementById('holdSalesCount').textContent = state.heldSales.length;
}

function promptSelectClient() {
    const q = prompt('Buscar cliente por nombre o NIT:', '');
    if (q !== null && q.trim() !== '') {
        fetch(`/api/pos/search-clients?q=${encodeURIComponent(q)}`)
            .then(r => r.json())
            .then(data => {
                const clients = data.clients || [];
                if (clients.length === 0) {
                    if (confirm('Cliente no encontrado. ¿Deseas registrarlo rápido?')) {
                        const nuevoNombre = prompt('Nombre completo del cliente:');
                        if (nuevoNombre) {
                            fetch('/api/pos/create-client', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({ nombre: nuevoNombre })
                            }).then(r => r.json()).then(res => {
                                if (res.ok && res.client) {
                                    state.selectedClient = res.client;
                                    document.getElementById('selectedClientName').textContent = res.client.nombre;
                                }
                            });
                        }
                    }
                } else {
                    const c = clients[0];
                    state.selectedClient = c;
                    document.getElementById('selectedClientName').textContent = c.nombre;
                    alert(`Cliente asignado: ${c.nombre} (${c.nit || c.dui || 'Consumidor Final'})`);
                }
            });
    }
}

// ==========================================
// MODAL DE COBRO Y PAGO
// ==========================================
function openCheckoutModal() {
    if (!state.cashSession) {
        showToast('Caja cerrada. Debes abrir un turno para procesar cobros.', 'warning');
        openModal('modalOpenCash');
        return;
    }
    if (!state.cart || state.cart.length === 0) {
        showToast('El carrito está vacío. Agrega productos antes de cobrar.', 'warning');
        return;
    }
    openModal('modalCheckout');
    const grandTotalStr = document.getElementById('summaryGrandTotal').textContent.replace('$', '');
    const grandTotal = parseFloat(grandTotalStr) || 0;

    const bannerEl = document.getElementById('modalPayTotalAmount');
    if (bannerEl) bannerEl.textContent = `$${grandTotal.toFixed(2)}`;

    const cashInput = document.getElementById('cashReceivedInput');
    cashInput.value = grandTotal.toFixed(2);
    calculateChange();
    setTimeout(() => {
        cashInput.focus();
        cashInput.select();
    }, 50);
}

function initCheckoutModalEvents() {
    const cashInput = document.getElementById('cashReceivedInput');
    const payTabs = document.querySelectorAll('.pay-tab-btn');
    let activeMethod = 'EFECTIVO';

    // Enter en input de efectivo ejecuta confirmación
    cashInput.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            document.getElementById('btnConfirmPayment').click();
        }
    });

    payTabs.forEach(btn => {
        btn.addEventListener('click', () => {
            payTabs.forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            activeMethod = btn.dataset.method;

            document.getElementById('cashPaymentFields').classList.toggle('hidden', activeMethod !== 'EFECTIVO');
            document.getElementById('cardPaymentFields').classList.toggle('hidden', activeMethod !== 'TARJETA');
            document.getElementById('transferPaymentFields').classList.toggle('hidden', activeMethod !== 'TRANSFERENCIA');
            document.getElementById('creditPaymentFields').classList.toggle('hidden', activeMethod !== 'CREDITO');

            if (activeMethod === 'CREDITO') {
                const cli = state.selectedClient;
                const creditInfo = document.getElementById('creditStatusInfo');
                if (cli.aplica_credito) {
                    const disponible = cli.limite_credito - cli.saldo_deudor;
                    creditInfo.innerHTML = `Cliente con crédito autorizado. Límite: $${cli.limite_credito.toFixed(2)} | Saldo Deudor: $${cli.saldo_deudor.toFixed(2)} | <strong>Disponible: $${disponible.toFixed(2)}</strong>`;
                } else {
                    creditInfo.innerHTML = `<span class="text-danger">⚠️ El cliente '${cli.nombre}' no tiene crédito autorizado. Selecciona otro método o cambia de cliente.</span>`;
                }
            }
        });
    });

    cashInput.addEventListener('input', calculateChange);

    document.getElementById('btnExactCash').addEventListener('click', () => {
        const grandTotal = parseFloat(document.getElementById('summaryGrandTotal').textContent.replace('$', '')) || 0;
        cashInput.value = grandTotal.toFixed(2);
        calculateChange();
    });

    document.getElementById('btnConfirmPayment').addEventListener('click', async () => {
        if (!state.cashSession) {
            showToast('Operación denegada: La caja está cerrada. No se pueden procesar transacciones.', 'error');
            closeModal('modalCheckout');
            openModal('modalOpenCash');
            return;
        }
        const grandTotal = parseFloat(document.getElementById('summaryGrandTotal').textContent.replace('$', '')) || 0;
        const docType = document.querySelector('input[name="docType"]:checked')?.value || '01';

        const pagos = [];
        if (activeMethod === 'EFECTIVO') {
            const recibido = parseFloat(cashInput.value) || 0;
            if (recibido < grandTotal) {
                alert('El monto en efectivo recibido es menor al total a pagar.');
                return;
            }
            pagos.push({
                metodo_pago: 'EFECTIVO',
                monto: grandTotal,
                monto_recibido: recibido
            });
        } else if (activeMethod === 'TARJETA') {
            const ref = document.getElementById('cardReferenceInput').value.trim();
            pagos.push({
                metodo_pago: 'TARJETA',
                monto: grandTotal,
                referencia: ref
            });
        } else if (activeMethod === 'TRANSFERENCIA') {
            const ref = document.getElementById('transferReferenceInput').value.trim();
            pagos.push({
                metodo_pago: 'TRANSFERENCIA',
                monto: grandTotal,
                referencia: ref
            });
        } else if (activeMethod === 'CREDITO') {
            pagos.push({
                metodo_pago: 'CREDITO',
                monto: grandTotal
            });
        }

        const payload = {
            cliente_id: state.selectedClient.id,
            tipo_documento: docType,
            items: state.cart.map(i => ({
                producto_id: i.product.id,
                cantidad: i.quantity,
                descuento: i.discount
            })),
            pagos: pagos
        };

        try {
            const res = await fetch('/api/pos/checkout', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const data = await res.json();
            if (data.ok) {
                closeModal('modalCheckout');
                renderTicket(data.venta);
                openModal('modalTicketPreview');
                // Vaciar carrito
                state.cart = [];
                renderCart();
            } else {
                alert(`Error en cobro: ${data.error}`);
            }
        } catch (err) {
            console.error(err);
            alert('Error de conexión al procesar venta');
        }
    });
}

function addQuickCash(val) {
    const cashInput = document.getElementById('cashReceivedInput');
    const current = parseFloat(cashInput.value) || 0;
    cashInput.value = (current + val).toFixed(2);
    calculateChange();
}

function calculateChange() {
    const grandTotal = parseFloat(document.getElementById('summaryGrandTotal').textContent.replace('$', '')) || 0;
    const recibido = parseFloat(document.getElementById('cashReceivedInput').value) || 0;
    const vuelto = Math.max(0, recibido - grandTotal);
    document.getElementById('modalChangeAmount').textContent = `$${vuelto.toFixed(2)}`;
}

function renderTicket(venta) {
    const paper = document.getElementById('printableTicket');
    paper.innerHTML = `
        <div class="text-center">
            <h4>SUPERMERCADO EL SOL</h4>
            <div>SUPERMERCADO EL SOL, S.A. DE C.V.</div>
            <div>NIT: 0614-010190-101-1 | NRC: 123456-7</div>
            <div>San Salvador, El Salvador</div>
            <hr>
            <div><strong>DOCUMENTO TRIBUTARIO ELECTRÓNICO</strong></div>
            <div>${venta.numero_control}</div>
            <div style="font-size: 10px;">UUID: ${venta.codigo_generacion}</div>
            <div style="font-size: 10px;">Fecha: ${venta.fecha_hora}</div>
        </div>
        <hr>
        <div>Cliente: ${escapeHtml(state.selectedClient.nombre)}</div>
        <table class="ticket-table">
            <thead>
                <tr>
                    <th>Cant</th>
                    <th>Desc</th>
                    <th class="text-right">Total</th>
                </tr>
            </thead>
            <tbody>
                ${venta.items.map(it => `
                    <tr>
                        <td>${it.cantidad}</td>
                        <td>${escapeHtml(it.nombre)}</td>
                        <td class="text-right">$${Number(it.total_linea).toFixed(2)}</td>
                    </tr>
                `).join('')}
            </tbody>
        </table>
        <hr>
        <div class="text-right">
            <div>Subtotal: $${Number(venta.subtotal).toFixed(2)}</div>
            <div>IVA (13%): $${Number(venta.iva).toFixed(2)}</div>
            <div><strong>TOTAL A PAGAR: $${Number(venta.total).toFixed(2)}</strong></div>
            <div>Vuelto entregado: $${Number(venta.vuelto || 0).toFixed(2)}</div>
        </div>
        <hr>
        <div class="text-center" style="font-size: 10px;">
            ¡Gracias por su compra!<br>
            Resolución DTE Ministerio de Hacienda
        </div>
    `;
}

// ==========================================
// MÓDULO CONTROL DE CAJA Y TURNOS
// ==========================================
function initCashRegister() {
    let openCashMode = 'quick';

    const btnQuick = document.getElementById('btnModeQuickOpen');
    const btnDetailed = document.getElementById('btnModeDetailedOpen');
    const secQuick = document.getElementById('openCashQuickMode');
    const secDetailed = document.getElementById('openCashDetailedMode');
    const quickInput = document.getElementById('quickOpenAmountInput');

    function updateQuickTotal() {
        const val = parseFloat(quickInput?.value) || 0;
        const totalEl = document.getElementById('openCashTotalDisplay');
        if (totalEl) totalEl.textContent = `$${val.toFixed(2)}`;
    }

    if (btnQuick && btnDetailed) {
        btnQuick.addEventListener('click', () => {
            openCashMode = 'quick';
            btnQuick.classList.add('active', 'btn-primary');
            btnQuick.classList.remove('btn-outline');
            btnDetailed.classList.remove('active', 'btn-primary');
            btnDetailed.classList.add('btn-outline');
            if (secQuick) secQuick.classList.remove('hidden');
            if (secDetailed) secDetailed.classList.add('hidden');
            updateQuickTotal();
        });

        btnDetailed.addEventListener('click', () => {
            openCashMode = 'detailed';
            btnDetailed.classList.add('active', 'btn-primary');
            btnDetailed.classList.remove('btn-outline');
            btnQuick.classList.remove('active', 'btn-primary');
            btnQuick.classList.add('btn-outline');
            if (secDetailed) secDetailed.classList.remove('hidden');
            if (secQuick) secQuick.classList.add('hidden');
            calculateOpeningTotal();
        });
    }

    if (quickInput) {
        quickInput.addEventListener('input', updateQuickTotal);
    }

    document.querySelectorAll('.btn-preset-amount').forEach(btn => {
        btn.addEventListener('click', () => {
            const amt = parseFloat(btn.dataset.amount) || 0;
            if (quickInput) {
                quickInput.value = amt.toFixed(2);
                updateQuickTotal();
            }
        });
    });

    // Calculador en tiempo real de denominaciones apertura
    const denomInputs = document.querySelectorAll('.denom-input');
    denomInputs.forEach(input => {
        input.addEventListener('input', calculateOpeningTotal);
    });

    const denomCloseInputs = document.querySelectorAll('.denom-close-input');
    denomCloseInputs.forEach(input => {
        input.addEventListener('input', calculateClosingTotal);
    });

    document.getElementById('btnConfirmOpenCash')?.addEventListener('click', async () => {
        const cajaId = document.getElementById('openCajaSelect')?.value;
        if (!cajaId) {
            showToast('Por favor selecciona una terminal/caja para abrir.', 'warning');
            return;
        }

        let payload = { caja_id: cajaId };

        // Si el usuario activo es Cajero, se exige el PIN del Gerente o Administrador
        const userRole = state.user?.rol || 'CAJERO';
        if (userRole === 'CAJERO') {
            const supervisorPin = document.getElementById('openCashSupervisorPin')?.value.trim();
            if (!supervisorPin) {
                showToast('Apertura bloqueada: Un Gerente o Administrador debe ingresar su PIN para autorizar este turno de caja.', 'warning');
                document.getElementById('openCashSupervisorPin')?.focus();
                return;
            }
            payload.pin_supervisor = supervisorPin;
        } else if (['ADMIN', 'GERENTE', 'SUPERVISOR'].includes(userRole)) {
            // Un supervisor o admin puede asignar el cajero titular
            const cajeroSelect = document.getElementById('openCajeroSelect');
            if (cajeroSelect && cajeroSelect.value) {
                payload.cajero_id = cajeroSelect.value;
            }
        }

        if (openCashMode === 'quick') {
            const val = parseFloat(quickInput?.value);
            if (isNaN(val) || val < 0) {
                showToast('Ingresa un monto de fondo válido (≥ $0.00).', 'warning');
                return;
            }
            payload.monto_inicial = val;
        } else {
            const denominaciones = [];
            document.querySelectorAll('.denom-input').forEach(inp => {
                const cant = parseInt(inp.value) || 0;
                if (cant > 0) {
                    denominaciones.push({
                        valor: parseFloat(inp.dataset.val),
                        tipo: inp.dataset.tipo,
                        cantidad: cant
                    });
                }
            });
            payload.denominaciones = denominaciones;
        }

        try {
            const res = await fetch('/api/cash/open', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const data = await res.json();
            if (data.ok) {
                const pinInp = document.getElementById('openCashSupervisorPin');
                if (pinInp) pinInp.value = '';
                closeModal('modalOpenCash');
                checkCashSession();
                showToast('Turno de caja abierto y autorizado con éxito.', 'success');
            } else {
                showToast(`Error de autorización: ${data.error}`, 'error');
            }
        } catch (e) {
            showToast('Error de comunicación con el servidor.', 'error');
        }
    });

    document.getElementById('btnFillExactCashClose')?.addEventListener('click', () => {
        let amt = parseFloat(document.getElementById('btnFillExactCashClose')?.dataset?.amount);
        if (isNaN(amt) && state.cashSession) {
            amt = parseFloat(state.cashSession.efectivo_esperado || 0);
        }
        if (isNaN(amt) || amt < 0) amt = 0;
        fillExactCashClose(amt);
    });

    document.getElementById('btnConfirmCloseCash')?.addEventListener('click', async () => {
        const targetSessionId = document.getElementById('closeTargetSessionId')?.value 
            || state.targetCloseSessionId 
            || state.cashSession?.id;

        if (!targetSessionId) {
            showToast('No se encontró ninguna sesión de caja para cerrar.', 'error');
            return;
        }

        const userRole = state.user?.rol || 'CAJERO';
        let pin = document.getElementById('closeSupervisorPin').value.trim();
        if (!pin && ['ADMIN', 'GERENTE'].includes(userRole)) {
            pin = '1234';
        }

        if (!pin && userRole === 'CAJERO') {
            showToast('Debes ingresar el PIN de supervisor para autorizar el Corte Z.', 'warning');
            document.getElementById('closeSupervisorPin')?.focus();
            return;
        }

        const denominaciones = [];
        document.querySelectorAll('.denom-close-input').forEach(inp => {
            const cant = parseInt(inp.value) || 0;
            if (cant > 0) {
                denominaciones.push({
                    valor: parseFloat(inp.dataset.val),
                    cantidad: cant
                });
            }
        });

        const countedTotal = parseFloat(document.getElementById('closeCashTotalDisplay')?.textContent.replace('$', '')) || 0;

        try {
            const res = await fetch('/api/cash/close', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    sesion_id: targetSessionId,
                    pin_supervisor: pin,
                    denominaciones: denominaciones,
                    monto_real: countedTotal
                })
            });
            const data = await res.json();
            if (data.ok) {
                closeModal('modalCloseCash');
                checkCashSession();
                showToast(`Cierre de caja completado. Corte Z: ${data.corte_z.corte_z_numero} | Esperado: $${Number(data.corte_z.monto_esperado).toFixed(2)} | Real: $${Number(data.corte_z.monto_real).toFixed(2)} | Dif: $${Number(data.corte_z.diferencia).toFixed(2)}`, 'success');
                // Limpiar inputs de modal de cierre
                document.querySelectorAll('.denom-close-input').forEach(i => i.value = 0);
                document.getElementById('closeCashTotalDisplay').textContent = '$0.00';
                document.getElementById('closeSupervisorPin').value = '';
                const targetInp = document.getElementById('closeTargetSessionId');
                if (targetInp) targetInp.value = '';
                const infoBox = document.getElementById('closeSessionHeaderInfo');
                if (infoBox) infoBox.innerHTML = '';
                state.targetCloseSessionId = null;
            } else {
                showToast(`Error en cierre: ${data.error}`, 'error');
            }
        } catch (e) {
            showToast('Error de conexión al cerrar caja.', 'error');
        }
    });
}

function calculateOpeningTotal() {
    let tot = 0;
    document.querySelectorAll('.denom-input').forEach(inp => {
        const val = parseFloat(inp.dataset.val) || 0;
        const cant = parseInt(inp.value) || 0;
        tot += (val * cant);
    });
    document.getElementById('openCashTotalDisplay').textContent = `$${tot.toFixed(2)}`;
}

function calculateClosingTotal() {
    let tot = 0;
    document.querySelectorAll('.denom-close-input').forEach(inp => {
        const val = parseFloat(inp.dataset.val) || 0;
        const cant = parseInt(inp.value) || 0;
        tot += (val * cant);
    });
    document.getElementById('closeCashTotalDisplay').textContent = `$${tot.toFixed(2)}`;
}

// Bloqueo y habilitación de controles del POS según estado de caja
function updatePosControlsState(isOpen) {
    const banner = document.getElementById('posCashClosedOverlay');
    const posLayout = document.querySelector('.pos-layout');
    const barcodeInput = document.getElementById('barcodeInput');
    const btnOpenSearch = document.getElementById('btnOpenSearchModal');
    const btnPay = document.getElementById('btnPayNow');
    const btnClear = document.getElementById('btnClearCart');
    const btnHold = document.getElementById('btnHoldSale');
    const btnClient = document.getElementById('btnChangeClient');

    if (!isOpen && state.cart && state.cart.length > 0) {
        state.cart = [];
        renderCart();
    }

    if (banner) {
        banner.classList.toggle('hidden', isOpen);
    }
    if (posLayout) {
        posLayout.classList.toggle('pos-disabled-controls', !isOpen);
    }
    if (barcodeInput) {
        barcodeInput.disabled = !isOpen;
        barcodeInput.placeholder = isOpen 
            ? 'Escanear código de barras o presionar F1 para buscar...'
            : '🔒 Caja cerrada. Abre turno para escanear productos...';
    }
    if (btnOpenSearch) btnOpenSearch.disabled = !isOpen;
    if (btnPay) btnPay.disabled = !isOpen;
    if (btnClear) btnClear.disabled = !isOpen;
    if (btnHold) btnHold.disabled = !isOpen;
    if (btnClient) btnClient.disabled = !isOpen;
}

async function checkCashSession() {
    try {
        const res = await fetch('/api/cash/session');
        const data = await res.json();
        const badge = document.getElementById('sessionStatusBadge');
        const actionsContainer = document.getElementById('cashActionButtons');
        const detailsContainer = document.getElementById('cashSessionDetails');

        // Mostrar u ocultar el Monitor de Cajas Activas según rol de administración
        const userRole = state.user?.rol || data.user_role || 'CAJERO';
        const adminPanel = document.getElementById('adminActiveCashPanel');
        if (adminPanel) {
            if (['ADMIN', 'GERENTE', 'SUPERVISOR', 'CONTADOR'].includes(userRole)) {
                adminPanel.classList.remove('hidden');
                renderActiveCashSessions(data.active_sessions || []);
            } else {
                adminPanel.classList.add('hidden');
            }
        }

        if (data.has_active_session && data.session) {
            state.cashSession = data.session;
            updatePosControlsState(true);
            badge.className = 'status-indicator status-open';
            badge.innerHTML = `<span class="status-dot green"></span> Turno Abierto (${escapeHtml(data.session.caja_nombre)})`;

            actionsContainer.innerHTML = `
                <button class="btn btn-secondary" onclick="openCashMovementModal()">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
                    Ingreso / Retiro
                </button>
                <button class="btn btn-danger" onclick="openModal('modalCloseCash')">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                    Corte Z (Cerrar Turno)
                </button>
            `;

            const m = data.metrics;
            const openTimeFormatted = formatDateTime(data.session.fecha_apertura);
            detailsContainer.innerHTML = `
                <div class="kpi-card">
                    <div class="kpi-header-row">
                        <span class="kpi-label">Fondo de Apertura</span>
                        <span class="kpi-icon-pill">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/></svg>
                        </span>
                    </div>
                    <div class="kpi-value font-mono">$${m.monto_apertura.toFixed(2)}</div>
                    <div class="kpi-footer-row text-muted">
                        <span>Apertura: ${openTimeFormatted}</span>
                    </div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-header-row">
                        <span class="kpi-label">Ventas en Efectivo</span>
                        <span class="kpi-icon-pill text-emerald">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" x2="12" y1="2" y2="22"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                        </span>
                    </div>
                    <div class="kpi-value font-mono text-emerald">$${m.ventas_efectivo.toFixed(2)}</div>
                    <div class="kpi-footer-row text-muted">
                        <span>Cobrado en gaveta hoy</span>
                    </div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-header-row">
                        <span class="kpi-label">Tarjetas & Transferencias</span>
                        <span class="kpi-icon-pill text-cyan">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/></svg>
                        </span>
                    </div>
                    <div class="kpi-value font-mono text-cyan">$${(m.ventas_tarjeta + m.ventas_transferencia).toFixed(2)}</div>
                    <div class="kpi-footer-row text-muted">
                        <span>Vouchers POS y Bancos</span>
                    </div>
                </div>
                <div class="kpi-card kpi-card-highlight">
                    <div class="kpi-header-row">
                        <span class="kpi-label text-emerald">Efectivo Esperado</span>
                        <span class="kpi-icon-pill text-emerald">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                        </span>
                    </div>
                    <div class="kpi-value font-mono text-emerald">$${m.efectivo_esperado.toFixed(2)}</div>
                    <div class="kpi-footer-row text-warning">
                        <span>Fondo + Ventas Efec. + Ingresos - Retiros</span>
                    </div>
                </div>
            `;
        } else {
            state.cashSession = null;
            updatePosControlsState(false);
            badge.className = 'status-indicator status-closed';
            badge.innerHTML = `<span class="status-dot red"></span> Caja Cerrada (Apertura requerida)`;

            actionsContainer.innerHTML = `
                <button class="btn btn-primary" onclick="openModal('modalOpenCash')">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 9.9-1"/></svg>
                    Abrir Turno de Caja
                </button>
            `;

            detailsContainer.innerHTML = `
                <div class="card p-4 text-center" style="grid-column: 1 / -1; border-style:dashed;">
                    <div style="margin-bottom:0.75rem;">
                        <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="var(--text-secondary)" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                    </div>
                    <h4>No hay ningún turno de caja abierto para tu usuario.</h4>
                    <p class="text-muted">Abre un turno con fondo inicial en gaveta para habilitar el escáner y registrar cobros en el POS.</p>
                    <button class="btn btn-primary btn-lg mt-3" style="margin: 0 auto; display:inline-flex; align-items:center; gap:0.5rem;" onclick="openModal('modalOpenCash')">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 9.9-1"/></svg>
                        Abrir Turno de Caja Ahora
                    </button>
                </div>
            `;

            // Llenar select de cajas disponibles
            const sel = document.getElementById('openCajaSelect');
            if (sel && data.available_cajas) {
                sel.innerHTML = data.available_cajas.map(c => `<option value="${c.id}">${c.nombre} (${c.numero_terminal})</option>`).join('');
            }

            // Configurar modal de apertura según el rol del usuario
            const supervisorAuthGroup = document.getElementById('openSupervisorAuthGroup');
            const cajeroGroup = document.getElementById('openCajeroGroup');
            const cajeroSelect = document.getElementById('openCajeroSelect');

            if (userRole === 'CAJERO') {
                // El cajero requiere obligatoriamente PIN de supervisor
                if (supervisorAuthGroup) supervisorAuthGroup.classList.remove('hidden');
                if (cajeroGroup) cajeroGroup.classList.add('hidden');
            } else {
                // Gerente o Admin puede abrir directamente y asignar cajero
                if (supervisorAuthGroup) supervisorAuthGroup.classList.add('hidden');
                if (cajeroGroup) cajeroGroup.classList.remove('hidden');
                if (cajeroSelect && data.available_cajeros) {
                    cajeroSelect.innerHTML = data.available_cajeros.map(u => 
                        `<option value="${u.id}" ${u.id === state.user?.id ? 'selected' : ''}>${escapeHtml(u.nombre_completo)} (${u.rol})</option>`
                    ).join('');
                }
            }
        }
    } catch (e) {
        console.error(e);
    }
}

// ==========================================
// MONITOR DE CAJAS ACTIVAS Y CORTE Z REMOTO (ADMIN)
// ==========================================
async function loadActiveCashSessions() {
    try {
        const res = await fetch('/api/cash/active-sessions');
        const data = await res.json();
        if (data.ok) {
            renderActiveCashSessions(data.active_sessions || []);
            showToast('Monitor de cajas activas actualizado', 'info');
        }
    } catch (e) {
        console.error(e);
    }
}

function renderActiveCashSessions(sessions) {
    const tbody = document.getElementById('activeCashTableBody');
    const countBadge = document.getElementById('activeCashCountBadge');
    if (countBadge) {
        countBadge.textContent = `${sessions.length} caja${sessions.length === 1 ? '' : 's'} activa${sessions.length === 1 ? '' : 's'}`;
    }

    if (!tbody) return;

    if (!sessions || sessions.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="10" class="text-center text-muted p-4">
                    <div style="font-size:1.5rem; margin-bottom:0.5rem;">🔒</div>
                    <strong>No hay ninguna caja abierta en este momento en la sucursal.</strong>
                    <div style="font-size:0.8125rem; margin-top:0.25rem;">Todas las terminales se encuentran cerradas o con Corte Z emitido.</div>
                </td>
            </tr>
        `;
        return;
    }

    tbody.innerHTML = sessions.map(s => {
        const openTime = formatDateTime(s.fecha_apertura);
        const sJson = JSON.stringify(s).replace(/"/g, '&quot;');
        return `
            <tr>
                <td style="padding:0.75rem 1rem;">
                    <strong>${escapeHtml(s.caja_nombre)}</strong>
                    <div class="font-mono text-muted" style="font-size:0.75rem;">${escapeHtml(s.numero_terminal)}</div>
                </td>
                <td style="padding:0.75rem 1rem;">
                    <div style="font-weight:600;">${escapeHtml(s.cajero_nombre)}</div>
                    <div class="text-muted" style="font-size:0.75rem;">@${escapeHtml(s.cajero_username)}</div>
                </td>
                <td style="padding:0.75rem 1rem; font-size:0.8125rem;">
                    ${openTime}
                </td>
                <td style="padding:0.75rem 1rem;" class="text-right font-mono font-bold">$${Number(s.monto_apertura).toFixed(2)}</td>
                <td style="padding:0.75rem 1rem;" class="text-right font-mono text-emerald font-bold">$${Number(s.ventas_efectivo).toFixed(2)}</td>
                <td style="padding:0.75rem 1rem;" class="text-right font-mono font-bold">$${Number(s.total_ventas).toFixed(2)}</td>
                <td style="padding:0.75rem 1rem;" class="text-center font-mono">
                    <span class="badge badge-info" style="font-size:0.8rem;">${s.total_transacciones}</span>
                </td>
                <td style="padding:0.75rem 1rem;" class="text-right font-mono font-bold" style="color:#059669; font-size:1.05rem;">
                    $${Number(s.efectivo_esperado).toFixed(2)}
                </td>
                <td style="padding:0.75rem 1rem;" class="text-center">
                    <span class="status-indicator status-open" style="font-size:0.75rem; padding:0.2rem 0.5rem;">
                        <span class="status-dot green"></span> Abierta
                    </span>
                </td>
                <td style="padding:0.75rem 1rem;" class="text-center">
                    <button class="btn btn-danger btn-sm" onclick="openAdminCloseCashModal(${sJson})" style="display:inline-flex; align-items:center; gap:0.35rem; padding:0.35rem 0.65rem; font-size:0.8125rem;">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                        Cerrar Caja
                    </button>
                </td>
            </tr>
        `;
    }).join('');
}

window.openAdminCloseCashModal = function(ses) {
    state.targetCloseSessionId = ses.id;
    const targetInp = document.getElementById('closeTargetSessionId');
    if (targetInp) targetInp.value = ses.id;

    const infoBox = document.getElementById('closeSessionHeaderInfo');
    if (infoBox) {
        infoBox.innerHTML = `
            <div style="background:#eff6ff; border:1px solid #bfdbfe; border-radius:8px; padding:0.75rem 1rem; margin-bottom:1rem;">
                <div style="display:flex; justify-content:space-between; align-items:center;">
                    <div>
                        <strong style="color:#1e40af; font-size:1rem;">${escapeHtml(ses.caja_nombre)} (${escapeHtml(ses.numero_terminal)})</strong>
                        <div class="text-muted" style="font-size:0.8125rem;">Cajero titular: <strong>${escapeHtml(ses.cajero_nombre)}</strong> (@${escapeHtml(ses.cajero_username)})</div>
                    </div>
                    <div class="text-right">
                        <span style="font-size:0.75rem; color:#6b7280; display:block;">Efectivo Estimado:</span>
                        <strong class="font-mono text-emerald" style="font-size:1.25rem;">$${Number(ses.efectivo_esperado).toFixed(2)}</strong>
                    </div>
                </div>
            </div>
        `;
    }

    const btnFill = document.getElementById('btnFillExactCashClose');
    if (btnFill) {
        btnFill.dataset.amount = ses.efectivo_esperado;
    }

    // Reset denominations
    document.querySelectorAll('.denom-close-input').forEach(i => i.value = 0);
    document.getElementById('closeCashTotalDisplay').textContent = '$0.00';

    const userRole = state.user?.rol || 'CAJERO';
    const pinInput = document.getElementById('closeSupervisorPin');
    if (pinInput) {
        if (['ADMIN', 'GERENTE'].includes(userRole)) {
            pinInput.placeholder = 'PIN supervisor (Opcional: ya autenticado como ' + userRole + ')';
            pinInput.value = '1234';
        } else {
            pinInput.placeholder = 'PIN supervisor requerido (1234)';
            pinInput.value = '';
        }
    }

    openModal('modalCloseCash');
};

function fillExactCashClose(amount) {
    let remaining = Math.round(amount * 100);
    const denoms = [
        { val: '50', cents: 5000 },
        { val: '20', cents: 2000 },
        { val: '10', cents: 1000 },
        { val: '5', cents: 500 },
        { val: '1', cents: 100 },
        { val: '0.25', cents: 25 },
        { val: '0.10', cents: 10 }
    ];

    document.querySelectorAll('.denom-close-input').forEach(i => i.value = 0);

    for (const d of denoms) {
        if (remaining >= d.cents) {
            const count = Math.floor(remaining / d.cents);
            remaining = remaining % d.cents;
            const input = document.querySelector(`.denom-close-input[data-val="${d.val}"]`);
            if (input) input.value = count;
        }
    }
    calculateClosingTotal();
}

// ==========================================
// MÓDULO INVENTARIO, AJUSTES, CSV Y LOTES
// ==========================================
let allInventoryCache = [];
let allCategoriesCache = [];
let allBodegasCache = [];

function initInventoryModule() {
    const btnNew = document.getElementById('btnNewProduct');
    const btnAdjust = document.getElementById('btnQuickAdjust');
    const btnCsv = document.getElementById('btnImportCsv');
    const btnLots = document.getElementById('btnViewLots');
    const btnSaveProd = document.getElementById('btnSaveProductModal');
    const btnConfirmAdjust = document.getElementById('btnConfirmStockAdjust');
    const btnRunCsv = document.getElementById('btnExecuteCsvImport');
    const btnSampleCsv = document.getElementById('btnFillCsvSample');
    const fileCsv = document.getElementById('importCsvFile');
    const btnRefreshLots = document.getElementById('btnRefreshLotsModal');
    const searchInput = document.getElementById('inventorySearchInput');
    const catFilter = document.getElementById('inventoryCategoryFilter');

    if (btnNew) btnNew.addEventListener('click', () => openProductEditModal(0));
    if (btnAdjust) btnAdjust.addEventListener('click', openStockAdjustModal);
    if (btnCsv) btnCsv.addEventListener('click', openImportCsvModal);
    if (btnLots) btnLots.addEventListener('click', openLotsModal);
    if (btnSaveProd) btnSaveProd.addEventListener('click', saveProductModal);
    if (btnConfirmAdjust) btnConfirmAdjust.addEventListener('click', confirmStockAdjust);
    if (btnRunCsv) btnRunCsv.addEventListener('click', executeCsvImport);
    if (btnRefreshLots) btnRefreshLots.addEventListener('click', loadLotsTable);

    if (btnSampleCsv) {
        btnSampleCsv.addEventListener('click', () => {
            const sample = `codigo,nombre,categoria,precio_unitario,costo_unitario,codigo_barra,stock_inicial,lote,vencimiento\n` +
                `BEB-010,Coca Cola 2.5L Retornable,Bebidas,2.50,1.90,741000000010,50,L-202610,2027-06-30\n` +
                `LAC-015,Queso Morolique Especial 1Lb,Lacteos,4.25,3.20,741000000015,30,L-202609,2026-11-20\n` +
                `PAN-003,Pan de Caja Blanco 500g,Panaderia,1.80,1.25,741000000018,40,L-202608,2026-10-31\n` +
                `ABR-005,Arroz Blanco San Pedro 1Lb,Granos Basicos,0.65,0.48,741000000022,100,L-202601,2028-01-15\n` +
                `CAR-002,Pechuga Deshuesada Pollo Kg,Carnes,5.50,4.10,741000000030,25,L-202611,2026-11-05`;
            document.getElementById('importCsvTextarea').value = sample;
        });
    }

    if (fileCsv) {
        fileCsv.addEventListener('change', (e) => {
            const file = e.target.files[0];
            if (file) {
                document.getElementById('importCsvFileName').textContent = file.name;
                const reader = new FileReader();
                reader.onload = (evt) => {
                    document.getElementById('importCsvTextarea').value = evt.target.result;
                };
                reader.readAsText(file);
            }
        });
    }

    if (searchInput) {
        searchInput.addEventListener('input', () => filterInventoryTable());
    }

    if (catFilter) {
        catFilter.addEventListener('change', () => filterInventoryTable());
    }

    const kardexSearch = document.getElementById('kardexSearchFilter');
    const kardexType = document.getElementById('kardexTypeFilter');
    if (kardexSearch) kardexSearch.addEventListener('input', filterKardexTable);
    if (kardexType) kardexType.addEventListener('change', filterKardexTable);
}

async function loadInventory() {
    try {
        const res = await fetch('/api/inventory/products');
        const data = await res.json();
        const tbody = document.getElementById('inventoryTableBody');

        allInventoryCache = data.products || [];
        allCategoriesCache = data.categorias || [];
        allBodegasCache = data.bodegas || [];

        // Llenar select categorías en filtro
        const catSel = document.getElementById('inventoryCategoryFilter');
        if (catSel && allCategoriesCache.length > 0) {
            const currentVal = catSel.value;
            catSel.innerHTML = '<option value="0">Todas las categorías</option>' +
                allCategoriesCache.map(c => `<option value="${c.id}" ${String(c.id) === String(currentVal) ? 'selected' : ''}>${escapeHtml(c.nombre)}</option>`).join('');
        }

        renderInventoryTable(allInventoryCache);
    } catch (e) {
        console.error(e);
        showToast('Error al cargar inventario', 'error');
    }
}

function renderInventoryTable(items) {
    const tbody = document.getElementById('inventoryTableBody');
    if (!tbody) return;

    if (!items || items.length === 0) {
        tbody.innerHTML = '<tr><td colspan="10" class="text-center text-muted">No se encontraron productos en el inventario.</td></tr>';
        return;
    }

    tbody.innerHTML = items.map(p => `
        <tr>
            <td class="font-mono text-cyan" style="font-weight:700;">${escapeHtml(p.codigo_interno)}</td>
            <td class="font-mono" style="font-size:0.75rem;">${escapeHtml(p.codigos_barra || '-')}</td>
            <td>
                <strong>${escapeHtml(p.nombre)}</strong>
                <div class="text-muted" style="font-size:0.75rem;">${escapeHtml(p.presentacion || '')} ${p.es_pesable ? '⚖️ (Pesable)' : ''} ${p.maneja_lotes ? '📅 (Lotes)' : ''}</div>
            </td>
            <td>${escapeHtml(p.categoria_nombre || '-')}</td>
            <td class="text-right font-mono">$${Number(p.costo_promedio).toFixed(2)}</td>
            <td class="text-right font-mono text-emerald" style="font-weight:700;">$${Number(p.precio_venta).toFixed(2)}</td>
            <td class="text-center font-mono">${Number(p.stock_piso_venta).toFixed(1)}</td>
            <td class="text-center font-mono">${Number(p.stock_bodega_principal).toFixed(1)}</td>
            <td class="text-center font-mono" style="font-weight:700; color:${Number(p.stock_total) <= Number(p.stock_minimo) ? '#ef4444' : '#10b981'};">${Number(p.stock_total).toFixed(1)}</td>
            <td style="white-space:nowrap;">
                <button class="btn btn-outline btn-xs" onclick="openProductEditModal(${p.id})">✏️ Editar</button>
                <button class="btn btn-secondary btn-xs" onclick="viewKardex(${p.id})">📊 Kardex</button>
            </td>
        </tr>
    `).join('');
}

function filterInventoryTable() {
    const q = (document.getElementById('inventorySearchInput')?.value || '').toLowerCase().trim();
    const catSel = document.getElementById('inventoryCategoryFilter');
    const catId = parseInt(catSel?.value || '0');
    const selectedCatText = (catSel && catId > 0 && catSel.selectedIndex >= 0) ? (catSel.options[catSel.selectedIndex]?.text || '').toLowerCase().trim() : '';

    const filtered = allInventoryCache.filter(p => {
        let matchCat = (catId === 0);
        if (!matchCat) {
            const pCatId = parseInt(p.categoria_id || '0');
            const pCatName = (p.categoria_nombre || '').toLowerCase().trim();
            if (pCatId > 0 && pCatId === catId) {
                matchCat = true;
            } else if (pCatName && selectedCatText && pCatName === selectedCatText) {
                matchCat = true;
            }
        }

        const matchText = !q ||
            (p.nombre && p.nombre.toLowerCase().includes(q)) ||
            (p.codigo_interno && p.codigo_interno.toLowerCase().includes(q)) ||
            (p.codigos_barra && p.codigos_barra.toLowerCase().includes(q));

        return matchCat && matchText;
    });

    renderInventoryTable(filtered);
}

function openProductEditModal(productId) {
    const idF = document.getElementById('prodEditId');
    const codF = document.getElementById('prodEditCodigo');
    const barF = document.getElementById('prodEditBarcode');
    const nomF = document.getElementById('prodEditNombre');
    const catF = document.getElementById('prodEditCategoria');
    const presF = document.getElementById('prodEditPresentacion');
    const precF = document.getElementById('prodEditPrecio');
    const costF = document.getElementById('prodEditCosto');
    const stkMinF = document.getElementById('prodEditStockMin');
    const pesableF = document.getElementById('prodEditPesable');
    const lotesF = document.getElementById('prodEditLotes');
    const titleF = document.getElementById('productEditModalTitle');

    // Cargar select categorías
    if (catF && allCategoriesCache.length > 0) {
        catF.innerHTML = allCategoriesCache.map(c => `<option value="${c.id}">${escapeHtml(c.nombre)}</option>`).join('');
    }

    if (productId > 0) {
        const prod = allInventoryCache.find(x => Number(x.id) === Number(productId));
        if (!prod) return;
        idF.value = prod.id;
        codF.value = prod.codigo_interno || '';
        barF.value = (prod.codigos_barra || '').split(',')[0].trim();
        nomF.value = prod.nombre || '';
        catF.value = prod.categoria_id || '';
        presF.value = prod.presentacion || '';
        precF.value = Number(prod.precio_venta || 0).toFixed(2);
        costF.value = Number(prod.costo_promedio || 0).toFixed(4);
        stkMinF.value = Number(prod.stock_minimo || 10).toFixed(0);
        pesableF.checked = Boolean(prod.es_pesable);
        lotesF.checked = Boolean(prod.maneja_lotes);
        titleF.textContent = `✏️ Modificar Producto: ${prod.nombre}`;
    } else {
        idF.value = '0';
        codF.value = '';
        barF.value = '';
        nomF.value = '';
        presF.value = '';
        precF.value = '1.00';
        costF.value = '0.75';
        stkMinF.value = '10';
        pesableF.checked = false;
        lotesF.checked = false;
        titleF.textContent = '➕ Nuevo Producto';
    }

    openModal('modalProductEdit');
}

async function saveProductModal() {
    const id = parseInt(document.getElementById('prodEditId').value || '0');
    const codigo = document.getElementById('prodEditCodigo').value.trim();
    const barcode = document.getElementById('prodEditBarcode').value.trim();
    const nombre = document.getElementById('prodEditNombre').value.trim();
    const categoria_id = parseInt(document.getElementById('prodEditCategoria').value || '1');
    const presentacion = document.getElementById('prodEditPresentacion').value.trim();
    const precio = parseFloat(document.getElementById('prodEditPrecio').value) || 0;
    const costo = parseFloat(document.getElementById('prodEditCosto').value) || 0;
    const stock_min = parseFloat(document.getElementById('prodEditStockMin').value) || 10;
    const es_pesable = document.getElementById('prodEditPesable').checked;
    const maneja_lotes = document.getElementById('prodEditLotes').checked;

    if (!codigo || !nombre) {
        showToast('Código y Nombre del producto son obligatorios.', 'warning');
        return;
    }
    if (precio <= 0) {
        showToast('El precio de venta debe ser mayor a $0.00.', 'warning');
        return;
    }
    if (precio > 2500) {
        showToast('El precio de venta no puede exceder $2,500.00 en supermercado minorista.', 'warning');
        return;
    }
    if (costo < 0 || costo > 2500) {
        showToast('El costo unitario debe estar entre $0.00 y $2,500.00.', 'warning');
        return;
    }
    if (stock_min < 0 || stock_min > 50000) {
        showToast('El stock mínimo debe estar entre 0 y 50,000 unidades.', 'warning');
        return;
    }

    const payload = {
        id: id,
        codigo_interno: codigo,
        codigo_barra: barcode,
        codigos_barra: barcode ? [barcode] : [],
        nombre: nombre,
        categoria_id: categoria_id,
        presentacion: presentacion,
        precio_venta: precio,
        precio_unitario: precio,
        costo_promedio: costo,
        costo_unitario: costo,
        stock_minimo: stock_min,
        es_pesable: es_pesable,
        maneja_lotes: maneja_lotes
    };

    try {
        const res = await fetch('/api/inventory/save-product', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const data = await res.json();
        if (data.ok) {
            closeModal('modalProductEdit');
            showToast(data.message || 'Producto guardado exitosamente', 'success');
            loadInventory();
        } else {
            showToast(data.error || 'Error al guardar producto', 'error');
        }
    } catch (e) {
        console.error(e);
        showToast('Error de red al guardar producto', 'error');
    }
}

function openStockAdjustModal() {
    const prodSel = document.getElementById('adjProductSelect');
    const bodSel = document.getElementById('adjBodegaSelect');
    const pinF = document.getElementById('adjSupervisorPin');
    const qtyF = document.getElementById('adjCantidad');
    const motF = document.getElementById('adjMotivo');

    if (prodSel && allInventoryCache.length > 0) {
        prodSel.innerHTML = allInventoryCache.map(p => `<option value="${p.id}">[${escapeHtml(p.codigo_interno)}] ${escapeHtml(p.nombre)} (Stock Total: ${Number(p.stock_total).toFixed(1)})</option>`).join('');
    }

    if (bodSel && allBodegasCache.length > 0) {
        bodSel.innerHTML = allBodegasCache.map(b => `<option value="${b.id}">${escapeHtml(b.nombre)} (${escapeHtml(b.codigo)})</option>`).join('');
    }

    qtyF.value = '';
    motF.value = '';
    pinF.value = '';
    openModal('modalStockAdjust');
}

async function confirmStockAdjust() {
    const prodId = parseInt(document.getElementById('adjProductSelect').value || '0');
    const bodId = parseInt(document.getElementById('adjBodegaSelect').value || '0');
    const tipo = document.getElementById('adjTipoSelect').value;
    const qty = parseFloat(document.getElementById('adjCantidad').value) || 0;
    const motivo = document.getElementById('adjMotivo').value.trim();
    const pin = document.getElementById('adjSupervisorPin').value.trim();

    if (prodId <= 0 || bodId <= 0 || !motivo || !pin) {
        showToast('Por favor completa todos los campos requeridos y el PIN de supervisor.', 'warning');
        return;
    }
    if (qty <= 0) {
        showToast('La cantidad debe ser mayor a 0.', 'warning');
        return;
    }
    if (qty > 10000) {
        showToast('La cantidad del ajuste no puede exceder 10,000 unidades.', 'warning');
        return;
    }

    const payload = {
        producto_id: prodId,
        bodega_id: bodId,
        tipo_ajuste: tipo,
        cantidad: qty,
        motivo: motivo,
        supervisor_pin: pin
    };

    try {
        const res = await fetch('/api/inventory/adjust', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const data = await res.json();
        if (data.ok) {
            closeModal('modalStockAdjust');
            showToast(data.message || 'Ajuste de inventario aplicado con éxito', 'success');
            loadInventory();
        } else {
            showToast(data.error || 'Error al aplicar ajuste', 'error');
        }
    } catch (e) {
        console.error(e);
        showToast('Error de red al aplicar ajuste', 'error');
    }
}

function openImportCsvModal() {
    document.getElementById('importCsvTextarea').value = '';
    document.getElementById('importCsvFileName').textContent = '';
    openModal('modalProductImport');
}

async function executeCsvImport() {
    const csvContent = document.getElementById('importCsvTextarea').value.trim();
    if (!csvContent) {
        showToast('Debes ingresar o cargar contenido CSV válido.', 'warning');
        return;
    }

    const btn = document.getElementById('btnExecuteCsvImport');
    btn.disabled = true;
    btn.textContent = '⏳ Procesando importación...';

    try {
        const res = await fetch('/api/inventory/import-csv', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ csv_content: csvContent, bodega_id: 1 })
        });
        const data = await res.json();
        btn.disabled = false;
        btn.textContent = '🚀 Procesar e Importar al Catálogo';

        if (data.ok) {
            closeModal('modalProductImport');
            showToast(data.message || `Importados: ${data.imported_count}`, 'success');
            if (data.errors && data.errors.length > 0) {
                showToast(`Algunas líneas tuvieron advertencias: ${data.errors.join(' | ')}`, 'warning');
            }
            loadInventory();
        } else {
            showToast(data.error || 'Error al procesar CSV', 'error');
        }
    } catch (e) {
        btn.disabled = false;
        btn.textContent = '🚀 Procesar e Importar al Catálogo';
        console.error(e);
        showToast('Error de conexión al importar CSV', 'error');
    }
}

function openLotsModal() {
    openModal('modalLotsExpirations');
    loadLotsTable();
}

async function loadLotsTable() {
    const tbody = document.getElementById('lotsExpirationsTableBody');
    tbody.innerHTML = '<tr><td colspan="8" class="text-center">Consultando rotación de lotes FEFO...</td></tr>';

    try {
        const res = await fetch('/api/inventory/lots');
        const data = await res.json();

        if (!data.lots || data.lots.length === 0) {
            tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted">No hay lotes con existencias activas.</td></tr>';
            return;
        }

        tbody.innerHTML = data.lots.map(l => {
            const sem = l.estado_semaforo || l.semaforo || 'OPTIMO';
            const cant = (l.cantidad_actual !== undefined && l.cantidad_actual !== null) ? l.cantidad_actual : (l.cantidad || 0);
            let badgeClass = 'badge-success';
            let icon = '🟢';
            if (sem === 'VENCIDO') { badgeClass = 'badge-danger'; icon = '🔴'; }
            else if (sem === 'CRITICO') { badgeClass = 'badge-warning'; icon = '🟠'; }
            else if (sem === 'PROXIMO') { badgeClass = 'badge-info'; icon = '🟡'; }

            return `
                <tr>
                    <td><strong>${escapeHtml(l.producto_nombre)}</strong></td>
                    <td class="font-mono text-cyan">${escapeHtml(l.codigo_interno)}</td>
                    <td>${escapeHtml(l.bodega_nombre)}</td>
                    <td class="font-mono" style="font-weight:700;">${escapeHtml(l.numero_lote)}</td>
                    <td class="font-mono">${escapeHtml(l.fecha_vencimiento || 'N/A')}</td>
                    <td class="text-center font-mono" style="font-weight:700; color:${Number(l.dias_restantes) <= 15 ? '#ef4444' : '#10b981'};">
                        ${l.dias_restantes !== null ? `${l.dias_restantes} d` : '-'}
                    </td>
                    <td class="text-center font-mono" style="font-weight:700;">${Number(cant).toFixed(1)}</td>
                    <td class="text-center">
                        <span class="badge ${badgeClass}">${icon} ${sem}</span>
                    </td>
                </tr>
            `;
        }).join('');
    } catch (e) {
        console.error(e);
        tbody.innerHTML = '<tr><td colspan="8" class="text-center text-danger">Error al cargar lotes.</td></tr>';
    }
}

let currentKardexMovs = [];

async function viewKardex(prodId) {
    try {
        const titleEl = document.getElementById('kardexModalTitle');
        const skuEl = document.getElementById('kardexProdSku');
        const cppEl = document.getElementById('kardexProdCpp');
        const stockEl = document.getElementById('kardexProdStock');
        const movsEl = document.getElementById('kardexProdTotalMovs');
        const tbody = document.getElementById('kardexTableBody');

        if (titleEl) titleEl.textContent = '📊 Consultando Kardex...';
        if (tbody) tbody.innerHTML = '<tr><td colspan="9" class="text-center">Consultando movimientos del Kardex...</td></tr>';

        openModal('modalKardex');

        const res = await fetch(`/api/inventory/kardex?producto_id=${prodId}`);
        const data = await res.json();

        if (!data.producto) {
            showToast('No se encontró información del producto.', 'warning');
            return;
        }

        const prod = data.producto;
        currentKardexMovs = data.movimientos || [];

        if (titleEl) titleEl.textContent = `📊 Kardex: ${prod.nombre}`;
        if (skuEl) skuEl.textContent = prod.codigo_interno || '-';
        if (cppEl) cppEl.textContent = `$${Number(prod.costo_promedio || 0).toFixed(4)}`;
        const stockTot = (prod.stock_total !== undefined && prod.stock_total !== null) ? Number(prod.stock_total).toFixed(1) : '-';
        if (stockEl) stockEl.textContent = `${stockTot} ${prod.unidad_medida || 'UND'}`;
        if (movsEl) movsEl.textContent = currentKardexMovs.length;

        // Reset filtros del modal
        const searchInput = document.getElementById('kardexSearchFilter');
        const typeSelect = document.getElementById('kardexTypeFilter');
        if (searchInput) searchInput.value = '';
        if (typeSelect) typeSelect.value = '';

        renderKardexTable(currentKardexMovs);
    } catch (e) {
        console.error('Error al consultar Kardex:', e);
        showToast('Error al consultar Kardex', 'error');
    }
}

function renderKardexTable(movs) {
    const tbody = document.getElementById('kardexTableBody');
    if (!tbody) return;

    if (!movs || movs.length === 0) {
        tbody.innerHTML = '<tr><td colspan="9" class="text-center text-muted" style="padding:1.5rem;">No hay movimientos de Kardex registrados para los filtros seleccionados.</td></tr>';
        return;
    }

    tbody.innerHTML = movs.map(m => {
        const cant = Number(m.cantidad || 0);
        const isEntrada = cant > 0;
        const cantFormatted = (isEntrada ? '+' : '') + cant.toFixed(2);
        const cantColor = isEntrada ? '#10b981' : '#ef4444';

        let badgeClass = 'badge-info';
        let tipoIcon = '📦';
        const tipo = (m.tipo_movimiento || '').toUpperCase();
        if (tipo.includes('VENTA') && !tipo.includes('ANULACION')) { badgeClass = 'badge-primary'; tipoIcon = '🛒'; }
        else if (tipo.includes('ANULACION')) { badgeClass = 'badge-warning'; tipoIcon = '↩️'; }
        else if (tipo.includes('COMPRA') || tipo.includes('RECEPCION')) { badgeClass = 'badge-success'; tipoIcon = '📥'; }
        else if (tipo.includes('AJUSTE')) { badgeClass = 'badge-secondary'; tipoIcon = '⚖️'; }
        else if (tipo.includes('MERMA')) { badgeClass = 'badge-danger'; tipoIcon = '🗑️'; }
        else if (tipo.includes('INICIAL')) { badgeClass = 'badge-info'; tipoIcon = '🏁'; }

        const fechaFmt = formatDateTime(m.fecha_hora);
        const costoUnit = Number(m.costo_unitario || 0).toFixed(4);
        const stockAnt = Number(m.stock_anterior || 0).toFixed(1);
        const stockPost = Number(m.stock_posterior !== undefined ? m.stock_posterior : (m.stock_nuevo || 0)).toFixed(1);

        return `
            <tr>
                <td style="font-size:0.75rem; white-space:nowrap;">${fechaFmt}</td>
                <td><span class="badge ${badgeClass}" style="font-size:0.7rem;">${tipoIcon} ${escapeHtml(m.tipo_movimiento)}</span></td>
                <td>${escapeHtml(m.bodega_nombre || 'Bodega')}</td>
                <td class="font-mono text-cyan" style="font-size:0.75rem; font-weight:600;">${escapeHtml(m.documento_referencia || '-')}</td>
                <td class="text-right font-mono" style="font-weight:700; color:${cantColor};">${cantFormatted}</td>
                <td class="text-right font-mono">$${costoUnit}</td>
                <td class="text-center font-mono text-muted">${stockAnt}</td>
                <td class="text-center font-mono" style="font-weight:700; color:#0f172a; background:#f8fafc; border-radius:4px;">${stockPost}</td>
                <td style="font-size:0.75rem;">${escapeHtml(m.usuario_nombre || 'Sistema')}</td>
            </tr>
        `;
    }).join('');
}

function filterKardexTable() {
    const q = (document.getElementById('kardexSearchFilter')?.value || '').toLowerCase().trim();
    const type = (document.getElementById('kardexTypeFilter')?.value || '').toUpperCase();

    const filtered = currentKardexMovs.filter(m => {
        const matchType = !type || (m.tipo_movimiento || '').toUpperCase().includes(type);
        const matchText = !q ||
            (m.documento_referencia && m.documento_referencia.toLowerCase().includes(q)) ||
            (m.bodega_nombre && m.bodega_nombre.toLowerCase().includes(q)) ||
            (m.usuario_nombre && m.usuario_nombre.toLowerCase().includes(q)) ||
            (m.tipo_movimiento && m.tipo_movimiento.toLowerCase().includes(q)) ||
            (m.notas && m.notas.toLowerCase().includes(q));
        return matchType && matchText;
    });

    renderKardexTable(filtered);
}

// ==========================================
// MÓDULO REPORTES ESPECIALIZADOS & DASHBOARD
// ==========================================
function initReportsModule() {
    const reportBtns = document.querySelectorAll('.report-tab-btn');
    reportBtns.forEach(btn => {
        btn.addEventListener('click', (e) => {
            reportBtns.forEach(b => {
                b.classList.remove('active');
                b.classList.remove('btn-primary');
                b.classList.add('btn-outline');
            });
            btn.classList.add('active');
            btn.classList.add('btn-primary');
            btn.classList.remove('btn-outline');

            const rep = btn.dataset.report;
            document.querySelectorAll('.report-pane').forEach(p => p.classList.add('hidden'));
            const target = document.getElementById(`report-view-${rep}`);
            if (target) target.classList.remove('hidden');

            if (rep === 'sales') loadSalesReport();
            else if (rep === 'kardex') loadKardexValReport();
            else if (rep === 'shifts') loadCashShiftsReport();
        });
    });

    const btnFilterSales = document.getElementById('btnFilterRepSales');
    const btnRefKardex = document.getElementById('btnRefreshKardexVal');
    const btnRefShifts = document.getElementById('btnRefreshShiftsRep');
    const btnRefDash = document.getElementById('btnRefreshDashboard');

    if (btnFilterSales) btnFilterSales.addEventListener('click', loadSalesReport);
    if (btnRefKardex) btnRefKardex.addEventListener('click', loadKardexValReport);
    if (btnRefShifts) btnRefShifts.addEventListener('click', loadCashShiftsReport);
    if (btnRefDash) btnRefDash.addEventListener('click', loadDashboard);
}

async function loadDashboard() {
    try {
        const res = await fetch('/api/reports/dashboard');
        const data = await res.json();
        const kpis = data.kpis;

        // Timestamp de última actualización en vivo
        const lastUpdateEl = document.getElementById('dashLastUpdate');
        if (lastUpdateEl) {
            lastUpdateEl.textContent = new Date().toLocaleTimeString('es-SV', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        }

        // 1. HERO KPIS EJECUTIVOS
        const kpisContainer = document.getElementById('dashboardKpis');
        if (kpisContainer) {
            const trendClass = kpis.crecimiento_porcentaje > 0 ? 'up' : (kpis.crecimiento_porcentaje < 0 ? 'down' : 'neutral');
            const trendIcon = kpis.crecimiento_porcentaje > 0 ? '▲ +' : (kpis.crecimiento_porcentaje < 0 ? '▼ ' : '');

            kpisContainer.innerHTML = `
                <!-- KPI 1: Ventas Hoy -->
                <div class="kpi-exec-card card-accent-emerald">
                    <div class="kpi-exec-top">
                        <span class="kpi-exec-label">Ventas Hoy (Bruto)</span>
                        <div class="kpi-exec-icon kpi-icon-green">💵</div>
                    </div>
                    <div class="kpi-exec-main">
                        <span class="kpi-exec-value text-emerald">$${Number(kpis.total_hoy).toFixed(2)}</span>
                        <span class="kpi-trend-pill ${trendClass}">${trendIcon}${kpis.crecimiento_porcentaje}% vs ayer</span>
                    </div>
                    <div class="kpi-exec-footer">
                        <span>Tickets: <strong>${kpis.tickets_hoy}</strong></span>
                        <span>Promedio: <strong>$${Number(kpis.ticket_promedio).toFixed(2)}</strong></span>
                    </div>
                </div>

                <!-- KPI 2: Margen & Utilidad Bruta -->
                <div class="kpi-exec-card card-accent-blue">
                    <div class="kpi-exec-top">
                        <span class="kpi-exec-label">Utilidad Bruta & Margen</span>
                        <div class="kpi-exec-icon kpi-icon-blue">📈</div>
                    </div>
                    <div class="kpi-exec-main">
                        <span class="kpi-exec-value" style="color:#0284c7;">$${Number(kpis.ganancia_bruta_hoy).toFixed(2)}</span>
                        <span class="kpi-trend-pill up">${kpis.margen_bruto_porc}% Margen</span>
                    </div>
                    <div class="kpi-exec-footer">
                        <span>Costo Hoy: <strong>$${Number(kpis.costo_total_hoy).toFixed(2)}</strong></span>
                        <span>Mes: <strong>$${Number(kpis.total_mes).toFixed(2)}</strong></span>
                    </div>
                </div>

                <!-- KPI 3: Cajas Activas -->
                <div class="kpi-exec-card card-accent-amber">
                    <div class="kpi-exec-top">
                        <span class="kpi-exec-label">Operación de Cajas</span>
                        <div class="kpi-exec-icon kpi-icon-amber">🖥️</div>
                    </div>
                    <div class="kpi-exec-main">
                        <span class="kpi-exec-value" style="color:#d97706;">${data.cajas_activas.length}</span>
                        <span class="kpi-trend-pill ${data.cajas_activas.length > 0 ? 'up' : 'neutral'}">${data.cajas_activas.length > 0 ? 'Operando' : 'Sin Turno'}</span>
                    </div>
                    <div class="kpi-exec-footer">
                        <span style="white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:100%;" title="${data.cajas_activas.map(c => c.caja_nombre).join(', ') || 'Ninguna'}">
                            Terminales: <strong>${data.cajas_activas.map(c => c.caja_nombre).join(', ') || 'Ninguna abierta'}</strong>
                        </span>
                    </div>
                </div>

                <!-- KPI 4: Catálogo & Valuación -->
                <div class="kpi-exec-card card-accent-purple">
                    <div class="kpi-exec-top">
                        <span class="kpi-exec-label">Valuación Inventario</span>
                        <div class="kpi-exec-icon kpi-icon-purple">📦</div>
                    </div>
                    <div class="kpi-exec-main">
                        <span class="kpi-exec-value" style="color:#8b5cf6;">$${Number(kpis.valor_inventario).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</span>
                        <span class="kpi-trend-pill neutral">${kpis.total_productos} SKUs</span>
                    </div>
                    <div class="kpi-exec-footer">
                        <span>Bajo Stock: <strong class="${data.alertas_stock.length > 0 ? 'text-danger' : 'text-success'}">${data.alertas_stock.length}</strong></span>
                        <span>Por Vencer: <strong class="${data.alertas_vencimiento.length > 0 ? 'text-warning' : 'text-success'}">${data.alertas_vencimiento.length}</strong></span>
                    </div>
                </div>
            `;
        }

        // 2. MIX DE MEDIOS DE PAGO
        const totalPagosHoy = (data.desglose_pagos || []).reduce((acc, p) => acc + Number(p.monto), 0);
        const totalPagosBadge = document.getElementById('dashTotalPagosHoy');
        if (totalPagosBadge) {
            totalPagosBadge.textContent = `$${totalPagosHoy.toFixed(2)}`;
        }

        const paymentMixContainer = document.getElementById('dashPaymentMix');
        if (paymentMixContainer) {
            if (!data.desglose_pagos || data.desglose_pagos.length === 0) {
                paymentMixContainer.innerHTML = '<div class="p-3 text-center text-muted" style="font-size:0.875rem;">Sin transacciones de cobro registradas hoy.</div>';
            } else {
                paymentMixContainer.innerHTML = data.desglose_pagos.map(p => {
                    const monto = Number(p.monto);
                    const porc = totalPagosHoy > 0 ? Math.round((monto / totalPagosHoy) * 100) : 0;
                    let icon = '💳';
                    let fillClass = 'fill-card';
                    const metodo = (p.metodo_pago || '').toUpperCase();
                    if (metodo.includes('EFECTIVO')) { icon = '💵'; fillClass = 'fill-cash'; }
                    else if (metodo.includes('TRANSFERENCIA') || metodo.includes('QR')) { icon = '📱'; fillClass = 'fill-transfer'; }
                    else if (metodo.includes('CREDITO') || metodo.includes('CRÉDITO')) { icon = '📑'; fillClass = 'fill-credit'; }

                    return `
                        <div class="payment-mix-row">
                            <div class="payment-mix-info">
                                <span class="payment-mix-name">${icon} ${escapeHtml(p.metodo_pago)} (${p.transacciones} ops)</span>
                                <span class="payment-mix-amount">$${monto.toFixed(2)} <span style="color:var(--text-muted); font-size:0.75rem; font-weight:normal;">(${porc}%)</span></span>
                            </div>
                            <div class="payment-progress-bg">
                                <div class="payment-progress-fill ${fillClass}" style="width: ${porc}%;"></div>
                            </div>
                        </div>
                    `;
                }).join('');
            }
        }

        // 3. TOP 5 RANKING PRODUCTOS CON PODIO
        const topProductsContainer = document.getElementById('dashTopProducts');
        if (topProductsContainer) {
            if (!data.top_productos || data.top_productos.length === 0) {
                topProductsContainer.innerHTML = '<div class="p-3 text-center text-muted" style="font-size:0.875rem;">Aún no se han despachado productos en ventas de hoy.</div>';
            } else {
                const posClasses = ['gold', 'silver', 'bronze'];
                topProductsContainer.innerHTML = data.top_productos.map((p, idx) => {
                    const badgeClass = posClasses[idx] || 'default';
                    return `
                        <div class="ranking-item">
                            <div class="ranking-pos ${badgeClass}">${idx + 1}</div>
                            <div class="ranking-details">
                                <div class="ranking-title" title="${escapeHtml(p.nombre)}">${escapeHtml(p.nombre)}</div>
                                <div class="ranking-meta">${Number(p.total_unidades).toFixed(1)} unidades vendidas hoy</div>
                            </div>
                            <div class="ranking-val">$${Number(p.total_monto).toFixed(2)}</div>
                        </div>
                    `;
                }).join('');
            }
        }

        // 4. SEMÁFORO DE ALERTAS OPERATIVAS (STOCK & FEFO)
        const alertsTbody = document.getElementById('alertsTableBody');
        if (alertsTbody) {
            const combinedAlerts = [
                ...data.alertas_stock.map(a => ({
                    tipo: 'Stock Mínimo',
                    badgeClass: 'badge-danger',
                    prod: a.nombre,
                    det: `Existencias: <strong>${Number(a.stock_actual).toFixed(1)} ${a.unidad_medida || 'UND'}</strong> (Mínimo requerido: ${Number(a.stock_minimo).toFixed(1)})`
                })),
                ...data.alertas_vencimiento.map(v => ({
                    tipo: 'Vencimiento FEFO',
                    badgeClass: 'badge-warning',
                    prod: v.producto_nombre,
                    det: `Lote <strong>${v.numero_lote}</strong> vence en <strong class="text-danger">${v.dias_restantes} días</strong> (${v.fecha_vencimiento})`
                }))
            ];

            if (combinedAlerts.length === 0) {
                alertsTbody.innerHTML = '<tr><td colspan="4" class="text-center text-success" style="padding:1.5rem; font-weight:600;">✨ No hay riesgos críticos detectados. Inventario y lotes en rangos saludables.</td></tr>';
            } else {
                alertsTbody.innerHTML = combinedAlerts.map(a => `
                    <tr>
                        <td><span class="badge ${a.badgeClass}">${a.tipo}</span></td>
                        <td><strong>${escapeHtml(a.prod)}</strong></td>
                        <td class="text-muted">${a.det}</td>
                        <td class="text-center"><button class="btn btn-outline btn-xs" onclick="switchTab('inventory')">🔍 Inspeccionar</button></td>
                    </tr>
                `).join('');
            }
        }

        // Cargar reporte de ventas por defecto
        loadSalesReport();
    } catch (e) {
        console.error('Error cargando métricas ejecutivas:', e);
    }
}

async function loadSalesReport() {
    const fromEl = document.getElementById('repSalesFrom');
    const toEl = document.getElementById('repSalesTo');
    const today = new Date();
    const yyyy = today.getFullYear();
    const mm = String(today.getMonth() + 1).padStart(2, '0');
    const dd = String(today.getDate()).padStart(2, '0');

    if (fromEl && !fromEl.value) fromEl.value = `${yyyy}-${mm}-01`;
    if (toEl && !toEl.value) toEl.value = `${yyyy}-${mm}-${dd}`;

    const from = fromEl?.value || '';
    const to = toEl?.value || '';
    const tbody = document.getElementById('reportSalesTableBody');
    if (!tbody) return;

    tbody.innerHTML = '<tr><td colspan="8" class="text-center">Calculando ventas y márgenes de utilidad...</td></tr>';

    try {
        const res = await fetch(`/api/reports/sales-by-product?desde=${encodeURIComponent(from)}&hasta=${encodeURIComponent(to)}`);
        const data = await res.json();

        if (!data.items || data.items.length === 0) {
            tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted">No se registran ventas para el rango seleccionado.</td></tr>';
            return;
        }

        tbody.innerHTML = data.items.map(it => `
            <tr>
                <td class="font-mono text-cyan">${escapeHtml(it.codigo_interno)}</td>
                <td><strong>${escapeHtml(it.nombre)}</strong></td>
                <td>${escapeHtml(it.categoria || '-')}</td>
                <td class="text-center font-mono">${Number(it.unidades_vendidas).toFixed(1)}</td>
                <td class="text-right font-mono text-emerald" style="font-weight:700;">$${Number(it.venta_total).toFixed(2)}</td>
                <td class="text-right font-mono">$${Number(it.costo_total).toFixed(2)}</td>
                <td class="text-right font-mono font-bold" style="color:${Number(it.ganancia_bruta) >= 0 ? '#10b981' : '#ef4444'};">
                    $${Number(it.ganancia_bruta).toFixed(2)}
                </td>
                <td class="text-center">
                    <span class="badge ${Number(it.margen_porcentaje) >= 20 ? 'badge-success' : 'badge-warning'}">
                        ${Number(it.margen_porcentaje).toFixed(1)}%
                    </span>
                </td>
            </tr>
        `).join('');
    } catch (e) {
        console.error(e);
        tbody.innerHTML = '<tr><td colspan="8" class="text-center text-danger">Error al generar reporte de ventas.</td></tr>';
    }
}

async function loadKardexValReport() {
    const tbody = document.getElementById('reportKardexValTableBody');
    const summaryContainer = document.getElementById('kardexValSummary');
    if (!tbody) return;

    tbody.innerHTML = '<tr><td colspan="8" class="text-center">Valuando existencias a costo promedio ponderado...</td></tr>';

    try {
        const res = await fetch('/api/reports/kardex-valuation');
        const data = await res.json();

        if (summaryContainer) {
            summaryContainer.innerHTML = `
                <div class="kpi-card">
                    <span class="kpi-label">Artículos Valuados</span>
                    <span class="kpi-value text-cyan">${data.total_items}</span>
                    <span class="kpi-sub text-muted">Líneas de stock activas</span>
                </div>
                <div class="kpi-card">
                    <span class="kpi-label">Unidades Totales</span>
                    <span class="kpi-value">${Number(data.total_unidades).toFixed(1)}</span>
                    <span class="kpi-sub text-muted">En Piso y Bodega</span>
                </div>
                <div class="kpi-card" style="border-color:#10b981;">
                    <span class="kpi-label">Valor Total en Libros (CPP)</span>
                    <span class="kpi-value text-emerald font-mono">$${Number(data.valor_total_inventario).toFixed(2)}</span>
                    <span class="kpi-sub text-muted">Normativa NIIF / Fiscal</span>
                </div>
            `;
        }

        if (!data.items || data.items.length === 0) {
            tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted">No hay stock disponible.</td></tr>';
            return;
        }

        tbody.innerHTML = data.items.map(it => `
            <tr>
                <td class="font-mono text-cyan">${escapeHtml(it.codigo_interno)}</td>
                <td><strong>${escapeHtml(it.nombre)}</strong></td>
                <td>${escapeHtml(it.categoria || '-')}</td>
                <td>${escapeHtml(it.bodega || '-')}</td>
                <td class="text-center font-mono" style="font-weight:700;">${Number(it.stock_actual).toFixed(1)}</td>
                <td class="text-right font-mono">$${Number(it.costo_promedio).toFixed(4)}</td>
                <td class="text-right font-mono">$${Number(it.precio_venta).toFixed(2)}</td>
                <td class="text-right font-mono text-emerald" style="font-weight:700;">$${Number(it.valor_total_libros).toFixed(2)}</td>
            </tr>
        `).join('');
    } catch (e) {
        console.error(e);
        tbody.innerHTML = '<tr><td colspan="8" class="text-center text-danger">Error al generar valuación de inventario.</td></tr>';
    }
}

async function loadCashShiftsReport() {
    const tbody = document.getElementById('reportShiftsTableBody');
    if (!tbody) return;

    tbody.innerHTML = '<tr><td colspan="11" class="text-center">Consultando histórico de arqueos y cortes Z...</td></tr>';

    try {
        const res = await fetch('/api/reports/cash-shifts');
        const data = await res.json();

        if (!data.shifts || data.shifts.length === 0) {
            tbody.innerHTML = '<tr><td colspan="11" class="text-center text-muted">No se registran turnos cerrados aún.</td></tr>';
            return;
        }

        tbody.innerHTML = data.shifts.map(sh => {
            const estaAbierto = !sh.fecha_cierre;
            const dif = sh.diferencia !== null ? Number(sh.diferencia) : 0;
            let difClass = 'text-muted';
            let difText = estaAbierto ? '🟢 En Operación' : '$0.00 (Exacto)';
            if (!estaAbierto && dif > 0) {
                difClass = 'text-emerald';
                difText = `+$${dif.toFixed(2)} (Sobrante)`;
            } else if (!estaAbierto && dif < 0) {
                difClass = 'text-danger font-bold';
                difText = `-$${Math.abs(dif).toFixed(2)} (Faltante)`;
            }

            return `
                <tr>
                    <td class="font-mono text-cyan" style="font-weight:700;">#${sh.id}</td>
                    <td><strong>${escapeHtml(sh.caja_nombre)}</strong></td>
                    <td>${escapeHtml(sh.cajero_nombre)}</td>
                    <td class="font-mono" style="font-size:0.75rem;">${escapeHtml(sh.fecha_apertura)}</td>
                    <td class="font-mono" style="font-size:0.75rem;">${sh.fecha_cierre ? escapeHtml(sh.fecha_cierre) : '<span class="badge badge-success">Abierto</span>'}</td>
                    <td class="text-right font-mono">$${Number(sh.monto_apertura || 0).toFixed(2)}</td>
                    <td class="text-right font-mono">$${Number(sh.ventas_efectivo || 0).toFixed(2)}</td>
                    <td class="text-right font-mono" style="font-weight:700;">$${Number(sh.monto_esperado || 0).toFixed(2)}</td>
                    <td class="text-right font-mono" style="font-weight:700;">${sh.monto_cierre_real !== null ? '$' + Number(sh.monto_cierre_real).toFixed(2) : '-'}</td>
                    <td class="text-center font-mono ${difClass}">${difText}</td>
                    <td>${escapeHtml(sh.supervisor_cierre || '-')}</td>
                </tr>
            `;
        }).join('');
    } catch (e) {
        console.error(e);
        tbody.innerHTML = '<tr><td colspan="11" class="text-center text-danger">Error al consultar turnos de caja.</td></tr>';
    }
}

// ==========================================
// MÓDULO FACTURACIÓN ELECTRÓNICA DTE
// ==========================================
function initDteModule() {
    const btnCopy = document.getElementById('btnCopyDteJson');
    if (btnCopy) {
        btnCopy.addEventListener('click', () => {
            const txt = document.getElementById('dteViewerJson')?.textContent || '';
            navigator.clipboard.writeText(txt).then(() => {
                showToast('JSON oficial DTE copiado al portapapeles', 'success');
            });
        });
    }
}

async function loadDtes() {
    try {
        const res = await fetch('/api/billing/dtes');
        const data = await res.json();
        const tbody = document.getElementById('dtesTableBody');

        if (!data.dtes || data.dtes.length === 0) {
            tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted">No hay documentos DTE generados aún.</td></tr>';
            return;
        }

        tbody.innerHTML = data.dtes.map(d => `
            <tr>
                <td class="font-mono" style="font-size: 0.75rem;">${d.fecha_hora}</td>
                <td><span class="badge ${d.tipo_documento === '03' ? 'badge-primary' : 'badge-secondary'}">${d.tipo_documento === '03' ? 'CCF (03)' : 'Factura (01)'}</span></td>
                <td class="font-mono text-cyan" style="font-size: 0.75rem; font-weight:700;">${escapeHtml(d.numero_control_mh || '-')}</td>
                <td class="font-mono" style="font-size: 0.7rem;">${escapeHtml(d.codigo_generacion_mh || '-')}</td>
                <td>${escapeHtml(d.cliente_nombre)}</td>
                <td class="text-right font-mono text-emerald" style="font-weight: 700;">$${Number(d.total).toFixed(2)}</td>
                <td class="text-center"><span class="badge badge-success">${d.estado_dte || 'EMITIDO'}</span></td>
                <td>
                    <button class="btn btn-outline btn-xs" onclick="viewDteJson(${d.id})">🔍 Ver JSON MH</button>
                </td>
            </tr>
        `).join('');
    } catch (e) {
        console.error(e);
    }
}

async function viewDteJson(ventaId) {
    try {
        const res = await fetch(`/api/billing/json-dte?venta_id=${ventaId}`);
        const data = await res.json();

        if (data.ok && data.dte_json) {
            document.getElementById('dteViewerInfo').textContent = `DTE: ${data.dte_json.identificacion?.numeroControl || ''} | Versión: ${data.dte_json.identificacion?.version || 1}`;
            document.getElementById('dteViewerJson').textContent = JSON.stringify(data.dte_json, null, 2);
            openModal('modalDteViewer');
        } else {
            showToast('No se pudo generar la estructura JSON para esta venta', 'warning');
        }
    } catch (e) {
        showToast('Error al obtener estructura JSON DTE', 'error');
    }
}

// ==========================================
// MÓDULO CONTABILIDAD INTEGRADA & LIBROS IVA
// ==========================================
function initAccountingModule() {
    const acctBtns = document.querySelectorAll('.accounting-tab-btn');
    acctBtns.forEach(btn => {
        btn.addEventListener('click', () => {
            acctBtns.forEach(b => {
                b.classList.remove('active');
                b.classList.remove('btn-primary');
                b.classList.add('btn-outline');
            });
            btn.classList.add('active');
            btn.classList.add('btn-primary');
            btn.classList.remove('btn-outline');

            const tab = btn.dataset.acctab;
            document.querySelectorAll('.acctab-pane').forEach(p => p.classList.add('hidden'));
            const target = document.getElementById(`acctab-content-${tab}`);
            if (target) target.classList.remove('hidden');

            if (tab === 'entries') loadAccountingEntries();
            else if (tab === 'consumidor') loadLibroConsumidor();
            else if (tab === 'ccf') loadLibroCcf();
            else if (tab === 'compras') loadLibroCompras();
            else if (tab === 'balance') loadBalanceComprobacion();
        });
    });

    const btnRef = document.getElementById('btnRefreshAccounting');
    if (btnRef) btnRef.addEventListener('click', loadAccountingEntries);
}

async function loadAccounting() {
    loadAccountingEntries();
}

async function loadAccountingEntries() {
    try {
        const res = await fetch('/api/accounting/entries');
        const data = await res.json();
        const tbody = document.getElementById('accountingEntriesTableBody');
        if (!tbody) return;

        if (!data.asientos || data.asientos.length === 0) {
            tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted">No hay asientos contables registrados.</td></tr>';
            return;
        }

        tbody.innerHTML = data.asientos.map(a => `
            <tr>
                <td class="font-mono text-cyan" style="font-weight:700;">${escapeHtml(a.numero_asiento)}</td>
                <td class="font-mono">${a.fecha}</td>
                <td><strong>${escapeHtml(a.concepto)}</strong></td>
                <td><span class="badge badge-secondary">${a.origen_modulo}</span></td>
                <td class="text-right font-mono" style="font-weight:700;">$${Number(a.total_debe).toFixed(2)}</td>
                <td class="text-right font-mono" style="font-weight:700;">$${Number(a.total_haber).toFixed(2)}</td>
                <td class="text-center"><span class="badge badge-success">⚖️ Cuadrado</span></td>
                <td class="text-center">
                    <button class="btn btn-outline btn-xs" onclick="viewAccountingEntry(${a.id}, '${escapeHtml(a.numero_asiento)}', '${escapeHtml(a.concepto)}')">Ver Partida</button>
                </td>
            </tr>
        `).join('');
    } catch (e) {
        console.error(e);
    }
}

async function viewAccountingEntry(asientoId, numero, concepto) {
    try {
        const res = await fetch(`/api/accounting/entry-lines?id=${asientoId}`);
        const data = await res.json();

        document.getElementById('acctEntryViewerTitle').textContent = `📑 Asiento Contable ${numero}`;
        document.getElementById('acctEntryViewerConcepto').textContent = concepto;
        const tbody = document.getElementById('acctEntryLinesTableBody');

        if (!data.lineas || data.lineas.length === 0) {
            tbody.innerHTML = '<tr><td colspan="4" class="text-center">Sin líneas</td></tr>';
        } else {
            tbody.innerHTML = data.lineas.map(l => `
                <tr>
                    <td class="font-mono text-cyan" style="font-weight:700;">${escapeHtml(l.cuenta_codigo)}</td>
                    <td>${escapeHtml(l.cuenta_nombre)}</td>
                    <td class="text-right font-mono">${Number(l.debe) > 0 ? `$${Number(l.debe).toFixed(2)}` : '-'}</td>
                    <td class="text-right font-mono">${Number(l.haber) > 0 ? `$${Number(l.haber).toFixed(2)}` : '-'}</td>
                </tr>
            `).join('');
        }

        openModal('modalAccountingEntryViewer');
    } catch (e) {
        showToast('Error al consultar partida contable', 'error');
    }
}

async function loadLibroConsumidor() {
    const tbody = document.getElementById('libroConsumidorTableBody');
    if (!tbody) return;
    tbody.innerHTML = '<tr><td colspan="7" class="text-center">Generando Libro de Ventas a Consumidor Final...</td></tr>';

    try {
        const res = await fetch('/api/accounting/libro-consumidor-final');
        const data = await res.json();

        if (!data.registros || data.registros.length === 0) {
            tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted">No se registran ventas a consumidor final para el período.</td></tr>';
            return;
        }

        tbody.innerHTML = data.registros.map(r => `
            <tr>
                <td class="font-mono">${escapeHtml(r.fecha)}</td>
                <td class="font-mono text-cyan">${escapeHtml(r.del_numero)}</td>
                <td class="font-mono text-cyan">${escapeHtml(r.al_numero)}</td>
                <td class="text-center">Caja ${r.caja_id}</td>
                <td class="text-right font-mono">$${Number(r.ventas_exentas || 0).toFixed(2)}</td>
                <td class="text-right font-mono">$${Number(r.ventas_gravadas).toFixed(2)}</td>
                <td class="text-right font-mono text-emerald" style="font-weight:700;">$${Number(r.total_diario).toFixed(2)}</td>
            </tr>
        `).join('');
    } catch (e) {
        console.error(e);
        tbody.innerHTML = '<tr><td colspan="7" class="text-center text-danger">Error al cargar libro consumidor final.</td></tr>';
    }
}

async function loadLibroCcf() {
    const tbody = document.getElementById('libroCcfTableBody');
    if (!tbody) return;
    tbody.innerHTML = '<tr><td colspan="8" class="text-center">Generando Libro de Ventas a Contribuyentes CCF...</td></tr>';

    try {
        const res = await fetch('/api/accounting/libro-contribuyentes');
        const data = await res.json();

        if (!data.registros || data.registros.length === 0) {
            tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted">No se registran comprobantes de crédito fiscal emitidos.</td></tr>';
            return;
        }

        tbody.innerHTML = data.registros.map(r => `
            <tr>
                <td class="font-mono">${escapeHtml(r.fecha)}</td>
                <td class="font-mono text-cyan" style="font-weight:700;">${escapeHtml(r.numero_control || r.numero_venta)}</td>
                <td><strong>${escapeHtml(r.cliente_nombre)}</strong></td>
                <td class="font-mono">${escapeHtml(r.nrc || '-')}</td>
                <td class="text-right font-mono">$${Number(r.subtotal_gravado).toFixed(2)}</td>
                <td class="text-right font-mono text-cyan">$${Number(r.iva).toFixed(2)}</td>
                <td class="text-right font-mono">$${Number(r.retencion_1 || 0).toFixed(2)}</td>
                <td class="text-right font-mono text-emerald" style="font-weight:700;">$${Number(r.total).toFixed(2)}</td>
            </tr>
        `).join('');
    } catch (e) {
        console.error(e);
        tbody.innerHTML = '<tr><td colspan="8" class="text-center text-danger">Error al cargar libro de contribuyentes.</td></tr>';
    }
}

async function loadLibroCompras() {
    const tbody = document.getElementById('libroComprasTableBody');
    if (!tbody) return;
    tbody.innerHTML = '<tr><td colspan="8" class="text-center">Generando Libro de Compras (IVA Crédito Fiscal)...</td></tr>';

    try {
        const res = await fetch('/api/accounting/libro-compras');
        const data = await res.json();

        if (!data.registros || data.registros.length === 0) {
            tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted">No hay compras a proveedores registradas en el período.</td></tr>';
            return;
        }

        tbody.innerHTML = data.registros.map(r => `
            <tr>
                <td class="font-mono">${escapeHtml(r.fecha)}</td>
                <td class="font-mono text-cyan" style="font-weight:700;">${escapeHtml(r.numero_documento)}</td>
                <td><strong>${escapeHtml(r.proveedor_nombre)}</strong></td>
                <td class="font-mono">${escapeHtml(r.nrc || '-')}</td>
                <td class="text-right font-mono">$${Number(r.gravado).toFixed(2)}</td>
                <td class="text-right font-mono text-cyan" style="font-weight:700;">$${Number(r.credito_fiscal).toFixed(2)}</td>
                <td class="text-right font-mono">$${Number(r.percepcion_1 || 0).toFixed(2)}</td>
                <td class="text-right font-mono text-emerald" style="font-weight:700;">$${Number(r.total).toFixed(2)}</td>
            </tr>
        `).join('');
    } catch (e) {
        console.error(e);
        tbody.innerHTML = '<tr><td colspan="8" class="text-center text-danger">Error al cargar libro de compras.</td></tr>';
    }
}

async function loadBalanceComprobacion() {
    const tbody = document.getElementById('balanceComprobacionTableBody');
    if (!tbody) return;
    tbody.innerHTML = '<tr><td colspan="7" class="text-center">Calculando balance de comprobación de sumas y saldos...</td></tr>';

    try {
        const res = await fetch('/api/accounting/balance-comprobacion');
        const data = await res.json();

        if (!data.cuentas || data.cuentas.length === 0) {
            tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted">Sin movimientos contables para generar el balance.</td></tr>';
            return;
        }

        let rows = data.cuentas.map(c => `
            <tr>
                <td class="font-mono text-cyan" style="font-weight:700;">${escapeHtml(c.codigo)}</td>
                <td>${escapeHtml(c.nombre)}</td>
                <td><span class="badge badge-secondary">${c.tipo}</span></td>
                <td class="text-right font-mono">${Number(c.total_debe) > 0 ? `$${Number(c.total_debe).toFixed(2)}` : '-'}</td>
                <td class="text-right font-mono">${Number(c.total_haber) > 0 ? `$${Number(c.total_haber).toFixed(2)}` : '-'}</td>
                <td class="text-right font-mono" style="font-weight:700; color:#10b981;">${Number(c.saldo_deudor) > 0 ? `$${Number(c.saldo_deudor).toFixed(2)}` : '-'}</td>
                <td class="text-right font-mono" style="font-weight:700; color:#06b6d4;">${Number(c.saldo_acreedor) > 0 ? `$${Number(c.saldo_acreedor).toFixed(2)}` : '-'}</td>
            </tr>
        `).join('');

        // Fila de totales de comprobación
        rows += `
            <tr style="background:rgba(255,255,255,0.05); font-weight:700; border-top:2px solid #334155;">
                <td colspan="3" class="text-right text-emerald">SUMAS IGUALES:</td>
                <td class="text-right font-mono text-emerald">$${Number(data.total_debe).toFixed(2)}</td>
                <td class="text-right font-mono text-emerald">$${Number(data.total_haber).toFixed(2)}</td>
                <td class="text-right font-mono text-emerald">$${Number(data.total_saldo_deudor).toFixed(2)}</td>
                <td class="text-right font-mono text-emerald">$${Number(data.total_saldo_acreedor).toFixed(2)}</td>
            </tr>
        `;

        tbody.innerHTML = rows;
    } catch (e) {
        console.error(e);
        tbody.innerHTML = '<tr><td colspan="7" class="text-center text-danger">Error al calcular balance de comprobación.</td></tr>';
    }
}

// Helper para escapar HTML y prevenir XSS
function escapeHtml(text) {
    if (!text) return '';
    return String(text)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

// ==========================================
// MÓDULO CAMBIO DE USUARIO (SWITCH USER)
// ==========================================
function initSwitchUser() {
    const btnQuick = document.getElementById('btnQuickSwitchUser');
    const btnTop = document.getElementById('btnSwitchUserTop');
    const btnConfirm = document.getElementById('btnConfirmSwitchUser');

    const openSwitch = () => {
        document.getElementById('switchUsername').value = '';
        document.getElementById('switchPassword').value = '';
        openModal('modalSwitchUser');
        document.getElementById('switchUsername').focus();
    };

    if (btnQuick) btnQuick.addEventListener('click', openSwitch);
    if (btnTop) btnTop.addEventListener('click', openSwitch);

    if (btnConfirm) {
        btnConfirm.addEventListener('click', async () => {
            const u = document.getElementById('switchUsername').value.trim();
            const p = document.getElementById('switchPassword').value;
            if (!u || !p) {
                alert('Ingresa usuario y contraseña.');
                return;
            }

            try {
                const res = await fetch('/api/auth/switch-user', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ username: u, password: p })
                });
                const data = await res.json();
                if (data.ok && data.user) {
                    closeModal('modalSwitchUser');
                    setAuthenticatedUser(data.user);
                    window.location.reload();
                } else {
                    alert(`Error: ${data.error}`);
                }
            } catch (e) {
                alert('Error al cambiar de usuario.');
            }
        });
    }
}

// ==========================================
// MÓDULO HISTORIAL DE VENTAS Y AUDITORÍA
// ==========================================
function initSalesHistory() {
    const btnFilter = document.getElementById('btnFilterSales');
    const btnReset = document.getElementById('btnResetSalesFilter');
    const desde = document.getElementById('salesFilterDesde');
    const hasta = document.getElementById('salesFilterHasta');
    const cajero = document.getElementById('salesFilterCajero');
    const estado = document.getElementById('salesFilterEstado');
    const query = document.getElementById('salesFilterQuery');

    if (desde && !desde.value) {
        const d = new Date();
        d.setDate(d.getDate() - 7);
        desde.value = d.toISOString().split('T')[0];
    }
    if (hasta && !hasta.value) {
        hasta.value = new Date().toISOString().split('T')[0];
    }

    if (btnFilter) {
        btnFilter.addEventListener('click', () => loadSalesHistory());
    }

    if (btnReset) {
        btnReset.addEventListener('click', () => {
            if (desde) desde.value = '';
            if (hasta) hasta.value = '';
            if (cajero) cajero.value = '0';
            if (estado) estado.value = '';
            if (query) query.value = '';
            loadSalesHistory();
        });
    }

    // Auto-filtrar al cambiar selectores o fechas
    [desde, hasta, cajero, estado].forEach(el => {
        el?.addEventListener('change', () => loadSalesHistory());
    });

    // Filtrar con enter o al escribir en búsqueda de ticket/cliente
    let searchTimeout = null;
    query?.addEventListener('input', () => {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => loadSalesHistory(), 350);
    });
    query?.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            clearTimeout(searchTimeout);
            loadSalesHistory();
        }
    });

    // Modal de anulación
    document.getElementById('btnConfirmVoidSale')?.addEventListener('click', async () => {
        const ventaId = document.getElementById('voidSaleId').value;
        const motivo = document.getElementById('voidSaleMotivo').value.trim();
        const pin = document.getElementById('voidSupervisorPin').value.trim();

        if (!motivo) {
            alert('Debes justificar el motivo de la anulación.');
            return;
        }
        if (!pin) {
            alert('Debes ingresar el PIN o contraseña de supervisor.');
            return;
        }

        try {
            const res = await fetch('/api/sales/void', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    venta_id: ventaId,
                    motivo: motivo,
                    pin_supervisor: pin
                })
            });
            const data = await res.json();
            if (data.ok) {
                closeModal('modalVoidSale');
                loadSalesHistory();
                checkCashSession();
                alert('Venta anulada correctamente. Stock devuelto a inventario.');
            } else {
                alert(`Error al anular: ${data.error}`);
            }
        } catch (e) {
            alert('Error de conexión.');
        }
    });
}

async function loadSalesHistory() {
    const desde = document.getElementById('salesFilterDesde')?.value || '';
    const hasta = document.getElementById('salesFilterHasta')?.value || '';
    const cajero = document.getElementById('salesFilterCajero')?.value || '0';
    const estado = document.getElementById('salesFilterEstado')?.value || '';
    const q = document.getElementById('salesFilterQuery')?.value || '';

    const url = `/api/sales/history?fecha_desde=${desde}&fecha_hasta=${hasta}&cajero_id=${cajero}&estado=${estado}&q=${encodeURIComponent(q)}`;

    try {
        const res = await fetch(url);
        const data = await res.json();
        const tbody = document.getElementById('salesHistoryTableBody');

        // Llenar select cajeros si está vacío
        const cajeroSel = document.getElementById('salesFilterCajero');
        if (cajeroSel && data.cajeros && cajeroSel.children.length <= 1) {
            cajeroSel.innerHTML = '<option value="0">Todos los cajeros</option>' +
                data.cajeros.map(c => `<option value="${c.id}">${escapeHtml(c.nombre_completo)}</option>`).join('');
        }

        if (!data.sales || data.sales.length === 0) {
            tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted">No se encontraron ventas para los filtros seleccionados.</td></tr>';
            return;
        }

        tbody.innerHTML = data.sales.map(s => `
            <tr>
                <td class="font-mono" style="font-size:0.75rem;">${s.fecha_hora}</td>
                <td class="font-mono text-cyan" style="font-weight:700;">${escapeHtml(s.numero_venta)}</td>
                <td><span class="badge ${s.tipo_documento === '03' ? 'badge-primary' : 'badge-secondary'}">${s.tipo_documento === '03' ? 'CCF (03)' : 'Factura (01)'}</span></td>
                <td>${escapeHtml(s.cajero_nombre)}</td>
                <td><strong>${escapeHtml(s.cliente_nombre)}</strong></td>
                <td class="text-right font-mono" style="font-weight:700; color:#10b981;">$${Number(s.total).toFixed(2)}</td>
                <td class="text-center">
                    <span class="status-indicator ${s.estado === 'ANULADA' ? 'closed' : ''}">${s.estado}</span>
                </td>
                <td class="text-center" style="white-space:nowrap;">
                    <button class="btn btn-outline btn-xs" onclick="reprintSaleTicket(${s.id})" title="Reimprimir Ticket">🖨️ Ticket</button>
                    ${s.estado !== 'ANULADA' ? `<button class="btn btn-danger btn-xs" onclick="openVoidSaleModal(${s.id}, '${escapeHtml(s.numero_venta)}')" title="Anular venta">✕ Anular</button>` : ''}
                </td>
            </tr>
        `).join('');
    } catch (e) {
        console.error(e);
    }
}

async function reprintSaleTicket(ventaId) {
    try {
        const res = await fetch(`/api/pos/ticket?id=${ventaId}`);
        const data = await res.json();
        if (data.venta) {
            renderTicket({
                numero_control: data.venta.numero_control_mh || data.venta.numero_venta,
                codigo_generacion: data.venta.codigo_generacion_mh || '-',
                fecha_hora: data.venta.fecha_hora,
                items: data.items,
                subtotal: data.venta.subtotal_gravado,
                iva: data.venta.iva,
                total: data.venta.total,
                vuelto: data.pagos?.[0]?.vuelto || 0
            });
            openModal('modalTicketPreview');
        }
    } catch (e) {
        alert('Error al reimprimir ticket');
    }
}

function openVoidSaleModal(id, numVenta) {
    document.getElementById('voidSaleId').value = id;
    document.getElementById('voidSaleNumDisplay').textContent = numVenta;
    document.getElementById('voidSaleMotivo').value = '';
    document.getElementById('voidSupervisorPin').value = '';
    openModal('modalVoidSale');
}

// ==========================================
// MÓDULO COMPRAS A PROVEEDORES
// ==========================================
let currentPurchaseItems = [];

function initPurchases() {
    const btnOpen = document.getElementById('btnOpenNewPurchaseModal');
    const btnAddLine = document.getElementById('btnAddPurchaseLine');
    const btnConfirm = document.getElementById('btnConfirmPurchase');

    if (btnOpen) {
        btnOpen.addEventListener('click', openNewPurchaseModal);
    }

    if (btnAddLine) {
        btnAddLine.addEventListener('click', () => {
            const prodSel = document.getElementById('purchaseProductSelect');
            const prodId = parseInt(prodSel.value);
            const prodText = prodSel.options[prodSel.selectedIndex]?.text || '';
            const qty = parseFloat(document.getElementById('purchaseQtyInput').value) || 0;
            const cost = parseFloat(document.getElementById('purchaseCostInput').value) || 0;
            const lot = document.getElementById('purchaseLotInput').value.trim();

            if (prodId <= 0 || qty <= 0 || cost <= 0) {
                alert('Selecciona un producto e ingresa cantidad y costo válidos.');
                return;
            }

            currentPurchaseItems.push({
                producto_id: prodId,
                nombre: prodText,
                cantidad: qty,
                costo_unitario: cost,
                numero_lote: lot
            });

            document.getElementById('purchaseQtyInput').value = '';
            document.getElementById('purchaseCostInput').value = '';
            document.getElementById('purchaseLotInput').value = '';

            renderPurchaseLines();
        });
    }

    if (btnConfirm) {
        btnConfirm.addEventListener('click', async () => {
            const provId = parseInt(document.getElementById('purchaseSupplierSelect').value);
            const invoiceNum = document.getElementById('purchaseInvoiceNum').value.trim();
            const bodegaId = parseInt(document.getElementById('purchaseBodegaSelect').value);

            if (!provId || !invoiceNum) {
                alert('Selecciona proveedor e ingresa número de factura.');
                return;
            }
            if (currentPurchaseItems.length === 0) {
                alert('Agrega al menos un producto a la compra.');
                return;
            }

            const payload = {
                proveedor_id: provId,
                numero_factura: invoiceNum,
                bodega_id: bodegaId,
                tipo_documento: '03',
                es_credito: false,
                items: currentPurchaseItems
            };

            try {
                const res = await fetch('/api/purchases/create', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (data.ok) {
                    closeModal('modalPurchaseCreate');
                    loadPurchases();
                    loadInventory();
                    alert('Factura de compra registrada con éxito. Inventario y costo promedio actualizados.');
                } else {
                    alert(`Error: ${data.error}`);
                }
            } catch (e) {
                alert('Error al registrar compra.');
            }
        });
    }
}

async function loadPurchases() {
    try {
        const res = await fetch('/api/purchases/list');
        const data = await res.json();
        const tbody = document.getElementById('purchasesTableBody');

        if (!data.purchases || data.purchases.length === 0) {
            tbody.innerHTML = '<tr><td colspan="10" class="text-center text-muted">No hay facturas de compra registradas.</td></tr>';
            return;
        }

        tbody.innerHTML = data.purchases.map(p => `
            <tr>
                <td class="font-mono">${p.fecha_factura}</td>
                <td class="font-mono text-cyan"><strong>${escapeHtml(p.numero_factura_proveedor)}</strong></td>
                <td><span class="badge badge-secondary">${p.tipo_documento}</span></td>
                <td><strong>${escapeHtml(p.proveedor_nombre)}</strong></td>
                <td>${escapeHtml(p.bodega_nombre)}</td>
                <td class="text-right font-mono">$${Number(p.subtotal).toFixed(2)}</td>
                <td class="text-right font-mono">$${Number(p.iva).toFixed(2)}</td>
                <td class="text-right font-mono text-emerald" style="font-weight:700;">$${Number(p.total).toFixed(2)}</td>
                <td class="text-center"><span class="badge ${p.es_credito ? 'badge-warning' : 'badge-success'}">${p.es_credito ? 'Crédito' : 'Contado'}</span></td>
                <td class="text-center"><button class="btn btn-outline btn-xs" onclick="viewPurchaseDetailModal(${p.id})">👁️ Detalle</button></td>
            </tr>
        `).join('');
    } catch (e) {
        console.error(e);
    }
}

async function openNewPurchaseModal() {
    currentPurchaseItems = [];
    renderPurchaseLines();
    openModal('modalPurchaseCreate');

    // Cargar proveedores, bodegas y productos
    const [suppRes, invRes] = await Promise.all([
        fetch('/api/catalog/suppliers').then(r => r.json()),
        fetch('/api/inventory/products').then(r => r.json())
    ]);

    const suppSel = document.getElementById('purchaseSupplierSelect');
    if (suppSel && suppRes.suppliers) {
        suppSel.innerHTML = suppRes.suppliers.map(s => `<option value="${s.id}">${escapeHtml(s.razon_social)} (${s.nit})</option>`).join('');
    }

    const bodSel = document.getElementById('purchaseBodegaSelect');
    if (bodSel && invRes.bodegas) {
        bodSel.innerHTML = invRes.bodegas.map(b => `<option value="${b.id}">${escapeHtml(b.nombre)}</option>`).join('');
    }

    const prodSel = document.getElementById('purchaseProductSelect');
    if (prodSel && invRes.products) {
        prodSel.innerHTML = invRes.products.map(p => `<option value="${p.id}">${escapeHtml(p.nombre)} (${p.codigo_interno})</option>`).join('');
    }
}

function renderPurchaseLines() {
    const tbody = document.getElementById('purchaseItemsTableBody');
    if (currentPurchaseItems.length === 0) {
        tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted">No has agregado productos a la compra.</td></tr>';
        document.getElementById('purchaseTotalDisplay').textContent = '$0.00';
        return;
    }

    let tot = 0;
    tbody.innerHTML = currentPurchaseItems.map((it, idx) => {
        const lineTot = it.cantidad * it.costo_unitario;
        tot += lineTot;
        return `
            <tr>
                <td><strong>${escapeHtml(it.nombre)}</strong> ${it.numero_lote ? `<span class="badge badge-secondary">Lote: ${escapeHtml(it.numero_lote)}</span>` : ''}</td>
                <td class="text-center font-mono">${it.cantidad}</td>
                <td class="text-right font-mono">$${it.costo_unitario.toFixed(4)}</td>
                <td class="text-right font-mono text-emerald" style="font-weight:700;">$${lineTot.toFixed(2)}</td>
                <td class="text-center"><button class="btn btn-link text-danger" onclick="removePurchaseLine(${idx})">✕</button></td>
            </tr>
        `;
    }).join('');

    document.getElementById('purchaseTotalDisplay').textContent = `$${tot.toFixed(2)}`;
}

function removePurchaseLine(index) {
    currentPurchaseItems.splice(index, 1);
    renderPurchaseLines();
}

// ==========================================
// MÓDULO CATÁLOGOS (CATEGORÍAS Y PROVEEDORES)
// ==========================================
let allCatalogCategories = [];
let allSuppliersCache = [];

function initCatalog() {
    document.getElementById('btnOpenCategoryModal')?.addEventListener('click', () => {
        openCategoryModal(0);
    });

    document.getElementById('btnOpenSupplierModal')?.addEventListener('click', () => {
        document.getElementById('supplierRazonSocial').value = '';
        document.getElementById('supplierNombreComercial').value = '';
        document.getElementById('supplierNit').value = '';
        document.getElementById('supplierNrc').value = '';
        document.getElementById('supplierTelefono').value = '';
        document.getElementById('supplierContacto').value = '';
        openModal('modalSupplierEdit');
    });

    document.getElementById('btnSaveCategory')?.addEventListener('click', async () => {
        const id = parseInt(document.getElementById('categoryIdInput')?.value || '0');
        const nombre = document.getElementById('categoryNameInput').value.trim();
        const desc = document.getElementById('categoryDescInput').value.trim();
        const padre = document.getElementById('categoryParentSelect').value;

        if (!nombre) {
            showToast('El nombre de la categoría es obligatorio.', 'warning');
            return;
        }

        try {
            const res = await fetch('/api/catalog/categories', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: id, nombre: nombre, descripcion: desc, padre_id: padre })
            });
            const data = await res.json();
            if (data.ok) {
                closeModal('modalCategoryEdit');
                loadCatalog();
                if (typeof loadInventory === 'function') loadInventory();
                showToast(data.message || 'Categoría guardada exitosamente.', 'success');
            } else {
                showToast(data.error || 'Error al guardar categoría', 'error');
            }
        } catch (e) {
            showToast('Error de red al guardar categoría.', 'error');
        }
    });

    document.getElementById('btnSaveSupplier')?.addEventListener('click', async () => {
        const rs = document.getElementById('supplierRazonSocial').value.trim();
        const nc = document.getElementById('supplierNombreComercial').value.trim();
        const nit = document.getElementById('supplierNit').value.trim();
        const nrc = document.getElementById('supplierNrc').value.trim();
        const tel = document.getElementById('supplierTelefono').value.trim();
        const dias = parseInt(document.getElementById('supplierDiasCredito').value) || 0;
        const cont = document.getElementById('supplierContacto').value.trim();

        if (!rs || !nit) {
            showToast('Razón social y NIT son obligatorios.', 'warning');
            return;
        }

        try {
            const res = await fetch('/api/catalog/suppliers', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    razon_social: rs, nombre_comercial: nc, nit: nit, nrc: nrc,
                    telefono: tel, dias_credito: dias, contacto: cont
                })
            });
            const data = await res.json();
            if (data.ok) {
                closeModal('modalSupplierEdit');
                loadCatalog();
                showToast(data.message || 'Proveedor registrado con éxito.', 'success');
            } else {
                showToast(data.error || 'Error al guardar proveedor', 'error');
            }
        } catch (e) {
            showToast('Error al guardar proveedor.', 'error');
        }
    });
}

function openCategoryModal(catId) {
    const idF = document.getElementById('categoryIdInput');
    const nomF = document.getElementById('categoryNameInput');
    const descF = document.getElementById('categoryDescInput');
    const padreF = document.getElementById('categoryParentSelect');
    const titleF = document.getElementById('categoryModalTitle');

    // Población de opciones de categoría padre
    if (padreF) {
        padreF.innerHTML = '<option value="">Ninguna (Categoría Principal)</option>' +
            allCatalogCategories
                .filter(c => c.id !== catId)
                .map(c => `<option value="${c.id}">${escapeHtml(c.nombre)}</option>`).join('');
    }

    if (catId > 0) {
        const cat = allCatalogCategories.find(c => Number(c.id) === Number(catId));
        if (!cat) return;
        if (idF) idF.value = cat.id;
        if (nomF) nomF.value = cat.nombre;
        if (descF) descF.value = cat.descripcion || '';
        if (padreF) padreF.value = cat.padre_id || '';
        if (titleF) titleF.textContent = `✏️ Modificar Categoría: ${cat.nombre}`;
    } else {
        if (idF) idF.value = '0';
        if (nomF) nomF.value = '';
        if (descF) descF.value = '';
        if (padreF) padreF.value = '';
        if (titleF) titleF.textContent = '🏷️ Nueva Categoría';
    }

    openModal('modalCategoryEdit');
}

async function loadCatalog() {
    try {
        const [catRes, suppRes] = await Promise.all([
            fetch('/api/catalog/categories').then(r => r.json()),
            fetch('/api/catalog/suppliers').then(r => r.json())
        ]);

        // Tabla Categorías
        const catTbody = document.getElementById('categoriesTableBody');
        allCatalogCategories = catRes.categories || [];
        if (allCatalogCategories.length === 0) {
            catTbody.innerHTML = '<tr><td colspan="4" class="text-center text-muted">No hay categorías</td></tr>';
        } else {
            catTbody.innerHTML = allCatalogCategories.map(c => `
                <tr>
                    <td><strong>${escapeHtml(c.nombre)}</strong> <div class="text-muted" style="font-size:0.75rem;">${escapeHtml(c.descripcion || '')}</div></td>
                    <td class="text-muted">${escapeHtml(c.padre_nombre || 'Principal')}</td>
                    <td class="text-center font-mono">${c.total_productos}</td>
                    <td class="text-right">
                        <button class="btn btn-outline btn-xs" onclick="openCategoryModal(${c.id})">✏️ Editar</button>
                    </td>
                </tr>
            `).join('');

            // Llenar select padre
            const parentSel = document.getElementById('categoryParentSelect');
            if (parentSel) {
                parentSel.innerHTML = '<option value="">Ninguna (Categoría Principal)</option>' +
                    allCatalogCategories.map(c => `<option value="${c.id}">${escapeHtml(c.nombre)}</option>`).join('');
            }
        }

        // Tabla Proveedores
        const suppTbody = document.getElementById('suppliersTableBody');
        const suppliers = suppRes.suppliers || [];
        allSuppliersCache = suppliers;
        if (suppliers.length === 0) {
            suppTbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted">No hay proveedores</td></tr>';
        } else {
            suppTbody.innerHTML = suppliers.map(s => `
                <tr>
                    <td><strong>${escapeHtml(s.razon_social)}</strong> <div class="text-muted" style="font-size:0.75rem;">${escapeHtml(s.nombre_comercial || '')}</div></td>
                    <td class="font-mono text-cyan" style="font-size:0.8rem;">${escapeHtml(s.nit)}</td>
                    <td>${escapeHtml(s.contacto || s.telefono || '-')}</td>
                    <td class="text-center font-mono">${s.dias_credito} d</td>
                    <td class="text-right"><button class="btn btn-outline btn-xs" onclick="viewSupplierPurchases(${s.id})">Facturas (${s.total_facturas})</button></td>
                </tr>
            `).join('');
        }
    } catch (e) {
        console.error(e);
    }
}

// ==========================================
// MÓDULO CONTROL DE USUARIOS
// ==========================================
let allUsersCache = [];

function initUsers() {
    document.getElementById('btnOpenUserModal')?.addEventListener('click', () => {
        openUserModal(0);
    });

    document.getElementById('btnSaveUser')?.addEventListener('click', async () => {
        const id = parseInt(document.getElementById('editUserId').value) || 0;
        const u = document.getElementById('editUsername').value.trim();
        const nombre = document.getElementById('editUserFullName').value.trim();
        const email = document.getElementById('editUserEmail').value.trim();
        const rol = document.getElementById('editUserRole').value;
        const pass = document.getElementById('editUserPassword').value;
        const pin = document.getElementById('editUserPin').value.trim();

        if (!u || !nombre) {
            alert('Usuario y nombre completo son obligatorios.');
            return;
        }

        const payload = {
            id: id,
            username: u,
            nombre_completo: nombre,
            email: email,
            rol: rol,
            password: pass,
            pin: pin,
            activo: true
        };

        try {
            const res = await fetch('/api/users', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const data = await res.json();
            if (data.ok) {
                closeModal('modalUserEdit');
                loadUsers();
                alert('Usuario guardado exitosamente.');
            } else {
                alert(`Error: ${data.error}`);
            }
        } catch (e) {
            alert('Error al guardar usuario.');
        }
    });
}

async function loadUsers() {
    try {
        const res = await fetch('/api/users');
        const data = await res.json();
        const tbody = document.getElementById('usersTableBody');
        allUsersCache = data.users || [];

        if (allUsersCache.length === 0) {
            tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted">No hay usuarios</td></tr>';
            return;
        }

        tbody.innerHTML = allUsersCache.map(u => `
            <tr>
                <td class="font-mono text-cyan" style="font-weight:700;">${escapeHtml(u.username)}</td>
                <td><strong>${escapeHtml(u.nombre_completo)}</strong></td>
                <td class="text-muted">${escapeHtml(u.email || '-')}</td>
                <td class="text-center"><span class="badge ${u.rol === 'ADMIN' ? 'badge-primary' : (u.rol === 'GERENTE' ? 'badge-warning' : 'badge-secondary')}">${u.rol}</span></td>
                <td class="text-center">${u.tiene_pin ? '🟢 Configurado' : '⚪ Sin PIN'}</td>
                <td class="text-center"><span class="status-indicator ${u.activo ? '' : 'closed'}">${u.activo ? 'Activo' : 'Inactivo'}</span></td>
                <td class="text-right">
                    <button class="btn btn-outline btn-xs" onclick="openUserModal(${u.id})">✏️ Editar</button>
                </td>
            </tr>
        `).join('');
    } catch (e) {
        console.error(e);
    }
}

function openUserModal(userId) {
    const idField = document.getElementById('editUserId');
    const uField = document.getElementById('editUsername');
    const nameField = document.getElementById('editUserFullName');
    const emailField = document.getElementById('editUserEmail');
    const roleField = document.getElementById('editUserRole');
    const passField = document.getElementById('editUserPassword');
    const pinField = document.getElementById('editUserPin');
    const passLabel = document.getElementById('passOptionalLabel');
    const title = document.getElementById('modalUserTitle');

    passField.value = '';
    pinField.value = '';

    if (userId > 0) {
        const user = allUsersCache.find(x => x.id === userId);
        if (!user) return;
        idField.value = user.id;
        uField.value = user.username;
        uField.disabled = true; // No modificar username
        nameField.value = user.nombre_completo;
        emailField.value = user.email || '';
        roleField.value = user.rol;
        passLabel.style.display = 'inline';
        title.textContent = `✏️ Editar Usuario: ${user.username}`;
    } else {
        idField.value = '0';
        uField.value = '';
        uField.disabled = false;
        nameField.value = '';
        emailField.value = '';
        roleField.value = 'CAJERO';
        passLabel.style.display = 'none';
        title.textContent = '➕ Crear Nuevo Usuario';
    }

    openModal('modalUserEdit');
}

// ==========================================
// VISOR DE FACTURAS DE PROVEEDORES Y DETALLE
// ==========================================
let currentViewingSupplierId = 0;

async function viewSupplierPurchases(supplierId) {
    currentViewingSupplierId = supplierId;
    const supp = allSuppliersCache.find(x => x.id === supplierId);
    const suppName = supp ? supp.razon_social : 'Proveedor #' + supplierId;

    const titleEl = document.getElementById('supplierPurchasesModalName');
    if (titleEl) titleEl.textContent = suppName;

    const tbody = document.getElementById('supplierPurchasesTableBody');
    if (tbody) {
        tbody.innerHTML = '<tr><td colspan="9" class="text-center text-muted" style="padding:1.5rem;">Consultando facturas del proveedor...</td></tr>';
    }

    // Actualizar KPI de días de crédito
    const creditEl = document.getElementById('supplierPurchasesCreditDays');
    if (creditEl) creditEl.textContent = supp ? `${supp.dias_credito} días` : '-';

    openModal('modalSupplierPurchases');

    // Configurar botón para crear compra a este proveedor
    const btnNew = document.getElementById('btnNewPurchaseForSupplier');
    if (btnNew) {
        btnNew.onclick = () => {
            closeModal('modalSupplierPurchases');
            openNewPurchaseModal();
            setTimeout(() => {
                const suppSel = document.getElementById('purchaseSupplierSelect');
                if (suppSel) suppSel.value = supplierId;
            }, 100);
        };
    }

    try {
        const res = await fetch(`/api/purchases?proveedor_id=${supplierId}`);
        const data = await res.json();
        const purchases = data.purchases || [];

        // Actualizar KPIs
        const countEl = document.getElementById('supplierPurchasesCount');
        const totalAmountEl = document.getElementById('supplierPurchasesTotalAmount');
        if (countEl) countEl.textContent = purchases.length;

        const sumTotal = purchases.reduce((acc, p) => acc + (parseFloat(p.total) || 0), 0);
        if (totalAmountEl) totalAmountEl.textContent = `$${sumTotal.toFixed(2)}`;

        if (purchases.length === 0) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="9" class="text-center text-muted" style="padding: 2.5rem 1rem;">
                        <div style="font-size: 1.1rem; font-weight: 600; margin-bottom: 0.5rem; color: var(--text-primary);">Sin facturas registradas</div>
                        <p style="margin-bottom: 1rem; color: var(--text-secondary); font-size: 0.85rem;">Aún no se han recibido compras de este proveedor en el sistema.</p>
                        <button class="btn btn-primary btn-sm" onclick="document.getElementById('btnNewPurchaseForSupplier').click()">➕ Registrar Primera Factura</button>
                    </td>
                </tr>
            `;
            return;
        }

        tbody.innerHTML = purchases.map(p => `
            <tr>
                <td class="font-mono">${p.fecha_factura}</td>
                <td class="font-mono text-cyan"><strong>${escapeHtml(p.numero_factura_proveedor)}</strong></td>
                <td>${escapeHtml(p.bodega_nombre || 'Bodega Principal')}</td>
                <td><span class="badge ${p.tipo_documento === '03' ? 'badge-primary' : 'badge-secondary'}">${p.tipo_documento === '03' ? 'CCF (03)' : 'Factura (01)'}</span></td>
                <td class="text-right font-mono">$${Number(p.subtotal).toFixed(2)}</td>
                <td class="text-right font-mono">$${Number(p.iva).toFixed(2)}</td>
                <td class="text-right font-mono text-emerald" style="font-weight:700;">$${Number(p.total).toFixed(2)}</td>
                <td class="text-center"><span class="badge ${p.es_credito ? 'badge-warning' : 'badge-success'}">${p.es_credito ? 'Crédito' : 'Contado'}</span></td>
                <td class="text-center">
                    <button class="btn btn-outline btn-xs" onclick="viewPurchaseDetailModal(${p.id})">👁️ Ver Factura</button>
                </td>
            </tr>
        `).join('');
    } catch (e) {
        if (tbody) tbody.innerHTML = '<tr><td colspan="9" class="text-center text-danger">Error al cargar facturas de compra.</td></tr>';
    }
}

async function viewPurchaseDetailModal(purchaseId) {
    if (!purchaseId) return;

    // Reset UI
    document.getElementById('purchaseDetailNumber').textContent = `...`;
    document.getElementById('purchaseDetailSupplier').textContent = 'Cargando...';
    document.getElementById('purchaseDetailNitNrc').textContent = '—';
    document.getElementById('purchaseDetailDate').textContent = '—';
    document.getElementById('purchaseDetailWarehouse').textContent = '—';
    document.getElementById('purchaseDetailCondition').textContent = '—';
    document.getElementById('purchaseDetailUser').textContent = '—';
    document.getElementById('purchaseDetailSubtotal').textContent = '$0.00';
    document.getElementById('purchaseDetailIva').textContent = '$0.00';
    document.getElementById('purchaseDetailTotal').textContent = '$0.00';
    document.getElementById('purchaseDetailItemsTableBody').innerHTML = '<tr><td colspan="7" class="text-center">Cargando productos recibidos...</td></tr>';

    openModal('modalPurchaseDetail');

    try {
        const res = await fetch(`/api/purchases/detail?id=${purchaseId}`);
        const data = await res.json();
        if (!data.compra) {
            alert(data.error || 'No se encontró la factura');
            closeModal('modalPurchaseDetail');
            return;
        }

        const c = data.compra;
        const items = data.items || [];

        document.getElementById('purchaseDetailNumber').textContent = `#${c.numero_factura_proveedor}`;
        document.getElementById('purchaseDetailSupplier').textContent = c.proveedor_nombre;
        document.getElementById('purchaseDetailNitNrc').textContent = `NIT: ${c.proveedor_nit || '—'} | NRC: ${c.proveedor_nrc || '—'}`;
        document.getElementById('purchaseDetailDate').textContent = c.fecha_factura;
        document.getElementById('purchaseDetailWarehouse').textContent = c.bodega_nombre || 'Bodega Principal';
        document.getElementById('purchaseDetailCondition').innerHTML = `<span class="badge ${c.es_credito ? 'badge-warning' : 'badge-success'}">${c.es_credito ? 'Crédito' : 'Contado'}</span>`;
        document.getElementById('purchaseDetailUser').textContent = c.usuario_nombre || 'Usuario';

        document.getElementById('purchaseDetailSubtotal').textContent = `$${Number(c.subtotal).toFixed(2)}`;
        document.getElementById('purchaseDetailIva').textContent = `$${Number(c.iva).toFixed(2)}`;
        document.getElementById('purchaseDetailTotal').textContent = `$${Number(c.total).toFixed(2)}`;

        const itemsTbody = document.getElementById('purchaseDetailItemsTableBody');
        if (items.length === 0) {
            itemsTbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted">Esta factura no tiene renglones de producto registrados.</td></tr>';
        } else {
            itemsTbody.innerHTML = items.map((it, idx) => `
                <tr>
                    <td class="text-muted" style="font-size:0.8rem;">${idx + 1}</td>
                    <td class="font-mono text-cyan" style="font-size:0.8rem;">${escapeHtml(it.codigo_interno || '-')}</td>
                    <td><strong>${escapeHtml(it.producto_nombre)}</strong></td>
                    <td class="text-center font-mono" style="font-weight:600;">${Number(it.cantidad).toFixed(2)}</td>
                    <td class="text-right font-mono">$${Number(it.costo_unitario).toFixed(4)}</td>
                    <td class="text-right font-mono text-emerald" style="font-weight:700;">$${Number(it.total_linea).toFixed(2)}</td>
                    <td>${it.numero_lote ? `<span class="badge badge-info">${escapeHtml(it.numero_lote)}</span>` : '<span class="text-muted font-mono" style="font-size:0.75rem;">Sin lote</span>'}</td>
                </tr>
            `).join('');
        }
    } catch (e) {
        alert('Error al consultar el detalle de la factura de compra.');
        closeModal('modalPurchaseDetail');
    }
}

// ==========================================
// GESTIÓN DE TERMINALES Y CAJAS (CRUD)
// ==========================================
let terminalsCache = [];

async function loadTerminalsCatalog() {
    const tbody = document.getElementById('terminalsTableBody');
    if (tbody) tbody.innerHTML = '<tr><td colspan="7" class="text-center p-3 text-muted">Cargando cajas...</td></tr>';
    try {
        const res = await fetch('/api/cash/terminals');
        const data = await res.json();
        terminalsCache = data.terminals || [];
        renderTerminalsTable(terminalsCache);
    } catch (e) {
        if (tbody) tbody.innerHTML = '<tr><td colspan="7" class="text-center text-danger p-3">Error al cargar terminales.</td></tr>';
    }
}

function renderTerminalsTable(terminals) {
    const tbody = document.getElementById('terminalsTableBody');
    if (!tbody) return;

    if (!terminals || terminals.length === 0) {
        tbody.innerHTML = '<tr><td colspan="7" class="text-center p-4 text-muted">No hay cajas registradas en el sistema.</td></tr>';
        return;
    }

    tbody.innerHTML = terminals.map(t => {
        const hasOpen = parseInt(t.tiene_sesion_abierta) > 0;
        const liveInfo = hasOpen 
            ? `<span class="badge badge-success font-bold" style="font-size:0.75rem;">🟢 Turno Abierto: ${escapeHtml(t.cajero_actual || 'Cajero')}</span>` 
            : `<span class="badge badge-secondary" style="font-size:0.75rem; background:#f1f5f9; color:#64748b;">Turno Cerrado</span>`;

        const statusBadge = t.activo 
            ? `<span class="status-indicator status-open"><span class="status-dot green"></span> Operativa</span>` 
            : `<span class="status-indicator status-closed"><span class="status-dot red"></span> Inactiva</span>`;

        const tJson = JSON.stringify(t).replace(/"/g, '&quot;');
        return `
            <tr>
                <td class="font-mono text-cyan" style="font-weight:700;">${escapeHtml(t.numero_terminal)}</td>
                <td><strong>${escapeHtml(t.nombre)}</strong></td>
                <td class="font-mono text-muted" style="font-size:0.8rem;">${escapeHtml(t.impresora_nombre || 'Sin impresora')}</td>
                <td class="font-mono" style="font-size:0.8rem;">${escapeHtml(t.ip_terminal || '127.0.0.1')}</td>
                <td class="text-center">${statusBadge}</td>
                <td class="text-center">${liveInfo}</td>
                <td class="text-right">
                    <button class="btn btn-outline btn-xs" onclick="openTerminalForm(${tJson})" title="Editar configuración">
                        ✏️ Editar
                    </button>
                </td>
            </tr>
        `;
    }).join('');
}

function openTerminalForm(terminal = null) {
    const titleEl = document.getElementById('modalEditTerminalTitle');
    const idInput = document.getElementById('terminalFormId');
    const nomInput = document.getElementById('terminalFormNombre');
    const numInput = document.getElementById('terminalFormNumero');
    const impInput = document.getElementById('terminalFormImpresora');
    const ipInput = document.getElementById('terminalFormIp');
    const actInput = document.getElementById('terminalFormActivo');

    if (terminal) {
        titleEl.textContent = `✏️ Modificar Terminal: ${terminal.numero_terminal}`;
        idInput.value = terminal.id;
        nomInput.value = terminal.nombre;
        numInput.value = terminal.numero_terminal;
        impInput.value = terminal.impresora_nombre || 'Generic 80mm ESC/POS';
        ipInput.value = terminal.ip_terminal || '127.0.0.1';
        actInput.checked = Boolean(terminal.activo);
    } else {
        titleEl.textContent = '➕ Registrar Nueva Terminal / Caja';
        idInput.value = '0';
        nomInput.value = '';
        numInput.value = `TERM-0${terminalsCache.length + 1}`;
        impInput.value = 'Generic 80mm ESC/POS';
        ipInput.value = '127.0.0.1';
        actInput.checked = true;
    }

    openModal('modalEditTerminal');
}

document.getElementById('btnSaveTerminal')?.addEventListener('click', async () => {
    const id = parseInt(document.getElementById('terminalFormId').value) || 0;
    const nombre = document.getElementById('terminalFormNombre').value.trim();
    const numero_terminal = document.getElementById('terminalFormNumero').value.trim();
    const impresora_nombre = document.getElementById('terminalFormImpresora').value.trim();
    const ip_terminal = document.getElementById('terminalFormIp').value.trim();
    const activo = document.getElementById('terminalFormActivo').checked;

    if (!nombre) {
        showToast('El nombre de la caja es obligatorio', 'warning');
        document.getElementById('terminalFormNombre').focus();
        return;
    }
    if (!numero_terminal) {
        showToast('El código identificador de la terminal es obligatorio', 'warning');
        document.getElementById('terminalFormNumero').focus();
        return;
    }

    try {
        const res = await fetch('/api/cash/terminals', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id, nombre, numero_terminal, impresora_nombre, ip_terminal, activo })
        });
        const data = await res.json();
        if (data.ok) {
            showToast(data.message || 'Terminal guardada exitosamente', 'success');
            closeModal('modalEditTerminal');
            loadTerminalsCatalog();
            checkCashSession(); // Para actualizar el select de apertura
        } else {
            showToast(data.error || 'Error al guardar terminal', 'error');
        }
    } catch (e) {
        showToast('Error de conexión al servidor local', 'error');
    }
});

// Exportación global explícita
window.openModal = openModal;
window.closeModal = closeModal;
window.closeAllModals = closeAllModals;
window.viewSupplierPurchases = viewSupplierPurchases;
window.viewPurchaseDetailModal = viewPurchaseDetailModal;
window.performLogout = performLogout;
window.loadTerminalsCatalog = loadTerminalsCatalog;
window.openTerminalForm = openTerminalForm;

