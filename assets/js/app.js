// public/assets/js/app.js

document.addEventListener('DOMContentLoaded', () => {
    const authSection = document.getElementById('authSection');
    const dashboardSection = document.getElementById('dashboardSection');
    const cleanSection = document.getElementById('cleanSection');

    const loginBtn = document.getElementById('loginBtn');
    const dashboardBtn = document.getElementById('dashboardBtn');
    const cleanBtn = document.getElementById('cleanBtn');
    const logoutBtn = document.getElementById('logoutBtn');

    const loginForm = document.getElementById('loginForm');
    const verifyForm = document.getElementById('verifyForm');
    const phoneNumberInput = document.getElementById('phoneNumber');
    const apiIdInput = document.getElementById('apiId');
    const apiHashInput = document.getElementById('apiHash');
    const loginCodeInput = document.getElementById('loginCode');
    const authMessage = document.getElementById('authMessage');

    const confirmCleanMode = document.getElementById('confirmCleanMode');
    const startCleanBtn = document.getElementById('startCleanBtn');
    const stopCleanBtn = document.getElementById('stopCleanBtn');
    const cleanProgressDiv = document.getElementById('cleanProgress');
    const cleanLog = document.getElementById('cleanLog');

    const accountCard = document.getElementById('accountCard');
    const statsCards = document.getElementById('statsCards');
    const dialogsList = document.getElementById('dialogsList');

    let currentSection = authSection;
    let currentCleanJobId = null;
    let cleanJobPollInterval = null;

    async function fetchWithAuth(url, options = {}) {
        const token = localStorage.getItem('jwt_token');
        if (!token) {
            showSection(authSection);
            throw new Error('Not authenticated');
        }
        options.headers = { ...options.headers, 'Authorization': `Bearer ${token}` };
        return fetch(url, options);
    }

    function showSection(section) {
        currentSection.classList.remove('active');
        currentSection.style.display = 'none';
        section.classList.add('active');
        section.style.display = 'block';
        currentSection = section;

        if (section === dashboardSection) loadDashboardData();
    }

    loginBtn.addEventListener('click', () => showSection(authSection));
    dashboardBtn.addEventListener('click', () => showSection(dashboardSection));
    cleanBtn.addEventListener('click', () => showSection(cleanSection));

    logoutBtn.addEventListener('click', async () => {
        try {
            await fetchWithAuth('/api/logout', { method: 'POST' });
            localStorage.removeItem('jwt_token');
            location.reload(); // Hard reload to clear state
        } catch (e) { console.error(e); }
    });

    loginForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const body = {
            phone_number: phoneNumberInput.value,
            api_id: apiIdInput.value,
            api_hash: apiHashInput.value
        };
        authMessage.style.display = 'none';

        try {
            const response = await fetch('/api/send-code', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body)
            });
            const data = await response.json();
            if (data.success) {
                authMessage.textContent = 'Code sent! Check Telegram.';
                authMessage.style.display = 'block';
                loginForm.style.display = 'none';
                verifyForm.style.display = 'block';
                // Store temporarily
                sessionStorage.setItem('login_data', JSON.stringify(body));
            } else {
                alert(data.message || 'Error');
            }
        } catch (error) { console.error(error); }
    });

    verifyForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const loginData = JSON.parse(sessionStorage.getItem('login_data'));
        const body = {
            ...loginData,
            code: loginCodeInput.value
        };

        try {
            const response = await fetch('/api/verify-code', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body)
            });
            const data = await response.json();
            if (data.success) {
                localStorage.setItem('jwt_token', data.token);
                sessionStorage.removeItem('login_data');
                location.reload();
            } else {
                alert(data.message || 'Error');
            }
        } catch (error) { console.error(error); }
    });

    async function loadDashboardData() {
        accountCard.innerHTML = 'Loading...';
        try {
            const resInfo = await fetchWithAuth('/api/account/info');
            const info = await resInfo.json();
            if (info.success) {
                const i = info.data;
                accountCard.innerHTML = `
                    <h3>${i.first_name} ${i.last_name || ''}</h3>
                    <p>@${i.username || 'N/A'}</p>
                    <p>${i.bio || ''}</p>
                `;
            }

            const resStats = await fetchWithAuth('/api/account/stats');
            const stats = await resStats.json();
            if (stats.success) {
                const s = stats.data;
                statsCards.innerHTML = `
                    <p>Dialogs<span>${s.total_dialogs}</span></p>
                    <p>Groups<span>${s.groups}</span></p>
                    <p>Channels<span>${s.channels}</span></p>
                    <p>Bots<span>${s.bots}</span></p>
                `;
            }
        } catch (e) { console.error(e); }
    }

    confirmCleanMode.addEventListener('change', () => {
        startCleanBtn.disabled = !confirmCleanMode.checked;
    });

    startCleanBtn.addEventListener('click', async () => {
        if (!confirm('Proceed with VOID Clean?')) return;
        
        const cleanOptions = {
            leave_groups: true,
            leave_channels: true,
            delete_bot_chats: true,
            delete_private_chats: true,
            clear_archive: true
        };

        try {
            const res = await fetchWithAuth('/api/account/clean', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ options: cleanOptions })
            });
            const data = await res.json();
            if (data.success) {
                cleanLog.innerHTML = '<li>Process Started...</li>';
                cleanProgressDiv.style.display = 'block';
            }
        } catch (e) { console.error(e); }
    });

    const token = localStorage.getItem('jwt_token');
    if (token) {
        loginBtn.style.display = 'none';
        dashboardBtn.style.display = 'block';
        cleanBtn.style.display = 'block';
        logoutBtn.style.display = 'block';
        showSection(dashboardSection);
    }
});
