/* ===== State ===== */
let currentUser = null;
let currentPage = 'dashboard';
let chartInstance = null;
let carouselInterval = null;
let currentSlide = 0;

/* ===== API Helper ===== */
const BASE_PATH = (() => {
    const p = window.location.pathname.replace(/\/index\.php$/, '').replace(/\/+$/, '');
    return p || '';
})();

function resolveMediaUrl(url) {
    if (!url) return url;
    if (/^(https?:)?\/\//i.test(url)) return url;
    if (url.charAt(0) === '/') return BASE_PATH + url;
    return BASE_PATH + '/' + url;
}

async function api(method, path, data) {
    const opts = { method, headers: { 'Content-Type': 'application/json' } };
    if (data) opts.body = JSON.stringify(data);
    const url = BASE_PATH + '/api/index.php' + path;
    const res = await fetch(url, opts);
    let json;
    try { json = await res.json(); } catch (e) {
        const text = await res.text().catch(() => '');
        throw new Error('Invalid server response: ' + (text.slice(0, 200) || '(empty)'));
    }
    if (!res.ok) throw new Error(json.error || 'Request failed');
    return json;
}

/* ===== Auth ===== */
async function handleLogin(e) {
    e.preventDefault();
    const btn = document.getElementById('loginBtn');
    const errEl = document.getElementById('loginError');
    btn.disabled = true; btn.innerHTML = '<span>Signing in...</span><i class="fas fa-spinner fa-spin"></i>';
    errEl.textContent = '';
    try {
        const res = await api('POST', '/auth/login', {
            unit_number: document.getElementById('loginUnit').value,
            password: document.getElementById('loginPassword').value
        });
        if (res.user) {
            currentUser = res.user;
            showApp();
            navigate('dashboard');
            refreshSupportBadge();
            if (['admin', 'finance', 'super_admin'].includes(currentUser.role)) refreshAdminTicketsBadge();
            startBadgePolling();
        }
    } catch (e) {
        errEl.textContent = e.message;
    }
    btn.disabled = false; btn.innerHTML = '<span>Sign In</span><i class="fas fa-arrow-right"></i>';
    return false;
}

function showForgotPasswordModal() {
    const overlay = document.createElement('div');
    overlay.id = 'forgotModal';
    overlay.className = 'modal-overlay open';
    overlay.innerHTML = `
        <div class="modal" style="max-width:440px">
            <h3 style="margin:0 0 4px">Forgot Password</h3>
            <p style="margin:0 0 16px;color:var(--text-secondary);font-size:13px">Enter your unit number (or registered email). If it exists, we will email you a new temporary password.</p>
            <form onsubmit="return handleForgotPassword(event)">
                <div class="form-group">
                    <label>Unit Number / Email</label>
                    <input type="text" id="forgotUnit" placeholder="e.g. GF-0C143 or email@example.com" required>
                </div>
                <p id="forgotErr" style="color:var(--danger);font-size:13px;display:none;margin:0 0 10px"></p>
                <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:8px">
                    <button type="button" class="btn-secondary" onclick="closeForgotPasswordModal()">Cancel</button>
                    <button type="submit" class="btn-primary" id="forgotBtn"><span>Send Reset Email</span></button>
                </div>
            </form>
        </div>`;
    overlay.addEventListener('click', (ev) => { if (ev.target === overlay) closeForgotPasswordModal(); });
    document.body.appendChild(overlay);
    setTimeout(() => document.getElementById('forgotUnit').focus(), 50);
}

function closeForgotPasswordModal() {
    const el = document.getElementById('forgotModal');
    if (el) el.remove();
}

async function handleForgotPassword(e) {
    e.preventDefault();
    const btn = document.getElementById('forgotBtn');
    const errEl = document.getElementById('forgotErr');
    btn.disabled = true;
    errEl.style.display = 'none';
    try {
        await api('POST', '/auth/forgot-password', {
            unit_number: document.getElementById('forgotUnit').value.trim()
        });
        closeForgotPasswordModal();
        showThankYouModal();
    } catch (err) {
        errEl.textContent = err.message;
        errEl.style.display = 'block';
        btn.disabled = false;
    }
    return false;
}

function showThankYouModal() {
    const overlay = document.createElement('div');
    overlay.className = 'thank-you-modal modal-overlay open';
    overlay.innerHTML = `
        <div class="modal" style="max-width:420px;text-align:center">
            <i class="fas fa-check-circle" style="font-size:44px;color:var(--success);margin-bottom:12px"></i>
            <h3 style="margin:0 0 8px">Thank You</h3>
            <p style="margin:0 0 20px;color:var(--text-secondary);font-size:14px;line-height:1.5">If this account exists, a new temporary password has been sent to the email on file. Please check your inbox (and spam folder).</p>
            <button type="button" class="btn-primary" onclick="this.closest('.modal-overlay').remove()">OK</button>
        </div>`;
    overlay.addEventListener('click', (ev) => { if (ev.target === overlay) overlay.remove(); });
    document.body.appendChild(overlay);
}

async function handleLogout() {
    await api('POST', '/auth/logout');
    currentUser = null;
    if (chartInstance) { chartInstance.destroy(); chartInstance = null; }
    document.getElementById('appLayout').style.display = 'none';
    if (carouselInterval) { clearInterval(carouselInterval); carouselInterval = null; }
    showPublicNews();
}

function showApp() {
    document.getElementById('loadingScreen').style.display = 'none';
    document.getElementById('loginPage').style.display = 'none';
    document.getElementById('publicFooter').style.display = 'none';
    document.getElementById('appLayout').style.display = 'flex';
    const initials = currentUser.name.split(' ').map(w => w[0]).join('').toUpperCase().slice(0, 2);
    document.getElementById('userAvatar').textContent = initials;
    document.getElementById('headerAvatar').textContent = initials;
    document.getElementById('sidebarUserName').textContent = currentUser.name;
    document.getElementById('sidebarUserRole').textContent = currentUser.role.charAt(0).toUpperCase() + currentUser.role.slice(1);
    const isAdmin = ['admin','finance','super_admin'].includes(currentUser.role);
    document.getElementById('adminNav').style.display = isAdmin ? 'block' : 'none';
}

function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    if (window.innerWidth <= 1024) {
        const isOpen = sidebar.classList.toggle('mobile-open');
        overlay.classList.toggle('active');
        document.body.style.overflow = isOpen ? 'hidden' : '';
    } else {
        sidebar.classList.toggle('collapsed');
    }
}

function toggleSidebarAccordion(id) {
    const el = document.getElementById(id);
    if (el) el.classList.toggle('open');
}

function toggleTheme() {
    const html = document.documentElement;
    const isDark = html.getAttribute('data-theme') === 'dark';
    html.setAttribute('data-theme', isDark ? 'light' : 'dark');
    localStorage.setItem('theme', isDark ? 'light' : 'dark');
}

function formatCurrency(n) {
    return 'Rp' + Number(n).toLocaleString('id-ID', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
}

function formatDate(d) {
    if (!d) return '';
    const dt = new Date(d + (d.includes(' ') ? '' : 'T00:00:00'));
    return dt.toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' });
}

function timeAgo(d) {
    const now = new Date();
    const dt = new Date(d + (d.includes(' ') ? '' : 'T00:00:00'));
    const diff = Math.floor((now - dt) / 1000);
    if (diff < 60) return 'Just now';
    if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
    if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
    if (diff < 2592000) return Math.floor(diff / 86400) + 'd ago';
    return formatDate(d);
}

function billPaymentStatus(bill) {
    const amount = Math.round((parseFloat(bill.amount) || 0) * 100) / 100;
    const paid = Math.round((parseFloat(bill.paid) || 0) * 100) / 100;
    if (amount === paid) return 'Paid';
    if (amount > 0 && paid === 0) return 'Belum Bayar';
    if (amount > paid) return 'Kurang Bayar';
    return 'Lebih Bayar';
}

function customerBillStatus(bill) {
    const paid = Math.round((parseFloat(bill.paid) || 0) * 100) / 100;
    return paid > 0 ? 'Paid' : 'Unpaid';
}

function statusBadge(status) {
    const map = {
        'paid': 'badge-green', 'pending': 'badge-yellow', 'overdue': 'badge-red',
        'Paid': 'badge-green', 'Unpaid': 'badge-red', 'Belum Bayar': 'badge-red', 'Kurang Bayar': 'badge-yellow', 'Lebih Bayar': 'badge-blue',
        'open': 'badge-yellow', 'in_progress': 'badge-blue', 'resolved': 'badge-green', 'closed': 'badge-gray'
    };
    return `badge ${map[status] || 'badge-gray'}`;
}

function statusDot(status) {
    const map = { 'paid': 'green', 'pending': 'yellow', 'overdue': 'red', 'open': 'yellow', 'in_progress': 'blue', 'resolved': 'green', 'closed': 'gray', 'Paid': 'green', 'Unpaid': 'red', 'Belum Bayar': 'red', 'Kurang Bayar': 'yellow', 'Lebih Bayar': 'blue' };
    return `<span class="status-dot ${map[status] || 'gray'}"></span>`;
}

function showToast(msg, type = 'success') {
    const existing = document.querySelector('.toast');
    if (existing) existing.remove();
    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    toast.innerHTML = `<i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i> ${msg}`;
    Object.assign(toast.style, {
        position: 'fixed', bottom: '24px', right: '24px',
        padding: '14px 24px', borderRadius: 'var(--radius-sm)',
        background: type === 'success' ? 'var(--secondary)' : type === 'warning' ? 'var(--warning)' : 'var(--danger)',
        color: 'white', fontWeight: 500, fontSize: '14px',
        zIndex: 200, boxShadow: 'var(--shadow-lg)',
        display: 'flex', alignItems: 'center', gap: '10px',
        transform: 'translateY(20px)', opacity: '0',
        transition: 'all 0.3s'
    });
    document.body.appendChild(toast);
    requestAnimationFrame(() => {
        toast.style.transform = 'translateY(0)';
        toast.style.opacity = '1';
    });
    setTimeout(() => {
        toast.style.transform = 'translateY(20px)';
        toast.style.opacity = '0';
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}

/* ===== Navigation ===== */
function navigate(page) {
    currentPage = page;
    document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
    const navItem = document.querySelector(`.nav-item[data-page="${page}"]`);
    if (navItem) navItem.classList.add('active');
    const accordion = navItem ? navItem.closest('.nav-accordion') : null;
    if (accordion) accordion.classList.add('open');
    if (window.innerWidth <= 1024) {
        document.getElementById('sidebar').classList.remove('mobile-open');
        document.getElementById('sidebarOverlay').classList.remove('active');
        document.body.style.overflow = '';
    }
    if (carouselInterval) { clearInterval(carouselInterval); carouselInterval = null; }
    loadPage(page);
}

async function loadPage(page) {
    const area = document.getElementById('contentArea');
    const title = document.getElementById('pageTitle');
    const subtitle = document.getElementById('pageSubtitle');
    area.innerHTML = '<div class="loading-screen" style="position:relative;min-height:400px"><div class="spinner"></div></div>';
    try {
        switch (page) {
            case 'dashboard': await renderDashboard(area, title, subtitle); break;
            case 'billing': await renderBilling(area, title, subtitle); break;
            case 'payments': await renderPayments(area, title, subtitle); break;
            case 'news': await renderNews(area, title, subtitle); break;
            case 'documents': await renderDocuments(area, title, subtitle); break;
            case 'support': await renderSupport(area, title, subtitle); break;
            case 'profile': await renderProfile(area, title, subtitle); break;
            case 'admin-dashboard': await renderAdminDashboard(area, title, subtitle); break;
            case 'admin-billing': await renderAdminBilling(area, title, subtitle); break;
            case 'admin-report-billing': await renderAdminBillingReport(area, title, subtitle); break;
            case 'admin-wa-billing': await renderAdminWaBilling(area, title, subtitle); break;
            case 'admin-news': await renderAdminNews(area, title, subtitle); break;
            case 'admin-documents': await renderAdminDocuments(area, title, subtitle); break;
            case 'admin-tickets': await renderAdminTickets(area, title, subtitle); break;
            case 'admin-users': await renderAdminUsers(area, title, subtitle); break;
            case 'admin-logs': await renderAdminLogs(area, title, subtitle); break;
            case 'admin-payments': await renderAdminPayments(area, title, subtitle); break;
            case 'admin-dup-payments': await renderAdminDuplicates(area, title, subtitle); break;
            case 'admin-ast-paytrx': await renderAdminAstPaytrx(area, title, subtitle); break;
            default:
                throw new Error('Unknown page: ' + page + ' — app.js may be out of date. Hard refresh (Ctrl+Shift+R).');
        }
    } catch (e) {
        area.innerHTML = `<div class="empty-state"><i class="fas fa-exclamation-triangle"></i><h3>Error loading page</h3><p>${e.message}</p></div>`;
    }
}

/* ===== Notification badges (support) ===== */
function setNavBadge(elId, total) {
    const el = document.getElementById(elId);
    if (!el) return;
    if (total > 0) {
        el.textContent = total > 99 ? '99+' : total;
        el.style.display = 'inline-block';
    } else {
        el.style.display = 'none';
    }
}

async function refreshSupportBadge() {
    try {
        const tickets = await api('GET', '/tickets');
        const total = tickets.reduce((s, t) => s + (t.unread || 0), 0);
        setNavBadge('supportBadge', total);
    } catch (e) { /* ignore */ }
}

async function refreshAdminTicketsBadge() {
    try {
        const tickets = await api('GET', '/admin/tickets');
        const total = tickets.reduce((s, t) => s + (t.unread || 0), 0);
        setNavBadge('adminTicketsBadge', total);
    } catch (e) { /* ignore */ }
}

function startBadgePolling() {
    setInterval(() => {
        if (!currentUser) return;
        refreshSupportBadge();
        if (['admin', 'finance', 'super_admin'].includes(currentUser.role)) refreshAdminTicketsBadge();
    }, 30000);
}

/* ===== Dashboard ===== */
async function renderDashboard(area, titleEl, subtitleEl) {
    titleEl.textContent = 'Dashboard';
    subtitleEl.textContent = `Welcome back, ${currentUser.name}`;
    const data = await api('GET', '/dashboard');

    area.innerHTML = `
        <div class="quick-actions">
            <div class="quick-action" onclick="navigate('billing')">
                <i class="fas fa-file-invoice"></i><span>View Bills</span>
            </div>
            <div class="quick-action" onclick="navigate('payments')">
                <i class="fas fa-credit-card"></i><span>Make Payment</span>
            </div>
            <div class="quick-action" onclick="navigate('news')">
                <i class="fas fa-newspaper"></i><span>Announcements</span>
            </div>
            <div class="quick-action" onclick="navigate('support')">
                <i class="fas fa-headset"></i><span>Get Support</span>
            </div>
        </div>

        <div class="stats-grid">
            <div class="stat-card blue">
                <div class="stat-header">
                    <span class="stat-label">Outstanding Balance</span>
                    <span class="stat-icon blue"><i class="fas fa-coins"></i></span>
                </div>
                <div class="stat-value">${formatCurrency(data.outstanding.amount)}</div>
                <div class="stat-change ${data.outstanding.count > 0 ? 'down' : 'up'}">
                    ${data.outstanding.count > 0 ? data.outstanding.count + ' pending invoice(s)' : 'All paid'}
                </div>
            </div>
            <div class="stat-card green">
                <div class="stat-header">
                    <span class="stat-label">Total Paid</span>
                    <span class="stat-icon green"><i class="fas fa-check-circle"></i></span>
                </div>
                <div class="stat-value">${formatCurrency(data.total_paid)}</div>
                <div class="stat-change up">Lifetime payments</div>
            </div>
            <div class="stat-card amber">
                <div class="stat-header">
                    <span class="stat-label">Next Payment Due</span>
                    <span class="stat-icon amber"><i class="fas fa-calendar-alt"></i></span>
                </div>
                <div class="stat-value">${data.next_due ? formatDate(data.next_due.due_date) : 'N/A'}</div>
                <div class="stat-change ${data.next_due ? 'down' : 'up'}">
                    ${data.next_due ? formatCurrency(data.next_due.amount) : 'No pending dues'}
                </div>
            </div>
            <div class="stat-card purple">
                <div class="stat-header">
                    <span class="stat-label">Total Invoices</span>
                    <span class="stat-icon purple"><i class="fas fa-receipt"></i></span>
                </div>
                <div class="stat-value">${data.chart.labels.length}</div>
                <div class="stat-change up">${data.chart.labels.length > 0 ? 'Last issued: ' + data.chart.labels[data.chart.labels.length-1] : ''}</div>
            </div>
        </div>

        <div class="dashboard-grid">
            <div>
                <div class="card">
                    <div class="card-header">
                        <h3>Payment Overview</h3>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="paymentChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
            <div>
                <div class="card">
                    <div class="card-header">
                        <h3>Recent Payments</h3>
                        <a href="#" onclick="navigate('payments');return false" style="font-size:13px;color:var(--primary)">View All</a>
                    </div>
                    <div class="card-body" style="padding:0">
                        ${data.recent_payments.length === 0 ? '<div class="empty-state" style="padding:32px"><p>No payments yet</p></div>' :
                        `<div class="table-container">
                            <table>
                                <thead><tr><th>Invoice</th><th>Amount</th><th>Method</th><th>Date</th></tr></thead>
                                <tbody>
                                    ${data.recent_payments.map(p => `
                                        <tr><td data-label="Invoice">${p.invoice_no || 'N/A'}</td>
                                        <td data-label="Amount"><strong>${formatCurrency(p.amount)}</strong></td>
                                        <td data-label="Method">${p.method || 'N/A'}</td>
                                        <td data-label="Date" style="font-size:12px;color:var(--text-muted)">${formatDate(p.paid_at)}</td></tr>
                                    `).join('')}
                                </tbody>
                            </table>
                        </div>`}
                    </div>
                </div>
            </div>
        </div>

        <div class="two-col" style="margin-top:24px">
            <div class="card">
                <div class="card-header">
                    <h3>Latest Announcements</h3>
                    <a href="#" onclick="navigate('news');return false" style="font-size:13px;color:var(--primary)">View All</a>
                </div>
                <div class="card-body" style="padding:0">
                    ${data.announcements.slice(0, 4).map(a => `
                        <div style="padding:14px 20px;border-bottom:1px solid var(--border-light);cursor:pointer" onclick="navigate('news')">
                            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px">
                                <div>
                                    <strong style="font-size:14px">${a.title}</strong>
                                    <p style="font-size:12px;color:var(--text-muted);margin-top:4px">${a.content.slice(0, 100)}...</p>
                                </div>
                                <span class="badge ${a.category === 'maintenance' ? 'badge-red' : a.category === 'promotion' ? 'badge-yellow' : a.category === 'event' ? 'badge-green' : 'badge-blue'}" style="flex-shrink:0">${a.category}</span>
                            </div>
                        </div>
                    `).join('')}
                    ${data.announcements.length === 0 ? '<div class="empty-state"><p>No announcements</p></div>' : ''}
                </div>
            </div>
            <div>
                <div class="card">
                    <div class="card-header">
                        <h3>Upcoming Events</h3>
                    </div>
                    <div class="card-body" style="padding:0">
                        ${data.upcoming_events.length === 0 ? '<div class="empty-state" style="padding:32px"><p>No upcoming events</p></div>' :
                        data.upcoming_events.map(e => `
                            <div style="padding:16px 20px;border-bottom:1px solid var(--border-light)">
                                <div style="display:flex;gap:14px;align-items:flex-start">
                                    <div style="width:44px;height:44px;background:var(--secondary-light);border-radius:var(--radius-sm);display:flex;align-items:center;justify-content:center;color:var(--secondary);flex-shrink:0">
                                        <i class="fas fa-calendar-day"></i>
                                    </div>
                                    <div>
                                        <strong style="font-size:14px">${e.title}</strong>
                                        <p style="font-size:12px;color:var(--text-muted);margin-top:2px">${formatDate(e.published_at)}</p>
                                    </div>
                                </div>
                            </div>
                        `).join('')}
                    </div>
                </div>
            </div>
        </div>
    `;

    // Render chart
    setTimeout(() => {
        const ctx = document.getElementById('paymentChart');
        if (!ctx) return;
        if (chartInstance) { chartInstance.destroy(); }
        chartInstance = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: data.chart.labels,
                datasets: [
                    { label: 'Paid', data: data.chart.paid, backgroundColor: 'rgba(5,150,105,0.8)', borderRadius: 4 },
                    { label: 'Pending', data: data.chart.pending, backgroundColor: 'rgba(245,158,11,0.8)', borderRadius: 4 }
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { position: 'top', labels: { usePointStyle: true, padding: 16 } } },
                scales: {
                    x: { grid: { display: false }, ticks: { font: { size: 11 } } },
                    y: { beginAtZero: true, ticks: { callback: v => '₱' + (v/1000).toFixed(0) + 'k', font: { size: 11 } } }
                }
            }
        });
    }, 100);
}

/* ===== Billing ===== */
async function renderBilling(area, titleEl, subtitleEl) {
    titleEl.textContent = 'Billing';
    subtitleEl.textContent = 'View and manage your invoices';
    const billsRes = await api('GET', '/billing');
    const bills = billsRes.bills || billsRes;

    const totalOutstanding = bills.filter(b => b.status === 'pending').reduce((s, b) => s + (b.amount - b.paid), 0);
    const totalPaid = bills.filter(b => b.status === 'paid').reduce((s, b) => s + (Number(b.paid) > 0 ? Number(b.paid) : (Number(b.amount) || 0)), 0);

    area.innerHTML = `
        <div class="stats-grid">
            <div class="stat-card blue">
                <div class="stat-header"><span class="stat-label">Outstanding</span><span class="stat-icon blue"><i class="fas fa-coins"></i></span></div>
                <div class="stat-value">${formatCurrency(totalOutstanding)}</div>
                <div class="stat-change down">${bills.filter(b => b.status === 'pending').length} unpaid invoice(s)</div>
            </div>
            <div class="stat-card green">
                <div class="stat-header"><span class="stat-label">Total Paid</span><span class="stat-icon green"><i class="fas fa-check-circle"></i></span></div>
                <div class="stat-value">${formatCurrency(totalPaid)}</div>
                <div class="stat-change up">${bills.filter(b => b.status === 'paid').length} paid invoice(s)</div>
            </div>
            <div class="stat-card amber">
                <div class="stat-header"><span class="stat-label">Total Billed</span><span class="stat-icon amber"><i class="fas fa-receipt"></i></span></div>
                <div class="stat-value">${formatCurrency(bills.reduce((s, b) => s + (Number(b.amount) || 0), 0))}</div>
                <div class="stat-change">${bills.length} total invoice(s)</div>
            </div>
            <div class="stat-card red">
                <div class="stat-header"><span class="stat-label">Overdue</span><span class="stat-icon red"><i class="fas fa-exclamation-circle"></i></span></div>
                <div class="stat-value">${bills.filter(b => b.status === 'pending' && new Date(b.due_date) < new Date()).length}</div>
                <div class="stat-change down">Requires immediate attention</div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3>Invoice History</h3>
                <div class="filter-bar" style="margin:0">
                    <select onchange="filterBilling(this.value)" style="padding:8px 12px;font-size:13px">
                        <option value="all">All Status</option>
                        <option value="paid">Paid</option>
                        <option value="unpaid">Unpaid</option>
                    </select>
                </div>
            </div>
            <div class="card-body" style="padding:0">
                ${bills.length === 0 ? '<div class="empty-state" style="padding:48px"><i class="fas fa-file-invoice"></i><h3>No invoices</h3></div>' :
                `<div class="table-container">
                    <table>
                        <thead><tr>
                            <th>Invoice #</th><th>Description</th><th>Category</th>
                            <th>Amount</th><th>Paid</th>
                            <th>Due Date</th><th>Status</th><th></th>
                        </tr></thead>
                        <tbody id="billingTableBody">
                            ${bills.map(b => `
                                <tr class="bill-row" data-status="${customerBillStatus(b).toLowerCase()}">
                                    <td data-label="Invoice"><strong>${b.invoice_no}</strong></td>
                                    <td data-label="Description">${b.description || 'N/A'}${b.payment_references ? `<div style="font-size:12px;color:var(--text-muted);margin-top:3px"><i class="fas fa-hashtag"></i> Ref: <span style="font-family:monospace">${escHtml(b.payment_references)}</span></div>` : ''}</td>
                                    <td data-label="Category">${b.category || 'N/A'}</td>
                                    <td class="text-right" data-label="Amount">${formatCurrency(b.amount)}</td>
                                    <td class="text-right" data-label="Paid">${formatCurrency(b.paid)}</td>
                                    <td data-label="Due Date" style="font-size:13px">${formatDate(b.due_date)} ${new Date(b.due_date) < new Date() && b.status === 'pending' ? '<span style="color:var(--danger)"><i class="fas fa-exclamation-circle"></i></span>' : ''}</td>
                                    <td data-label="Status"><span class="status-indicator">${statusDot(customerBillStatus(b))}<span class="${statusBadge(customerBillStatus(b))}">${customerBillStatus(b)}</span></span></td>
                                    <td>
                                        <button class="btn-secondary" style="padding:4px 10px;font-size:12px" onclick="showCustomerBillDetail(${b.id})"><i class="fas fa-eye"></i></button>
                                        <button class="btn-secondary" style="padding:4px 10px;font-size:12px" onclick="downloadInvoice(${b.id})"><i class="fas fa-download"></i></button>
                                    </td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                </div>`}
            </div>
        </div>
        <div class="modal-overlay" id="billDetailModal">
            <div class="modal" style="max-width:600px">
                <div id="billDetailContent"></div>
                <div class="form-actions" style="margin-top:20px">
                    <button type="button" class="btn-secondary" onclick="closeModal('billDetailModal')">Close</button>
                    <button type="button" class="btn-primary" onclick="downloadInvoice(window._currentBillId)"><i class="fas fa-download"></i> Download PDF</button>
                </div>
            </div>
        </div>
    `;
}

async function showCustomerBillDetail(id) {
    try {
        const bill = await api('GET', '/billing/' + id);
        const c = document.getElementById('billDetailContent');
        const hasBreakdown = (bill.rent || bill.service_charge || bill.electricity || bill.water || bill.gas || bill.sinking_fund || bill.other_rev || bill.fine);
        c.innerHTML = `
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px">
                <div>
                    <h3 style="margin:0">${bill.invoice_no}</h3>
                    <p style="color:var(--text-secondary);font-size:13px;margin-top:2px">${bill.description || ''}</p>
                </div>
                <span class="${statusBadge(customerBillStatus(bill))}" style="font-size:13px;padding:6px 16px">${customerBillStatus(bill).toUpperCase()}</span>
            </div>
            <div class="grid-2" style="margin-bottom:16px;font-size:14px">
                <div><span style="color:var(--text-muted)">Unit:</span> <strong>${bill.unit_number || 'N/A'}</strong></div>
                <div><span style="color:var(--text-muted)">Period:</span> <strong>${bill.billing_period || 'N/A'}</strong></div>
                <div><span style="color:var(--text-muted)">Virtual Account:</span> <strong>${(bill.virtual_account || bill.user_virtual_account || '') && (bill.virtual_account || bill.user_virtual_account || '').indexOf('00535') !== 0 ? '00535' : ''}${bill.virtual_account || bill.user_virtual_account || 'N/A'}</strong></div>
                <div><span style="color:var(--text-muted)">Category:</span> <strong>${bill.category || 'N/A'}</strong></div>
                <div><span style="color:var(--text-muted)">Issued:</span> <strong>${formatDate(bill.issued_date)}</strong></div>
                <div><span style="color:var(--text-muted)">Due:</span> <strong>${formatDate(bill.due_date)}</strong></div>
            </div>
            ${hasBreakdown ? `
            <div style="background:var(--bg);border-radius:var(--radius-sm);padding:16px;border:1px solid var(--border);margin-bottom:16px">
                <p style="font-size:13px;font-weight:600;margin-bottom:10px;color:var(--text-secondary)">Breakdown</p>
                <div class="grid-2" style="gap:8px 24px;font-size:14px">
                    <div style="display:flex;justify-content:space-between;padding:4px 0"><span style="color:var(--text-secondary)">Rent</span><strong>${formatCurrency(bill.rent||0)}</strong></div>
                    <div style="display:flex;justify-content:space-between;padding:4px 0"><span style="color:var(--text-secondary)">Service Charge</span><strong>${formatCurrency(bill.service_charge||0)}</strong></div>
                    <div style="display:flex;justify-content:space-between;padding:4px 0"><span style="color:var(--text-secondary)">Electricity</span><strong>${formatCurrency(bill.electricity||0)}</strong></div>
                    <div style="display:flex;justify-content:space-between;padding:4px 0"><span style="color:var(--text-secondary)">Water</span><strong>${formatCurrency(bill.water||0)}</strong></div>
                    <div style="display:flex;justify-content:space-between;padding:4px 0"><span style="color:var(--text-secondary)">Gas</span><strong>${formatCurrency(bill.gas||0)}</strong></div>
                    <div style="display:flex;justify-content:space-between;padding:4px 0"><span style="color:var(--text-secondary)">Sinking Fund</span><strong>${formatCurrency(bill.sinking_fund||0)}</strong></div>
                    <div style="display:flex;justify-content:space-between;padding:4px 0"><span style="color:var(--text-secondary)">Other Revenue</span><strong>${formatCurrency(bill.other_rev||0)}</strong></div>
                    <div style="display:flex;justify-content:space-between;padding:4px 0"><span style="color:var(--text-secondary)">Fine</span><strong>${formatCurrency(bill.fine||0)}</strong></div>
                </div>
                <div style="border-top:2px solid var(--border);margin-top:8px;padding-top:10px;display:flex;justify-content:space-between">
                    <span style="font-weight:700">Total</span>
                    <span style="font-size:20px;font-weight:800;color:var(--primary)">${formatCurrency(bill.amount)}</span>
                </div>
            </div>` : `
            <div style="background:var(--bg);border-radius:var(--radius-sm);padding:16px;border:1px solid var(--border);margin-bottom:16px;display:flex;justify-content:space-between;align-items:center">
                <span style="font-weight:600">Total Amount</span>
                <span style="font-size:20px;font-weight:800;color:var(--primary)">${formatCurrency(bill.amount)}</span>
            </div>`}
            <div style="display:flex;justify-content:space-between;padding:12px 0;border-top:1px solid var(--border);font-size:14px">
                <span>Paid</span>
                <strong style="color:var(--secondary)">${formatCurrency(bill.paid)}</strong>
            </div>
            <div style="display:flex;justify-content:space-between;padding:12px 0;border-top:1px solid var(--border);font-size:14px">
                <span>Balance</span>
                <strong style="color:${bill.amount - bill.paid > 0 ? 'var(--danger)' : 'var(--secondary)'}">${formatCurrency(bill.amount - bill.paid)}</strong>
            </div>
        `;
        document.getElementById('billDetailModal').classList.add('open');
        window._currentBillId = id;
    } catch (e) {
        showToast(e.message, 'error');
    }
}

/* ===== Invoice PDF generation ===== */
function pdfEscape(s) {
    return String(s ?? '').replace(/\\/g, '\\\\').replace(/\(/g, '\\(').replace(/\)/g, '\\)');
}

function pdfCp1252(s) {
    const table = {
        0x20AC:0x80, 0x201A:0x82, 0x0192:0x83, 0x201E:0x84, 0x2026:0x85, 0x2020:0x86, 0x2021:0x87,
        0x02C6:0x88, 0x2030:0x89, 0x0160:0x8A, 0x2039:0x8B, 0x0152:0x8C, 0x017D:0x8E, 0x2018:0x91,
        0x2019:0x92, 0x201C:0x93, 0x201D:0x94, 0x2022:0x95, 0x2013:0x96, 0x2014:0x97, 0x02DC:0x98,
        0x2122:0x99, 0x0161:0x9A, 0x203A:0x9B, 0x0153:0x9C, 0x017E:0x9E, 0x0178:0x9F
    };
    const bytes = [];
    for (const ch of String(s ?? '')) {
        const cp = ch.codePointAt(0);
        if (cp <= 0x7F) bytes.push(cp);
        else if (cp >= 0xA0 && cp <= 0xFF) bytes.push(cp);
        else if (table[cp] !== undefined) bytes.push(table[cp]);
        else bytes.push(0x3F);
    }
    return bytes.map(b => b.toString(16).padStart(2, '0')).join('');
}

function pdfTextWidth(s, size, bold) {
    return String(s ?? '').length * size * (bold ? 0.55 : 0.5);
}

async function buildInvoicePdf(bill) {
    const W = 595, H = 842, ML = 40, MR = 555;
    const parts = [];
    const logo = await loadInvoiceLogo();
    const text = (str, size, bold, x, y) => {
        const hex = pdfCp1252(str);
        parts.push(`BT /${bold ? 'F2' : 'F1'} ${size} Tf ${x} ${y} Td <${hex}> Tj ET`);
    };
    const line = (x1, y1, x2, y2, w) => {
        parts.push(`${w ? w + ' w ' : ''}${x1} ${y1} m ${x2} ${y2} l S`);
    };
    const amountRow = (label, amt, y, bold) => {
        text(label, 11, bold, ML, y);
        const s = formatCurrency(amt);
        text(s, 11, bold, MR - pdfTextWidth(s, 11, bold), y);
    };

    text('Mangga Dua Square', 20, true, ML, 790);
    text('Customer Billing Invoice', 11, false, ML, 773);
    line(ML, 762, MR, 762, 1.5);

    const meta = [
        ['Invoice No.', bill.invoice_no || '-'],
        ['Billing Period', bill.billing_period || '-'],
        ['Unit Number', bill.unit_number || '-'],
        ['Virtual Account', bill.virtual_account || bill.user_virtual_account || '-'],
        ['Category', bill.category || '-'],
        ['Issued Date', bill.issued_date ? formatDate(bill.issued_date) : '-'],
        ['Due Date', bill.due_date ? formatDate(bill.due_date) : '-']
    ];
    let y = 740;
    meta.forEach(([k, v]) => {
        text(k + ':', 10, true, ML, y);
        text(pdfEscape(v), 10, false, ML + 95, y);
        y -= 18;
    });

    const items = [
        ['Rent', bill.rent], ['Service Charge', bill.service_charge], ['Electricity', bill.electricity],
        ['Water', bill.water], ['Gas', bill.gas], ['Sinking Fund', bill.sinking_fund],
        ['Other Revenue', bill.other_rev], ['Fine', bill.fine]
    ];

    if (items.length) {
        y -= 8;
        line(ML, y + 10, MR, y + 10);
        text('BREAKDOWN', 10, true, ML, y);
        y -= 18;
        items.forEach(([label, amt]) => {
            amountRow(label, Number(amt) || 0, y, false);
            y -= 18;
        });
        line(ML, y + 10, MR, y + 10);
    }

    y -= 8;
    amountRow('TOTAL', bill.amount || 0, y, true);
    // text(bill.status ? bill.status.toUpperCase() : '', 9, false, MR - pdfTextWidth(bill.status || '', 9, false), y + 4);
    y -= 24;
    amountRow('Paid', bill.paid || 0, y, false);
    y -= 18;
    amountRow('Balance', (bill.amount || 0) - (bill.paid || 0), y, false);

    if (bill.description) {
        y -= 28;
        text('Notes:', 10, true, ML, y);
        text(pdfEscape(bill.description), 10, false, ML, y - 16);
    }

    text('Thank you for your business.', 10, false, ML, 60);

    const logoH = 40;
    const logoW = logo ? logoH * (logo.w / logo.h) : 0;
    const logoCmd = logo ? `q ${logoW.toFixed(2)} 0 0 ${logoH} ${(MR - logoW).toFixed(2)} 772 cm /Img1 Do Q` : '';
    const stream = (logoCmd ? logoCmd + '\n' : '') + parts.join('\n');
    const resources = '<< /Font << /F1 4 0 R /F2 5 0 R >>' + (logo ? ' /XObject << /Img1 7 0 R >>' : '') + ' >>';
    const objs = [
        '<< /Type /Catalog /Pages 2 0 R >>',
        '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' + W + ' ' + H + '] /Resources ' + resources + ' /Contents 6 0 R >>',
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
        '<< /Length ' + stream.length + ' >>\nstream\n' + stream + '\nendstream'
    ];
    const enc = new TextEncoder();
    const chunks = [];
    const offsets = [];
    let byteOffset = 0;
    chunks.push(enc.encode('%PDF-1.4\n'));
    byteOffset = chunks[0].length;
    for (let i = 0; i < objs.length; i++) {
        offsets.push(byteOffset);
        const b = enc.encode(`${i + 1} 0 obj\n${objs[i]}\nendobj\n`);
        chunks.push(b);
        byteOffset += b.length;
    }
    if (logo) {
        offsets.push(byteOffset);
        const headB = enc.encode('7 0 obj\n<< /Type /XObject /Subtype /Image /Width ' + logo.w + ' /Height ' + logo.h + ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /FlateDecode /Length ' + logo.compressed.length + ' >>\nstream\n');
        chunks.push(headB);
        byteOffset += headB.length;
        chunks.push(logo.compressed);
        byteOffset += logo.compressed.length;
        const tailB = enc.encode('\nendstream\nendobj\n');
        chunks.push(tailB);
        byteOffset += tailB.length;
    }
    const objCount = objs.length + (logo ? 1 : 0);
    const xrefStart = byteOffset;
    chunks.push(enc.encode('xref\n0 ' + (objCount + 1) + '\n0000000000 65535 f \n'));
    offsets.forEach(o => { chunks.push(enc.encode(String(o).padStart(10, '0') + ' 00000 n \n')); });
    chunks.push(enc.encode(`trailer\n<< /Size ${objCount + 1} /Root 1 0 R >>\nstartxref\n${xrefStart}\n%%EOF`));
    return new Blob(chunks, { type: 'application/pdf' });
}

async function loadInvoiceLogo() {
    try {
        const res = await fetch(BASE_PATH + '/assets/img/m2s.png', { credentials: 'same-origin' });
        if (!res.ok) return null;
        const blob = await res.blob();
        const url = URL.createObjectURL(blob);
        const img = await new Promise((resolve, reject) => {
            const im = new Image();
            im.onload = () => resolve(im);
            im.onerror = reject;
            im.src = url;
        });
        const targetH = 120;
        const w = Math.max(1, Math.round(img.width * (targetH / img.height)));
        const h = targetH;
        const canvas = document.createElement('canvas');
        canvas.width = w;
        canvas.height = h;
        const ctx = canvas.getContext('2d', { willReadFrequently: true });
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, w, h);
        ctx.drawImage(img, 0, 0, w, h);
        const rgba = ctx.getImageData(0, 0, w, h).data;
        const rgb = new Uint8Array(w * h * 3);
        for (let i = 0, j = 0; i < rgba.length; i += 4, j += 3) {
            rgb[j] = rgba[i]; rgb[j + 1] = rgba[i + 1]; rgb[j + 2] = rgba[i + 2];
        }
        URL.revokeObjectURL(url);
        if (typeof CompressionStream !== 'function') return null;
        const cs = new CompressionStream('deflate');
        const writer = cs.writable.getWriter();
        writer.write(rgb);
        writer.close();
        const compressed = new Uint8Array(await new Response(cs.readable).arrayBuffer());
        return { w, h, compressed };
    } catch (e) {
        console.warn('Invoice logo not embedded:', e && e.message);
        return null;
    }
}

async function downloadInvoice(id, isAdmin) {
    if (!id) return;
    try {
        const bill = await api('GET', (isAdmin ? '/admin/billing/' : '/billing/') + id);
        const blob = await buildInvoicePdf(bill);
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'invoice-' + (bill.invoice_no || 'invoice') + '.pdf';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        setTimeout(() => URL.revokeObjectURL(url), 2000);
    } catch (e) {
        showToast(e.message, 'error');
    }
}

function filterBilling(val) {
    document.querySelectorAll('.bill-row').forEach(r => {
        r.style.display = (val === 'all' || r.dataset.status === val) ? '' : 'none';
    });
}

/* ===== Payments ===== */
async function renderPayments(area, titleEl, subtitleEl) {
    titleEl.textContent = 'Payments';
    subtitleEl.textContent = 'Payment history and transactions';
    const payments = await api('GET', '/payments');

    const totalPaid = payments.reduce((s, p) => s + (Number(p.amount) || 0), 0);

    area.innerHTML = `
        <div class="stats-grid">
            <div class="stat-card green">
                <div class="stat-header"><span class="stat-label">Total Payments</span><span class="stat-icon green"><i class="fas fa-credit-card"></i></span></div>
                <div class="stat-value">${payments.length}</div>
                <div class="stat-change up">All time transactions</div>
            </div>
            <div class="stat-card blue">
                <div class="stat-header"><span class="stat-label">Total Amount Paid</span><span class="stat-icon blue"><i class="fas fa-money-bill-wave"></i></span></div>
                <div class="stat-value">${formatCurrency(totalPaid)}</div>
                <div class="stat-change up">Lifetime payments</div>
            </div>
            <div class="stat-card amber">
                <div class="stat-header"><span class="stat-label">Payment Methods</span><span class="stat-icon amber"><i class="fas fa-wallet"></i></span></div>
                <div class="stat-value">${new Set(payments.map(p => p.method)).size}</div>
                <div class="stat-change">Methods used</div>
            </div>
            <div class="stat-card purple">
                <div class="stat-header"><span class="stat-label">Last Payment</span><span class="stat-icon purple"><i class="fas fa-clock"></i></span></div>
                <div class="stat-value" style="font-size:20px">${payments.length > 0 ? formatDate(payments[0].paid_at) : 'N/A'}</div>
                <div class="stat-change">${payments.length > 0 ? formatCurrency(payments[0].amount) : ''}</div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3>Payment History</h3>
            </div>
            <div class="card-body" style="padding:0">
                ${payments.length === 0 ? '<div class="empty-state" style="padding:48px"><i class="fas fa-credit-card"></i><h3>No payments recorded</h3><p>Your payment history will appear here.</p></div>' :
                `<div class="table-container">
                    <table>
                        <thead><tr><th>Reference</th><th>Invoice</th><th>Description</th><th>Amount</th><th>Method</th><th>Date</th></tr></thead>
                        <tbody>
                            ${payments.map(p => `
                                <tr>
                                    <td data-label="Reference"><span style="font-family:monospace;font-size:13px">${p.reference || 'N/A'}</span></td>
                                    <td data-label="Invoice">${p.invoice_no || 'N/A'}</td>
                                    <td data-label="Description">${p.description || 'N/A'}</td>
                                    <td data-label="Amount"><strong>${formatCurrency(p.amount)}</strong></td>
                                    <td data-label="Method"><span class="badge badge-blue">${p.method || 'N/A'}</span></td>
                                    <td data-label="Date" style="font-size:13px">${formatDate(p.paid_at)}</td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                </div>`}
            </div>
        </div>
    `;
}

/* ===== News ===== */
async function renderNews(area, titleEl, subtitleEl) {
    titleEl.textContent = 'News & Announcements';
    subtitleEl.textContent = 'Stay updated with the latest mall news and events';
    const data = await api('GET', '/news' + window.location.search);

    area.innerHTML = `
        <div class="filter-bar">
            <select onchange="filterNews('category', this.value)">
                <option value="">All Categories</option>
                <option value="announcement">Announcements</option>
                <option value="promotion">Promotions</option>
                <option value="event">Events</option>
                <option value="maintenance">Maintenance</option>
            </select>
            <input type="text" placeholder="Search news..." oninput="filterNews('search', this.value)">
        </div>

        ${data.featured.length > 0 ? `
        <div class="featured-carousel" id="featuredCarousel">
            ${data.featured.map((n, i) => `
                <div class="featured-slide ${i === 0 ? 'active' : ''}" ${n.image && !isInstagramUrl(n.image) ? `style="background-image:url('${resolveMediaUrl(n.image)}')"` : 'style="background:linear-gradient(135deg,var(--primary) 0%,var(--primary-dark) 100%)"'} onclick="openPublicNews(${n.id})">
                    <div class="featured-overlay">
                        <span class="news-category-badge ${n.category}">${n.category}</span>
                        <h2>${n.title}</h2>
                        <p>${(n.content||'').slice(0, 150)}${(n.content||'').length > 150 ? '...' : ''}</p>
                    </div>
                </div>
            `).join('')}
            <div class="carousel-dots">
                ${data.featured.map((_, i) => `<span class="carousel-dot ${i === 0 ? 'active' : ''}" onclick="goToSlide(${i})"></span>`).join('')}
            </div>
        </div>` : ''}

        <div class="news-grid" id="newsGrid">
            ${data.news.map(n => `
                <div class="news-card news-item" data-category="${n.category}" onclick="openPublicNews(${n.id})" style="cursor:pointer">
                    ${n.image && !isInstagramUrl(n.image) ? `<div class="news-image" style="background-image:url('${resolveMediaUrl(n.image)}')">
                        <span class="news-category-badge ${n.category}">${n.category}</span>
                    </div>` : `<div class="news-image" style="background:linear-gradient(135deg,var(--primary-light),var(--secondary-light));display:flex;align-items:center;justify-content:center">
                        <i class="fab fa-instagram" style="font-size:48px;color:var(--primary);opacity:0.5"></i>
                        <span class="news-category-badge ${n.category}" style="position:absolute;top:12px;left:12px">${n.category}</span>
                    </div>`}
                    <div class="news-body">
                        <div class="news-meta">
                            <span><i class="far fa-calendar"></i> ${formatDate(n.published_at)}</span>
                            <span><i class="far fa-clock"></i> ${timeAgo(n.published_at)}</span>
                        </div>
                        <h3>${n.title}</h3>
                        <p>${n.content.slice(0, 150)}${n.content.length > 150 ? '...' : ''}</p>
                    </div>
                </div>
            `).join('')}
        </div>
        ${data.news.length === 0 ? '<div class="empty-state"><i class="fas fa-newspaper"></i><h3>No news found</h3><p>Check back later for updates.</p></div>' : ''}
    `;

    // Start carousel
    if (data.featured.length > 1) {
        currentSlide = 0;
        carouselInterval = setInterval(() => {
            const slides = document.querySelectorAll('.featured-slide');
            const dots = document.querySelectorAll('.carousel-dot');
            if (!slides.length) return;
            slides.forEach(s => s.classList.remove('active'));
            dots.forEach(d => d.classList.remove('active'));
            currentSlide = (currentSlide + 1) % slides.length;
            slides[currentSlide].classList.add('active');
            dots[currentSlide].classList.add('active');
        }, 8000);
    }
    window._publicNews = data.news;
}

function goToSlide(index) {
    currentSlide = index;
    document.querySelectorAll('.featured-slide').forEach((s, i) => s.classList.toggle('active', i === index));
    document.querySelectorAll('.carousel-dot').forEach((d, i) => d.classList.toggle('active', i === index));
}

function filterNews(type, value) {
    if (type === 'category') {
        document.querySelectorAll('.news-item').forEach(item => {
            item.style.display = (!value || item.dataset.category === value) ? '' : 'none';
        });
    }
}

/* ===== Documents ===== */
async function renderDocuments(area, titleEl, subtitleEl) {
    titleEl.textContent = 'Documents';
    subtitleEl.textContent = 'Access lease agreements, forms, and important notices';
    const docs = await api('GET', '/documents');

    area.innerHTML = `
        <div class="filter-bar">
            <select onchange="filterDocs(this.value)">
                <option value="">All Categories</option>
                <option value="lease">Lease Agreements</option>
                <option value="guidelines">Guidelines</option>
                <option value="circular">Circular Letters</option>
                <option value="form">Forms</option>
            </select>
        </div>

        <div class="card">
            <div class="card-header"><h3>Available Documents</h3></div>
            <div class="card-body" style="padding:16px">
                <div class="doc-list" id="docList">
                    ${docs.map(d => `
                        <div class="doc-item" data-category="${d.category}">
                            <div class="doc-icon ${d.type.toLowerCase()}">
                                <i class="fas fa-file-${d.type === 'PDF' ? 'pdf' : d.type === 'DOCX' ? 'word' : 'excel'}"></i>
                            </div>
                            <div class="doc-info">
                                <h4>${d.title}</h4>
                                <p>${d.description || ''} · ${d.file_size || 'N/A'} · ${d.category}</p>
                            </div>
                            <span class="doc-action" onclick="${d.file_url ? 'window.open(\'' + d.file_url + '\',\'_blank\')' : 'alert(\'No file attached\')'}"><i class="fas fa-download"></i></span>
                        </div>
                    `).join('')}
                </div>
                ${docs.length === 0 ? '<div class="empty-state"><i class="fas fa-folder-open"></i><h3>No documents</h3></div>' : ''}
            </div>
        </div>
    `;
}

function filterDocs(val) {
    document.querySelectorAll('.doc-item').forEach(item => {
        item.style.display = (!val || item.dataset.category === val) ? '' : 'none';
    });
}

/* ===== Support Center ===== */
async function renderSupport(area, titleEl, subtitleEl) {
    titleEl.textContent = 'Support Center';
    subtitleEl.textContent = 'Get help, submit inquiries, and track requests';
    const tickets = await api('GET', '/tickets');
    window._myTickets = tickets;
    const faqs = await api('GET', '/faq');

    area.innerHTML = `
        <div class="two-col" style="margin-bottom:24px">
            <div class="card">
                <div class="card-header"><h3><i class="fas fa-headset" style="color:var(--primary)"></i> Submit a Request</h3></div>
                <div class="card-body">
                    <form onsubmit="return submitTicket(event)">
                        <div class="form-group">
                            <label>Category</label>
                            <select id="ticketCategory">
                                <option value="inquiry">General Inquiry</option>
                                <option value="maintenance">Maintenance Request</option>
                                <option value="complaint">Complaint</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Priority</label>
                            <select id="ticketPriority">
                                <option value="low">Low</option>
                                <option value="normal" selected>Normal</option>
                                <option value="high">High</option>
                                <option value="urgent">Urgent</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Subject</label>
                            <input type="text" id="ticketSubject" placeholder="Brief description of your concern" required>
                        </div>
                        <div class="form-group">
                            <label>Message</label>
                            <textarea id="ticketMessage" placeholder="Provide details about your request..." required></textarea>
                        </div>
                        <button type="submit" class="btn-primary btn-block">
                            <i class="fas fa-paper-plane"></i> Submit Request
                        </button>
                    </form>
                    <div id="ticketSuccess" style="display:none;margin-top:16px;padding:12px;background:var(--secondary-light);color:var(--secondary);border-radius:var(--radius-sm);text-align:center">
                        <i class="fas fa-check-circle"></i> Ticket submitted successfully!
                    </div>
                </div>
            </div>
            <div class="card">
                <div class="card-header"><h3><i class="fas fa-ticket-alt" style="color:var(--accent)"></i> My Tickets (${tickets.length})</h3></div>
                <div class="card-body" style="padding:0">
                    ${tickets.length === 0 ? '<div class="empty-state" style="padding:32px"><i class="fas fa-ticket-alt"></i><h3>No tickets</h3><p>Your support tickets will appear here.</p></div>' :
                    `<div class="ticket-list" style="padding:12px">
                        ${tickets.map(t => `
                            <div class="ticket-item" style="cursor:pointer" onclick="openCustomerTicket(${t.id})">
                                <div class="ticket-icon ${t.category}">
                                    <i class="fas fa-${t.category === 'inquiry' ? 'question' : t.category === 'maintenance' ? 'tools' : 'exclamation'}"></i>
                                </div>
                                <div class="ticket-info">
                                    <h4>${escHtml(t.subject)}${t.unread > 0 ? `<span class="msg-badge" title="${t.unread} unread message${t.unread>1?'s':''}">${t.unread}</span>` : ''}</h4>
                                    <p>${escHtml((t.message || '').slice(0, 80))}... · ${formatDate(t.created_at)}</p>
                                </div>
                                <span class="${statusBadge(t.status)}">${t.status.replace('_', ' ')}</span>
                            </div>
                        `).join('')}
                    </div>`}
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h3><i class="fas fa-phone-alt" style="color:var(--primary)"></i> Contact Us</h3></div>
            <div class="card-body">
                <p style="margin:0 0 8px"><strong>Mall Mangga Dua Square</strong></p>
                <p style="margin:0 0 6px"><i class="fas fa-map-marker-alt" style="color:var(--text-muted);width:18px"></i> Jalan Gunung Sahari Raya No.1</p>
                <p style="margin:0 0 6px"><i class="fas fa-phone" style="color:var(--text-muted);width:18px"></i> Telpon : (021) 6231 3000</p>
                <p style="margin:0"><i class="fas fa-mobile-alt" style="color:var(--text-muted);width:18px"></i> Arcoll : 085710080090</p>
            </div>
        </div>

        <div class="card" style="margin-top:24px">
            <div class="card-header"><h3><i class="fas fa-question-circle" style="color:var(--info)"></i> Frequently Asked Questions</h3></div>
            <div class="card-body">
                <div class="faq-list">
                    ${faqs.map((f, i) => `
                        <div class="faq-item" onclick="toggleFaq(this)">
                            <div class="faq-question">
                                <span>${f.q}</span>
                                <i class="fas fa-chevron-down"></i>
                            </div>
                            <div class="faq-answer">${f.a}</div>
                        </div>
                    `).join('')}
                </div>
            </div>
        </div>

        <div class="modal-overlay" id="custChatModal">
            <div class="modal" style="max-width:640px;display:flex;flex-direction:column;max-height:90vh">
                <div style="display:flex;justify-content:space-between;align-items:center;padding-bottom:12px;border-bottom:1px solid var(--border);margin-bottom:12px">
                    <div>
                        <h3 style="margin:0" id="custChatTitle">Ticket</h3>
                        <p style="color:var(--text-secondary);font-size:12px;margin:2px 0 0" id="custChatMeta"></p>
                    </div>
                    <span class="status-indicator" id="custChatStatus"></span>
                </div>
                <div class="chat-window" id="custChatMessages"></div>
                <div id="custChatLock" style="display:none;padding:12px 14px;background:var(--danger-light);color:var(--danger);border-radius:var(--radius-sm);font-size:13px;margin-top:8px"><i class="fas fa-lock"></i> This ticket is <span id="custChatLockStatus"></span>. You can no longer send messages.</div>
                <form class="chat-input" onsubmit="return sendCustomerMessage(event)">
                    <input type="text" id="custChatText" placeholder="Type a reply..." autocomplete="off">
                    <button type="submit" class="btn-primary" style="padding:9px 16px"><i class="fas fa-paper-plane"></i></button>
                </form>
            </div>
        </div>
    `;
}

function toggleFaq(el) {
    el.classList.toggle('open');
}

async function submitTicket(e) {
    e.preventDefault();
    try {
        await api('POST', '/tickets', {
            category: document.getElementById('ticketCategory').value,
            priority: document.getElementById('ticketPriority').value,
            subject: document.getElementById('ticketSubject').value,
            message: document.getElementById('ticketMessage').value
        });
        document.getElementById('ticketSuccess').style.display = 'block';
        document.getElementById('ticketSubject').value = '';
        document.getElementById('ticketMessage').value = '';
        showToast('Ticket submitted successfully!');
        setTimeout(() => {
            document.getElementById('ticketSuccess').style.display = 'none';
            navigate('support');
        }, 1500);
    } catch (e) {
        showToast(e.message, 'error');
    }
    return false;
}

async function openCustomerTicket(id) {
    const t = (window._myTickets || []).find(x => x.id === id);
    if (!t) return;
    window._custChatTicket = t;
    document.getElementById('custChatTitle').textContent = t.subject;
    document.getElementById('custChatMeta').textContent = '#' + t.id + ' · ' + t.category + ' · ' + formatDate(t.created_at);
    document.getElementById('custChatStatus').className = 'status-indicator';
    document.getElementById('custChatStatus').innerHTML = `${statusDot(t.status)}<span class="${statusBadge(t.status)}">${t.status.replace('_',' ')}</span>`;
    document.getElementById('custChatMessages').innerHTML = '<div class="empty-state" style="padding:24px"><div class="spinner"></div></div>';
    document.getElementById('custChatModal').classList.add('open');
    await loadCustomerChat(id);
}

async function loadCustomerChat(id) {
    const t = window._custChatTicket;
    if (!t) return;
    const msgs = await api('GET', '/tickets/' + id + '/messages');
    const w = document.getElementById('custChatMessages');
    let html = msgs.map(m => renderChatBubble(m, m.sender_role === 'customer')).join('');
    if (!msgs.length && t.message) html = renderChatBubble({ sender_role: 'customer', message: t.message, created_at: t.created_at || '' }, true);
    w.innerHTML = html || '<div class="empty-state" style="padding:24px">No messages yet</div>';
    w.scrollTop = w.scrollHeight;
    document.getElementById('custChatText').value = '';
    setCustomerChatLock(t.status);
    if (t.unread > 0) {
        t.unread = 0;
        api('POST', '/tickets/' + id + '/read').catch(() => {});
        refreshSupportBadge();
        if (window._myTickets) { const rt = window._myTickets.find(x => x.id === id); if (rt) rt.unread = 0; }
    }
}

function setCustomerChatLock(status) {
    const locked = status === 'resolved' || status === 'closed';
    const input = document.getElementById('custChatText');
    const btn = input && input.closest('.chat-input') ? input.closest('.chat-input').querySelector('button') : null;
    const lock = document.getElementById('custChatLock');
    if (input) input.disabled = locked;
    if (btn) btn.disabled = locked;
    if (lock) {
        if (locked) {
            document.getElementById('custChatLockStatus').textContent = status;
            lock.style.display = 'block';
        } else {
            lock.style.display = 'none';
        }
    }
}

function sendCustomerMessage(e) {
    e.preventDefault();
    const t = window._custChatTicket;
    if (!t) return false;
    const locked = t.status === 'resolved' || t.status === 'closed';
    if (locked) { setCustomerChatLock(t.status); return false; }
    const input = document.getElementById('custChatText');
    const text = (input.value || '').trim();
    if (!text) return false;
    const w = document.getElementById('custChatMessages');
    w.insertAdjacentHTML('beforeend', renderChatBubble({ sender_role: 'customer', message: text, created_at: new Date().toISOString() }, true));
    w.scrollTop = w.scrollHeight;
    input.value = '';
    api('POST', '/tickets/' + t.id + '/messages', { message: text }).catch(err => showToast(err.message, 'error'));
    return false;
}

function renderChatBubble(m, mine) {
    const when = m.created_at ? formatDate(m.created_at) : '';
    const time = m.created_at ? (m.created_at.includes(' ') ? m.created_at.split(' ')[1] : '') : '';
    const stamp = (when ? (when + (time ? ' ' + time : '')) : '').trim();
    return `<div class="chat-msg ${mine ? 'mine' : 'theirs'}">
        <div class="bubble">${escHtml(m.message)}</div>
        <div class="chat-time">${mine ? 'You' : 'Support'}${stamp ? ' · ' + stamp : ''}</div>
    </div>`;
}


async function renderProfile(area, titleEl, subtitleEl) {
    titleEl.textContent = 'Profile';
    subtitleEl.textContent = 'Manage your account and preferences';
    const user = await api('GET', '/profile');

    area.innerHTML = `
        <div class="profile-header">
            <div class="profile-avatar">${escHtml(user.name.split(' ').map(w => w[0]).join('').toUpperCase().slice(0, 2))}</div>
            <div class="profile-info">
                <h2>${escHtml(user.name)}</h2>
                <p>${escHtml(user.email)} · ${escHtml(user.role.charAt(0).toUpperCase() + user.role.slice(1))} · ${escHtml(user.company || 'N/A')}</p>
            </div>
        </div>

        <div class="two-col">
            <div class="card">
                <div class="card-header"><h3><i class="fas fa-user-edit"></i> Personal Information</h3></div>
                <div class="card-body">
                    <form onsubmit="return updateProfile(event)">
                        <div class="form-row">
                            <div class="form-group">
                                <label>Full Name</label>
                                <input type="text" id="profileName" value="${escHtml(user.name)}" required>
                            </div>
                            <div class="form-group">
                                <label>Email</label>
                                <input type="email" id="profileEmail" value="${escHtml(user.email)}" required>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Company</label>
                                <input type="text" id="profileCompany" value="${escHtml(user.company || '')}">
                            </div>
                            <div class="form-group">
                                <label>Phone</label>
                                <input type="text" id="profilePhone" value="${user.phone || ''}">
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Extra Phones</label>
                                <div id="extraPhonesContainer">
                                    ${(JSON.parse(user.extra_phones || '[]')).map((p, i) => `
                                        <div class="extra-contact-row">
                                            <input type="text" class="extra-phone" value="${escHtml(p)}" placeholder="Phone ${i+2}">
                                            <button type="button" class="btn-icon" onclick="removeExtraContact(this,'extraPhonesContainer')"><i class="fas fa-times"></i></button>
                                        </div>
                                    `).join('')}
                                </div>
                                <button type="button" class="btn-link" onclick="addExtraContact('extraPhonesContainer','extra-phone','Phone')"><i class="fas fa-plus"></i> Add phone</button>
                            </div>
                            <div class="form-group">
                                <label>Extra Emails</label>
                                <div id="extraEmailsContainer">
                                    ${(JSON.parse(user.extra_emails || '[]')).map((e, i) => `
                                        <div class="extra-contact-row">
                                            <input type="email" class="extra-email" value="${escHtml(e)}" placeholder="Email ${i+2}">
                                            <button type="button" class="btn-icon" onclick="removeExtraContact(this,'extraEmailsContainer')"><i class="fas fa-times"></i></button>
                                        </div>
                                    `).join('')}
                                </div>
                                <button type="button" class="btn-link" onclick="addExtraContact('extraEmailsContainer','extra-email','Email')"><i class="fas fa-plus"></i> Add email</button>
                            </div>
                        </div>
                        <button type="submit" class="btn-primary" style="margin-top:8px">
                            <i class="fas fa-save"></i> Save Changes
                        </button>
                    </form>
                </div>
            </div>
            <div>
                <div class="card" style="margin-bottom:24px">
                    <div class="card-header"><h3><i class="fas fa-lock"></i> Change Password</h3></div>
                    <div class="card-body">
                        <form onsubmit="return changePassword(event)">
                            <div class="form-group">
                                <label>Current Password</label>
                                <input type="password" id="currentPassword" placeholder="Enter current password" required>
                            </div>
                            <div class="form-group">
                                <label>New Password</label>
                                <input type="password" id="newPassword" placeholder="Enter new password" required minlength="6">
                            </div>
                            <div class="form-group">
                                <label>Confirm New Password</label>
                                <input type="password" id="confirmPassword" placeholder="Confirm new password" required>
                            </div>
                            <button type="submit" class="btn-secondary">
                                <i class="fas fa-key"></i> Update Password
                            </button>
                        </form>
                        <div id="passwordMsg" style="margin-top:12px;font-size:13px"></div>
                    </div>
                </div>
                <div class="card">
                    <div class="card-header"><h3><i class="fas fa-bell"></i> Notification Preferences</h3></div>
                    <div class="card-body">
                        <div class="notif-setting">
                            <div>
                                <h4>Email Notifications</h4>
                                <p>Receive billing reminders and announcements via email</p>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" ${user.notification_email ? 'checked' : ''} onchange="updateNotif('email', this.checked)">
                                <span class="toggle-slider"></span>
                            </label>
                        </div>
                        <div class="notif-setting">
                            <div>
                                <h4>SMS Notifications</h4>
                                <p>Receive important alerts via SMS</p>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" ${user.notification_sms ? 'checked' : ''} onchange="updateNotif('sms', this.checked)">
                                <span class="toggle-slider"></span>
                            </label>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    `;
}

function addExtraContact(containerId, cls, label) {
    const c = document.getElementById(containerId);
    const row = document.createElement('div');
    row.className = 'extra-contact-row';
    const idx = c.children.length + 2;
    row.innerHTML = `<input type="${cls === 'extra-email' ? 'email' : 'text'}" class="${cls}" placeholder="${label} ${idx}"><button type="button" class="btn-icon" onclick="removeExtraContact(this,'${containerId}')"><i class="fas fa-times"></i></button>`;
    c.appendChild(row);
}

function removeExtraContact(btn, containerId) {
    btn.parentElement.remove();
}

async function updateProfile(e) {
    e.preventDefault();
    try {
        const extraPhones = Array.from(document.querySelectorAll('.extra-phone')).map(i => i.value).filter(v => v.trim());
        const extraEmails = Array.from(document.querySelectorAll('.extra-email')).map(i => i.value).filter(v => v.trim());
        const res = await api('PUT', '/profile', {
            name: document.getElementById('profileName').value,
            email: document.getElementById('profileEmail').value,
            company: document.getElementById('profileCompany').value,
            phone: document.getElementById('profilePhone').value,
            extra_phones: extraPhones,
            extra_emails: extraEmails
        });
        currentUser = res.user;
        const initials = currentUser.name.split(' ').map(w => w[0]).join('').toUpperCase().slice(0, 2);
        document.getElementById('userAvatar').textContent = initials;
        document.getElementById('headerAvatar').textContent = initials;
        document.getElementById('sidebarUserName').textContent = currentUser.name;
        document.getElementById('pageSubtitle').textContent = `Welcome back, ${currentUser.name}`;
        showToast('Profile updated successfully!');
    } catch (e) {
        showToast(e.message, 'error');
    }
    return false;
}

async function changePassword(e) {
    e.preventDefault();
    const msg = document.getElementById('passwordMsg');
    const newPw = document.getElementById('newPassword').value;
    const confirmPw = document.getElementById('confirmPassword').value;
    if (newPw !== confirmPw) {
        msg.innerHTML = '<span style="color:var(--danger)">Passwords do not match</span>';
        return false;
    }
    try {
        await api('PUT', '/profile/password', {
            current_password: document.getElementById('currentPassword').value,
            new_password: newPw
        });
        msg.innerHTML = '<span style="color:var(--secondary)">Password updated successfully!</span>';
        document.getElementById('currentPassword').value = '';
        document.getElementById('newPassword').value = '';
        document.getElementById('confirmPassword').value = '';
        showToast('Password changed!');
    } catch (e) {
        msg.innerHTML = `<span style="color:var(--danger)">${e.message}</span>`;
    }
    return false;
}

async function updateNotif(type, checked) {
    const data = {};
    data[type === 'email' ? 'email' : 'sms'] = checked ? 1 : 0;
    if (type === 'email') data['sms'] = currentUser.notification_sms || 0;
    else data['email'] = currentUser.notification_email || 0;
    await api('PUT', '/profile/notifications', data);
    showToast(`${type === 'email' ? 'Email' : 'SMS'} notifications ${checked ? 'enabled' : 'disabled'}`);
}

/* ===== Admin Pages ===== */

async function renderAdminDashboard(area, titleEl, subtitleEl) {
    titleEl.textContent = 'Admin Dashboard';
    subtitleEl.textContent = `Management overview, ${currentUser.name}`;
    const d = await api('GET', '/admin/dashboard');

    area.innerHTML = `
        <div style="display:flex;justify-content:flex-end;align-items:center;gap:10px;margin-bottom:12px">
            <label for="kpiPeriodFilter" style="font-size:12px;font-weight:600;color:var(--text-secondary)">Month</label>
            <select id="kpiPeriodFilter" onchange="loadKpiTotals(this.value)" style="padding:7px 12px;font-size:13px;border:1px solid var(--border);border-radius:var(--radius-sm);background:var(--bg-card);color:var(--text)">
                <option value="">All months</option>
                ${(d.kpi_months || []).map(p => `<option value="${p}">${p}</option>`).join('')}
            </select>
        </div>
        <div class="stats-row">
            <div class="stat-card purple"><div class="stat-header"><span class="stat-label">Total Billed</span><span class="stat-icon purple"><i class="fas fa-file-invoice-dollar"></i></span></div>
                <div class="stat-value" data-kpi="billed">${formatCurrency(d.total_billed_amount)}</div><div class="stat-change">All invoices</div></div>
            <div class="stat-card green"><div class="stat-header"><span class="stat-label">Total Revenue</span><span class="stat-icon green"><i class="fas fa-coins"></i></span></div>
                <div class="stat-value" data-kpi="revenue">${formatCurrency(d.total_revenue)}</div><div class="stat-change up">Collected payments</div></div>
            <div class="stat-card orange"><div class="stat-header"><span class="stat-label">Outstanding</span><span class="stat-icon orange"><i class="fas fa-hourglass-half"></i></span></div>
                <div class="stat-value" data-kpi="outstanding">${formatCurrency(d.outstanding_revenue)}</div><div class="stat-change down">Unpaid balance</div></div>
        </div>
        <div class="stats-row">
            <div class="stat-card blue"><div class="stat-header"><span class="stat-label">Total Users</span><span class="stat-icon blue"><i class="fas fa-users"></i></span></div>
                <div class="stat-value">${d.total_users}</div><div class="stat-change">Registered accounts</div></div>
            <div class="stat-card amber"><div class="stat-header"><span class="stat-label">Pending Bills</span><span class="stat-icon amber"><i class="fas fa-clock"></i></span></div>
                <div class="stat-value">${d.total_pending}</div><div class="stat-change down">/${d.total_bills} total invoices</div></div>
            <div class="stat-card red"><div class="stat-header"><span class="stat-label">Open Tickets</span><span class="stat-icon red"><i class="fas fa-headset"></i></span></div>
                <div class="stat-value">${d.open_tickets}</div><div class="stat-change down">Requires attention</div></div>
        </div>
        <div class="card" style="margin:20px 0">
            <div class="card-header"><h3>Revenue vs Outstanding</h3><span class="card-sub">Last 12 months</span></div>
            <div class="card-body" style="height:300px;position:relative">
                <canvas id="kjRevenueChart"></canvas>
            </div>
        </div>
        <div class="chart-grid">
            <div class="card">
                <div class="card-header"><h3>Bills by Status</h3><span class="card-sub">${d.total_pending} of ${d.total_bills} pending</span></div>
                <div class="card-body" style="height:260px;position:relative;display:flex;align-items:center;justify-content:center">
                    <canvas id="kjBillStatusChart"></canvas>
                </div>
            </div>
            <div class="card">
                <div class="card-header"><h3>Open Tickets</h3><span class="card-sub">Last 6 months</span></div>
                <div class="card-body" style="height:260px;position:relative">
                    <canvas id="kjTicketsChart"></canvas>
                </div>
            </div>
        </div>
        <div class="two-col" style="margin-top:20px">
            <div class="card">
                <div class="card-header"><h3>Recent Invoices</h3></div>
                <div class="card-body" style="padding:0">
                    ${d.recent_bills.length === 0 ? '<div class="empty-state"><p>No invoices</p></div>' :
                    `<div class="table-container"><table><thead><tr><th>Invoice</th><th>Tenant</th><th>Amount</th><th>Status</th></tr></thead><tbody>
                        ${d.recent_bills.map(b => `<tr><td data-label="Invoice">${escHtml(b.invoice_no)}</td><td data-label="Tenant">${escHtml(b.user_name || 'N/A')}</td><td data-label="Amount">${formatCurrency(b.amount)}</td><td data-label="Status"><span class="${statusBadge(b.status)}">${b.status}</span></td></tr>`).join('')}
                    </tbody></table></div>`}
                </div>
            </div>
            <div class="card">
                <div class="card-header"><h3>Recent Support Tickets</h3></div>
                <div class="card-body" style="padding:0">
                    ${d.recent_tickets.length === 0 ? '<div class="empty-state"><p>No tickets</p></div>' :
                    `<div class="table-container"><table><thead><tr><th>Subject</th><th>From</th><th>Status</th></tr></thead><tbody>
                        ${d.recent_tickets.map(t => `<tr><td data-label="Subject">${escHtml(t.subject.slice(0,30))}${t.subject.length>30?'...':''}</td><td data-label="From">${escHtml(t.user_name||'N/A')}</td><td data-label="Status"><span class="${statusBadge(t.status)}">${t.status.replace('_',' ')}</span></td></tr>`).join('')}
                    </tbody></table></div>`}
                </div>
            </div>
        </div>`;
    initAdminCharts(d);
}

async function loadKpiTotals(period) {
    const q = period ? `?period=${encodeURIComponent(period)}` : '';
    const d = await api('GET', '/admin/dashboard/totals' + q);
    document.querySelectorAll('[data-kpi="billed"]').forEach(el => el.textContent = formatCurrency(d.total_billed_amount));
    document.querySelectorAll('[data-kpi="revenue"]').forEach(el => el.textContent = formatCurrency(d.total_revenue));
    document.querySelectorAll('[data-kpi="outstanding"]').forEach(el => el.textContent = formatCurrency(d.outstanding_revenue));
}

/* ===== Admin KPI Charts ===== */
let adminCharts = [];

function cgMoneyK(v) {
    if (v >= 1000000000) return 'Rp' + (v / 1000000000).toFixed(1) + 'B';
    if (v >= 1000000) return 'Rp' + (v / 1000000).toFixed(1) + 'M';
    return 'Rp' + (v / 1000).toFixed(0) + 'k';
}

function adminChartTheme() {
    const css = getComputedStyle(document.documentElement);
    return {
        grid: css.getPropertyValue('--border').trim() || '#e2e8f0',
        tick: css.getPropertyValue('--text-secondary').trim() || '#64748b',
        label: css.getPropertyValue('--text').trim() || '#0f172a'
    };
}

function initAdminCharts(d) {
    adminCharts.forEach(c => { try { c.destroy(); } catch (_) { /* noop */ } });
    adminCharts = [];
    const theme = adminChartTheme();

    const paidMap = {}, outMap = {};
    const months = {};
    (d.monthly_billing || []).forEach(r => { paidMap[r.month] = Number(r.total) || 0; months[r.month] = true; });
    (d.monthly_outstanding || []).forEach(r => { outMap[r.month] = Number(r.total) || 0; months[r.month] = true; });
    const labels = Object.keys(months).sort();

    const revCtx = document.getElementById('kjRevenueChart');
    if (revCtx && typeof Chart !== 'undefined') {
        adminCharts.push(new Chart(revCtx, {
            type: 'bar',
            data: {
                labels,
                datasets: [
                    { label: 'Collected', data: labels.map(m => paidMap[m] || 0), backgroundColor: 'rgba(5,150,105,0.75)', borderRadius: 4 },
                    { label: 'Outstanding', data: labels.map(m => outMap[m] || 0), backgroundColor: 'rgba(245,158,11,0.75)', borderRadius: 4 }
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, color: theme.tick } } },
                scales: {
                    x: { grid: { display: false }, ticks: { color: theme.tick, font: { size: 10 } } },
                    y: { beginAtZero: true, grid: { color: theme.grid }, ticks: { callback: v => cgMoneyK(v), color: theme.tick, font: { size: 10 } } }
                }
            }
        }));
    }

    const stCtx = document.getElementById('kjBillStatusChart');
    if (stCtx && typeof Chart !== 'undefined') {
        const bs = d.bill_status || {};
        const total = bs.paid + bs.partial + bs.unpaid || 1;
        adminCharts.push(new Chart(stCtx, {
            type: 'doughnut',
            data: {
                labels: ['Paid', 'Partially Paid', 'Unpaid'],
                datasets: [{
                    data: [bs.paid || 0, bs.partial || 0, bs.unpaid || 0],
                    backgroundColor: ['rgba(5,150,105,0.85)', 'rgba(245,158,11,0.85)', 'rgba(239,68,68,0.85)'],
                    borderColor: theme.grid, borderWidth: 2
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                cutout: '62%',
                plugins: {
                    legend: { position: 'bottom', labels: { usePointStyle: true, color: theme.tick } },
                    tooltip: { callbacks: { label: c => ` ${c.label}: ${c.parsed} (${Math.round(c.parsed / total * 100)}%)` } }
                }
            }
        }));
    }

    const tkCtx = document.getElementById('kjTicketsChart');
    if (tkCtx && typeof Chart !== 'undefined') {
        const tm = d.tickets_monthly || [];
        adminCharts.push(new Chart(tkCtx, {
            type: 'line',
            data: {
                labels: tm.map(r => r.month),
                datasets: [{
                    label: 'Tickets',
                    data: tm.map(r => Number(r.total) || 0),
                    borderColor: 'rgba(239,68,68,0.9)',
                    backgroundColor: 'rgba(239,68,68,0.12)',
                    fill: true, tension: 0.35,
                    pointBackgroundColor: 'rgba(239,68,68,1)',
                    pointRadius: 3
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { grid: { display: false }, ticks: { color: theme.tick, font: { size: 10 } } },
                    y: { beginAtZero: true, grid: { color: theme.grid }, ticks: { precision: 0, color: theme.tick, font: { size: 10 } } }
                }
            }
        }));
    }
}

async function renderAdminBilling(area, titleEl, subtitleEl) {
    titleEl.textContent = 'Manage Billing';
    subtitleEl.textContent = 'Create and manage tenant invoices';
    if (!window._billPeriodKey) window._billPeriodKey = '';
    if (!window._billStatusKey) window._billStatusKey = '';
    if (!window._billQuery) window._billQuery = '';
    if (!window._billPage) window._billPage = 1;
    window._billPageSize = 50;
    const billsRes = await api('GET', '/admin/billing?period=' + encodeURIComponent(window._billPeriodKey || '') + '&q=' + encodeURIComponent(window._billQuery || '') + '&page=' + window._billPage + '&page_size=50' + (window._billStatusKey ? '&status=' + encodeURIComponent(window._billStatusKey) : ''));
    const bills = billsRes.bills || [];
    window._billUsers = window._billUsers || (await api('GET', '/admin/users'));
    window._allBills = bills;
    window._billTotal = billsRes.total || bills.length;
    window._billPeriods = billsRes.periods || [];

    area.innerHTML = `
        <div class="admin-toolbar" style="margin-bottom:20px;display:flex;gap:8px;flex-wrap:wrap;align-items:center">
            <div class="admin-toolbar-actions" style="display:flex;gap:6px;flex-wrap:wrap">
            <button class="btn-primary" onclick="showBillForm(null)" style="padding:6px 10px;font-size:12px">
                <i class="fas fa-plus"></i> New
            </button>
            <button class="btn-success" onclick="openUploadModal()" style="padding:6px 10px;font-size:12px">
                <i class="fas fa-upload"></i> Billing
            </button>
            <button class="btn-secondary" style="border-color:var(--primary);color:var(--primary);padding:6px 10px;font-size:12px" onclick="openVaModal()">
                <i class="fas fa-hashtag"></i> VA
            </button>
            <button class="btn-primary" style="background:var(--accent);border-color:var(--accent);padding:6px 10px;font-size:12px" onclick="openPaymentsModal()">
                <i class="fas fa-money-bill-wave"></i> Payment
            </button>
            <a href="data:text/csv;charset=utf-8,unit_number,paid_amount,reference,method,paid_at,billing_period%0A203,52500.00,PAY-001,Bank Transfer,2026-08-15,July 2026%0A105,33600.00,,Cash,2026-08-16,July 2026" download="payment_template.csv" class="btn-secondary" style="text-decoration:none;padding:6px 10px;font-size:12px">
                <i class="fas fa-download"></i> Payment Tmpl
            </a>
            <a href="data:text/csv;charset=utf-8,unit_number,invoice_number,virtual_account,billing_period,Other_Rev,Electricity,Water,Gas,Sinking_Fund,Fine,Rent,Service_Charge,total,due_date%0A203,INV-CSV-001,00535VA-203-01,July 2026,0,1500.00,500.00,0,2000.00,0,45000.00,3500.00,52500.00,2026-08-15%0A105,INV-CSV-002,00535VA-105-01,July 2026,0,1200.00,400.00,0,1500.00,0,28000.00,2500.00,33600.00,2026-08-15" download="billing_template.csv" class="btn-secondary" style="text-decoration:none;padding:6px 10px;font-size:12px">
                <i class="fas fa-download"></i> Billing Tmpl
            </a>
            </div>
            <div class="admin-toolbar-search" style="display:flex;gap:6px;flex:1;min-width:120px;max-width:360px">
                <input type="text" id="billSearch" placeholder="Search unit, name, invoice..." style="flex:1;padding:6px 10px;border:1px solid var(--border);border-radius:var(--radius-sm);font-size:13px;background:var(--bg);color:var(--text)" oninput="searchAdminBills()" onkeydown="if(event.key==='Enter')searchAdminBills(true)">
                <select id="billStatusFilter" style="padding:6px 8px;border:1px solid var(--border);border-radius:var(--radius-sm);font-size:13px;background:var(--bg);color:var(--text)" onchange="filterAdminBills()">
                    <option value="">Status</option>
                    <option value="Belum Bayar">Belum Bayar</option>
                    <option value="Kurang Bayar">Kurang Bayar</option>
                    <option value="Lebih Bayar">Lebih Bayar</option>
                    <option value="Paid">Paid</option>
                </select>
                <select id="billPeriodFilter" style="padding:6px 8px;border:1px solid var(--border);border-radius:var(--radius-sm);font-size:13px;background:var(--bg);color:var(--text)" onchange="filterAdminBills()">
                    <option value="">All Months</option>
                    ${(window._billPeriods||[]).map(p => `<option value="${escHtml(p)}"${window._billPeriodKey===p?' selected':''}>${escHtml(p)}</option>`).join('')}
                </select>
                <button class="btn-secondary" style="padding:6px 8px;font-size:12px" onclick="filterAdminBills()" title="Apply month filter"><i class="fas fa-filter"></i></button>
            </div>
        </div>
        <div class="card">
            <div class="card-header"><h3>Invoices <span id="billCount">${window._billTotal||0}</span>${window._billPeriodKey?' <span style="font-weight:400;color:var(--text-secondary)">— '+escHtml(window._billPeriodKey)+'</span>':''}</h3></div>
            <div class="card-body" style="padding:0">
                <div class="table-container">
                    <table><thead><tr>
                        <th style="width:12%;cursor:pointer" onclick="sortAdminBills('invoice_no')">Invoice # <i class="fas fa-sort" style="font-size:10px" data-sort="invoice_no"></i></th>
                        <th style="width:12%;cursor:pointer" onclick="sortAdminBills('user_name')">Tenant / VA <i class="fas fa-sort" style="font-size:10px" data-sort="user_name"></i></th>
                        <th style="cursor:pointer" onclick="sortAdminBills('unit_number')">Unit <i class="fas fa-sort" style="font-size:10px" data-sort="unit_number"></i></th>
                        <th style="cursor:pointer" onclick="sortAdminBills('amount')">Amount <i class="fas fa-sort" style="font-size:10px" data-sort="amount"></i></th>
                        <th style="cursor:pointer" onclick="sortAdminBills('paid')">Paid <i class="fas fa-sort" style="font-size:10px" data-sort="paid"></i></th>
                        <th style="cursor:pointer" onclick="sortAdminBills('due_date')">Due <i class="fas fa-sort" style="font-size:10px" data-sort="due_date"></i></th>
                        <th style="cursor:pointer" onclick="sortAdminBills('payment_status')">Payment Status <i class="fas fa-sort" style="font-size:10px" data-sort="payment_status"></i></th>
                        <th></th>
                    </tr></thead><tbody id="adminBillingBody">
                        ${renderAdminBillRows(bills)}
                    </tbody></table>
                </div>
                <div id="billPagination" style="display:flex;justify-content:space-between;align-items:center;padding:12px 16px;border-top:1px solid var(--border);flex-wrap:wrap;gap:8px">
                    <span style="font-size:12px;color:var(--text-secondary)">Showing ${bills.length} of <span id="billTotalLabel">${window._billTotal||0}</span> invoice(s)</span>
                    <div style="display:flex;gap:6px;align-items:center">
                        <button class="btn-secondary" style="padding:5px 10px;font-size:12px" onclick="changeBillPage(-1)" ${window._billPage<=1?'disabled':''}><i class="fas fa-chevron-left"></i> Prev</button>
                        <span style="font-size:13px" id="billPageLabel">Page ${window._billPage} / ${Math.max(1,Math.ceil((window._billTotal||0)/window._billPageSize))}</span>
                        <button class="btn-secondary" style="padding:5px 10px;font-size:12px" onclick="changeBillPage(1)" ${window._billPage>=Math.ceil((window._billTotal||0)/window._billPageSize)?'disabled':''}>Next <i class="fas fa-chevron-right"></i></button>
                    </div>
                </div>
            </div>
        </div>
        <div class="modal-overlay" id="billModal">
            <div class="modal" style="max-width:680px">
                <h3 id="billModalTitle">New Invoice</h3>
                <form onsubmit="return saveBill(event)">
                    <input type="hidden" id="billId">
                    <div class="form-row">
                        <div class="form-group"><label>Tenant</label><select id="billUserId"></select></div>
                        <div class="form-group"><label>Unit #</label><input type="text" id="billUnitNumber" placeholder="e.g. 203"></div>
                    </div>
                    <div class="form-row">
                        <div class="form-group"><label>Invoice No</label><input type="text" id="billInvoiceNo" required></div>
                        <div class="form-group"><label>Virtual Account</label><input type="text" id="billVirtualAccount" placeholder="e.g. VA-203-01"></div>
                    </div>
                    <div class="form-row">
                        <div class="form-group"><label>Billing Period</label><input type="text" id="billPeriod" placeholder="e.g. July 2026"></div>
                        <div class="form-group"><label>Due Date</label><input type="date" id="billDueDate"></div>
                    </div>
                    <div style="background:var(--bg);border-radius:var(--radius-sm);padding:16px;margin:12px 0;border:1px solid var(--border)">
                        <p style="font-size:13px;font-weight:600;margin-bottom:10px;color:var(--text-secondary)">Breakdown</p>
                        <div class="form-row grid-4">
                            <div class="form-group"><label style="font-size:12px">Rent</label><input type="number" step="0.01" id="billRent" value="0"></div>
                            <div class="form-group"><label style="font-size:12px">Service Charge</label><input type="number" step="0.01" id="billServiceCharge" value="0"></div>
                            <div class="form-group"><label style="font-size:12px">Electricity</label><input type="number" step="0.01" id="billElectricity" value="0"></div>
                            <div class="form-group"><label style="font-size:12px">Water</label><input type="number" step="0.01" id="billWater" value="0"></div>
                            <div class="form-group"><label style="font-size:12px">Gas</label><input type="number" step="0.01" id="billGas" value="0"></div>
                            <div class="form-group"><label style="font-size:12px">Sinking Fund</label><input type="number" step="0.01" id="billSinkingFund" value="0"></div>
                            <div class="form-group"><label style="font-size:12px">Other Rev</label><input type="number" step="0.01" id="billOtherRev" value="0"></div>
                            <div class="form-group"><label style="font-size:12px">Fine</label><input type="number" step="0.01" id="billFine" value="0"></div>
                        </div>
                        <div style="display:flex;justify-content:space-between;align-items:center;padding-top:10px;border-top:1px solid var(--border);margin-top:8px">
                            <span style="font-size:14px;font-weight:600">Total</span>
                            <span style="font-size:20px;font-weight:800;color:var(--primary)" id="billTotalDisplay">₱0.00</span>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group"><label>Paid (₱)</label><input type="number" step="0.01" id="billPaid" value="0"></div>
                        <div class="form-group"><label>Status</label><select id="billStatus" onchange="document.getElementById('billMethodGroup').style.display=this.value==='paid'?'block':'none'"><option value="pending">Pending</option><option value="paid">Paid</option></select></div>
                    </div>
                    <div class="form-group" id="billMethodGroup" style="display:none"><label>Payment Method</label>
                        <select id="billMethod">
                            <option value="Bank Transfer">Bank Transfer</option>
                            <option value="GCash">GCash</option>
                            <option value="Cash">Cash</option>
                            <option value="Cheque">Cheque</option>
                        </select>
                    </div>
                    <div class="form-row">
                        <div class="form-group"><label>Issue Date</label><input type="date" id="billIssuedDate"></div>
                        <div class="form-group"><label>Category</label><select id="billCategory"><option value="Rental">Rental</option><option value="CAM Fee">CAM Fee</option><option value="Utilities">Utilities</option><option value="Penalty">Penalty</option></select></div>
                    </div>
                    <div class="form-group"><label>Description</label><input type="text" id="billDescription" placeholder="e.g. Monthly Rental - Unit 203"></div>
                    <div class="form-actions">
                        <button type="button" class="btn-secondary" onclick="closeModal('billModal')">Cancel</button>
                        <button type="submit" class="btn-primary">Save Invoice</button>
                    </div>
                </form>
            </div>
        </div>
        <div class="modal-overlay" id="uploadModal">
            <div class="modal" style="max-width:600px">
                <h3>Upload Billing CSV</h3>
                <p style="color:var(--text-secondary);font-size:14px;margin-bottom:16px">
                    Upload a CSV file with columns: <code>unit_number</code>, <code>invoice_number</code>, <code>virtual_account</code>, <code>billing_period</code>, <code>Other_Rev</code>, <code>Electricity</code>, <code>Water</code>, <code>Gas</code>, <code>Sinking_Fund</code>, <code>Fine</code>, <code>Rent</code>, <code>Service_Charge</code>, <code>total</code>, <code>due_date</code>
                </p>
                <div id="dropZone" style="border:2px dashed var(--border);border-radius:var(--radius);padding:40px;text-align:center;cursor:pointer;transition:var(--transition);margin-bottom:16px"
                     ondragover="event.preventDefault();this.style.borderColor='var(--primary)';this.style.background='var(--primary-light)'"
                     ondragleave="this.style.borderColor='var(--border)';this.style.background='transparent'"
                     ondrop="event.preventDefault();handleFileDrop(event.dataTransfer.files[0]);this.style.borderColor='var(--border)';this.style.background='transparent'"
                     onclick="document.getElementById('csvFileInput').click()">
                    <i class="fas fa-cloud-upload-alt" style="font-size:48px;color:var(--text-muted);margin-bottom:12px;display:block"></i>
                    <p style="color:var(--text-secondary);font-weight:500">Drop CSV file here or click to browse</p>
                    <p style="font-size:12px;color:var(--text-muted);margin-top:4px">Supports .csv files exported from Excel</p>
                    <input type="file" id="csvFileInput" accept=".csv" style="display:none" onchange="handleFileDrop(this.files[0])">
                </div>
                <div id="uploadPreview" style="display:none;margin-bottom:16px">
                    <div style="background:var(--secondary-light);color:var(--secondary);padding:12px 16px;border-radius:var(--radius-sm);display:flex;align-items:center;gap:10px">
                        <i class="fas fa-file-csv" style="font-size:20px"></i>
                        <span id="uploadFileName" style="font-weight:600;flex:1"></span>
                        <span id="uploadFileRows" style="font-size:13px"></span>
                    </div>
                </div>
                <div id="uploadProgress" style="display:none;margin-bottom:16px">
                    <div style="background:var(--bg-hover);border-radius:20px;height:6px;overflow:hidden">
                        <div id="uploadProgressBar" style="height:100%;width:0%;background:var(--primary);border-radius:20px;transition:width 0.3s"></div>
                    </div>
                    <p id="uploadStatus" style="font-size:13px;color:var(--text-secondary);margin-top:8px"></p>
                </div>
                <div id="uploadResults" style="display:none;margin-bottom:16px"></div>
                <div class="form-actions">
                    <button type="button" class="btn-secondary" onclick="closeModal('uploadModal')">Close</button>
                    <button type="button" class="btn-primary" id="uploadSubmitBtn" onclick="submitUpload()" disabled>
                        <i class="fas fa-upload"></i> Upload
                    </button>
                </div>
            </div>
        </div>
        <div class="modal-overlay" id="vaModal">
            <div class="modal" style="max-width:600px">
                <h3>Import Virtual Account Numbers</h3>
                <p style="color:var(--text-secondary);font-size:14px;margin-bottom:16px">
                    Upload a CSV with columns <code>unit_number</code> and <code>virtual_account</code>.
                    This updates the VA for each unit on every invoice. In Excel, format the VA column as <strong>text</strong> before saving the CSV so large numbers are not converted to scientific notation.
                </p>
                <div style="margin-bottom:12px;padding:12px 14px;background:var(--danger-light);color:var(--danger);border-radius:var(--radius-sm);font-size:13px">
                    <i class="fas fa-exclamation-triangle"></i> Existing rows with values like <code>5.35403e15</code> are corrupted by Excel rounding and will be replaced by this import.
                </div>
                <div id="vaDropZone" style="border:2px dashed var(--border);border-radius:var(--radius);padding:40px;text-align:center;cursor:pointer;transition:var(--transition);margin-bottom:16px"
                     ondragover="event.preventDefault();this.style.borderColor='var(--primary)';this.style.background='var(--primary-light)'"
                     ondragleave="this.style.borderColor='var(--border)';this.style.background='transparent'"
                     ondrop="event.preventDefault();handleVaFileDrop(event.dataTransfer.files[0]);this.style.borderColor='var(--border)';this.style.background='transparent'"
                     onclick="document.getElementById('vaFileInput').click()">
                    <i class="fas fa-cloud-upload-alt" style="font-size:48px;color:var(--text-muted);margin-bottom:12px;display:block"></i>
                    <p style="color:var(--text-secondary);font-weight:500">Drop VA CSV here or click to browse</p>
                    <p style="font-size:12px;color:var(--text-muted);margin-top:4px">Columns: unit_number, virtual_account</p>
                    <input type="file" id="vaFileInput" accept=".csv" style="display:none" onchange="handleVaFileDrop(this.files[0])">
                </div>
                <div id="vaPreview" style="display:none;margin-bottom:16px">
                    <div style="background:var(--secondary-light);color:var(--secondary);padding:12px 16px;border-radius:var(--radius-sm);display:flex;align-items:center;gap:10px">
                        <i class="fas fa-file-csv" style="font-size:20px"></i>
                        <span id="vaFileName" style="font-weight:600;flex:1"></span>
                        <span id="vaFileRows" style="font-size:13px"></span>
                    </div>
                </div>
                <div id="vaProgress" style="display:none;margin-bottom:16px">
                    <div style="background:var(--bg-hover);border-radius:20px;height:6px;overflow:hidden">
                        <div id="vaProgressBar" style="height:100%;width:0%;background:var(--primary);border-radius:20px;transition:width 0.3s"></div>
                    </div>
                    <p id="vaStatus" style="font-size:13px;color:var(--text-secondary);margin-top:8px"></p>
                </div>
                <div id="vaResults" style="display:none;margin-bottom:16px"></div>
                <div class="form-actions">
                    <button type="button" class="btn-secondary" onclick="closeModal('vaModal')">Close</button>
                    <button type="button" class="btn-primary" id="vaSubmitBtn" onclick="submitVaImport()" disabled>
                        <i class="fas fa-hashtag"></i> Import VA
                    </button>
                </div>
            </div>
        </div>
        <div class="modal-overlay" id="paymentsModal">
            <div class="modal" style="max-width:600px">
                <h3>Upload Paid Payments</h3>
                <p style="color:var(--text-secondary);font-size:14px;margin-bottom:16px">
                    Upload a CSV with columns <code>unit_number</code> and <code>paid_amount</code> (optional: <code>reference</code>, <code>method</code>, <code>paid_at</code>, plus <code>billing_period</code> or <code>invoice_no</code> to target a specific invoice). Each row records a tenant's payment; invoices fully covered become <strong>paid</strong>.
                </p>
                <div id="paymentsDropZone" style="border:2px dashed var(--border);border-radius:var(--radius);padding:40px;text-align:center;cursor:pointer;transition:var(--transition);margin-bottom:16px"
                     ondragover="event.preventDefault();this.style.borderColor='var(--accent)';this.style.background='var(--accent-light)'"
                     ondragleave="this.style.borderColor='var(--border)';this.style.background='transparent'"
                     ondrop="event.preventDefault();handlePaymentsFileDrop(event.dataTransfer.files[0]);this.style.borderColor='var(--border)';this.style.background='transparent'"
                     onclick="document.getElementById('paymentsFileInput').click()">
                    <i class="fas fa-money-bill-wave" style="font-size:48px;color:var(--text-muted);margin-bottom:12px;display:block"></i>
                    <p style="color:var(--text-secondary);font-weight:500">Drop payments CSV here or click to browse</p>
                    <p style="font-size:12px;color:var(--text-muted);margin-top:4px">Columns: unit_number, paid_amount (optional reference, method, paid_at, billing_period)</p>
                    <input type="file" id="paymentsFileInput" accept=".csv" style="display:none" onchange="handlePaymentsFileDrop(this.files[0])">
                </div>
                <div id="paymentsPreview" style="display:none;margin-bottom:16px">
                    <div style="background:var(--secondary-light);color:var(--secondary);padding:12px 16px;border-radius:var(--radius-sm);display:flex;align-items:center;gap:10px">
                        <i class="fas fa-file-csv" style="font-size:20px"></i>
                        <span id="paymentsFileName" style="font-weight:600;flex:1"></span>
                        <span id="paymentsFileRows" style="font-size:13px"></span>
                    </div>
                </div>
                <div id="paymentsProgress" style="display:none;margin-bottom:16px">
                    <div style="background:var(--bg-hover);border-radius:20px;height:6px;overflow:hidden">
                        <div id="paymentsProgressBar" style="height:100%;width:0%;background:var(--accent);border-radius:20px;transition:width 0.3s"></div>
                    </div>
                    <p id="paymentsStatus" style="font-size:13px;color:var(--text-secondary);margin-top:8px"></p>
                </div>
                <div id="paymentsResults" style="display:none;margin-bottom:16px"></div>
                <div class="form-actions">
                    <button type="button" class="btn-secondary" onclick="closeModal('paymentsModal')">Close</button>
                    <button type="button" class="btn-primary" id="paymentsSubmitBtn" onclick="submitPaymentsUpload()" disabled>
                        <i class="fas fa-money-bill-wave"></i> Record Payments
                    </button>
                </div>
            </div>
        </div>`;
}

function renderAdminBillRows(rows) {
    if (!rows.length) return '<tr><td colspan="8" style="text-align:center;padding:40px;color:var(--text-muted)">No invoices found</td></tr>';
    const vaDisplay = b => {
        const va = (b.virtual_account || b.user_virtual_account || '').trim();
        if (va === '' || va === '0') return '';
        return `<br><span style="font-family:monospace;font-size:11px;color:var(--text-secondary)">${escHtml(va)}</span>`;
    };
    return rows.map(b => `<tr>
        <td data-label="Invoice"><strong>${escHtml(b.invoice_no)}</strong></td>
        <td data-label="Tenant">${escHtml(b.user_name || 'N/A')}${vaDisplay(b)}</td>
        <td data-label="Unit">${b.unit_number || 'N/A'}</td>
        <td data-label="Amount">${formatCurrency(b.amount)}</td>
        <td data-label="Paid">${formatCurrency(b.paid)}</td>
        <td data-label="Due" style="font-size:13px">${formatDate(b.due_date)}</td>
        <td data-label="Status"><span class="${statusBadge(billPaymentStatus(b))}">${billPaymentStatus(b)}${b.amount-b.paid!==0?' ('+formatCurrency(Math.abs(b.amount-b.paid))+')':''}</span></td>
        <td data-label="Actions">
            <button class="btn-secondary" style="padding:4px 8px;font-size:12px" onclick="viewBill(${b.id})" title="View"><i class="fas fa-eye"></i></button>
            <button class="btn-secondary" style="padding:4px 8px;font-size:12px" onclick="showBillForm(${b.id})"><i class="fas fa-edit"></i></button>
            <button class="btn-secondary" style="padding:4px 8px;font-size:12px;color:var(--danger)" onclick="deleteBill(${b.id})"><i class="fas fa-trash"></i></button>
            <span style="display:inline-flex;align-items:center;cursor:pointer;font-size:16px;margin-left:4px" onclick="toggleBillStatus(${b.id},this)" title="${b.status==='paid'?'Mark pending':'Mark paid'}">${b.status==='paid'?'<i class="fas fa-check-circle" style="color:var(--success)"></i>':'<i class="far fa-circle" style="color:var(--text-muted)"></i>'}</span>
        </td>
    </tr>`).join('');
}

let _billSearchTimer = null;

function filterAdminBills() {
    const periodFilter = (document.getElementById('billPeriodFilter').value || '').trim();
    const statusFilter = (document.getElementById('billStatusFilter').value || '').trim();
    if (periodFilter !== (window._billPeriodKey || '') || statusFilter !== (window._billStatusKey || '')) {
        window._billPeriodKey = periodFilter;
        window._billStatusKey = statusFilter;
        window._billPage = 1;
        reloadAdminBills();
        return;
    }
    const all = window._allBills || [];
    let filtered = statusFilter ? all.filter(b => billPaymentStatus(b) === statusFilter) : all;
    if (window._billSortKey) filtered = sortBillsArray(filtered, window._billSortKey, window._billSortDir);
    document.getElementById('adminBillingBody').innerHTML = renderAdminBillRows(filtered);
}

function searchAdminBills(immediate) {
    if (_billSearchTimer) clearTimeout(_billSearchTimer);
    _billSearchTimer = setTimeout(() => {
        const q = (document.getElementById('billSearch').value || '').trim();
        window._billQuery = q;
        window._billPage = 1;
        reloadAdminBills();
    }, immediate ? 0 : 350);
}

async function reloadAdminBills() {
    try {
        const sort = window._billSortKey ? '&sort=' + encodeURIComponent(window._billSortKey) + '&order=' + encodeURIComponent(window._billSortDir || 'asc') : '';
        const status = window._billStatusKey ? '&status=' + encodeURIComponent(window._billStatusKey) : '';
        const res = await api('GET', '/admin/billing?period=' + encodeURIComponent(window._billPeriodKey || '') + '&q=' + encodeURIComponent(window._billQuery || '') + '&page=' + (window._billPage||1) + '&page_size=' + window._billPageSize + sort + status);
        window._allBills = res.bills || [];
        window._billTotal = res.total || window._allBills.length;
        const periodFilter = document.getElementById('billPeriodFilter');
        if (periodFilter) periodFilter.value = window._billPeriodKey || '';
        const statusFilter = document.getElementById('billStatusFilter');
        if (statusFilter) statusFilter.value = window._billStatusKey || '';
        filterAdminBills();
        renderBillPagination();
    } catch (e) { showToast(e.message, 'error'); }
}

function changeBillPage(dir) {
    const next = (window._billPage||1) + dir;
    const last = Math.max(1, Math.ceil((window._billTotal||0) / window._billPageSize));
    if (next < 1 || next > last) return;
    window._billPage = next;
    reloadAdminBills();
}

function renderBillPagination() {
    const last = Math.max(1, Math.ceil((window._billTotal||0) / window._billPageSize));
    const lbl = document.getElementById('billPageLabel');
    if (lbl) lbl.textContent = 'Page ' + (window._billPage||1) + ' / ' + last;
    const total = document.getElementById('billTotalLabel');
    if (total) total.textContent = window._billTotal || 0;
    const count = document.getElementById('billCount');
    if (count) count.textContent = window._billTotal || 0;
    document.querySelectorAll('th [data-sort]').forEach(icon => {
        const key = icon.getAttribute('data-sort');
        if (key === window._billSortKey) {
            icon.className = window._billSortDir === 'desc' ? 'fas fa-sort-down' : 'fas fa-sort-up';
        } else {
            icon.className = 'fas fa-sort';
        }
    });
}

function sortBillsArray(arr, key, dir) {
    return [...arr].sort((a, b) => {
        let va, vb;
        if (key === 'payment_status') {
            va = Math.abs((a.amount || 0) - (a.paid || 0)); vb = Math.abs((b.amount || 0) - (b.paid || 0));
        } else {
            va = a[key]; vb = b[key];
        }
        if (typeof va === 'number' && typeof vb === 'number') return dir === 'asc' ? va - vb : vb - va;
        va = (va || '').toString().toLowerCase(); vb = (vb || '').toString().toLowerCase();
        return dir === 'asc' ? va.localeCompare(vb) : vb.localeCompare(va);
    });
}

function sortAdminBills(key) {
    if (window._billSortKey === key) { window._billSortDir = window._billSortDir === 'asc' ? 'desc' : 'asc'; }
    else { window._billSortKey = key; window._billSortDir = 'asc'; }
    window._billPage = 1;
    reloadAdminBills();
}

async function showBillForm(id) {
    const users = window._billUsers || [];
    let bill = null;
    if (id) {
        try { bill = await api('GET', '/admin/billing/' + id); } catch(e) { showToast(e.message, 'error'); return; }
    }
    const uid = bill ? (users.find(u => u.unit_number === bill.unit_number)?.id || bill.user_id || '') : (users[0]?.id || '');
    const unit = bill ? (bill.unit_number || '') : (users.find(u => u.id == uid)?.unit_number || '');
    document.getElementById('billModalTitle').textContent = bill ? 'Edit Invoice' : 'New Invoice';
    document.getElementById('billId').value = bill ? bill.id : '';
    document.getElementById('billUserId').innerHTML = users.map(u => `<option value="${u.id}">${escHtml(u.name)} (${escHtml(u.company||'')})</option>`).join('');
    document.getElementById('billUserId').value = uid;
    document.getElementById('billInvoiceNo').value = bill ? bill.invoice_no : 'INV-' + new Date().getFullYear() + '-' + String(Date.now()).slice(-4);
    document.getElementById('billVirtualAccount').value = bill ? (bill.virtual_account ? (bill.virtual_account.indexOf('00535') === 0 ? bill.virtual_account : '00535' + bill.virtual_account) : '') : ('00535VA-' + unit + '-01');
    document.getElementById('billUnitNumber').value = bill ? (bill.unit_number || '') : '';
    document.getElementById('billPeriod').value = bill ? (bill.billing_period || '') : '';
    document.getElementById('billRent').value = bill ? (bill.rent || 0) : 0;
    document.getElementById('billServiceCharge').value = bill ? (bill.service_charge || 0) : 0;
    document.getElementById('billElectricity').value = bill ? (bill.electricity || 0) : 0;
    document.getElementById('billWater').value = bill ? (bill.water || 0) : 0;
    document.getElementById('billGas').value = bill ? (bill.gas || 0) : 0;
    document.getElementById('billSinkingFund').value = bill ? (bill.sinking_fund || 0) : 0;
    document.getElementById('billOtherRev').value = bill ? (bill.other_rev || 0) : 0;
    document.getElementById('billFine').value = bill ? (bill.fine || 0) : 0;
    document.getElementById('billPaid').value = bill ? (bill.paid || 0) : 0;
    document.getElementById('billStatus').value = bill ? bill.status : 'pending';
    const methodGroup = document.getElementById('billMethodGroup');
    methodGroup.style.display = (bill && bill.status === 'paid') ? 'block' : 'none';
    document.getElementById('billMethod').value = bill ? (bill.method || 'Bank Transfer') : 'Bank Transfer';
    document.getElementById('billStatus').onchange = function() {
        const total = parseInt(document.getElementById('billTotalDisplay').textContent.replace(/[^0-9]/g,''), 10) || 0;
        if (this.value === 'paid' && (parseFloat(document.getElementById('billPaid').value) || 0) < total) {
            document.getElementById('billPaid').value = Math.round(total);
        }
        methodGroup.style.display = this.value === 'paid' ? 'block' : 'none';
    };
    document.getElementById('billIssuedDate').value = bill ? bill.issued_date : new Date().toISOString().slice(0,10);
    document.getElementById('billDueDate').value = bill ? bill.due_date : '';
    document.getElementById('billDescription').value = bill ? (bill.description || '') : '';
    document.getElementById('billCategory').value = bill ? (bill.category || 'Rental') : 'Rental';
    document.getElementById('billModal').classList.add('open');
    updateBillTotal();
}

function updateBillTotal() {
    const rent = parseFloat(document.getElementById('billRent').value) || 0;
    const sc = parseFloat(document.getElementById('billServiceCharge').value) || 0;
    const elec = parseFloat(document.getElementById('billElectricity').value) || 0;
    const water = parseFloat(document.getElementById('billWater').value) || 0;
    const gas = parseFloat(document.getElementById('billGas').value) || 0;
    const sf = parseFloat(document.getElementById('billSinkingFund').value) || 0;
    const or = parseFloat(document.getElementById('billOtherRev').value) || 0;
    const fine = parseFloat(document.getElementById('billFine').value) || 0;
    const total = rent + sc + elec + water + gas + sf + or + fine;
    document.getElementById('billTotalDisplay').textContent = formatCurrency(total);
}

async function saveBill(e) {
    e.preventDefault();
    const id = document.getElementById('billId').value;
    const rent = parseFloat(document.getElementById('billRent').value) || 0;
    const serviceCharge = parseFloat(document.getElementById('billServiceCharge').value) || 0;
    const electricity = parseFloat(document.getElementById('billElectricity').value) || 0;
    const water = parseFloat(document.getElementById('billWater').value) || 0;
    const gas = parseFloat(document.getElementById('billGas').value) || 0;
    const sinkingFund = parseFloat(document.getElementById('billSinkingFund').value) || 0;
    const otherRev = parseFloat(document.getElementById('billOtherRev').value) || 0;
    const fine = parseFloat(document.getElementById('billFine').value) || 0;
    const total = rent + serviceCharge + electricity + water + gas + sinkingFund + otherRev + fine;

    const data = {
        user_id: parseInt(document.getElementById('billUserId').value),
        unit_number: document.getElementById('billUnitNumber').value,
        invoice_no: document.getElementById('billInvoiceNo').value,
        virtual_account: document.getElementById('billVirtualAccount').value,
        billing_period: document.getElementById('billPeriod').value,
        amount: total,
        rent, service_charge: serviceCharge, electricity, water, gas,
        sinking_fund: sinkingFund, other_rev: otherRev, fine,
        paid: parseFloat(document.getElementById('billPaid').value) || 0,
        status: document.getElementById('billStatus').value,
        method: document.getElementById('billMethod').value,
        issued_date: document.getElementById('billIssuedDate').value,
        due_date: document.getElementById('billDueDate').value,
        description: document.getElementById('billDescription').value,
        category: document.getElementById('billCategory').value
    };
    try {
        if (id) { await api('PUT', '/admin/billing/' + id, data); showToast('Invoice updated'); }
        else { await api('POST', '/admin/billing', data); showToast('Invoice created'); }
        closeModal('billModal');
        navigate('admin-billing');
    } catch(e) { showToast(e.message, 'error'); }
    return false;
}

async function deleteBill(id) {
    if (!confirm('Delete this invoice?')) return;
    try { await api('DELETE', '/admin/billing/' + id); showToast('Invoice deleted'); navigate('admin-billing'); }
    catch(e) { showToast(e.message, 'error'); }
}

async function viewBill(id) {
    try {
        const bill = await api('GET', '/admin/billing/' + id);
        const breakdownFields = [
            ['Rent', 'rent'], ['Service Charge', 'service_charge'], ['Electricity', 'electricity'],
            ['Water', 'water'], ['Gas', 'gas'], ['Sinking Fund', 'sinking_fund'],
            ['Other Rev', 'other_rev'], ['Fine', 'fine']
        ];
        const breakdownHtml = breakdownFields.map(([label, key]) =>
            `<tr><td style="padding:4px 8px;color:var(--text-secondary)">${label}</td><td style="padding:4px 8px;text-align:right">${formatCurrency(bill[key]||0)}</td></tr>`
        ).join('');
        const html = `
            <div class="modal-overlay open" onclick="if(event.target===this)this.remove()">
                <div class="modal" style="max-width:500px" onclick="event.stopPropagation()">
                    <h3>Invoice Detail</h3>
                    <div style="margin:16px 0">
                        <div class="grid-2" style="gap:8px;font-size:14px">
                            <div><strong>Invoice</strong><br>${bill.invoice_no}</div>
                            <div><strong>Unit</strong><br>${bill.unit_number||'-'}</div>
                            <div><strong>Period</strong><br>${bill.billing_period||'-'}</div>
                            <div><strong>Status</strong><br><span class="${statusBadge(billPaymentStatus(bill))}">${billPaymentStatus(bill)}</span></div>
                            <div><strong>Due Date</strong><br>${formatDate(bill.due_date)}</div>
                            <div><strong>Issue Date</strong><br>${bill.issued_date||'-'}</div>
                        </div>
                        <table style="width:100%;margin-top:12px;border-collapse:collapse">
                            <thead><tr style="border-bottom:1px solid var(--border)"><th style="padding:4px 8px;text-align:left">Component</th><th style="padding:4px 8px;text-align:right">Amount</th></tr></thead>
                            <tbody>${breakdownHtml}</tbody>
                            <tfoot><tr style="border-top:2px solid var(--border)"><td style="padding:4px 8px;font-weight:700">Total</td><td style="padding:4px 8px;text-align:right;font-weight:700">${formatCurrency(bill.amount)}</td></tr></tfoot>
                        </table>
                        <div style="margin-top:12px;display:flex;justify-content:space-between;font-size:14px">
                            <span><strong>Paid:</strong> ${formatCurrency(bill.paid)}</span>
                            <span><strong>Balance:</strong> ${formatCurrency((bill.amount||0)-(bill.paid||0))}</span>
                        </div>
                        ${bill.description ? `<div style="margin-top:8px;font-size:13px;color:var(--text-secondary)"><strong>Notes:</strong> ${bill.description}</div>` : ''}
                    </div>
                    <div class="form-actions">
                        <button type="button" class="btn-secondary" onclick="downloadInvoice(${id}, true)"><i class="fas fa-download"></i> Download PDF</button>
                        <button type="button" class="btn-secondary" onclick="this.closest('.modal-overlay').remove()">Close</button>
                    </div>
                </div>
            </div>`;
        document.body.insertAdjacentHTML('beforeend', html);
    } catch(e) { showToast(e.message, 'error'); }
}

async function toggleBillStatus(id, el) {
    try {
        const isPaid = el.innerHTML.includes('fa-check-circle');
        if (isPaid) {
            // Mark as pending
            await api('PUT', '/admin/billing/' + id, { status: 'pending', paid: 0 });
            el.innerHTML = '<i class="far fa-circle" style="color:var(--text-muted)"></i>';
            el.title = 'Mark paid';
        } else {
            // Mark as paid — pick method first
            const method = await pickMethod();
            if (!method) return;
            const bill = await api('GET', '/admin/billing/' + id);
            const paid = Math.round(bill.amount);
            await api('PUT', '/admin/billing/' + id, { status: 'paid', paid, method });
            el.innerHTML = '<i class="fas fa-check-circle" style="color:var(--success)"></i>';
            el.title = 'Mark pending';
        }
        const row = el.closest('tr');
        if (row) {
            const statusCell = row.querySelector('td:nth-child(7)');
            if (statusCell) {
                const status = isPaid ? 'pending' : 'paid';
                statusCell.innerHTML = `<span class="${statusBadge(status)}">${status}</span>`;
            }
        }
        showToast('Status changed');
    } catch(e) { showToast(e.message, 'error'); }
}

function pickMethod() {
    return new Promise(resolve => {
        const overlay = document.createElement('div');
        overlay.className = 'modal-overlay';
        overlay.style.cssText = 'display:flex;align-items:center;justify-content:center;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:9999';
        overlay.innerHTML = `
            <div class="modal" style="max-width:360px;padding:24px">
                <h3 style="margin-bottom:16px">Payment Method</h3>
                <select id="methodPickerSelect" style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:var(--radius-sm);font-size:14px;margin-bottom:16px;background:var(--bg);color:var(--text)">
                    <option value="Bank Transfer">Bank Transfer</option>
                    <option value="GCash">GCash</option>
                    <option value="Cash">Cash</option>
                    <option value="Cheque">Cheque</option>
                </select>
                <div class="form-actions" style="margin:0">
                    <button class="btn-secondary" id="methodCancelBtn">Cancel</button>
                    <button class="btn-primary" id="methodConfirmBtn">Confirm</button>
                </div>
            </div>`;
        document.body.appendChild(overlay);
        overlay.querySelector('select').focus();
        overlay.querySelector('#methodCancelBtn').onclick = () => { overlay.remove(); resolve(null); };
        overlay.querySelector('#methodConfirmBtn').onclick = () => { const v = document.getElementById('methodPickerSelect').value; overlay.remove(); resolve(v); };
    });
}

function closeModal(id) { document.getElementById(id).classList.remove('open'); }

document.addEventListener('input', function(e) {
    if (e.target.closest('#billModal')) {
        const ids = ['billRent','billServiceCharge','billElectricity','billWater','billGas','billSinkingFund','billOtherRev','billFine'];
        if (ids.includes(e.target.id)) updateBillTotal();
    }
});
document.addEventListener('click', function(e) {
    if (e.target.classList.contains('modal-overlay')) e.target.classList.remove('open');
});

let uploadedFile = null;
let vaFile = null;

function openUploadModal() {
    uploadedFile = null;
    document.getElementById('uploadPreview').style.display = 'none';
    document.getElementById('uploadProgress').style.display = 'none';
    document.getElementById('uploadResults').style.display = 'none';
    document.getElementById('uploadSubmitBtn').disabled = true;
    document.getElementById('csvFileInput').value = '';
    document.getElementById('uploadModal').classList.add('open');
}

function handleFileDrop(file) {
    if (!file || !file.name.endsWith('.csv')) {
        showToast('Please select a .csv file', 'error');
        return;
    }
    uploadedFile = file;
    document.getElementById('uploadFileName').textContent = file.name;
    document.getElementById('uploadSubmitBtn').disabled = false;
    document.getElementById('uploadPreview').style.display = 'block';
    document.getElementById('uploadProgress').style.display = 'none';
    document.getElementById('uploadResults').style.display = 'none';

    const reader = new FileReader();
    reader.onload = function(e) {
        const lines = e.target.result.replace(/\r\n/g, '\n').replace(/\r/g, '\n').split('\n').filter(l => l.trim());
        document.getElementById('uploadFileRows').textContent = Math.max(0, lines.length - 1) + ' rows';
    };
    reader.readAsText(file);
}

async function submitUpload() {
    if (!uploadedFile) return;
    const btn = document.getElementById('uploadSubmitBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Uploading...';
    document.getElementById('uploadPreview').style.display = 'none';
    document.getElementById('uploadProgress').style.display = 'block';
    document.getElementById('uploadProgressBar').style.width = '50%';
    document.getElementById('uploadStatus').textContent = 'Processing file...';

    try {
        const formData = new FormData();
        formData.append('file', uploadedFile);
        const res = await fetch(BASE_PATH + '/api/index.php/admin/billing/upload', {
            method: 'POST',
            credentials: 'same-origin',
            body: formData
        });
        const json = await res.json();
        if (!res.ok) throw new Error(json.error || 'Upload failed');

        document.getElementById('uploadProgressBar').style.width = '100%';
        document.getElementById('uploadStatus').textContent = `Uploaded ${json.count} invoice(s)`;

        const resultsDiv = document.getElementById('uploadResults');
        resultsDiv.style.display = 'block';
        let html = '';
        if (json.uploaded && json.uploaded.length > 0) {
            html += `<div style="background:var(--secondary-light);color:var(--secondary);padding:12px 16px;border-radius:var(--radius-sm);margin-bottom:8px">
                <strong>${json.uploaded.length} invoice(s) created successfully</strong>
            </div>`;
        }
        if (json.errors && json.errors.length > 0) {
            html += `<div style="background:var(--danger-light);color:var(--danger);padding:12px 16px;border-radius:var(--radius-sm);margin-top:8px">
                <strong>${json.errors.length} error(s):</strong>
                ${json.errors.map(e => '<div style="font-size:13px;margin-top:4px">• ' + e + '</div>').join('')}
            </div>`;
        }
        resultsDiv.innerHTML = html;
        btn.innerHTML = '<i class="fas fa-check"></i> Done';
        setTimeout(() => navigate('admin-billing'), 2000);
    } catch (e) {
        document.getElementById('uploadStatus').textContent = 'Error: ' + e.message;
        document.getElementById('uploadProgressBar').style.width = '0%';
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-upload"></i> Retry';
        showToast(e.message, 'error');
    }
}

function openVaModal() {
    vaFile = null;
    document.getElementById('vaPreview').style.display = 'none';
    document.getElementById('vaProgress').style.display = 'none';
    document.getElementById('vaResults').style.display = 'none';
    document.getElementById('vaSubmitBtn').disabled = true;
    document.getElementById('vaFileInput').value = '';
    document.getElementById('vaModal').classList.add('open');
}

function handleVaFileDrop(file) {
    if (!file || !file.name.endsWith('.csv')) {
        showToast('Please select a .csv file', 'error');
        return;
    }
    vaFile = file;
    document.getElementById('vaFileName').textContent = file.name;
    document.getElementById('vaSubmitBtn').disabled = false;
    document.getElementById('vaPreview').style.display = 'block';
    document.getElementById('vaProgress').style.display = 'none';
    document.getElementById('vaResults').style.display = 'none';

    const reader = new FileReader();
    reader.onload = function(e) {
        const lines = e.target.result.replace(/\r\n/g, '\n').replace(/\r/g, '\n').split('\n').filter(l => l.trim());
        document.getElementById('vaFileRows').textContent = Math.max(0, lines.length - 1) + ' rows';
    };
    reader.readAsText(file);
}

async function submitVaImport() {
    if (!vaFile) return;
    const btn = document.getElementById('vaSubmitBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Importing...';
    document.getElementById('vaPreview').style.display = 'none';
    document.getElementById('vaProgress').style.display = 'block';
    document.getElementById('vaProgressBar').style.width = '50%';
    document.getElementById('vaStatus').textContent = 'Processing file...';

    try {
        const formData = new FormData();
        formData.append('file', vaFile);
        const res = await fetch(BASE_PATH + '/api/index.php/admin/va-import', {
            method: 'POST',
            credentials: 'same-origin',
            body: formData
        });
        const json = await res.json();
        if (!res.ok) throw new Error(json.error || 'Import failed');

        document.getElementById('vaProgressBar').style.width = '100%';
        document.getElementById('vaStatus').textContent = `Imported ${json.count} VA number(s)`;

        const resultsDiv = document.getElementById('vaResults');
        resultsDiv.style.display = 'block';
        let html = '';
        if (json.updated && json.updated.length > 0) {
            html += `<div style="background:var(--secondary-light);color:var(--secondary);padding:12px 16px;border-radius:var(--radius-sm);margin-bottom:8px">
                <strong>${json.updated.length} VA number(s) updated</strong>
            </div>`;
        }
        if (json.errors && json.errors.length > 0) {
            html += `<div style="background:var(--danger-light);color:var(--danger);padding:12px 16px;border-radius:var(--radius-sm);margin-top:8px">
                <strong>${json.errors.length} error(s):</strong>
                ${json.errors.map(e => '<div style="font-size:13px;margin-top:4px">• ' + e + '</div>').join('')}
            </div>`;
        }
        resultsDiv.innerHTML = html;
        btn.innerHTML = '<i class="fas fa-check"></i> Done';
        setTimeout(() => navigate('admin-billing'), 2000);
    } catch (e) {
        document.getElementById('vaStatus').textContent = 'Error: ' + e.message;
        document.getElementById('vaProgressBar').style.width = '0%';
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-hashtag"></i> Retry';
        showToast(e.message, 'error');
    }
}

let paymentsFile = null;

function openPaymentsModal() {
    paymentsFile = null;
    document.getElementById('paymentsPreview').style.display = 'none';
    document.getElementById('paymentsProgress').style.display = 'none';
    document.getElementById('paymentsResults').style.display = 'none';
    document.getElementById('paymentsSubmitBtn').disabled = true;
    document.getElementById('paymentsFileInput').value = '';
    document.getElementById('paymentsModal').classList.add('open');
}

function handlePaymentsFileDrop(file) {
    if (!file || !file.name.endsWith('.csv')) {
        showToast('Please select a .csv file', 'error');
        return;
    }
    paymentsFile = file;
    document.getElementById('paymentsFileName').textContent = file.name;
    document.getElementById('paymentsSubmitBtn').disabled = false;
    document.getElementById('paymentsPreview').style.display = 'block';
    document.getElementById('paymentsProgress').style.display = 'none';
    document.getElementById('paymentsResults').style.display = 'none';

    const reader = new FileReader();
    reader.onload = function(e) {
        const lines = e.target.result.replace(/\r\n/g, '\n').replace(/\r/g, '\n').split('\n').filter(l => l.trim());
        document.getElementById('paymentsFileRows').textContent = Math.max(0, lines.length - 1) + ' rows';
    };
    reader.readAsText(file);
}

async function submitPaymentsUpload() {
    if (!paymentsFile) return;
    const btn = document.getElementById('paymentsSubmitBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Recording...';
    document.getElementById('paymentsPreview').style.display = 'none';
    document.getElementById('paymentsProgress').style.display = 'block';
    document.getElementById('paymentsProgressBar').style.width = '50%';
    document.getElementById('paymentsStatus').textContent = 'Processing file...';

    try {
        const formData = new FormData();
        formData.append('file', paymentsFile);
        const res = await fetch(BASE_PATH + '/api/index.php/admin/payments/upload', {
            method: 'POST',
            credentials: 'same-origin',
            body: formData
        });
        const json = await res.json();
        if (!res.ok) throw new Error(json.error || 'Upload failed');

        document.getElementById('paymentsProgressBar').style.width = '100%';
        document.getElementById('paymentsStatus').textContent = `Recorded ${json.count} payment(s)`;

        const resultsDiv = document.getElementById('paymentsResults');
        resultsDiv.style.display = 'block';
        let html = '';
        if (json.recorded && json.recorded.length > 0) {
            html += `<div style="background:var(--secondary-light);color:var(--secondary);padding:12px 16px;border-radius:var(--radius-sm);margin-bottom:8px">
                <strong>${json.recorded.length} payment(s) recorded</strong>
            </div>`;
        }
        if (json.errors && json.errors.length > 0) {
            html += `<div style="background:var(--danger-light);color:var(--danger);padding:12px 16px;border-radius:var(--radius-sm);margin-top:8px">
                <strong>${json.errors.length} error(s):</strong>
                ${json.errors.map(e => '<div style="font-size:13px;margin-top:4px">• ' + escHtml(e) + '</div>').join('')}
            </div>`;
        }
        resultsDiv.innerHTML = html;
        btn.innerHTML = '<i class="fas fa-check"></i> Done';
        setTimeout(() => navigate('admin-billing'), 2000);
    } catch (e) {
        document.getElementById('paymentsStatus').textContent = 'Error: ' + e.message;
        document.getElementById('paymentsProgressBar').style.width = '0%';
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-money-bill-wave"></i> Retry';
        showToast(e.message, 'error');
    }
}

async function renderAdminNews(area, titleEl, subtitleEl) {
    titleEl.textContent = 'Manage News';
    subtitleEl.textContent = 'Publish announcements, promotions, and events';
    const news = await api('GET', '/admin/news');

    area.innerHTML = `
        <div style="margin-bottom:20px">
            <button class="btn-primary" onclick="showNewsForm(null)"><i class="fas fa-plus"></i> New Article</button>
        </div>
        <div class="card">
            <div class="card-header"><h3>All Articles (${news.length})</h3></div>
            <div class="card-body" style="padding:0">
                <div class="table-container">
                    <table><thead><tr><th>Title</th><th>Category</th><th>Priority</th><th>Featured</th><th>Date</th><th></th></tr></thead><tbody>
                        ${news.map(n => `<tr>
                            <td data-label="Title"><strong>${n.title.slice(0,50)}${n.title.length>50?'...':''}</strong></td>
                            <td data-label="Category"><span class="badge ${n.category==='maintenance'?'badge-red':n.category==='promotion'?'badge-yellow':n.category==='event'?'badge-green':'badge-blue'}">${n.category}</span></td>
                            <td data-label="Priority">${n.priority}</td>
                            <td data-label="Featured">${n.featured ? '<i class="fas fa-star" style="color:var(--accent)"></i>' : '—'}</td>
                            <td data-label="Date" style="font-size:13px">${formatDate(n.published_at)}</td>
                            <td>
                                <button class="btn-secondary" style="padding:4px 10px;font-size:12px" onclick="showNewsForm(${n.id})"><i class="fas fa-edit"></i></button>
                                <button class="btn-secondary" style="padding:4px 10px;font-size:12px;color:var(--danger)" onclick="deleteNews(${n.id})"><i class="fas fa-trash"></i></button>
                            </td>
                        </tr>`).join('')}
                    </tbody></table>
                </div>
            </div>
        </div>
        <div class="modal-overlay" id="newsModal">
            <div class="modal">
                <h3 id="newsModalTitle">New Article</h3>
                <form onsubmit="return saveNews(event)">
                    <input type="hidden" id="newsId">
                    <div class="form-group"><label>Title</label><input type="text" id="newsTitle" required></div>
                    <div class="form-group"><label>Content</label><textarea id="newsContent" rows="4" required></textarea></div>
                    <div class="form-row">
                        <div class="form-group"><label>Category</label><select id="newsCategory"><option value="announcement">Announcement</option><option value="promotion">Promotion</option><option value="event">Event</option><option value="maintenance">Maintenance</option></select></div>
                        <div class="form-group"><label>Priority</label><select id="newsPriority"><option value="normal">Normal</option><option value="high">High</option></select></div>
                    </div>
                    <div class="form-group">
                        <label>Image</label>
                        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
                            <input type="text" id="newsImage" placeholder="Image URL or upload below" style="flex:1;min-width:200px">
                            <label class="btn-secondary" style="cursor:pointer;padding:8px 16px;white-space:nowrap">
                                <i class="fas fa-upload"></i> Browse
                                <input type="file" id="newsImageFile" accept="image/*" style="display:none" onchange="uploadNewsImage(this)">
                            </label>
                        </div>
                        <div id="newsImagePreview" style="margin-top:8px;display:none">
                            <img src="" alt="Preview" style="max-width:100%;max-height:160px;border-radius:var(--radius-sm);border:1px solid var(--border)">
                        </div>
                    </div>
                    <div class="form-group"><label>Social Media Embed</label><textarea id="newsEmbed" rows="2" placeholder="Instagram/TikTok URL or paste embed code (blockquote/iframe)"></textarea></div>
                    <div class="form-group"><label><input type="checkbox" id="newsFeatured"> Featured article (shows in carousel)</label></div>
                    <div class="form-actions">
                        <button type="button" class="btn-secondary" onclick="closeModal('newsModal')">Cancel</button>
                        <button type="submit" class="btn-primary">Save Article</button>
                    </div>
                </form>
            </div>
        </div>`;
}

async function showNewsForm(id) {
    const article = id ? await api('GET', '/admin/news/' + id) : null;
    document.getElementById('newsModalTitle').textContent = article ? 'Edit Article' : 'New Article';
    document.getElementById('newsId').value = article ? article.id : '';
    document.getElementById('newsTitle').value = article ? article.title : '';
    document.getElementById('newsContent').value = article ? article.content : '';
    document.getElementById('newsCategory').value = article ? article.category : 'announcement';
    document.getElementById('newsPriority').value = article ? article.priority : 'normal';
    document.getElementById('newsImage').value = article ? (article.image || '') : '';
    const preview = document.getElementById('newsImagePreview');
    const previewImg = preview.querySelector('img');
    if (article && article.image) {
        previewImg.src = resolveMediaUrl(article.image);
        preview.style.display = 'block';
    } else {
        preview.style.display = 'none';
    }
    document.getElementById('newsFeatured').checked = article ? !!article.featured : false;
    document.getElementById('newsEmbed').value = article ? (article.embed_url || '') : '';
    document.getElementById('newsModal').classList.add('open');
}

async function saveNews(e) {
    e.preventDefault();
    const id = document.getElementById('newsId').value;
    const data = {
        title: document.getElementById('newsTitle').value,
        content: document.getElementById('newsContent').value,
        category: document.getElementById('newsCategory').value,
        priority: document.getElementById('newsPriority').value,
        image: document.getElementById('newsImage').value,
        embed_url: document.getElementById('newsEmbed').value,
        featured: document.getElementById('newsFeatured').checked ? 1 : 0
    };
    try {
        if (id) { await api('PUT', '/admin/news/' + id, data); showToast('Article updated'); }
        else { await api('POST', '/admin/news', data); showToast('Article published'); }
        closeModal('newsModal');
        navigate('admin-news');
    } catch(e) { showToast(e.message, 'error'); }
    return false;
}

async function deleteNews(id) {
    if (!confirm('Delete this article?')) return;
    try { await api('DELETE', '/admin/news/' + id); showToast('Article deleted'); navigate('admin-news'); }
    catch(e) { showToast(e.message, 'error'); }
}

async function uploadNewsImage(input) {
    const file = input.files[0];
    if (!file) return;
    if (file.size > 5 * 1024 * 1024) { showToast('File too large (max 5MB)', 'error'); input.value = ''; return; }
    const validTypes = ['image/jpeg','image/png','image/gif','image/webp','image/svg+xml'];
    if (!validTypes.includes(file.type)) { showToast('Invalid file type', 'error'); input.value = ''; return; }
    const preview = document.getElementById('newsImagePreview');
    const previewImg = preview.querySelector('img');
    previewImg.src = URL.createObjectURL(file);
    preview.style.display = 'block';
    const formData = new FormData();
    formData.append('file', file);
    try {
        const res = await fetch(BASE_PATH + '/api/index.php/admin/news/upload', { method: 'POST', body: formData });
        const json = await res.json();
        if (!res.ok) throw new Error(json.error || 'Upload failed');
        document.getElementById('newsImage').value = json.path;
        showToast('Image uploaded');
    } catch(e) { showToast(e.message, 'error'); }
}

async function renderAdminDocuments(area, titleEl, subtitleEl) {
    titleEl.textContent = 'Manage Documents';
    subtitleEl.textContent = 'Upload and manage tenant documents';
    const [docs, users] = await Promise.all([
        api('GET', '/admin/documents'),
        api('GET', '/admin/users')
    ]);

    area.innerHTML = `
        <div style="margin-bottom:20px">
            <button class="btn-primary" onclick="showDocForm(null, ${JSON.stringify(users).replace(/"/g,'&quot;')})"><i class="fas fa-plus"></i> Add Document</button>
        </div>
        <div class="card">
            <div class="card-header"><h3>All Documents (${docs.length})</h3></div>
            <div class="card-body" style="padding:0">
                <div class="table-container">
                    <table><thead><tr><th>Title</th><th>Type</th><th>Category</th><th>Size</th><th>Public</th><th>Uploaded</th><th></th></tr></thead><tbody>
                        ${docs.map(d => `<tr>
                            <td data-label="Title"><strong>${d.title}</strong>${d.description ? '<br><span style="font-size:12px;color:var(--text-muted)">' + escHtml(d.description) + '</span>' : ''}</td>
                            <td data-label="Type"><span class="badge badge-blue">${d.type}</span></td>
                            <td data-label="Category">${d.category || '—'}</td>
                            <td data-label="Size" style="font-size:13px">${d.file_size || '—'}</td>
                            <td data-label="Public">${d.is_public ? '<i class="fas fa-globe" style="color:var(--secondary)"></i>' : '<i class="fas fa-lock" style="color:var(--text-muted)"></i>'}</td>
                            <td data-label="Uploaded" style="font-size:13px">${formatDate(d.uploaded_at)}</td>
                            <td data-label="">
                                ${d.file_url ? '<a href="' + d.file_url + '" target="_blank" class="btn-secondary" style="padding:4px 10px;font-size:12px"><i class="fas fa-download"></i></a> ' : ''}
                                <button class="btn-secondary" style="padding:4px 10px;font-size:12px;color:var(--danger)" onclick="deleteDoc(${d.id})"><i class="fas fa-trash"></i></button>
                            </td>
                        </tr>`).join('')}
                    </tbody></table>
                </div>
            </div>
        </div>
        <div class="modal-overlay" id="docModal">
            <div class="modal" style="max-width:600px">
                <h3 id="docModalTitle">Add Document</h3>
                <form onsubmit="return saveDoc(event)">
                    <input type="hidden" id="docId">
                    <div class="form-group"><label>Title</label><input type="text" id="docTitle" required></div>
                    <div class="form-row">
                        <div class="form-group"><label>Type</label><select id="docType" onchange="updateDocTypeDisplay()"><option value="PDF">PDF</option><option value="DOCX">DOCX</option><option value="XLSX">XLSX</option></select></div>
                        <div class="form-group"><label>Category</label><select id="docCategory"><option value="lease">Lease</option><option value="guidelines">Guidelines</option><option value="circular">Circular</option><option value="form">Form</option></select></div>
                    </div>
                    <div class="form-group"><label>File</label>
                        <div id="docDropZone" style="border:2px dashed var(--border);border-radius:var(--radius);padding:24px;text-align:center;cursor:pointer;transition:var(--transition)"
                             ondragover="event.preventDefault();this.style.borderColor='var(--primary)';this.style.background='var(--primary-light)'"
                             ondragleave="this.style.borderColor='var(--border)';this.style.background='transparent'"
                             ondrop="event.preventDefault();handleDocFileDrop(event.dataTransfer.files[0]);this.style.borderColor='var(--border)';this.style.background='transparent'"
                             onclick="document.getElementById('docFileInput').click()">
                            <i class="fas fa-cloud-upload-alt" style="font-size:32px;color:var(--text-muted);margin-bottom:8px;display:block"></i>
                            <p style="color:var(--text-secondary);font-weight:500;font-size:13px">Drop file here or click to browse</p>
                            <p style="font-size:11px;color:var(--text-muted);margin-top:4px">PDF, DOCX, XLSX, ZIP (max 20MB)</p>
                            <input type="file" id="docFileInput" accept=".pdf,.doc,.docx,.xls,.xlsx,.zip" style="display:none" onchange="handleDocFileDrop(this.files[0])">
                        </div>
                        <div id="docFilePreview" style="display:none;margin-top:8px">
                            <div style="background:var(--secondary-light);color:var(--secondary);padding:8px 14px;border-radius:var(--radius-sm);display:flex;align-items:center;gap:8px;font-size:13px">
                                <i class="fas fa-file"></i>
                                <span id="docFileName" style="font-weight:600;flex:1"></span>
                                <span id="docFileSizeDisplay"></span>
                                <button type="button" class="btn-icon" onclick="clearDocFile()" style="font-size:12px"><i class="fas fa-times"></i></button>
                            </div>
                        </div>
                        <input type="hidden" id="docFileUrl">
                    </div>
                    <div class="form-group"><label>Description</label><input type="text" id="docDescription"></div>
                    <div class="form-row">
                        <div class="form-group"><label>Assigned To (optional)</label><select id="docUserId"><option value="">— Public —</option>${users.map(u => `<option value="${u.id}">${escHtml(u.unit_number || u.name)}</option>`).join('')}</select></div>
                        <div class="form-group"><label>Visibility</label><label class="checkbox-label" style="margin-top:8px"><input type="checkbox" id="docPublic" checked> Public document</label></div>
                    </div>
                    <div class="form-actions">
                        <button type="button" class="btn-secondary" onclick="closeModal('docModal')">Cancel</button>
                        <button type="submit" class="btn-primary" id="docSubmitBtn"><i class="fas fa-save"></i> Save</button>
                    </div>
                </form>
            </div>
        </div>`;
}

function showDocForm(doc, users) {
    document.getElementById('docId').value = doc ? doc.id : '';
    document.getElementById('docModalTitle').textContent = doc ? 'Edit Document' : 'Add Document';
    document.getElementById('docTitle').value = doc ? doc.title : '';
    document.getElementById('docType').value = doc ? doc.type : 'PDF';
    document.getElementById('docCategory').value = doc ? doc.category : 'lease';
    document.getElementById('docFileUrl').value = doc ? (doc.file_url || '') : '';
    document.getElementById('docDescription').value = doc ? (doc.description || '') : '';
    document.getElementById('docUserId').value = doc ? (doc.user_id || '') : '';
    document.getElementById('docPublic').checked = doc ? !!doc.is_public : true;
    clearDocFile();
    if (doc && doc.file_url) {
        document.getElementById('docFilePreview').style.display = 'block';
        document.getElementById('docFileName').textContent = doc.file_url.split('/').pop();
        document.getElementById('docFileSizeDisplay').textContent = doc.file_size || '';
    }
    document.getElementById('docModal').classList.add('open');
}

let docFile = null;

function handleDocFileDrop(file) {
    if (!file) return;
    const ext = file.name.split('.').pop().toLowerCase();
    const allowed = ['pdf','doc','docx','xls','xlsx','zip'];
    if (!allowed.includes(ext)) { showToast('File type not allowed', 'error'); return; }
    if (file.size > 20 * 1024 * 1024) { showToast('File too large (max 20MB)', 'error'); return; }
    docFile = file;
    document.getElementById('docFileName').textContent = file.name;
    document.getElementById('docFileSizeDisplay').textContent = formatFileSize(file.size);
    document.getElementById('docFilePreview').style.display = 'block';
    document.getElementById('docFileUrl').value = '';
    const typeMap = { pdf: 'PDF', doc: 'DOCX', docx: 'DOCX', xls: 'XLSX', xlsx: 'XLSX' };
    if (typeMap[ext]) document.getElementById('docType').value = typeMap[ext];
}

function clearDocFile() {
    docFile = null;
    document.getElementById('docFileInput').value = '';
    document.getElementById('docFilePreview').style.display = 'none';
}

function formatFileSize(bytes) {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
}

async function saveDoc(e) {
    e.preventDefault();
    const btn = document.getElementById('docSubmitBtn');
    let fileUrl = document.getElementById('docFileUrl').value;
    let fileSize = '';

    if (docFile) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Uploading...';
        try {
            const formData = new FormData();
            formData.append('file', docFile);
            const res = await fetch(BASE_PATH + '/api/index.php/admin/documents/upload', {
                method: 'POST', credentials: 'same-origin', body: formData
            });
            const json = await res.json();
            if (!res.ok) throw new Error(json.error || 'Upload failed');
            fileUrl = json.path;
            fileSize = formatFileSize(docFile.size);
        } catch (err) {
            showToast(err.message, 'error');
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-save"></i> Save';
            return false;
        }
    }

    const data = {
        title: document.getElementById('docTitle').value,
        type: document.getElementById('docType').value,
        category: document.getElementById('docCategory').value,
        file_url: fileUrl,
        file_size: fileSize,
        description: document.getElementById('docDescription').value,
        user_id: document.getElementById('docUserId').value || null,
        is_public: document.getElementById('docPublic').checked ? 1 : 0
    };

    try {
        const id = document.getElementById('docId').value;
        if (id) { await api('PUT', '/admin/documents/' + id, data); showToast('Document updated'); }
        else { await api('POST', '/admin/documents', data); showToast('Document added'); }
        closeModal('docModal');
        navigate('admin-documents');
    } catch(e) {
        showToast(e.message, 'error');
    }
    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-save"></i> Save';
    return false;
}

async function deleteDoc(id) {
    if (!confirm('Delete this document?')) return;
    try { await api('DELETE', '/admin/documents/' + id); showToast('Document deleted'); navigate('admin-documents'); }
    catch(e) { showToast(e.message, 'error'); }
}

async function renderAdminTickets(area, titleEl, subtitleEl) {
    titleEl.textContent = 'Support Tickets';
    subtitleEl.textContent = 'Manage tenant inquiries and requests';
    const tickets = await api('GET', '/admin/tickets');
    window._allTickets = tickets;

    area.innerHTML = `
        <div class="card">
            <div class="card-header"><h3>All Tickets (${tickets.length})</h3></div>
            <div class="card-body" style="padding:0">
                <div class="table-container">
                    <table><thead><tr><th>Subject</th><th>From</th><th>Category</th><th>Priority</th><th>Status</th><th>Date</th><th></th></tr></thead><tbody>
                        ${tickets.map(t => `<tr style="cursor:pointer" onclick="openTicket(${t.id})">
                            <td data-label="Subject"><strong>${escHtml(t.subject.slice(0,45))}${t.subject.length>45?'...':''}</strong>${(t.unread||0) > 0 ? `<span class="msg-badge" title="${t.unread} unread">${t.unread}</span>` : ''}</td>
                            <td data-label="From">${escHtml(t.user_name || 'N/A')}</td>
                            <td data-label="Category"><span class="badge badge-blue">${t.category}</span></td>
                            <td data-label="Priority"><span class="badge ${t.priority==='high'||t.priority==='urgent'?'badge-red':t.priority==='low'?'badge-gray':'badge-yellow'}">${t.priority}</span></td>
                            <td data-label="Status"><span class="${statusBadge(t.status)}">${t.status.replace('_',' ')}</span></td>
                            <td data-label="Date" style="font-size:13px">${formatDate(t.created_at)}</td>
                            <td><button class="btn-secondary" style="padding:4px 10px;font-size:12px" onclick="event.stopPropagation();openTicket(${t.id})"><i class="fas fa-comments"></i> Manage</button></td>
                        </tr>`).join('')}
                    </tbody></table>
                </div>
            </div>
        </div>
        <div class="modal-overlay" id="ticketModal">
            <div class="modal" style="max-width:640px;display:flex;flex-direction:column;max-height:90vh">
                <div style="display:flex;justify-content:space-between;align-items:center;padding-bottom:12px;border-bottom:1px solid var(--border);margin-bottom:12px">
                    <div style="min-width:0">
                        <h3 style="margin:0" id="ticketModalTitle">Ticket</h3>
                        <p style="color:var(--text-secondary);font-size:12px;margin:2px 0 0" id="ticketModalMeta"></p>
                    </div>
                    <span class="status-indicator" id="ticketModalStatus"></span>
                </div>
                <div style="display:flex;gap:8px;padding-bottom:12px;border-bottom:1px solid var(--border);margin-bottom:12px;flex-wrap:wrap">
                    <select id="ticketStatus" style="flex:1;min-width:130px;padding:8px 10px;border:1px solid var(--border);border-radius:var(--radius-sm);font-size:13px;background:var(--bg);color:var(--text)">
                        <option value="open">Open</option>
                        <option value="in_progress">In Progress</option>
                        <option value="resolved">Resolved</option>
                        <option value="closed">Closed</option>
                    </select>
                    <select id="ticketPriority" style="flex:1;min-width:130px;padding:8px 10px;border:1px solid var(--border);border-radius:var(--radius-sm);font-size:13px;background:var(--bg);color:var(--text)">
                        <option value="low">Low</option>
                        <option value="normal">Normal</option>
                        <option value="high">High</option>
                        <option value="urgent">Urgent</option>
                    </select>
                </div>
                <div class="chat-window" id="ticketChatMessages"></div>
                <form class="chat-input" onsubmit="return sendAdminMessage(event)">
                    <input type="text" id="ticketChatText" placeholder="Reply to customer..." autocomplete="off">
                    <button type="submit" class="btn-primary" style="padding:9px 16px"><i class="fas fa-paper-plane"></i></button>
                </form>
                <div class="form-actions" style="margin-top:12px">
                    <button type="button" class="btn-secondary" onclick="closeModal('ticketModal')">Close</button>
                    <button type="button" class="btn-primary" onclick="saveTicketMeta()"><i class="fas fa-save"></i> Save Status</button>
                </div>
            </div>
        </div>`;
}

function openTicket(id) {
    const t = (window._allTickets || []).find(x => x.id === id);
    if (!t) return;
    window._currentTicket = t;
    document.getElementById('ticketModalTitle').textContent = t.subject;
    document.getElementById('ticketModalMeta').textContent = '#' + t.id + ' · ' + (t.user_name || 'N/A') + (t.unit_number ? ' · Unit ' + t.unit_number : '') + ' · ' + formatDate(t.created_at);
    document.getElementById('ticketModalStatus').className = 'status-indicator';
    document.getElementById('ticketModalStatus').innerHTML = `${statusDot(t.status)}<span class="${statusBadge(t.status)}">${t.status.replace('_',' ')}</span>`;
    document.getElementById('ticketStatus').value = t.status || 'open';
    document.getElementById('ticketPriority').value = t.priority || 'normal';
    document.getElementById('ticketChatMessages').innerHTML = '<div class="empty-state" style="padding:24px"><div class="spinner"></div></div>';
    document.getElementById('ticketModal').classList.add('open');
    loadAdminChat(id, t.user_name || 'Unknown');
}

async function loadAdminChat(id, fromName) {
    const msgs = await api('GET', '/admin/tickets/' + id + '/messages');
    const w = document.getElementById('ticketChatMessages');
    let html = msgs.map(m => renderChatBubble(m, m.sender_role === 'admin')).join('');
    if (!msgs.length && window._currentTicket && window._currentTicket.id === id && window._currentTicket.message) {
        html = renderChatBubble({ sender_role: 'customer', message: window._currentTicket.message, created_at: window._currentTicket.created_at || '' }, false);
    }
    w.innerHTML = html || '<div class="empty-state" style="padding:24px">No messages yet</div>';
    document.getElementById('ticketChatText').placeholder = 'Reply to ' + fromName + '...';
    document.getElementById('ticketChatText').value = '';
    w.scrollTop = w.scrollHeight;
    document.getElementById('ticketChatText').focus();
    const tc = window._currentTicket;
    if (tc && tc.unread > 0) {
        tc.unread = 0;
        api('POST', '/admin/tickets/' + id + '/read').catch(() => {});
        refreshAdminTicketsBadge();
    }
}

function sendAdminMessage(e) {
    e.preventDefault();
    const t = window._currentTicket;
    if (!t) return false;
    const input = document.getElementById('ticketChatText');
    const text = (input.value || '').trim();
    if (!text) return false;
    const w = document.getElementById('ticketChatMessages');
    w.insertAdjacentHTML('beforeend', renderChatBubble({ sender_role: 'admin', message: text, created_at: new Date().toISOString() }, true));
    w.scrollTop = w.scrollHeight;
    input.value = '';
    api('POST', '/admin/tickets/' + t.id + '/messages', { message: text }).then(() => {
        const st = document.getElementById('ticketStatus');
        if (st.value === 'open' || st.value === '') st.value = 'in_progress';
    }).catch(err => showToast(err.message, 'error'));
    return false;
}

async function saveTicketMeta() {
    const t = window._currentTicket;
    if (!t) return;
    try {
        await api('PUT', '/admin/tickets/' + t.id, {
            status: document.getElementById('ticketStatus').value,
            priority: document.getElementById('ticketPriority').value
        });
        showToast('Ticket updated');
        navigate('admin-tickets');
    } catch (e) {
        showToast(e.message, 'error');
    }
}

async function renderAdminUsers(area, titleEl, subtitleEl) {
    titleEl.textContent = 'Users';
    subtitleEl.textContent = 'Manage portal users';
    window._userPage = 1;
    window._userPageSize = 50;
    window._userQuery = '';
    window._userTotal = 0;

    area.innerHTML = `
        <div class="card">
            <div class="card-header">
                <h3>Registered Users (<span id="userCount">0</span>)</h3>
                <div class="admin-search-wrap" style="display:flex;gap:8px;align-items:center;flex:1;justify-content:flex-end;flex-wrap:wrap">
                    <input type="text" id="userSearch" placeholder="Search unit, name, email, company, phone or VA..." style="padding:8px 12px;border:1px solid var(--border);border-radius:var(--radius-sm);font-size:14px;background:var(--bg);color:var(--text);min-width:0;flex:1;max-width:280px" onkeydown="if(event.key==='Enter')reloadAdminUsers()" oninput="filterAdminUsers()">
                    <button class="btn-secondary" style="padding:8px 12px;font-size:12px" onclick="reloadAdminUsers()" title="Search"><i class="fas fa-search"></i> Search</button>
                    <label style="font-size:12px;color:var(--text-secondary)">Rows:</label>
                    <select id="userPageSizeSelector" onchange="userChangePageSize(this.value)" style="padding:8px 12px;border:1px solid var(--border);border-radius:var(--radius-sm);font-size:13px;background:var(--bg-card);color:var(--text)">
                        <option value="5">5</option>
                        <option value="10">10</option>
                        <option value="20">20</option>
                        <option value="50" selected>50</option>
                        <option value="100">100</option>
                        <option value="200">200</option>
                        <option value="500">500</option>
                        <option value="1000">1000</option>
                    </select>
                    <button class="btn-primary" onclick="openNewUserForm()"><i class="fas fa-user-plus"></i> Add User</button>
                    <button class="btn-secondary" onclick="downloadUserTemplate()"><i class="fas fa-download"></i> Template</button>
                    <button class="btn-secondary" onclick="openUserUploadModal()"><i class="fas fa-upload"></i> Upload CSV</button>
                </div>
            </div>
            <div class="card-body" style="padding:0">
                <div class="table-container">
                    <table class="users-table"><thead><tr><th>Unit</th><th>Name</th><th>Email</th><th>VA</th><th>Role</th><th>Company</th><th>Phone</th><th>Extra</th><th>Joined</th><th></th></tr></thead><tbody id="adminUsersBody">
                        <tr><td colspan="10" style="text-align:center;padding:40px;color:var(--text-muted)">Loading...</td></tr>
                    </tbody></table>
                </div>
                <div id="userPagination" style="display:flex;justify-content:space-between;align-items:center;padding:12px 16px;border-top:1px solid var(--border);flex-wrap:wrap;gap:8px">
                    <div style="display:flex;gap:8px;align-items:center;font-size:12px;color:var(--text-secondary)">
                        <span>Showing <span id="userRowsShown">0</span> of <span id="userTotalLabel">0</span> user(s)</span>
                    </div>
                    <div style="display:flex;gap:6px;align-items:center">
                        <button class="btn-secondary" style="padding:5px 10px;font-size:12px" onclick="userChangePage(-1)" id="userPrevBtn" disabled><i class="fas fa-chevron-left"></i> Prev</button>
                        <span style="font-size:13px" id="userPageLabel">Page 1 / 1</span>
                        <button class="btn-secondary" style="padding:5px 10px;font-size:12px" onclick="userChangePage(1)" id="userNextBtn" disabled>Next <i class="fas fa-chevron-right"></i></button>
                    </div>
                </div>
            </div>
        </div>
        <div class="modal-overlay" id="userUploadModal">
            <div class="modal" style="max-width:600px">
                <h3>Upload Users CSV</h3>
                <p style="color:var(--text-secondary);font-size:14px;margin-bottom:16px">
                    Upload a CSV file with columns: <code>name</code>, <code>unit_number</code>, <code>email</code>, <code>password</code>, <code>role</code>, <code>company</code>, <code>phone</code>. Only <code>name</code> and <code>unit_number</code> are required.
                </p>
                <div id="userDropZone" style="border:2px dashed var(--border);border-radius:var(--radius);padding:40px;text-align:center;cursor:pointer;transition:var(--transition);margin-bottom:16px"
                     ondragover="event.preventDefault();this.style.borderColor='var(--primary)';this.style.background='var(--primary-light)'"
                     ondragleave="this.style.borderColor='var(--border)';this.style.background='transparent'"
                     ondrop="event.preventDefault();handleUserFileDrop(event.dataTransfer.files[0]);this.style.borderColor='var(--border)';this.style.background='transparent'"
                     onclick="document.getElementById('userCsvFileInput').click()">
                    <i class="fas fa-cloud-upload-alt" style="font-size:48px;color:var(--text-muted);margin-bottom:12px;display:block"></i>
                    <p style="color:var(--text-secondary);font-weight:500">Drop CSV file here or click to browse</p>
                    <p style="font-size:12px;color:var(--text-muted);margin-top:4px">Supports .csv files exported from Excel</p>
                    <input type="file" id="userCsvFileInput" accept=".csv" style="display:none" onchange="handleUserFileDrop(this.files[0])">
                </div>
                <div id="userUploadPreview" style="display:none;margin-bottom:16px">
                    <div style="background:var(--secondary-light);color:var(--secondary);padding:12px 16px;border-radius:var(--radius-sm);display:flex;align-items:center;gap:10px">
                        <i class="fas fa-file-csv" style="font-size:20px"></i>
                        <span id="userUploadFileName" style="font-weight:600;flex:1"></span>
                        <span id="userUploadFileRows" style="font-size:13px"></span>
                    </div>
                </div>
                <div id="userUploadProgress" style="display:none;margin-bottom:16px">
                    <div style="background:var(--bg-hover);border-radius:20px;height:6px;overflow:hidden">
                        <div id="userUploadProgressBar" style="height:100%;width:0%;background:var(--primary);border-radius:20px;transition:width 0.3s"></div>
                    </div>
                    <p id="userUploadStatus" style="font-size:13px;color:var(--text-secondary);margin-top:8px"></p>
                </div>
                <div id="userUploadResults" style="display:none;margin-bottom:16px"></div>
                <div class="form-actions">
                    <button type="button" class="btn-secondary" onclick="closeModal('userUploadModal')">Close</button>
                    <button type="button" class="btn-primary" id="userUploadSubmitBtn" onclick="submitUserUpload()" disabled>
                        <i class="fas fa-upload"></i> Upload
                    </button>
                </div>
            </div>
        </div>`;
    reloadAdminUsers();
}

function renderUserRows(users) {
    return users.map(u => {
        const extraPhones = JSON.parse(u.extra_phones || '[]');
        const extraEmails = JSON.parse(u.extra_emails || '[]');
        const extras = [];
        if (extraPhones.length) extras.push(...extraPhones.map(p => '<span style="font-size:11px;display:block">📞 ' + escHtml(p) + '</span>'));
        if (extraEmails.length) extras.push(...extraEmails.map(e => '<span style="font-size:11px;display:block">✉ ' + escHtml(e) + '</span>'));
        const email = escHtml(u.email || '');
        const phone = escHtml(u.phone || '—');
        const company = escHtml(u.company || '—');
        return `<tr>
            <td data-label="Unit" style="white-space:nowrap;font-weight:600">${escHtml(u.unit_number || '—')}</td>
            <td data-label="Name"><strong style="font-size:12px">${escHtml(u.name)}</strong></td>
            <td data-label="Email" title="${email}" style="max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:12px">${email}</td>
            <td data-label="VA" style="white-space:nowrap;font-family:monospace;font-size:11px;color:var(--text-secondary)" title="${escHtml(u.virtual_account || '')}">${escHtml(u.virtual_account || '—')}</td>
            <td data-label="Role"><span class="badge ${u.role==='admin'?'badge-red':u.role==='finance'?'badge-blue':u.role==='super_admin'?'badge-green':'badge-gray'}" style="font-size:10px;padding:2px 7px">${u.role.replace('_',' ')}</span></td>
            <td data-label="Company" title="${company}" style="max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:12px">${company}</td>
            <td data-label="Phone" style="white-space:nowrap;font-size:12px">${phone}</td>
            <td data-label="Extra" style="max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:11px">${extras.length ? extras.join('') : '—'}</td>
            <td data-label="Joined" style="white-space:nowrap;font-size:11px;color:var(--text-secondary)">${formatDate(u.created_at)}</td>
            <td style="white-space:nowrap">
                <button class="btn-secondary" style="padding:3px 6px;font-size:11px" onclick="showUserForm(${u.id})" title="View"><i class="fas fa-eye"></i></button>
                <button class="btn-secondary" style="padding:3px 6px;font-size:11px" onclick="showUserForm(${u.id})" title="Edit"><i class="fas fa-edit"></i></button>
                <button class="btn-secondary" style="padding:3px 6px;font-size:11px;color:var(--danger)" onclick="deleteUser(${u.id},'${u.name.replace(/'/g,"\\'")}')" title="Delete"><i class="fas fa-trash"></i></button>
            </td>
        </tr>`;
    }).join('');
}

let _userSearchTimer = null;

function filterAdminUsers() {
    if (_userSearchTimer) clearTimeout(_userSearchTimer);
    _userSearchTimer = setTimeout(() => {
        const q = (document.getElementById('userSearch').value || '').trim();
        if (q === (window._userQuery || '')) return;
        window._userQuery = q;
        window._userPage = 1;
        reloadAdminUsers();
    }, 350);
}

async function reloadAdminUsers() {
    const q = window._userQuery || '';
    const url = '/admin/users?q=' + encodeURIComponent(q) + '&page=' + (window._userPage||1) + '&page_size=' + window._userPageSize;
    try {
        const res = await api('GET', url);
        const users = res.users || [];
        window._userTotal = res.total || users.length;
        const body = document.getElementById('adminUsersBody');
        if (body) body.innerHTML = renderUserRows(users);
        userRenderPagination();
    } catch (e) {
        showToast(e.message, 'error');
    }
}

function userChangePage(dir) {
    const next = (window._userPage||1) + dir;
    const last = Math.max(1, Math.ceil((window._userTotal||0) / window._userPageSize));
    if (next < 1 || next > last) return;
    window._userPage = next;
    reloadAdminUsers();
}

function userChangePageSize(size) {
    window._userPageSize = parseInt(size, 10) || 50;
    const sel = document.getElementById('userPageSizeSelector');
    if (sel) sel.value = String(window._userPageSize);
    window._userPage = 1;
    reloadAdminUsers();
}

function userRenderPagination() {
    const last = Math.max(1, Math.ceil((window._userTotal||0) / window._userPageSize));
    const lbl = document.getElementById('userPageLabel');
    if (lbl) lbl.textContent = 'Page ' + (window._userPage||1) + ' / ' + last;
    const total = document.getElementById('userTotalLabel');
    if (total) total.textContent = window._userTotal || 0;
    const count = document.getElementById('userCount');
    if (count) count.textContent = window._userTotal || 0;
    const rowsShown = document.getElementById('userRowsShown');
    if (rowsShown) rowsShown.textContent = Math.min(window._userPageSize || 50, window._userTotal || 0);
    const prev = document.getElementById('userPrevBtn');
    if (prev) prev.disabled = (window._userPage||1) <= 1;
    const next = document.getElementById('userNextBtn');
    if (next) next.disabled = (window._userPage||1) >= last;
}

function downloadUserTemplate() {
    const csv = 'name,unit_number,email,password,role,company,phone\n"John Doe","301",,,"customer",,\n"Jane Smith","302",,,"customer",,\n';
    const blob = new Blob([csv], { type: 'text/csv' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url; a.download = 'user_template.csv'; a.click();
    URL.revokeObjectURL(url);
}

async function showUserForm(id) {
    const isEdit = !!id;
    let user = null;
    if (isEdit) {
        user = await api('GET', '/admin/users/' + id);
    }
    if (!document.getElementById('userFormModal')) {
        const div = document.createElement('div');
        div.className = 'modal-overlay';
        div.id = 'userFormModal';
        div.innerHTML = `
            <div class="modal" style="max-width:550px">
                <h3 id="userFormTitle">Edit User</h3>
                <form onsubmit="return saveUser(event)">
                    <input type="hidden" id="userFormId">
                    <div class="form-row">
                        <div class="form-group"><label>Name</label><input type="text" id="userFormName" required oninput="const c=document.getElementById('userFormCompany'),n=this.value;if(!c.value||/^(PT|CV|PT\.|CV\.)\s/i.test(n))c.value=n"></div>
                        <div class="form-group"><label>Email</label><input type="email" id="userFormEmail"></div>
                    </div>
                    <div class="form-row">
                        <div class="form-group"><label>Unit Number</label><input type="text" id="userFormUnit" required></div>
                        <div class="form-group"><label>Role</label><select id="userFormRole"><option value="customer">Customer</option><option value="finance">Finance</option><option value="admin">Admin</option></select></div>
                    </div>
                    <div class="form-row">
                        <div class="form-group"><label>Company</label><input type="text" id="userFormCompany"></div>
                        <div class="form-group"><label>Phone</label><input type="text" id="userFormPhone"></div>
                    </div>
                    <div class="form-group"><label>Virtual Account</label><input type="text" id="userFormVA" style="font-family:monospace" placeholder="18-digit VA number"></div>
                    <div class="form-group" id="userFormPasswordGroup"><label>Password</label><input type="text" id="userFormPassword" placeholder="Leave empty to use default"></div>
                    <div class="form-actions" style="justify-content:space-between">
                        <div>
                            <button type="button" class="btn-secondary" onclick="closeModal('userFormModal')">Cancel</button>
                            <button type="submit" class="btn-primary">Save</button>
                        </div>
                        <button type="button" class="btn-secondary" id="userFormResetBtn" style="color:var(--warning)" onclick="resetUserPassword(document.getElementById('userFormId').value)"><i class="fas fa-key"></i> Reset Password</button>
                    </div>
                </form>
            </div>`;
        document.body.appendChild(div);
    }
    document.getElementById('userFormTitle').textContent = isEdit ? 'Edit User' : 'New User';
    document.getElementById('userFormId').value = isEdit ? id : '';
    document.getElementById('userFormName').value = user?.name || '';
    document.getElementById('userFormEmail').value = user?.email || '';
    document.getElementById('userFormUnit').value = user?.unit_number || '';
    document.getElementById('userFormRole').value = user?.role || 'customer';
    document.getElementById('userFormCompany').value = user?.company || '';
    document.getElementById('userFormPhone').value = user?.phone || '';
    document.getElementById('userFormVA').value = user?.virtual_account || '';
    document.getElementById('userFormPassword').value = '';
    document.getElementById('userFormPasswordGroup').style.display = isEdit ? 'none' : 'block';
    document.getElementById('userFormResetBtn').style.display = isEdit ? 'inline-flex' : 'none';
    document.getElementById('userFormModal').classList.add('open');
}

function openNewUserForm() {
    showUserForm(0);
}

async function saveUser(e) {
    e.preventDefault();
    const id = document.getElementById('userFormId').value;
    const data = {
        name: document.getElementById('userFormName').value,
        email: document.getElementById('userFormEmail').value,
        unit_number: document.getElementById('userFormUnit').value,
        role: document.getElementById('userFormRole').value,
        company: document.getElementById('userFormCompany').value,
        phone: document.getElementById('userFormPhone').value,
        virtual_account: document.getElementById('userFormVA').value
    };
    const password = document.getElementById('userFormPassword').value;
    if (password) data.password = password;
    try {
        if (id) {
            await api('PUT', '/admin/users/' + id, data);
            showToast('User updated');
        } else {
            await api('POST', '/admin/users', data);
            showToast('User created');
        }
        closeModal('userFormModal');
        navigate('admin-users');
    } catch (e) {
        showToast(e.message, 'error');
    }
    return false;
}

async function resetUserPassword(id) {
    if (!id || !confirm('Reset password to a random 8-character password?')) return;
    try {
        const res = await api('POST', '/admin/users/' + id + '/reset-password');
        showToast(res.new_password ? 'New password: ' + res.new_password : 'Password reset', 'success');
    } catch(e) { showToast(e.message, 'error'); }
}

async function deleteUser(id, name) {
    if (!confirm('Delete user "' + name + '"? This will also remove all their billing, payments, tickets, and documents.')) return;
    try {
        await api('DELETE', '/admin/users/' + id);
        showToast('User deleted');
        navigate('admin-users');
    } catch (e) {
        showToast(e.message, 'error');
    }
}

let userUploadedFile = null;

function openUserUploadModal() {
    userUploadedFile = null;
    document.getElementById('userUploadPreview').style.display = 'none';
    document.getElementById('userUploadProgress').style.display = 'none';
    document.getElementById('userUploadResults').style.display = 'none';
    document.getElementById('userUploadSubmitBtn').disabled = true;
    document.getElementById('userCsvFileInput').value = '';
    document.getElementById('userUploadModal').classList.add('open');
}

function handleUserFileDrop(file) {
    if (!file || !file.name.endsWith('.csv')) {
        showToast('Please select a .csv file', 'error');
        return;
    }
    userUploadedFile = file;
    document.getElementById('userUploadFileName').textContent = file.name;
    document.getElementById('userUploadSubmitBtn').disabled = false;
    document.getElementById('userUploadPreview').style.display = 'block';
    document.getElementById('userUploadProgress').style.display = 'none';
    document.getElementById('userUploadResults').style.display = 'none';

    const reader = new FileReader();
    reader.onload = function(e) {
        const lines = e.target.result.replace(/\r\n/g, '\n').replace(/\r/g, '\n').split('\n').filter(l => l.trim());
        document.getElementById('userUploadFileRows').textContent = Math.max(0, lines.length - 1) + ' rows';
    };
    reader.readAsText(file);
}

async function submitUserUpload() {
    if (!userUploadedFile) return;
    const btn = document.getElementById('userUploadSubmitBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Uploading...';
    document.getElementById('userUploadPreview').style.display = 'none';
    document.getElementById('userUploadProgress').style.display = 'block';
    document.getElementById('userUploadProgressBar').style.width = '50%';
    document.getElementById('userUploadStatus').textContent = 'Processing file...';

    try {
        const formData = new FormData();
        formData.append('file', userUploadedFile);
        const res = await fetch(BASE_PATH + '/api/index.php/admin/users/upload', {
            method: 'POST',
            credentials: 'same-origin',
            body: formData
        });
        const json = await res.json();
        if (!res.ok) throw new Error(json.error || 'Upload failed');

        document.getElementById('userUploadProgressBar').style.width = '100%';
        document.getElementById('userUploadStatus').textContent = `Uploaded ${json.count} user(s)`;

        const resultsDiv = document.getElementById('userUploadResults');
        resultsDiv.style.display = 'block';
        let html = '';
        if (json.uploaded && json.uploaded.length > 0) {
            html += `<div style="background:var(--secondary-light);color:var(--secondary);padding:12px 16px;border-radius:var(--radius-sm);margin-bottom:8px">
                <strong>${json.uploaded.length} user(s) created successfully</strong>
            </div>`;
        }
        if (json.errors && json.errors.length > 0) {
            html += `<div style="background:var(--danger-light);color:var(--danger);padding:12px 16px;border-radius:var(--radius-sm);margin-top:8px">
                <strong>${json.errors.length} error(s):</strong>
                ${json.errors.map(e => '<div style="font-size:13px;margin-top:4px">• ' + e + '</div>').join('')}
            </div>`;
        }
        resultsDiv.innerHTML = html;
        btn.innerHTML = '<i class="fas fa-check"></i> Done';
        setTimeout(() => navigate('admin-users'), 2000);
    } catch (e) {
        document.getElementById('userUploadStatus').textContent = 'Error: ' + e.message;
        document.getElementById('userUploadProgressBar').style.width = '0%';
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-upload"></i> Retry';
        showToast(e.message, 'error');
    }
}

/* ===== Audit Logs (Admin) ===== */
async function renderAdminLogs(area, titleEl, subtitleEl) {
    titleEl.textContent = 'Audit Logs';
    subtitleEl.textContent = 'Activity trail of logins, changes, and system events';
    if (!window._logPage) window._logPage = 1;
    if (!window._logQ) window._logQ = '';
    if (!window._logAction) window._logAction = '';
    if (!window._logFrom) window._logFrom = '';
    if (!window._logTo) window._logTo = '';
    window._logPageSize = 50;

    const qs = '?page=' + window._logPage + '&page_size=' + window._logPageSize +
        (window._logQ ? '&q=' + encodeURIComponent(window._logQ) : '') +
        (window._logAction ? '&action=' + encodeURIComponent(window._logAction) : '') +
        (window._logFrom ? '&from=' + encodeURIComponent(window._logFrom) : '') +
        (window._logTo ? '&to=' + encodeURIComponent(window._logTo) : '');
    const res = await api('GET', '/admin/logs' + qs);
    const logs = res.logs || [];
    const actions = res.actions || [];
    const total = res.total || 0;
    const pages = Math.max(1, Math.ceil(total / window._logPageSize));

    area.innerHTML = `
        <div class="card">
            <div class="card-header">
                <h3>Log Entries <span id="logCount">${total}</span></h3>
            </div>
            <div class="card-body">
                <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:14px">
                    <input type="text" id="logQ" value="${escHtml(window._logQ)}" placeholder="Search user, unit, action, details" style="flex:1;min-width:200px;padding:9px 12px;border:1px solid var(--border);border-radius:var(--radius-sm);font-size:13px;background:var(--bg);color:var(--text)">
                    <select id="logActionSel" style="padding:9px 12px;border:1px solid var(--border);border-radius:var(--radius-sm);font-size:13px;background:var(--bg);color:var(--text)">
                        <option value="">All actions</option>
                        ${actions.map(a => `<option value="${escHtml(a)}"${window._logAction===a?' selected':''}>${escHtml(a)}</option>`).join('')}
                    </select>
                    <input type="date" id="logFrom" value="${escHtml(window._logFrom)}" style="padding:9px 12px;border:1px solid var(--border);border-radius:var(--radius-sm);font-size:13px;background:var(--bg);color:var(--text)">
                    <span style="color:var(--text-muted);font-size:13px">to</span>
                    <input type="date" id="logTo" value="${escHtml(window._logTo)}" style="padding:9px 12px;border:1px solid var(--border);border-radius:var(--radius-sm);font-size:13px;background:var(--bg);color:var(--text)">
                    <button class="btn-primary" style="padding:9px 16px;font-size:13px" onclick="applyLogFilters()"><i class="fas fa-search"></i> Filter</button>
                    <button class="btn-secondary" style="padding:9px 16px;font-size:13px" onclick="resetLogFilters()"><i class="fas fa-undo"></i> Reset</button>
                </div>
                <div class="table-container">
                    <table><thead><tr>
                        <th>Time</th><th>User</th><th>Action</th><th>Details</th><th>IP Address</th>
                    </tr></thead><tbody>
                        ${logs.length === 0 ? '<tr><td colspan="5" style="text-align:center;padding:28px;color:var(--text-muted)">No log entries found</td></tr>' :
                        logs.map(l => `<tr>
                            <td data-label="Time" style="white-space:nowrap;font-size:12px">${escHtml(l.created_at)}</td>
                            <td data-label="User" style="white-space:nowrap;font-size:12px"><strong>${escHtml(l.user_name || '—')}</strong>${l.unit_number ? '<br><span style="color:var(--text-muted);font-size:11px">' + escHtml(l.unit_number) + '</span>' : ''}</td>
                            <td data-label="Action"><span class="badge badge-gray" style="font-size:11px;text-transform:lowercase">${escHtml(l.action)}</span></td>
                            <td data-label="Details" style="font-size:13px;max-width:420px">${escHtml(l.details || '')}</td>
                            <td data-label="IP" style="white-space:nowrap;font-family:monospace;font-size:11px;color:var(--text-secondary)">${escHtml(l.ip_address || '—')}</td>
                        </tr>`).join('')}
                    </tbody></table>
                </div>
                <div style="display:flex;justify-content:space-between;align-items:center;padding:12px 4px 0;flex-wrap:wrap;gap:8px">
                    <span style="font-size:12px;color:var(--text-secondary)">Showing ${logs.length} of ${total} entries</span>
                    <div style="display:flex;gap:6px;align-items:center">
                        <button class="btn-secondary" style="padding:5px 10px;font-size:12px" onclick="changeLogPage(-1)" ${window._logPage<=1?'disabled':''}><i class="fas fa-chevron-left"></i> Prev</button>
                        <span style="font-size:13px">Page ${window._logPage} / ${pages}</span>
                        <button class="btn-secondary" style="padding:5px 10px;font-size:12px" onclick="changeLogPage(1)" ${window._logPage>=pages?'disabled':''}>Next <i class="fas fa-chevron-right"></i></button>
                    </div>
                </div>
            </div>
        </div>`;
}

function applyLogFilters() {
    window._logQ = document.getElementById('logQ').value.trim();
    window._logAction = document.getElementById('logActionSel').value;
    window._logFrom = document.getElementById('logFrom').value;
    window._logTo = document.getElementById('logTo').value;
    window._logPage = 1;
    loadPage('admin-logs');
}

function resetLogFilters() {
    window._logQ = '';
    window._logAction = '';
    window._logFrom = '';
    window._logTo = '';
    window._logPage = 1;
    loadPage('admin-logs');
}

function changeLogPage(delta) {
    window._logPage = Math.max(1, window._logPage + delta);
    loadPage('admin-logs');
}

function showLoginPage() {
    document.getElementById('publicHeader').style.display = 'none';
    document.getElementById('publicContent').style.display = 'none';
    document.getElementById('publicFooter').style.display = 'none';
    document.getElementById('loginPage').style.display = 'flex';
    document.getElementById('loadingScreen').style.display = 'none';
}

async function showPublicNews() {
    document.getElementById('publicHeader').style.display = 'block';
    document.getElementById('publicContent').style.display = 'block';
    document.getElementById('publicFooter').style.display = 'block';
    document.getElementById('loginPage').style.display = 'none';
    document.getElementById('loadingScreen').style.display = 'none';
    try {
        const data = await api('GET', '/news');
        renderPublicNews(data);
    } catch(e) {
        document.getElementById('publicContent').innerHTML = '<div class="empty-state"><i class="fas fa-newspaper"></i><h3>News</h3><p>Unable to load news at this time.</p></div>';
    }
}

function renderPublicNews(data) {
    const area = document.getElementById('publicContent');
    const allNews = data.news || [];
    const featured = data.featured && data.featured.length ? data.featured : allNews.slice(0, 5);

    if (!allNews.length) {
        area.innerHTML = '<div class="empty-state"><i class="fas fa-newspaper"></i><h3>No news yet</h3></div>';
        return;
    }

    const categories = ['all', ...new Set(allNews.map(n => n.category).filter(Boolean))];

    area.innerHTML = `
        <div style="margin-bottom:24px">
            <h2 style="font-size:26px;font-weight:800;color:var(--text);margin-bottom:4px">News & Updates</h2>
            <p style="color:var(--text-secondary);font-size:15px">Latest news, announcements, and events from the mall</p>
        </div>

        ${featured.length ? `
        <div class="public-carousel" id="publicCarousel">
            ${featured.map((n, i) => `
                <div class="featured-slide ${i === 0 ? 'active' : ''}" ${n.image && !isInstagramUrl(n.image) ? `style="background-image:url('${resolveMediaUrl(n.image)}')"` : 'style="background:linear-gradient(135deg,var(--primary) 0%,var(--primary-dark) 100%)"'} onclick="openPublicNews(${n.id})">
                    <div class="featured-overlay">
                        ${n.category ? `<span class="category-tag">${n.category}</span>` : ''}
                        <h2>${n.title}</h2>
                        <p>${(n.content || n.description || '').slice(0, 150)}${(n.content || n.description || '').length > 150 ? '...' : ''}</p>
                        <span class="date"><i class="far fa-calendar-alt"></i> ${formatDate(n.created_at)}</span>
                    </div>
                </div>
            `).join('')}
            <button class="carousel-arrow prev" onclick="event.stopPropagation();prevPublicSlide()"><i class="fas fa-chevron-left"></i></button>
            <button class="carousel-arrow next" onclick="event.stopPropagation();nextPublicSlide()"><i class="fas fa-chevron-right"></i></button>
            <div class="carousel-dots">
                ${featured.map((_, i) => `<span class="carousel-dot ${i === 0 ? 'active' : ''}" onclick="event.stopPropagation();goPublicSlide(${i})"></span>`).join('')}
            </div>
        </div>` : ''}

        ${categories.length > 1 ? `
        <div class="news-portal-tabs" id="newsTabs">
            ${categories.map(c => `<button class="${c === 'all' ? 'active' : ''}" data-cat="${c}" onclick="filterPublicNews('${c}')">${c.charAt(0).toUpperCase() + c.slice(1)}</button>`).join('')}
        </div>
        ` : ''}

        <div class="news-portal-grid" id="newsGrid">
            ${allNews.map(n => `
            <div class="news-portal-card" onclick="openPublicNews(${n.id})" style="cursor:pointer">
                ${n.image && !isInstagramUrl(n.image) ? `<img class="news-portal-card-img" src="${resolveMediaUrl(n.image)}" alt="${n.title}" loading="lazy">` : `<div style="height:200px;background:linear-gradient(135deg,var(--primary-light) 0%,var(--bg) 100%);display:flex;align-items:center;justify-content:center">${isInstagramUrl(n.image) ? '<i class="fab fa-instagram" style="font-size:40px;color:var(--primary)"></i>' : '<i class="fas fa-newspaper" style="font-size:40px;color:var(--text-muted)"></i>'}</div>`}
                <div class="news-portal-card-body">
                    ${n.category ? `<span class="category-tag">${n.category}</span>` : ''}
                    <h3>${n.title}</h3>
                    <p>${(n.content || n.description || '').length > 120 ? (n.content || n.description || '').slice(0, 120) + '...' : (n.content || n.description || '')}</p>
                    <div class="card-footer">
                        <span><i class="far fa-calendar-alt"></i> ${formatDate(n.created_at)}</span>
                        <span style="color:var(--primary);font-weight:600;font-size:13px">Read more <i class="fas fa-arrow-right" style="font-size:11px"></i></span>
                    </div>
                </div>
            </div>
            `).join('')}
        </div>
    `;

    window._publicNews = allNews;

    // Start auto-slide
    if (featured.length > 1) {
        if (window._pubCarouselInterval) clearInterval(window._pubCarouselInterval);
        window._pubSlideIndex = 0;
        window._pubCarouselInterval = setInterval(() => {
            const slides = document.querySelectorAll('#publicCarousel .featured-slide');
            const dots = document.querySelectorAll('#publicCarousel .carousel-dot');
            if (!slides.length) return;
            slides.forEach(s => s.classList.remove('active'));
            dots.forEach(d => d.classList.remove('active'));
            window._pubSlideIndex = (window._pubSlideIndex + 1) % slides.length;
            slides[window._pubSlideIndex].classList.add('active');
            dots[window._pubSlideIndex].classList.add('active');
        }, 8000);
    }
}

function goPublicSlide(index) {
    window._pubSlideIndex = index;
    document.querySelectorAll('#publicCarousel .featured-slide').forEach((s, i) => s.classList.toggle('active', i === index));
    document.querySelectorAll('#publicCarousel .carousel-dot').forEach((d, i) => d.classList.toggle('active', i === index));
}

function prevPublicSlide() {
    const slides = document.querySelectorAll('#publicCarousel .featured-slide');
    if (!slides.length) return;
    goPublicSlide((window._pubSlideIndex - 1 + slides.length) % slides.length);
}

function nextPublicSlide() {
    const slides = document.querySelectorAll('#publicCarousel .featured-slide');
    if (!slides.length) return;
    goPublicSlide((window._pubSlideIndex + 1) % slides.length);
}

function filterPublicNews(category) {
    document.querySelectorAll('.news-portal-tabs button').forEach(b => b.classList.toggle('active', b.dataset.cat === category));
    const all = window._publicNews || [];
    const filtered = category === 'all' ? all : all.filter(n => n.category === category);
    const grid = document.getElementById('newsGrid');
    if (!grid) return;
    if (!filtered.length) {
        grid.innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:60px 20px;color:var(--text-muted)"><i class="fas fa-filter" style="font-size:32px;margin-bottom:12px;display:block"></i><p>No articles in this category</p></div>';
        return;
    }
    grid.innerHTML = filtered.map(n => `
        <div class="news-portal-card" onclick="openPublicNews(${n.id})" style="cursor:pointer">
            ${n.image && !isInstagramUrl(n.image) ? `<img class="news-portal-card-img" src="${resolveMediaUrl(n.image)}" alt="${n.title}" loading="lazy">` : `<div style="height:200px;background:linear-gradient(135deg,var(--primary-light) 0%,var(--bg) 100%);display:flex;align-items:center;justify-content:center">${isInstagramUrl(n.image) ? '<i class="fab fa-instagram" style="font-size:40px;color:var(--primary)"></i>' : '<i class="fas fa-newspaper" style="font-size:40px;color:var(--text-muted)"></i>'}</div>`}
            <div class="news-portal-card-body">
                ${n.category ? `<span class="category-tag">${n.category}</span>` : ''}
                <h3>${n.title}</h3>
                <p>${(n.content || n.description || '').length > 120 ? (n.content || n.description || '').slice(0, 120) + '...' : (n.content || n.description || '')}</p>
                <div class="card-footer">
                    <span><i class="far fa-calendar-alt"></i> ${formatDate(n.created_at)}</span>
                    <span style="color:var(--primary);font-weight:600;font-size:13px">Read more <i class="fas fa-arrow-right" style="font-size:11px"></i></span>
                </div>
            </div>
        </div>
    `).join('');
}

function escAttr(s) { return String(s ?? '').replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }
function escHtml(s) { return String(s ?? '').replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }

function isInstagramUrl(url) {
    return url && /instagram\.com\/(p|reel|tv)\//.test(url);
}

function getInstaEmbedUrl(url) {
    const m = url.match(/(p|reel|tv)\/([^/?]+)/);
    return m ? `https://www.instagram.com/${m[1]}/${m[2]}/embed/captioned` : null;
}

function renderImageField(url, alt) {
    if (!url) return '';
    if (isInstagramUrl(url)) {
        const embedUrl = getInstaEmbedUrl(url);
        if (embedUrl) {
            return `<div style="margin:16px 0;display:flex;justify-content:center"><iframe src="${embedUrl}" width="400" height="480" frameborder="0" scrolling="no" allowtransparency="true" style="max-width:100%;border-radius:var(--radius-sm);background:#fff"></iframe><p style="font-size:12px;color:var(--text-muted);text-align:center;margin-top:4px">Instagram embed — may be blocked by network</p></div>`;
        }
    }
    return `<img src="${escAttr(resolveMediaUrl(url))}" alt="${escAttr(alt)}" style="width:100%;max-height:60vh;object-fit:contain;border-radius:var(--radius-sm);margin-bottom:16px;background:var(--bg)">`;
}

function renderEmbed(code) {
    if (!code) return '';
    const trimmed = code.trim();
    let instaUrl = null;
    if (trimmed.indexOf('<') === 0) {
        const urlMatch = trimmed.match(/instagram\.com\/(p|reel)\/([^/?\"]+)/);
        if (urlMatch) instaUrl = `https://www.instagram.com/${urlMatch[1]}/${urlMatch[2]}/embed/captioned`;
    }
    if (!instaUrl && trimmed.match(/instagram\.com\/(p|reel)\//)) {
        const m = trimmed.match(/(p|reel)\/([^/?]+)/);
        if (m) instaUrl = `https://www.instagram.com/${m[1]}/${m[2]}/embed/captioned`;
    }
    if (instaUrl) {
        return `<div style="margin:16px 0;display:flex;justify-content:center"><iframe src="${instaUrl}" width="400" height="480" frameborder="0" scrolling="no" allowtransparency="true" style="max-width:100%;border-radius:var(--radius-sm);background:#fff"></iframe><p style="font-size:12px;color:var(--text-muted);text-align:center;margin-top:4px">Instagram embed — may be blocked by network</p></div>`;
    }
    if (trimmed.match(/tiktok\.com\/@/)) {
        const clean = trimmed.replace(/\?.*$/, '');
        return `<div style="margin:16px 0;display:flex;justify-content:center"><iframe src="${clean}?embed=1" width="325" height="580" frameborder="0" allowfullscreen style="max-width:100%;border-radius:var(--radius-sm)"></iframe></div>`;
    }
    return '';
}

function openPublicNews(id) {
    const all = window._publicNews || [];
    const n = all.find(item => item.id === id);
    if (!n) return;
    const overlay = document.createElement('div');
    overlay.className = 'modal-overlay open';
    overlay.onclick = function(e) { if (e.target === this) this.remove(); };
    overlay.innerHTML = `
        <div class="modal" style="max-width:720px" onclick="event.stopPropagation()">
            ${renderImageField(n.image, n.title)}
            <div style="margin-bottom:8px">
                ${n.category ? `<span class="category-tag" style="display:inline-block;background:var(--primary-light);color:var(--primary);padding:3px 10px;border-radius:12px;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;margin-right:8px">${n.category}</span>` : ''}
                <span style="font-size:13px;color:var(--text-muted)"><i class="far fa-calendar-alt"></i> ${formatDate(n.created_at)}</span>
            </div>
            <h2 style="margin-bottom:16px;font-size:24px;font-weight:700">${n.title}</h2>
            <div style="color:var(--text-secondary);line-height:1.8;font-size:15px">${(n.content || n.description || '').replace(/\n/g, '<br>')}</div>
            ${n.embed_url ? renderEmbed(n.embed_url) : ''}
            <div class="form-actions" style="margin-top:20px">
                <button type="button" class="btn-secondary" onclick="this.closest('.modal-overlay').remove()">Close</button>
            </div>
        </div>
    `;
    document.body.appendChild(overlay);
}

/* ===== Manage Payments (Admin) ===== */
async function renderAdminPayments(area, titleEl, subtitleEl) {
    titleEl.textContent = 'Manage Payments';
    subtitleEl.textContent = 'View and manage tenant payments';
    window._payPage = 1;
    window._payPageSize = 50;
    window._payQuery = '';
    window._payPeriod = '';
    window._payMethod = '';
    window._payTotal = 0;

    area.innerHTML = `
        <div class="card">
            <div class="card-header">
                <h3>Payments (<span id="payCount">0</span>)</h3>
                <div class="admin-search-wrap" style="display:flex;gap:8px;align-items:center;flex:1;justify-content:flex-end;flex-wrap:wrap">
                    <input type="text" id="paySearch" placeholder="Search unit, name, invoice, reference, method..." style="padding:8px 12px;border:1px solid var(--border);border-radius:var(--radius-sm);font-size:14px;background:var(--bg);color:var(--text);min-width:0;flex:1;max-width:300px" onkeydown="if(event.key==='Enter')reloadAdminPayments()" oninput="filterAdminPayments()">
                    <button class="btn-secondary" style="padding:8px 12px;font-size:12px" onclick="reloadAdminPayments()" title="Search"><i class="fas fa-search"></i> Search</button>
                    <label style="font-size:12px;color:var(--text-secondary)">Period:</label>
                    <select id="payPeriodSel" onchange="paymentFilterChanged()" style="padding:8px 12px;border:1px solid var(--border);border-radius:var(--radius-sm);font-size:13px;background:var(--bg-card);color:var(--text)">
                        <option value="">All periods</option>
                    </select>
                    <label style="font-size:12px;color:var(--text-secondary)">Rows:</label>
                    <select id="payPageSizeSelector" onchange="paymentChangePageSize(this.value)" style="padding:8px 12px;border:1px solid var(--border);border-radius:var(--radius-sm);font-size:13px;background:var(--bg-card);color:var(--text)">
                        <option value="5">5</option>
                        <option value="10">10</option>
                        <option value="20">20</option>
                        <option value="50" selected>50</option>
                        <option value="100">100</option>
                        <option value="200">200</option>
                        <option value="500">500</option>
                        <option value="1000">1000</option>
                    </select>
                    <button class="btn-primary" onclick="showPaymentForm()"><i class="fas fa-plus"></i> Add Payment</button>
                </div>
            </div>
            <div class="card-body" style="padding:0">
                <div class="table-container">
                    <table class="users-table"><thead><tr><th>ID</th><th>Unit</th><th>Name</th><th>Invoice</th><th>Period</th><th>Amount</th><th>Method</th><th>Reference</th><th>Paid At</th><th></th></tr></thead><tbody id="adminPaymentsBody">
                        <tr><td colspan="10" style="text-align:center;padding:40px;color:var(--text-muted)">Loading...</td></tr>
                    </tbody></table>
                </div>
                <div id="payPagination" style="display:flex;justify-content:space-between;align-items:center;padding:12px 16px;border-top:1px solid var(--border);flex-wrap:wrap;gap:8px">
                    <div style="display:flex;gap:8px;align-items:center;font-size:12px;color:var(--text-secondary)">
                        <span>Showing <span id="payRowsShown">0</span> of <span id="payTotalLabel">0</span> payment(s)</span>
                    </div>
                    <div style="display:flex;gap:6px;align-items:center">
                        <button class="btn-secondary" style="padding:5px 10px;font-size:12px" onclick="paymentChangePage(-1)" id="payPrevBtn" disabled><i class="fas fa-chevron-left"></i> Prev</button>
                        <span style="font-size:13px" id="payPageLabel">Page 1 / 1</span>
                        <button class="btn-secondary" style="padding:5px 10px;font-size:12px" onclick="paymentChangePage(1)" id="payNextBtn" disabled>Next <i class="fas fa-chevron-right"></i></button>
                    </div>
                </div>
            </div>
        </div>`;
    loadPaymentPeriods();
    reloadAdminPayments();
}

function renderPaymentRows(rows) {
    if (!rows.length) return '<tr><td colspan="10" style="text-align:center;padding:40px;color:var(--text-muted)">No payments found</td></tr>';
    return rows.map(p => `
        <tr>
            <td data-label="ID" style="white-space:nowrap;font-size:11px;color:var(--text-secondary);font-family:monospace">${p.id}</td>
            <td data-label="Unit" style="white-space:nowrap;font-weight:600">${escHtml(p.unit_number || '—')}</td>
            <td data-label="Name" style="max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:12px" title="${escHtml(p.user_name || '')}">${escHtml(p.user_name || '—')}</td>
            <td data-label="Invoice" style="white-space:nowrap;font-size:12px" title="${escHtml(p.invoice_no || '')}">${escHtml(p.invoice_no || '—')}</td>
            <td data-label="Period" style="white-space:nowrap;font-size:12px">${escHtml(p.billing_period || '—')}</td>
            <td data-label="Amount" style="white-space:nowrap;font-weight:600">${formatCurrency(p.amount)}</td>
            <td data-label="Method" style="white-space:nowrap;font-size:12px">${escHtml(p.method || '—')}</td>
            <td data-label="Reference" style="white-space:nowrap;font-family:monospace;font-size:11px;color:var(--text-secondary)" title="${escHtml(p.reference || '')}">${escHtml(p.reference || '—')}</td>
            <td data-label="Paid At" style="white-space:nowrap;font-size:11px;color:var(--text-secondary)">${formatDate(p.paid_at)}</td>
            <td style="white-space:nowrap">
                <button class="btn-secondary" style="padding:3px 6px;font-size:11px" onclick="showPaymentForm(${p.id})" title="Edit"><i class="fas fa-edit"></i></button>
                <button class="btn-secondary" style="padding:3px 6px;font-size:11px;color:var(--danger)" onclick="deletePayment(${p.id})" title="Delete"><i class="fas fa-trash"></i></button>
            </td>
        </tr>`).join('');
}

async function loadPaymentPeriods() {
    try {
        const res = await api('GET', '/admin/billing?page_size=1');
        const sel = document.getElementById('payPeriodSel');
        if (!sel) return;
        (res.periods || []).forEach(p => {
            const opt = document.createElement('option');
            opt.value = p;
            opt.textContent = p;
            sel.appendChild(opt);
        });
    } catch (e) { /* non-fatal */ }
}

function paymentFilterChanged() {
    window._payPeriod = document.getElementById('payPeriodSel').value || '';
    window._payPage = 1;
    reloadAdminPayments();
}

let _paySearchTimer = null;

function filterAdminPayments() {
    if (_paySearchTimer) clearTimeout(_paySearchTimer);
    _paySearchTimer = setTimeout(() => {
        const q = (document.getElementById('paySearch').value || '').trim();
        if (q === (window._payQuery || '')) return;
        window._payQuery = q;
        window._payPage = 1;
        reloadAdminPayments();
    }, 350);
}

async function reloadAdminPayments() {
    const q = window._payQuery || '';
    const period = window._payPeriod || '';
    const url = '/admin/payments?q=' + encodeURIComponent(q) + '&period=' + encodeURIComponent(period) + '&page=' + (window._payPage||1) + '&page_size=' + window._payPageSize;
    try {
        const res = await api('GET', url);
        const payments = res.payments || [];
        window._payTotal = res.total || payments.length;
        const body = document.getElementById('adminPaymentsBody');
        if (body) body.innerHTML = renderPaymentRows(payments);
        paymentRenderPagination();
    } catch (e) {
        showToast(e.message, 'error');
    }
}

function paymentChangePage(dir) {
    const next = (window._payPage||1) + dir;
    const last = Math.max(1, Math.ceil((window._payTotal||0) / window._payPageSize));
    if (next < 1 || next > last) return;
    window._payPage = next;
    reloadAdminPayments();
}

function paymentChangePageSize(size) {
    window._payPageSize = parseInt(size, 10) || 50;
    const sel = document.getElementById('payPageSizeSelector');
    if (sel) sel.value = String(window._payPageSize);
    window._payPage = 1;
    reloadAdminPayments();
}

function paymentRenderPagination() {
    const last = Math.max(1, Math.ceil((window._payTotal||0) / window._payPageSize));
    const lbl = document.getElementById('payPageLabel');
    if (lbl) lbl.textContent = 'Page ' + (window._payPage||1) + ' / ' + last;
    const total = document.getElementById('payTotalLabel');
    if (total) total.textContent = window._payTotal || 0;
    const count = document.getElementById('payCount');
    if (count) count.textContent = window._payTotal || 0;
    const rowsShown = document.getElementById('payRowsShown');
    if (rowsShown) rowsShown.textContent = Math.min(window._payPageSize || 50, window._payTotal || 0);
    const prev = document.getElementById('payPrevBtn');
    if (prev) prev.disabled = (window._payPage||1) <= 1;
    const next = document.getElementById('payNextBtn');
    if (next) next.disabled = (window._payPage||1) >= last;
}

/* ===== Duplicate Payments (Admin) ===== */
async function renderAdminDuplicates(area, titleEl, subtitleEl) {
    titleEl.textContent = 'Duplicate Payments';
    subtitleEl.textContent = 'Payments with the same billing period and the same amount (possible double bookings)';
    area.innerHTML = `
        <div class="card">
            <div class="card-header">
                <h3>Duplicate Groups (<span id="dupCount">0</span>)</h3>
                <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                    <label style="font-size:12px;color:var(--text-secondary)">Period:</label>
                    <select id="dupPeriodSel" onchange="reloadAdminDuplicates()" style="padding:8px 12px;border:1px solid var(--border);border-radius:var(--radius-sm);font-size:13px;background:var(--bg-card);color:var(--text)">
                        <option value="">All periods</option>
                    </select>
                    <button class="btn-secondary" style="padding:8px 12px;font-size:12px" onclick="reloadAdminDuplicates()"><i class="fas fa-sync"></i> Refresh</button>
                    <button class="btn-primary" style="background:var(--danger);border-color:var(--danger);padding:8px 12px;font-size:12px" onclick="removeAllDuplicates()"><i class="fas fa-trash"></i> Remove all duplicates</button>
                </div>
            </div>
            <div class="card-body" style="padding:0">
                <div class="table-container">
                    <table class="users-table"><thead><tr><th>Period</th><th>Unit</th><th>Tenant</th><th>Amount</th><th>#</th><th>Invoices</th><th>Payments</th></tr></thead><tbody id="dupBody">
                        <tr><td colspan="7" style="text-align:center;padding:40px;color:var(--text-muted)">Loading...</td></tr>
                    </tbody></table>
                </div>
            </div>
        </div>`;
    const sel = document.getElementById('dupPeriodSel');
    try {
        const pres = await api('GET', '/admin/billing?page_size=1');
        (pres.periods || []).forEach(p => {
            const opt = document.createElement('option');
            opt.value = p;
            opt.textContent = p;
            sel.appendChild(opt);
        });
    } catch (e) { /* periods optional */ }
    reloadAdminDuplicates();
}

async function reloadAdminDuplicates() {
    const period = (document.getElementById('dupPeriodSel') || {}).value || '';
    const url = '/admin/payments/duplicates' + (period ? '?period=' + encodeURIComponent(period) : '');
    try {
        const res = await api('GET', url);
        const list = res.duplicates || [];
        const count = document.getElementById('dupCount');
        if (count) count.textContent = list.length;
        const body = document.getElementById('dupBody');
        if (!body) return;
        if (!list.length) {
            body.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:40px;color:var(--text-muted)">No duplicate payments found for this filter</td></tr>';
            return;
        }
        body.innerHTML = list.map(g => `
            <tr>
                <td data-label="Period" style="white-space:nowrap;font-size:12px">${escHtml(g.billing_period || '—')}</td>
                <td data-label="Unit" style="white-space:nowrap;font-weight:600">${escHtml(g.unit_number || '—')}</td>
                <td data-label="Tenant" style="max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:12px" title="${escHtml(g.user_name || '')}">${escHtml(g.user_name || '—')}</td>
                <td data-label="Amount" style="white-space:nowrap;font-weight:600">${formatCurrency(g.amount)}</td>
                <td data-label="#" style="white-space:nowrap">
                    <span style="display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;background:${g.same_invoice ? 'var(--danger);color:#fff' : 'var(--warning);color:#222'}">x${g.cnt}</span>
                </td>
                <td data-label="Invoices" style="font-size:12px;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${escHtml(g.invoices || '')}">${escHtml(g.invoices || '—')}</td>
                <td data-label="Payments" style="max-width:420px">
                    ${(g.payments || []).map(p => `
                        <div style="font-size:11px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                            <span style="font-family:monospace;color:var(--text-secondary)">#${p.id}</span>
                            <span style="color:var(--text-secondary)">·</span> ${escHtml(p.method || '—')}
                            <span style="color:var(--text-secondary)">·</span> <span style="font-family:monospace" title="${escHtml(p.reference || '')}">${escHtml(p.reference || '—')}</span>
                            <span style="color:var(--text-secondary)">·</span> ${formatDate(p.paid_at)}
                            <button class="btn-secondary" style="padding:0 4px;font-size:10px;margin-left:6px;color:var(--danger)" onclick="deletePayment(${p.id}, () => reloadAdminDuplicates())" title="Delete this duplicate payment #${p.id}"><i class="fas fa-trash"></i></button>
                        </div>`).join('') || '—'}
                </td>
            </tr>`).join('');
    } catch (e) {
        showToast(e.message, 'error');
    }
}

async function removeAllDuplicates() {
    const period = (document.getElementById('dupPeriodSel') || {}).value || '';
    const scope = period ? ' for period ' + period : '';
    const groups = (document.getElementById('dupCount') || {}).textContent || '0';
    if (!confirm('Remove ALL duplicate payments' + scope + '?\n\nFor each group on the same invoice, the EARLIEST payment is kept and all extra matching payments are deleted. Affected bills are recalculated automatically.\n\n' + groups + ' group(s) affected.')) return;
    try {
        const res = await api('POST', '/admin/payments/duplicates/remove-all' + (period ? '?period=' + encodeURIComponent(period) : ''));
        showToast('Removed ' + (res.count || 0) + ' duplicate payments in ' + (res.groups || 0) + ' group(s)');
        reloadAdminDuplicates();
    } catch (e) {
        showToast(e.message, 'error');
    }
}

async function showPaymentForm(id) {
    const isEdit = !!id;
    let p = null, periods = [];
    if (isEdit) {
        try { p = await api('GET', '/admin/payments/' + id); }
        catch (e) { showToast(e.message, 'error'); return; }
    }
    try {
        const pres = await api('GET', '/admin/billing?page_size=1');
        periods = pres.periods || [];
    } catch (e) { /* periods optional */ }
    if (isEdit && p && p.billing_period && !periods.includes(p.billing_period)) periods.unshift(p.billing_period);
    if (document.getElementById('payFormModal')) document.getElementById('payFormModal').remove();
    const overlay = document.createElement('div');
    overlay.className = 'modal-overlay';
    overlay.style.display = 'flex';
    overlay.id = 'payFormModal';
    const billingArea = isEdit ? `
        <div class="form-group">
            <label>Billing Period (target bill)</label>
            <select id="payPeriodTarget" data-orig="${escHtml(p.billing_period || '')}" style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:var(--radius-sm);font-size:14px;background:var(--bg-card);color:var(--text)" title="Move this payment to the selected period's bill for the same unit">
                <option value="">(keep current)</option>
                ${periods.map(pr => `<option value="${escHtml(pr)}" ${pr === p.billing_period ? 'selected' : ''}>${escHtml(pr)}</option>`).join('')}
            </select>
            <p style="font-size:11px;color:var(--text-muted);margin-top:4px">Changing period moves the payment to that period's invoice for ${escHtml(p.unit_number || 'this unit')}.</p>
        </div>` : `
        <div class="form-group">
            <label>Unit Number or Billing ID</label>
            <input type="text" id="payUnitLookup" autocomplete="off" placeholder="e.g. GF-0A156, or billing id 18853" style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:var(--radius-sm);font-size:14px;background:var(--bg);color:var(--text)">
            <p style="font-size:11px;color:var(--text-muted);margin-top:4px">Enter a unit number (with optional billing_period below) or a billing id to attach this payment to a bill.</p>
        </div>
        <div class="form-group">
            <label>Billing Period (optional)</label>
            <input type="text" id="payPeriodLookup" autocomplete="off" placeholder="e.g. Sep 2026" style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:var(--radius-sm);font-size:14px;background:var(--bg);color:var(--text)">
        </div>`;
    overlay.innerHTML = `
        <div class="modal" style="max-width:500px">
            <h3>${isEdit ? ('Payment #' + p.id) : 'Add Payment'}</h3>
            ${isEdit ? `<p style="font-size:12px;color:var(--text-secondary);margin-bottom:12px">${escHtml(p.unit_number || '')} · ${escHtml(p.invoice_no || '')} · ${escHtml(p.billing_period || '')}</p>` : ''}
            <form onsubmit="submitPaymentForm(event, ${isEdit ? p.id : 'null'})">
                ${billingArea}
                <div class="form-group">
                    <label>Amount (Rp)</label>
                    <input type="number" step="0.01" min="0.01" id="payAmount" value="${isEdit ? p.amount : ''}" required style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:var(--radius-sm);font-size:14px;background:var(--bg);color:var(--text)">
                </div>
                <div class="form-group">
                    <label>Method</label>
                    <select id="payMethod" style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:var(--radius-sm);font-size:14px;background:var(--bg);color:var(--text)">
                        <option value="">Select method</option>
                        ${['Bank Transfer','Transfer Bank','Tunai/Cash','QRIS','Virtual Account','Kartu Kredit','Lainnya'].map(m => `<option value="${m}" ${(p && String(p.method).trim() === m) ? 'selected' : ''}>${m}</option>`).join('')}
                    </select>
                </div>
                <div class="form-group">
                    <label>Reference</label>
                    <input type="text" id="payReference" autocomplete="off" value="${isEdit ? escHtml(p.reference || '') : ''}" placeholder="Optional; generated if blank" style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:var(--radius-sm);font-size:14px;background:var(--bg);color:var(--text)">
                </div>
                <div class="form-group">
                    <label>Paid At (date)</label>
                    <input type="date" id="payPaidAt" value="${isEdit ? (p.paid_at || '').slice(0, 10) : new Date().toISOString().slice(0, 10)}" style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:var(--radius-sm);font-size:14px;background:var(--bg);color:var(--text)">
                </div>
                <div class="form-actions">
                    <button type="button" class="btn-secondary" onclick="this.closest('.modal-overlay').remove()">Cancel</button>
                    <button type="submit" class="btn-primary"><i class="fas fa-check"></i> ${isEdit ? 'Update' : 'Save'}</button>
                </div>
            </form>
        </div>`;
    document.body.appendChild(overlay);
}

async function submitPaymentForm(e, id) {
    e.preventDefault();
    const amount = parseFloat(document.getElementById('payAmount').value);
    const method = document.getElementById('payMethod').value;
    const reference = document.getElementById('payReference').value.trim();
    const paid_at = document.getElementById('payPaidAt').value;
    const isEdit = !!id;
    const data = { amount, method, reference, paid_at };
    try {
        if (isEdit) {
            const ps = document.getElementById('payPeriodTarget');
            if (ps && ps.value && ps.value !== (ps.dataset.orig || '')) data.billing_period = ps.value;
            await api('PUT', '/admin/payments/' + id, data);
            showToast('Payment updated');
        } else {
            const billingId = parseInt((document.getElementById('payUnitLookup').value || '').trim(), 10);
            if (billingId > 0) data.billing_id = billingId;
            else {
                const unit = document.getElementById('payUnitLookup').value.trim();
                const period = document.getElementById('payPeriodLookup').value.trim();
                if (unit) data.unit_number = unit;
                if (period) data.billing_period = period;
                if (!unit) { showToast('Enter a unit number or billing id', 'error'); return; }
            }
            await api('POST', '/admin/payments', data);
            showToast('Payment created');
        }
        document.getElementById('payFormModal').remove();
        reloadAdminPayments();
    } catch (err) {
        showToast(err.message, 'error');
    }
}

async function deletePayment(id, onDone) {
    if (!confirm('Delete this payment record?')) return;
    try {
        await api('DELETE', '/admin/payments/' + id);
        showToast('Payment deleted');
        (onDone || reloadAdminPayments)();
    } catch (e) {
        showToast(e.message, 'error');
    }
}

/* ===== Send Billing via WhatsApp (Admin) ===== */
const WA_DEFAULT_TEMPLATE = [
    'Halo {name},',
    '',
    'Berikut tagihan untuk Unit {unit_number}:',
    '',
    'Invoice       : {invoice_no}',
    'Periode       : {billing_period}',
    'Jumlah        : {amount}',
    'Jatuh tempo   : {due_date}',
    '',
    'Silakan lakukan pembayaran melalui Virtual Account:',
    'VA: {virtual_account}',
    '',
    'Terima kasih,',
    'Manajemen Mall'
].join('\n');

async function renderAdminBillingReport(area, titleEl, subtitleEl) {
    titleEl.textContent = 'Billing Report';
    subtitleEl.textContent = 'Filter and list units by billing status (Paid, Unpaid, Kurang Bayar, Lebih Bayar)';
    if (typeof window._reportPeriod === 'undefined') window._reportPeriod = '';
    if (typeof window._reportStatus === 'undefined') window._reportStatus = '';
    if (typeof window._reportUnit === 'undefined') window._reportUnit = '';
    if (typeof window._reportInitialized === 'undefined') window._reportInitialized = false;

    area.innerHTML = `
        <div class="card">
            <div class="card-header">
                <h3>Billing Report</h3>
                <div class="admin-search-wrap" style="display:flex;gap:8px;align-items:center;flex:1;justify-content:flex-start;flex-wrap:wrap">
                    <label style="font-size:12px;color:var(--text-secondary)">Month:</label>
                    <select id="reportPeriodSel" onchange="reportFilterChanged()" style="padding:8px 12px;border:1px solid var(--border);border-radius:var(--radius-sm);font-size:13px;background:var(--bg-card);color:var(--text)">
                        <option value="">All periods</option>
                    </select>
                    <label style="font-size:12px;color:var(--text-secondary)">Status:</label>
                    <select id="reportStatusSel" onchange="reportFilterChanged()" style="padding:8px 12px;border:1px solid var(--border);border-radius:var(--radius-sm);font-size:13px;background:var(--bg-card);color:var(--text)">
                        <option value="">All status</option>
                        <option value="unpaid">Unpaid</option>
                        <option value="paid">Paid</option>
                        <option value="kurang">Kurang Bayar</option>
                        <option value="lebih">Lebih Bayar</option>
                    </select>
                    <label style="font-size:12px;color:var(--text-secondary)">Unit:</label>
                    <input id="reportUnitInput" type="text" placeholder="e.g. B1-A1 / tenant name" value="${escHtml(window._reportUnit)}"
                        style="padding:8px 12px;border:1px solid var(--border);border-radius:var(--radius-sm);font-size:13px;background:var(--bg-card);color:var(--text);min-width:180px"
                        onkeydown="if(event.key==='Enter')reportFilterChanged()">
                    <button class="btn-secondary" onclick="reportFilterChanged()"><i class="fas fa-search"></i> Filter</button>
                    <button class="btn-primary" onclick="exportBillingReportXlsx()"><i class="fas fa-file-excel"></i> Export XLSX</button>
                    <button class="btn-secondary" onclick="exportBillingReportPdf()"><i class="fas fa-file-pdf"></i> Export PDF</button>
                </div>
            </div>
            <div class="card-body">
                <div id="reportStats" style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:18px"></div>
                <h4 style="margin:0 0 8px">Summary per period</h4>
                <div class="table-container">
                    <table class="users-table"><thead><tr>
                        <th>Period</th><th>Invoices</th><th>Amount</th><th>Paid</th><th>Outstanding</th><th>Outstanding Invoices</th>
                        <th>Rent</th><th>Service Charge</th><th>Electricity</th><th>Water</th><th>Gas</th><th>Sinking Fund</th><th>Other Rev</th><th>Fine</th>
                    </tr></thead><tbody id="reportBody">
                        <tr><td colspan="14" style="text-align:center;padding:40px;color:var(--text-muted)">Loading...</td></tr>
                    </tbody></table>
                </div>
                <h4 style="margin:20px 0 8px">Units (filtered by status)</h4>
                <div class="table-container">
                    <table class="users-table"><thead><tr>
                        <th style="text-align:left">Unit</th><th style="text-align:left">Tenant</th><th style="text-align:left">Period</th><th style="text-align:left">Invoice No</th><th>Amount</th><th>Paid</th><th>Outstanding</th><th style="text-align:left">Status</th>
                    </tr></thead><tbody id="reportUnitBody">
                        <tr><td colspan="8" style="text-align:center;padding:40px;color:var(--text-muted)">Loading...</td></tr>
                    </tbody></table>
                </div>
                <div id="reportUnitPager"></div>
            </div>
        </div>`;
    loadBillingReport();
}

async function loadBillingReport() {
    const period = window._reportPeriod || '';
    const status = window._reportStatus || '';
    const unit = window._reportUnit || '';
    const qs = new URLSearchParams();
    if (period) qs.set('period', period);
    if (status) qs.set('status', status);
    if (unit) qs.set('unit', unit);
    const res = await api('GET', '/admin/report-billing?' + qs.toString());
    const periods = res.periods || [];
    if (!window._reportInitialized && periods.length) {
        window._reportInitialized = true;
        window._reportPeriod = periods[0];
        return loadBillingReport();
    }
    window._reportInitialized = true;
    const rows = res.rows || [];
    const unitRows = res.unit_rows || [];
    window._reportRows = rows;
    window._reportUnitRows = unitRows;

    const sel = document.getElementById('reportPeriodSel');
    if (sel) {
        sel.innerHTML = '<option value="">All periods</option>' + periods.map(p => `<option value="${escHtml(p)}"${p === period ? ' selected' : ''}>${escHtml(p)}</option>`).join('');
    }
    const uin = document.getElementById('reportUnitInput');
    if (uin && uin.value !== unit) uin.value = unit;

    const statsEl = document.getElementById('reportStats');
    if (statsEl) {
        const t = { invoices: 0, amount: 0, paid: 0, outstanding: 0 };
        unitRows.forEach(r => { t.invoices++; t.amount += Number(r.amount || 0); t.paid += Number(r.paid || 0); t.outstanding += Number(r.outstanding || 0); });
        const cards = [
            { label: 'Invoices', value: Number(t.invoices) },
            { label: 'Billed', value: formatCurrency(t.amount) },
            { label: 'Paid', value: formatCurrency(t.paid) },
            { label: 'Outstanding', value: formatCurrency(t.outstanding) },
        ];
        statsEl.innerHTML = cards.map(s => `<div style="flex:1;min-width:170px;background:var(--bg);border:1px solid var(--border);border-radius:var(--radius-sm);padding:12px 16px">
            <div style="font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:var(--text-muted)">${s.label}</div>
            <div style="font-size:18px;font-weight:700;margin-top:4px">${s.value}</div></div>`).join('');
    }

    const tbody = document.getElementById('reportBody');
    if (!rows.length) {
        if (tbody) tbody.innerHTML = '<tr><td colspan="14" style="text-align:center;padding:40px;color:var(--text-muted)">No billing data found</td></tr>';
    } else {
        const tot = { invoices: 0, amount: 0, paid: 0, outstanding: 0, outstanding_invoices: 0, rent: 0, service_charge: 0, electricity: 0, water: 0, gas: 0, sinking_fund: 0, other_rev: 0, fine: 0 };
        rows.forEach(r => { Object.keys(tot).forEach(k => { tot[k] += Number(r[k] || 0); }); });
        const rowCells = r => `<td style="white-space:nowrap;font-weight:600">${escHtml(r.period)}</td>` +
            `<td>${Number(r.invoices || 0)}</td>` +
            `<td>${formatCurrency(r.amount)}</td>` +
            `<td>${formatCurrency(r.paid)}</td>` +
            `<td style="color:${Number(r.outstanding) > 0 ? 'var(--danger)' : 'var(--text-secondary)'}">${formatCurrency(r.outstanding)}</td>` +
            `<td>${Number(r.outstanding_invoices || 0)}</td>` +
            `<td>${formatCurrency(r.rent)}</td>` +
            `<td>${formatCurrency(r.service_charge)}</td>` +
            `<td>${formatCurrency(r.electricity)}</td>` +
            `<td>${formatCurrency(r.water)}</td>` +
            `<td>${formatCurrency(r.gas)}</td>` +
            `<td>${formatCurrency(r.sinking_fund)}</td>` +
            `<td>${formatCurrency(r.other_rev)}</td>` +
            `<td>${formatCurrency(r.fine)}</td>`;
        tbody.innerHTML = rows.map(r => '<tr>' + rowCells(r) + '</tr>').join('') +
            `<tr style="font-weight:700;border-top:2px solid var(--border);background:var(--bg)">` + rowCells({ period: 'TOTAL', ...tot }) + `</tr>`;
    }

    window._reportUnitPage = 1;
    renderUnitTable();
}

const REPORT_UNIT_PAGE_SIZE = 100;

function renderUnitTable() {
    const unitRows = window._reportUnitRows || [];
    const ub = document.getElementById('reportUnitBody');
    const pager = document.getElementById('reportUnitPager');
    if (!ub) return;
    if (!unitRows.length) {
        ub.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:40px;color:var(--text-muted)">No units match the selected filters</td></tr>';
        if (pager) pager.innerHTML = '';
        return;
    }
    const totalPages = Math.ceil(unitRows.length / REPORT_UNIT_PAGE_SIZE);
    let page = window._reportUnitPage || 1;
    if (page < 1) page = 1;
    if (page > totalPages) page = totalPages;
    window._reportUnitPage = page;
    const slice = unitRows.slice((page - 1) * REPORT_UNIT_PAGE_SIZE, page * REPORT_UNIT_PAGE_SIZE);
    ub.innerHTML = slice.map(unitRowCells).join('');
    if (pager) {
        const t = { amount: 0, paid: 0, outstanding: 0 };
        unitRows.forEach(r => { t.amount += Number(r.amount || 0); t.paid += Number(r.paid || 0); t.outstanding += Number(r.outstanding || 0); });
        pager.innerHTML = `<div style="display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;padding:10px 16px;border-top:1px solid var(--border)">
            <button class="btn-secondary" onclick="reportUnitPage(-1)" ${page <= 1 ? 'disabled' : ''}><i class="fas fa-chevron-left"></i> Prev</button>
            <span style="font-size:13px;color:var(--text-secondary)">Page ${page} of ${totalPages} · <strong style="font-weight:700;color:var(--text)">${Number(unitRows.length).toLocaleString('id-ID')} units</strong> · Billed <strong style="font-weight:700;color:var(--text)">${formatCurrency(t.amount)}</strong> · Paid <strong style="font-weight:700;color:var(--text)">${formatCurrency(t.paid)}</strong> · Outstanding <strong style="font-weight:700;color:var(--text)">${formatCurrency(t.outstanding)}</strong></span>
            <button class="btn-secondary" onclick="reportUnitPage(1)" ${page >= totalPages ? 'disabled' : ''}>Next <i class="fas fa-chevron-right"></i></button>
        </div>`;
    }
}

function reportUnitPage(delta) {
    window._reportUnitPage = (window._reportUnitPage || 1) + delta;
    renderUnitTable();
}

function unitRowCells(r) {
    return `<tr>` +
        `<td style="white-space:nowrap;font-weight:600;text-align:left">${escHtml(r.unit_number)}</td>` +
        `<td style="white-space:nowrap;text-align:left">${escHtml(r.user_name || '')}</td>` +
        `<td style="white-space:nowrap;text-align:left">${escHtml(r.period || '')}</td>` +
        `<td style="white-space:nowrap;text-align:left">${escHtml(r.invoice_no || '')}</td>` +
        `<td>${formatCurrency(r.amount)}</td>` +
        `<td>${formatCurrency(r.paid)}</td>` +
        `<td style="color:${Number(r.outstanding) > 0 ? 'var(--danger)' : 'var(--text-secondary)'}">${formatCurrency(r.outstanding)}</td>` +
        `<td style="text-align:left"><span class="${statusBadge(r.status)}">${escHtml(r.status)}</span></td>` +
        `</tr>`;
}

function reportFilterChanged() {
    window._reportPeriod = document.getElementById('reportPeriodSel').value;
    window._reportStatus = document.getElementById('reportStatusSel').value;
    window._reportUnit = (document.getElementById('reportUnitInput').value || '').trim();
    loadBillingReport();
}

function exportBillingReportXlsx() {
    let url = BASE_PATH + '/api/index.php/admin/report-billing?format=xlsx';
    if (window._reportPeriod) url += '&period=' + encodeURIComponent(window._reportPeriod);
    if (window._reportStatus) url += '&status=' + encodeURIComponent(window._reportStatus);
    if (window._reportUnit) url += '&unit=' + encodeURIComponent(window._reportUnit);
    window.location.href = url;
}

function exportBillingReportPdf() {
    const rows = window._reportRows || [];
    const unitRows = window._reportUnitRows || [];
    const period = window._reportPeriod || 'All Periods';
    const unit = window._reportUnit || 'All Units';
    const statusLabel = { 'paid': 'Paid', 'unpaid': 'Unpaid', 'kurang': 'Kurang Bayar', 'lebih': 'Lebih Bayar' }[window._reportStatus || ''] || 'All';
    const w = window.open('', '_blank');
    if (!w) { showToast('Please allow popups to export PDF'); return; }
    const rp = Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 });
    const money = n => 'Rp' + rp.format(Number(n || 0));
    const num = n => Number(n || 0).toLocaleString('id-ID');
    const tot = { invoices: 0, amount: 0, paid: 0, outstanding: 0, outstanding_invoices: 0, rent: 0, service_charge: 0, electricity: 0, water: 0, gas: 0, sinking_fund: 0, other_rev: 0, fine: 0 };
    rows.forEach(r => { Object.keys(tot).forEach(k => { tot[k] += Number(r[k] || 0); }); });
    const tr = r => `<tr><td>${escHtml(r.period)}</td><td>${num(r.invoices)}</td><td>${money(r.amount)}</td><td>${money(r.paid)}</td><td>${money(r.outstanding)}</td><td>${num(r.outstanding_invoices)}</td><td>${money(r.rent)}</td><td>${money(r.service_charge)}</td><td>${money(r.electricity)}</td><td>${money(r.water)}</td><td>${money(r.gas)}</td><td>${money(r.sinking_fund)}</td><td>${money(r.other_rev)}</td><td>${money(r.fine)}</td></tr>`;
    const uTot = { amount: 0, paid: 0, outstanding: 0 };
    unitRows.forEach(r => { uTot.amount += Number(r.amount || 0); uTot.paid += Number(r.paid || 0); uTot.outstanding += Number(r.outstanding || 0); });
    const utr = r => `<tr><td>${escHtml(r.unit_number)}</td><td>${escHtml(r.user_name || '')}</td><td>${escHtml(r.period || '')}</td><td>${escHtml(r.invoice_no || '')}</td><td>${money(r.amount)}</td><td>${money(r.paid)}</td><td>${money(r.outstanding)}</td><td>${escHtml(r.status)}</td></tr>`;
    w.document.write(`<!DOCTYPE html><html><head><meta charset="utf-8"><title>Billing Report</title><style>
        body{font-family:Arial,sans-serif;color:#222;margin:24px}
        h1{font-size:20px;margin:0 0 2px}h2{font-size:13px;font-weight:400;color:#555;margin:0 0 16px}
        h3{font-size:14px;margin:20px 0 8px}
        table{border-collapse:collapse;width:100%;font-size:11px}
        th,td{border:1px solid #ccc;padding:5px 7px;text-align:right;white-space:nowrap}
        th{background:#f0f0f0}td:first-child,th:first-child{text-align:left}
        table.units th:nth-child(2),table.units td:nth-child(2),
        table.units th:nth-child(3),table.units td:nth-child(3),
        table.units th:nth-child(4),table.units td:nth-child(4),
        table.units th:nth-child(8),table.units td:nth-child(8){text-align:left}
        tr.total td{font-weight:700;background:#fafafa}
        @media print{body{margin:8mm}}
    </style></head><body>
        <h1>Mangga Dua Square — Billing Report</h1>
        <h2>Period: ${escHtml(period)} · Status: ${escHtml(statusLabel)} · Unit: ${escHtml(unit)}</h2>
        <h3>Summary per period</h3>
        <table><thead><tr>
            <th>Period</th><th>Invoices</th><th>Amount</th><th>Paid</th><th>Outstanding</th><th>O/S Invoices</th>
            <th>Rent</th><th>Service Charge</th><th>Electricity</th><th>Water</th><th>Gas</th><th>Sinking Fund</th><th>Other Rev</th><th>Fine</th>
        </tr></thead><tbody>
            ${rows.map(tr).join('')}
            <tr class="total">${tr({ period: 'TOTAL', ...tot })}</tr>
        </tbody></table>
        <h3>Units (${num(unitRows.length)})</h3>
        <table class="units"><thead><tr>
            <th>Unit</th><th>Tenant</th><th>Period</th><th>Invoice No</th><th>Amount</th><th>Paid</th><th>Outstanding</th><th>Status</th>
        </tr></thead><tbody>
            ${unitRows.map(utr).join('')}
            <tr class="total"><td colspan="4">TOTAL</td><td>${money(uTot.amount)}</td><td>${money(uTot.paid)}</td><td>${money(uTot.outstanding)}</td><td></td></tr>
        </tbody></table>
        <script>setTimeout(function(){window.print();},400);<\/script>
    </body></html>`);
    w.document.close();
}

async function renderAdminWaBilling(area, titleEl, subtitleEl) {
    titleEl.textContent = 'Send Billing WA';
    subtitleEl.textContent = 'Send tenant invoices via WhatsApp';

    if (!window._waPeriodKey) window._waPeriodKey = '';
    if (!window._waQuery) window._waQuery = '';
    if (!window._waPage) window._waPage = 1;
    window._waPageSize = 50;

    const [usersRes, billsRes, settings] = await Promise.all([
        api('GET', '/admin/users'),
        api('GET', '/admin/billing?paid=unpaid&period=' + encodeURIComponent(window._waPeriodKey || '') + '&q=' + encodeURIComponent(window._waQuery || '') + '&page=' + window._waPage + '&page_size=50'),
        api('GET', '/admin/wa-settings').catch(() => null)
    ]);
    const users = usersRes || [];
    const bills = billsRes.bills || [];
    window._waUsers = users;
    window._waBills = bills;
    window._waTotal = billsRes.total || bills.length;
    window._waPeriods = billsRes.periods || [];
    window._waCurrentBill = null;
    window._waTemplate = (settings && settings.template) ? settings.template : WA_DEFAULT_TEMPLATE;

    area.innerHTML = `
        <div class="tabs">
            <button type="button" class="tab active" id="waTabBtnSend" onclick="waSwitchTab('send')"><i class="fab fa-whatsapp"></i> Send WA</button>
            <button type="button" class="tab" id="waTabBtnSettings" onclick="waSwitchTab('settings')"><i class="fas fa-cog"></i> Setting WA</button>
        </div>

        <div id="waTabSend">
            <div class="card">
                <div class="card-header">
                    <h3>Unpaid Invoices (<span id="waCount">${waPendingBills().length}</span>)</h3>
                    <div class="filter-bar" style="margin:0;gap:10px;align-items:center;flex-wrap:wrap;width:100%">
                        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                            <div class="wa-search">
                                <i class="fas fa-search wa-search-icon"></i>
                                <input type="search" id="waTableSearch" autocomplete="off" placeholder="Search unit, name, invoice or VA..." onkeydown="if(event.key==='Enter')waSearch()" oninput="waSearch()">
                            </div>
                            <button class="btn-secondary" style="padding:8px 12px;font-size:12px" onclick="waSearch()" title="Search"><i class="fas fa-search"></i> Search</button>
                        </div>
                        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                            <select id="waPeriodFilter" style="padding:8px 12px;border:1px solid var(--border);border-radius:var(--radius-sm);font-size:13px;background:var(--bg-card);color:var(--text)">
                                <option value="">All Months</option>
                                ${(window._waPeriods||[]).map(p => `<option value="${escHtml(p)}"${window._waPeriodKey===p?' selected':''}>${escHtml(p)}</option>`).join('')}
                            </select>
                            <button class="btn-secondary" style="padding:8px 12px;font-size:12px" onclick="waFilterPeriod()" title="Apply month filter"><i class="fas fa-filter"></i> Filter</button>
                        </div>
                        <div style="display:flex;gap:8px;align-items:center;margin-left:auto">
                            <label style="font-size:12px;color:var(--text-secondary)">Rows:</label>
                            <select id="waPageSize" onchange="waChangePageSize(this.value)" style="padding:8px 12px;border:1px solid var(--border);border-radius:var(--radius-sm);font-size:13px;background:var(--bg-card);color:var(--text)">
                                <option value="50" ${window._waPageSize==50?'selected':''}>50</option>
                                <option value="100" ${window._waPageSize==100?'selected':''}>100</option>
                                <option value="200" ${window._waPageSize==200?'selected':''}>200</option>
                                <option value="500" ${window._waPageSize==500?'selected':''}>500</option>
                                <option value="1000" ${window._waPageSize==1000?'selected':''}>1000</option>
                            </select>
                        </div>
                        <div style="display:flex;gap:8px;align-items:center;margin-left:12px;padding-left:12px;border-left:1px solid var(--border)">
                            <button class="btn-wa" id="waSendSelectedBtn" onclick="waSendSelected()" disabled style="white-space:nowrap">
                                <i class="fab fa-whatsapp"></i> Send Selected <span class="wa-badge" id="waSelectedCount">0</span>
                            </button>
                            <button class="btn-secondary" style="display:none;white-space:nowrap" onclick="waClearSelection()" id="waClearSelBtn">
                                <i class="fas fa-times"></i> Clear
                            </button>
                        </div>
                    </div>
                </div>
                <div class="card-body" style="padding:0">
                    <div class="table-container">
                        <table>
                            <thead><tr>
                                <th style="width:34px"><input type="checkbox" id="waSelectAll" onchange="waToggleSelectAll(this.checked)" title="Select all on this page"></th>
                                <th>Invoice #</th><th>Unit</th><th>Tenant</th><th>Period</th><th>VA</th><th>Amount</th><th>Due</th><th>Status</th><th>WA</th><th></th>
                            </tr></thead>
                            <tbody id="waSendBody">${renderWaSendRows()}</tbody>
                        </table>
                    </div>
                    <div id="waPagination" style="display:flex;justify-content:space-between;align-items:center;padding:12px 16px;border-top:1px solid var(--border);flex-wrap:wrap;gap:8px">
                        <div style="display:flex;gap:8px;align-items:center;font-size:12px;color:var(--text-secondary)">
                            <span>Showing <span id="waRowsShown">${bills.length}</span> of <span id="waTotalLabel">${window._waTotal||0}</span> unpaid invoice(s)</span>
                        </div>
                        <div style="display:flex;gap:6px;align-items:center">
                            <button class="btn-secondary" style="padding:5px 10px;font-size:12px" onclick="waChangePage(-1)" ${window._waPage<=1?'disabled':''}><i class="fas fa-chevron-left"></i> Prev</button>
                            <span style="font-size:13px" id="waPageLabel">Page ${window._waPage} / ${Math.max(1,Math.ceil((window._waTotal||0)/window._waPageSize))}</span>
                            <button class="btn-secondary" style="padding:5px 10px;font-size:12px" onclick="waChangePage(1)" ${window._waPage>=Math.ceil((window._waTotal||0)/window._waPageSize)?'disabled':''}>Next <i class="fas fa-chevron-right"></i></button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div id="waTabSettings" style="display:none">
            <div class="card">
                <div class="card-header"><h3>Setting WA</h3></div>
                <div class="card-body">
                    <p style="color:var(--text-secondary);font-size:13px;margin-bottom:16px">
                        Store the WhatsApp provider token server-side so it is never exposed to browsers.
                    </p>
                    <div class="form-group"><label>Provider</label>
                        <select id="waSetProvider" style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:var(--radius-sm);font-size:14px;background:var(--bg);color:var(--text)">
                            <option value="whatsapp_cloud">WhatsApp Cloud API</option>
                            <option value="fonnte">Fonnte</option>
                            <option value="silvanix">Silvanix</option>
                            <option value="wablas">Wablas</option>
                            <option value="twilio">Twilio</option>
                        </select>
                    </div>
                    <div class="form-group"><label>API Token</label>
                        <input type="password" id="waSetToken" placeholder="Fonnte/Wablas token, Twilio accountSid:authToken, or Cloud API bearer token" style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:var(--radius-sm);font-size:14px;background:var(--bg);color:var(--text)">
                    </div>
                    <div class="form-group"><label>From / Sender</label>
                        <input type="text" id="waSetFrom" placeholder="Twilio WhatsApp sender or WhatsApp Cloud Phone Number ID (optional)" style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:var(--radius-sm);font-size:14px;background:var(--bg);color:var(--text)">
                    </div>
                    <div class="form-group"><label>Default Message Template</label>
                        <div class="form-row" style="align-items:flex-start">
                            <div style="flex:1;min-width:0">
                                <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:8px">
                                    ${['name','unit_number','invoice_number','billing_period','virtual_account','total','due_date','other_rev','electricity','water','gas','sinking_fund','fine','rent','service_charge'].map(t =>
                                        `<button type="button" class="btn-secondary" style="padding:3px 10px;font-size:12px" onclick="waInsertSetToken('{${t}}')">{${t}}</button>`
                                    ).join('')}
                                </div>
                                <textarea id="waSetTemplate" rows="12" style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:var(--radius-sm);font-size:14px;font-family:inherit;background:var(--bg);color:var(--text);line-height:1.6"></textarea>
                            </div>
                            <div style="width:300px;max-width:100%;background:var(--bg);border:1px solid var(--border);border-radius:var(--radius-sm);padding:10px 12px;margin-top:34px">
                                <div style="font-size:13px;font-weight:600;margin-bottom:6px">Available Variables</div>
                                ${[['name','Tenant name'],['unit_number','Unit number'],['invoice_number','Invoice number'],['billing_period','Billing period'],['virtual_account','Virtual account'],['total','Total bill amount'],['due_date','Due date'],['other_rev','Other revenue'],['electricity','Electricity'],['water','Water'],['gas','Gas'],['sinking_fund','Sinking fund'],['fine','Fine'],['rent','Rent'],['service_charge','Service charge']].map(([t,d]) =>
                                    `<div style="display:flex;align-items:center;justify-content:space-between;gap:8px;padding:3px 0;border-bottom:1px dashed var(--border)">
                                        <button type="button" class="btn-secondary" style="padding:2px 8px;font-size:11px;font-family:monospace" onclick="waInsertSetToken('{${t}}')">{${t}}</button>
                                        <span style="font-size:11px;color:var(--text-secondary)">${d}</span>
                                    </div>`).join('')}
                            </div>
                        </div>
                    </div>
                    <div class="form-group"><label>Default Send Method</label>
                        <select id="waSetMode" style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:var(--radius-sm);font-size:14px;background:var(--bg);color:var(--text)">
                            <option value="api">Send via API</option>
                            <option value="link">WhatsApp link (wa.me)</option>
                        </select>
                    </div>
                    <div class="form-group" style="margin-top:8px">
                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer"><input type="checkbox" id="waSetAttachPdf" style="width:auto" onchange="waSetTogglePdf()"> Attach invoice PDF (API)</label>
                    </div>
                    <div id="waSetPdfGroup" style="display:none" class="form-group">
                        <label>PDF URL (hosted)</label>
                        <input type="text" id="waSetPdfUrl" autocomplete="off" placeholder="https://portal.example.com/uploads/billing/INV-xxx.pdf" style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:var(--radius-sm);font-size:14px;background:var(--bg);color:var(--text)">
                    </div>
                    <div class="form-actions">
                        <button type="button" class="btn-primary" onclick="waSaveSettings()"><i class="fas fa-save"></i> Save Settings</button>
                    </div>
                </div>
            </div>
        </div>
    `;
    waLoadSettings();
}

function waPendingBills() {
    return (window._waBills || []).filter(b => b.status !== 'paid');
}

function waPhoneOf(id) {
    const u = (window._waUsers || []).find(x => x.id === id);
    return u ? (u.phone || '') : '';
}

function renderWaSendRows() {
    const rows = waPendingBills();
    let html;
    if (!rows.length) {
        html = '<tr><td colspan="11" style="text-align:center;padding:40px;color:var(--text-muted)">No unpaid invoices</td></tr>';
    } else {
        html = rows.map(b => {
            const va = (b.virtual_account || b.user_virtual_account || '').trim();
            const vaHtml = (va === '' || va === '0') ? '-' : `<span style="font-family:monospace;font-size:12px">${escHtml(va)}</span>`;
            return `<tr data-id="${b.id}">
                <td><input type="checkbox" class="wa-row-check" value="${b.id}" onchange="waUpdateSelection()"></td>
                <td><strong>${escHtml(b.invoice_no)}</strong></td>
                <td>${escHtml(b.unit_number || 'N/A')}</td>
                <td>${escHtml(b.user_name || 'N/A')}</td>
                <td>${escHtml(b.billing_period || '-')}</td>
                <td>${vaHtml}</td>
                <td>${formatCurrency(b.amount)}</td>
                <td style="font-size:13px">${formatDate(b.due_date)}</td>
                <td><span class="${statusBadge(billPaymentStatus(b))}">${billPaymentStatus(b)}</span></td>
                <td>${waStatusBadge(b.wa_status, b.wa_sent_at)}</td>
                <td><button class="btn-primary" style="padding:6px 12px;font-size:12px" onclick="waTableSend(${b.id})"><i class="fab fa-whatsapp"></i> Send WA</button></td>
            </tr>`;
        }).join('');
    }
    const body = document.getElementById('waSendBody');
    if (body) body.innerHTML = html;
    const count = document.getElementById('waCount');
    if (count) count.textContent = rows.length;
    return html;
}

function waStatusBadge(status, sentAt) {
    const s = (status || '').toString().toLowerCase();
    const map = { 'sent': 'badge-blue', 'received': 'badge-green', 'failed': 'badge-red' };
    const label = s ? s.charAt(0).toUpperCase() + s.slice(1) : 'Not sent';
    const tip = sentAt ? ` title="Sent ${escHtml(formatDate(sentAt))}"` : '';
    return `<span class="badge ${map[s] || 'badge-gray'}"${tip}>${label}</span>`;
}

let _waSearchTimer = null;

function waReloadBills() {
    const period = window._waPeriodKey || '';
    const q = window._waQuery || '';
    const url = '/admin/billing?paid=unpaid&period=' + encodeURIComponent(period) + '&q=' + encodeURIComponent(q) + '&page=' + (window._waPage||1) + '&page_size=' + window._waPageSize;
    api('GET', url).then(res => {
        window._waBills = res.bills || [];
        window._waTotal = res.total || window._waBills.length;
        const periodFilter = document.getElementById('waPeriodFilter');
        if (periodFilter) periodFilter.value = window._waPeriodKey || '';
        renderWaSendRows();
        waRenderPagination();
        waClearSelection();
    }).catch(e => showToast(e.message, 'error'));
}

function waSearch() {
    if (_waSearchTimer) clearTimeout(_waSearchTimer);
    _waSearchTimer = setTimeout(() => {
        const q = (document.getElementById('waTableSearch').value || '').trim();
        window._waQuery = q;
        window._waPage = 1;
        waReloadBills();
    }, 350);
}

function waFilterPeriod() {
    const periodFilter = (document.getElementById('waPeriodFilter').value || '').trim();
    window._waPeriodKey = periodFilter;
    window._waPage = 1;
    waReloadBills();
}

function waChangePage(dir) {
    const next = (window._waPage||1) + dir;
    const last = Math.max(1, Math.ceil((window._waTotal||0) / window._waPageSize));
    if (next < 1 || next > last) return;
    window._waPage = next;
    waReloadBills();
}

function waChangePageSize(size) {
    window._waPageSize = parseInt(size, 10) || 50;
    window._waPage = 1;
    waReloadBills();
}

function waRenderPagination() {
    const last = Math.max(1, Math.ceil((window._waTotal||0) / window._waPageSize));
    const lbl = document.getElementById('waPageLabel');
    if (lbl) lbl.textContent = 'Page ' + (window._waPage||1) + ' / ' + last;
    const total = document.getElementById('waTotalLabel');
    if (total) total.textContent = window._waTotal || 0;
    const rowsShown = document.getElementById('waRowsShown');
    if (rowsShown) rowsShown.textContent = (window._waBills || []).length;
    const count = document.getElementById('waCount');
    if (count) count.textContent = (window._waBills || []).length;
    const ps = document.getElementById('waPageSize');
    if (ps) ps.value = String(window._waPageSize);
    const body = document.getElementById('waSendBody');
    if (body) body.innerHTML = renderWaSendRows();
}

function waSwitchTab(name) {
    document.getElementById('waTabSend').style.display = name === 'send' ? 'block' : 'none';
    document.getElementById('waTabSettings').style.display = name === 'settings' ? 'block' : 'none';
    document.getElementById('waTabBtnSend').classList.toggle('active', name === 'send');
    document.getElementById('waTabBtnSettings').classList.toggle('active', name === 'settings');
}

async function waLoadSettings() {
    const settings = await api('GET', '/admin/wa-settings').catch(() => null);
    window._waSettings = settings || {};
    document.getElementById('waSetProvider').value = settings ? settings.provider : 'fonnte';
    document.getElementById('waSetToken').value = settings && settings.token_masked ? settings.api_token : '';
    document.getElementById('waSetToken').placeholder = settings && settings.token_masked
        ? 'Saved token ' + settings.api_token + ' — leave blank to keep'
        : 'Fonnte/Wablas token, Twilio accountSid:authToken, or Cloud API bearer token';
    document.getElementById('waSetFrom').value = settings ? (settings.from_number || '') : '';
    document.getElementById('waSetTemplate').value = settings && settings.template ? settings.template : WA_DEFAULT_TEMPLATE;
    document.getElementById('waSetMode').value = settings && settings.mode ? settings.mode : 'api';
    document.getElementById('waSetAttachPdf').checked = settings && Number(settings.attach_pdf) === 1;
    document.getElementById('waSetPdfUrl').value = settings ? (settings.pdf_url || '') : '';
    waSetTogglePdf();
}

async function waSaveSettings() {
    const data = {
        provider: document.getElementById('waSetProvider').value,
        from_number: document.getElementById('waSetFrom').value,
        template: document.getElementById('waSetTemplate').value,
        mode: document.getElementById('waSetMode').value,
        attach_pdf: document.getElementById('waSetAttachPdf').checked ? 1 : 0,
        pdf_url: document.getElementById('waSetPdfUrl').value.trim()
    };
    const token = document.getElementById('waSetToken').value.trim();
    if (token && token.indexOf('****') === -1) data.api_token = token;
    try {
        await api('PUT', '/admin/wa-settings', data);
        window._waTemplate = data.template;
        window._waSettings = data;
        showToast('WhatsApp settings saved');
    } catch (e) { showToast(e.message, 'error'); }
}

function waSetTogglePdf() {
    const g = document.getElementById('waSetPdfGroup');
    if (g) g.style.display = document.getElementById('waSetAttachPdf').checked ? 'block' : 'none';
}

function waInsertSetToken(token) {
    const el = document.getElementById('waSetTemplate');
    const start = el.selectionStart || 0;
    const end = el.selectionEnd || 0;
    el.value = el.value.slice(0, start) + token + el.value.slice(end);
    el.focus();
    el.setSelectionRange(start + token.length, start + token.length);
}

function waBuildMessage(bill) {
    const template = window._waTemplate || WA_DEFAULT_TEMPLATE;
    if (!bill) return template;
    const user = (window._waUsers || []).find(u => u.id === bill.user_id) || {};
    const balance = (bill.amount || 0) - (bill.paid || 0);
    let msg = template;
    const map = {
        '{name}': user.name || bill.user_name || '',
        '{unit_number}': bill.unit_number || user.unit_number || '',
        '{invoice_no}': bill.invoice_no || '',
        '{invoice_number}': bill.invoice_no || '',
        '{billing_period}': bill.billing_period || '',
        '{amount}': formatCurrency(bill.amount || 0),
        '{total}': formatCurrency(bill.amount || 0),
        '{due_date}': bill.due_date ? formatDate(bill.due_date) : '',
        '{virtual_account}': bill.virtual_account || bill.user_virtual_account || '',
        '{balance}': formatCurrency(balance),
        '{other_rev}': formatCurrency(bill.other_rev || 0),
        '{electricity}': formatCurrency(bill.electricity || 0),
        '{water}': formatCurrency(bill.water || 0),
        '{gas}': formatCurrency(bill.gas || 0),
        '{sinking_fund}': formatCurrency(bill.sinking_fund || 0),
        '{fine}': formatCurrency(bill.fine || 0),
        '{rent}': formatCurrency(bill.rent || 0),
        '{service_charge}': formatCurrency(bill.service_charge || 0)
    };
    Object.keys(map).forEach(k => { msg = msg.split(k).join(map[k]); });
    return msg;
}

async function waSendOne(id) {
    const bill = (window._waBills || []).find(b => b.id === id);
    if (!bill) return { ok: false, reason: 'Billing not found' };
    const settings = window._waSettings || {};
    const mode = settings.mode || 'api';
    const message = waBuildMessage(bill);
    const phone = waPhoneOf(bill.user_id);
    if (!phone) return { ok: false, reason: bill.unit_number + ': no phone' };
    if (!message.trim()) return { ok: false, reason: bill.invoice_no + ': empty message' };
    const data = { mode, billing_id: bill.id, phone, message };
    if (mode === 'api') {
        data.provider = settings.provider || 'fonnte';
        if (settings.attach_pdf && settings.pdf_url) { data.attach_pdf = true; data.pdf_url = settings.pdf_url; }
    }
    const res = await api('POST', '/admin/wa-billing/send', data);
    if (res.wa_status) {
        bill.wa_status = res.wa_status;
        bill.wa_sent_at = new Date().toISOString().slice(0, 19).replace('T', ' ');
        if (mode === 'api' && res.message_id) bill.wa_message_id = res.message_id;
    }
    return { ok: true, link: mode === 'link' ? (res.wa_link || '') : '' };
}

async function waTableSend(id) {
    const btn = document.querySelector(`#waSendBody tr[data-id="${id}"] button`);
    const settings = window._waSettings || {};
    const mode = settings.mode || 'api';
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>'; }
    try {
        const r = await waSendOne(id);
        if (!r.ok) { showToast(r.reason, 'error'); }
        else if (mode === 'link') { if (r.link) window.open(r.link, '_blank'); showToast('WhatsApp link opened'); }
        else { showToast('Message sent via ' + (settings.provider || 'API')); }
        renderWaSendRows();
        waClearSelection();
    } catch (e) {
        showToast(e.message, 'error');
    } finally {
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fab fa-whatsapp"></i> Send WA'; }
    }
}

function waSelectedIds() {
    return Array.from(document.querySelectorAll('.wa-row-check:checked')).map(c => parseInt(c.value, 10));
}

function waUpdateSelection() {
    const ids = waSelectedIds();
    const allPage = document.querySelectorAll('.wa-row-check');
    const selAll = document.getElementById('waSelectAll');
    if (selAll) selAll.checked = allPage.length > 0 && ids.length === allPage.length;
    waRefreshSelUi(ids.length);
}

function waToggleSelectAll(checked) {
    document.querySelectorAll('.wa-row-check').forEach(c => c.checked = checked);
    waUpdateSelection();
}

function waClearSelection() {
    document.querySelectorAll('.wa-row-check').forEach(c => c.checked = false);
    const selAll = document.getElementById('waSelectAll');
    if (selAll) selAll.checked = false;
    waRefreshSelUi(0);
}

function waRefreshSelUi(count) {
    const btn = document.getElementById('waSendSelectedBtn');
    const lbl = document.getElementById('waSelectedCount');
    const clear = document.getElementById('waClearSelBtn');
    if (lbl) lbl.textContent = count;
    if (btn) btn.disabled = count === 0;
    if (clear) clear.style.display = count === 0 ? 'none' : 'inline-flex';
}

async function waSendSelected() {
    const ids = waSelectedIds();
    if (!ids.length) { showToast('Select at least one invoice', 'error'); return; }
    const btn = document.getElementById('waSendSelectedBtn');
    const settings = window._waSettings || {};
    const mode = settings.mode || 'api';
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...'; }
    let ok = 0, fail = 0, openedLinks = 0;
    const reasons = [];
    for (const id of ids) {
        try {
            const r = await waSendOne(id);
            if (r.ok) { ok++; if (mode === 'link' && r.link) { window.open(r.link, '_blank'); openedLinks++; } }
            else { fail++; reasons.push(r.reason); }
        } catch (e) {
            fail++; reasons.push(e.message);
        }
    }
    renderWaSendRows();
    waClearSelection();
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fab fa-whatsapp"></i> Send Selected <span class="wa-badge" id="waSelectedCount">0</span>'; }
    let msg = `Sent ${ok} invoice(s)`;
    if (mode === 'link' && openedLinks) msg += ` (${openedLinks} link(s) opened)`;
    if (fail) msg += `, ${fail} failed`;
    showToast(msg, fail ? 'error' : 'success');
    if (reasons.length) { reasons.slice(0, 8).forEach(r => console.warn('WA send fail:', r)); showToast('First failure: ' + reasons[0], 'error'); }
}

/* ===== AST Payment Trx (external SQL Server) ===== */
const AST_DISPLAY_COLS = ['doc_no', 'debtor_acct', 'trx_mode', 'bank_cd', 'ref_no', 'descs', 'trx_amt', 'audit_date'];
async function renderAdminAstPaytrx(area, titleEl, subtitleEl) {
    titleEl.textContent = 'AST Payment Trx';
    subtitleEl.textContent = 'Read-only view of dbo.ar_paytrx from your SQL Server';
    window._astMonth = '';
    window._astQuery = '';
    area.innerHTML = `
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-plug"></i> SQL Server Connection</h3>
            </div>
            <div class="card-body">
                <div class="form-group" style="display:flex;flex-wrap:wrap;gap:12px">
                    <div style="flex:1;min-width:180px">
                        <label>Host</label>
                        <input type="text" id="astHost" placeholder="10.10.21.87" style="width:100%;padding:8px 12px;border:1px solid var(--border);border-radius:var(--radius-sm);background:var(--bg);color:var(--text)">
                    </div>
                    <div style="flex:0 0 90px">
                        <label>Port</label>
                        <input type="number" id="astPort" placeholder="1433" style="width:100%;padding:8px 12px;border:1px solid var(--border);border-radius:var(--radius-sm);background:var(--bg);color:var(--text)">
                    </div>
                    <div style="flex:1;min-width:180px">
                        <label>Database</label>
                        <input type="text" id="astDb" placeholder="pms_standard" style="width:100%;padding:8px 12px;border:1px solid var(--border);border-radius:var(--radius-sm);background:var(--bg);color:var(--text)">
                    </div>
                    <div style="flex:0 0 160px">
                        <label>Username</label>
                        <input type="text" id="astUser" style="width:100%;padding:8px 12px;border:1px solid var(--border);border-radius:var(--radius-sm);background:var(--bg);color:var(--text)">
                    </div>
                    <div style="flex:0 0 160px">
                        <label>Password</label>
                        <input type="password" id="astPass" placeholder="••••••" style="width:100%;padding:8px 12px;border:1px solid var(--border);border-radius:var(--radius-sm);background:var(--bg);color:var(--text)">
                    </div>
                </div>
                <div style="display:flex;gap:8px;margin-top:14px;flex-wrap:wrap;align-items:center">
                    <button class="btn-primary" onclick="saveAstSettings()"><i class="fas fa-save"></i> Save Connection</button>
                    <button class="btn-secondary" onclick="loadAstMonthSel()"><i class="fas fa-sync-alt"></i> Reload Data</button>
                    <span id="astConnStatus" style="font-size:12px;color:var(--text-secondary)">Not connected</span>
                </div>
            </div>
        </div>

        <div class="card" style="margin-top:16px">
            <div class="card-header" style="flex-direction:column;align-items:flex-start;gap:10px">
                <h3>Payments from dbo.ar_paytrx (<span id="astPayCount">0</span>)</h3>
                <div class="admin-search-wrap" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;width:100%">
                    <label style="font-size:12px;color:var(--text-secondary);white-space:nowrap">Periode / Month:</label>
                    <select id="astMonthSel" onchange="reloadAstDataFromSel()" style="padding:8px 12px;border:1px solid var(--border);border-radius:var(--radius-sm);font-size:13px;background:var(--bg-card);color:var(--text)">
                        <option value="">All months</option>
                    </select>
                    <input type="text" id="astSearch" placeholder="Search debtor_acct / doc_no / ref_no..." style="padding:8px 12px;border:1px solid var(--border);border-radius:var(--radius-sm);font-size:14px;background:var(--bg);color:var(--text);min-width:0;flex:1;max-width:360px" onkeydown="if(event.key==='Enter')reloadAstData()">
                    <button class="btn-primary" onclick="reloadAstData()"><i class="fas fa-search"></i> Search</button>
                    <select id="astPeriod" title="Book payments to this billing period (auto-matches e.g. 2026-09 / Sep 2026 / Aug-26)" style="padding:8px 12px;border:1px solid var(--border);border-radius:var(--radius-sm);font-size:13px;background:var(--bg-card);color:var(--text)"><option value="">Billing period (auto)</option></select>
                    <button class="btn-secondary" style="padding:8px 12px;font-size:13px" onclick="astUploadSelected()" id="astUploadBtn" disabled><i class="fas fa-upload"></i> Upload Selected (<span id="astSelCount">0</span>) to Payments</button>
                </div>
            </div>
            <div class="card-body" style="padding:0">
                <div class="table-container" id="astPayTableWrap" style="overflow:auto;max-height:min(62vh,760px)">
                    <table class="users-table"><thead id="astPayHead"><tr><th style="padding:40px;text-align:center;color:var(--text-muted)">Connect to load data</th></tr></thead><tbody id="astPayBody"></tbody></table>
                </div>
            </div>
        </div>`;
    loadAstSettings();
}

async function loadAstSettings() {
    try {
        const s = await api('GET', '/admin/ast-settings');
        document.getElementById('astHost').value = s.host || '';
        document.getElementById('astPort').value = s.port || 1433;
        document.getElementById('astDb').value = s.database_name || '';
        document.getElementById('astUser').value = s.username || '';
        const st = document.getElementById('astConnStatus');
        if (s.username) {
            st.textContent = `Saved: ${s.host}${s.port && s.port !== 1433 ? ':' + s.port : ''}/${s.database_name}`;
            st.style.color = 'var(--text-secondary)';
            loadAstData();
        }
    } catch (e) {
        document.getElementById('astConnStatus').textContent = e.message;
        document.getElementById('astConnStatus').style.color = 'var(--danger)';
    }
}

async function saveAstSettings() {
    const data = {
        host: document.getElementById('astHost').value.trim(),
        port: parseInt(document.getElementById('astPort').value || '1433', 10),
        database_name: document.getElementById('astDb').value.trim(),
        username: document.getElementById('astUser').value.trim(),
    };
    const pw = document.getElementById('astPass').value;
    if (pw) data.password = pw;
    try {
        await api('PUT', '/admin/ast-settings', data);
        document.getElementById('astPass').value = '';
        showToast('AST connection saved');
        loadAstSettings();
    } catch (e) {
        showToast(e.message, 'error');
    }
}

async function loadAstMonthSel(rowsRes) {
    if (rowsRes && rowsRes.months) {
        const sel = document.getElementById('astMonthSel');
        const prev = window._astMonth;
        const ok = (rowsRes.months || []).filter(m => /^\d{4}-\d{2}$/.test(m) && +m.slice(0,4) >= 2000 && +m.slice(0,4) <= 2100);
        sel.innerHTML = '<option value="">All months</option>' + ok.map(m => `<option value="${escHtml(m)}"${m === prev ? ' selected' : ''}>${escHtml(m)}</option>`).join('');
    }
    await reloadAstData();
}

async function reloadAstDataFromSel() {
    window._astMonth = document.getElementById('astMonthSel').value;
    await reloadAstData();
}

async function reloadAstData() {
    window._astSel = window._astSel || new Set();
    window._astSel.clear();
    astUpdateSelUI();
    const q = (document.getElementById('astSearch').value || '').trim();
    const month = window._astMonth || '';
    const params = new URLSearchParams();
    if (month) params.set('month', month);
    if (q) params.set('q', q);
    const qs = params.toString();
    const url = '/admin/ast-paytrx' + (qs ? '?' + qs : '');
    try {
        const res = await api('GET', url);
        document.getElementById('astPayCount').textContent = (res.total != null ? res.total : res.count) || 0;
        document.getElementById('astConnStatus').textContent = `${res.database}@${res.host} · sorted by ${res.sort_col || res.date_col || 'row order'}${res.total > 2000 ? ' · showing first 2000' : ''}`;
        document.getElementById('astConnStatus').style.color = 'var(--success)';
        const selP = document.getElementById('astPeriod');
        if (selP && res.periods && res.periods.length && selP.querySelectorAll('option').length <= 1) {
            const curP = selP.value;
            selP.innerHTML = '<option value="">Billing period (auto)</option>' + res.periods.map(p => `<option value="${escHtml(p)}">${escHtml(p)}</option>`).join('');
            if (curP) selP.value = curP;
        }
        document.getElementById('astPayHead').innerHTML = '<tr><th style="width:34px"><input type="checkbox" id="astSelAll" onclick="astToggleAll(this.checked)" title="Select all rows"></th><th>' + (res.columns || []).filter(c => AST_DISPLAY_COLS.includes(c)).map(c => escHtml(c)).join('</th><th>') + '</th></tr>';
        const tbody = document.getElementById('astPayBody');
        const cols = (res.columns || []).filter(c => AST_DISPLAY_COLS.includes(c));
        if (!res.rows || !res.rows.length) {
            tbody.innerHTML = '<tr><td colspan="' + (cols.length + 1) + '" style="text-align:center;padding:40px;color:var(--text-muted)">No rows found</td></tr>';
        } else {
            window._astRows = res.rows;
            window._astSel = window._astSel || new Set();
            window._astSel = new Set([...window._astSel].filter(i => i < res.rows.length));
            tbody.innerHTML = res.rows.map((r, i) => '<tr>' + `<td style="width:34px"><input type="checkbox" class="ast-sel" data-i="${i}" onclick="astToggleRow(this, ${i})"${window._astSel.has(i) ? ' checked' : ''}></td>` + cols.map(c => {
                let v = r[c];
                if (/amt|amount|balance|_amt$|rate|numeric/i.test(c)) {
                    const n = parseFloat(v);
                    if (!isNaN(n) && v !== '' && v !== null) v = n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                }
                if (/(date|_dt|time)/i.test(c) && v && !/^\d{4}-\d{2}-\d{2}$/.test(String(v).slice(0,10))) v = '';
                if (c === 'descs') v = String(v).trim();
                const cellStyle = c === 'descs' ? 'white-space:normal;min-width:200px;max-width:420px;font-size:12px' + (/amt|amount|balance|rate/i.test(c) ? ';text-align:right' : '') : 'white-space:nowrap;font-size:12px' + (/amt|amount|balance|rate/i.test(c) ? ';text-align:right' : '');
                return `<td data-label="${escHtml(c)}" style="${cellStyle}">${escHtml(v)}</td>`;
            }).join('') + '</tr>').join('');
        }
    } catch (e) {
        showToast(e.message, 'error');
        document.getElementById('astPayHead').innerHTML = '<tr><th style="padding:40px;text-align:center;color:var(--text-muted)">Unable to load</th></tr>';
        document.getElementById('astPayBody').innerHTML = '';
        document.getElementById('astConnStatus').textContent = e.message;
        document.getElementById('astConnStatus').style.color = 'var(--danger)';
    }
}

async function loadAstData() {
    try {
        const res = await api('GET', '/admin/ast-paytrx');
        await loadAstMonthSel(res);
    } catch (e) {
        showToast(e.message, 'error');
        document.getElementById('astConnStatus').textContent = e.message;
        document.getElementById('astConnStatus').style.color = 'var(--danger)';
    }
}

function astUpdateSelUI() {
    const boxes = document.querySelectorAll('.ast-sel');
    window._astSel = new Set([...boxes].filter(b => b.checked).map(b => parseInt(b.dataset.i, 10)));
    const cnt = window._astSel.size;
    const t = document.getElementById('astSelCount'); if (t) t.textContent = cnt;
    const b = document.getElementById('astUploadBtn'); if (b) b.disabled = cnt === 0;
    const all = document.getElementById('astSelAll'); if (all) all.checked = boxes.length > 0 && cnt === boxes.length;
}

function astToggleRow(cb, i) {
    if (cb.checked) window._astSel.add(i); else window._astSel.delete(i);
    astUpdateSelUI();
}

function astToggleAll(v) {
    document.querySelectorAll('.ast-sel').forEach(b => { b.checked = v; });
    astUpdateSelUI();
}

async function astUploadSelected() {
    window._astSel = window._astSel || new Set();
    const idx = [...window._astSel].sort((a, b) => a - b);
    if (!idx.length) { showToast('No rows selected', 'error'); return; }
    const period = (document.getElementById('astPeriod').value || '').trim();
    const rows = idx.map(i => {
        const r = (window._astRows || [])[i] || {};
        return { debtor_acct: r.debtor_acct, trx_amt: r.trx_amt, doc_no: r.doc_no, ref_no: r.ref_no, doc_date: r.doc_date, trx_mode: r.trx_mode, bank_cd: r.bank_cd };
    });
    try {
        const res = await api('POST', '/admin/ast-payments', { billing_period: period, rows });
        const errs = res.errors || [];
        const skips = res.skipped_count || 0;
        const parts = [];
        if (res.count > 0) parts.push('Recorded ' + res.count + ' payment(s)');
        if (skips > 0) parts.push(skips + ' skipped (same amount already recorded for period)');
        if (errs.length) parts.push(errs.length + ' error(s)');
        if (res.count > 0 && !errs.length) {
            showToast(parts.join(', '), skips ? 'warning' : 'success');
        } else if (res.count > 0) {
            showToast(parts.join(', '), 'warning');
        } else {
            showToast(parts.length ? parts.join(', ') : 'No payments recorded', 'error');
        }
        if (errs.length) console.warn('AST upload errors:', errs);
        window._astSel = new Set();
        astUpdateSelUI();
        reloadAstData();
    } catch (e) {
        showToast(e.message, 'error');
    }
}

/* ===== Init ===== */
async function init() {
    const savedTheme = localStorage.getItem('theme');
    if (savedTheme) document.documentElement.setAttribute('data-theme', savedTheme);
    try {
        const res = await api('GET', '/auth/me');
        if (res && res.user) {
            currentUser = res.user;
            showApp();
            navigate('dashboard');
            refreshSupportBadge();
            if (['admin', 'finance', 'super_admin'].includes(currentUser.role)) refreshAdminTicketsBadge();
            startBadgePolling();
            return;
        }
    } catch (e) {
        console.warn('Session check failed, showing public page:', e.message);
    }
    showPublicNews();
}

window.addEventListener('hashchange', function() {
    const hash = window.location.hash.replace('#','');
    if (!hash && !currentUser) showPublicNews();
});

setTimeout(() => {
    const ls = document.getElementById('loadingScreen');
    if (ls && ls.style.display !== 'none') {
        ls.style.display = 'none';
        showPublicNews();
    }
}, 8000);

init();