const $ = (selector, scope = document) => scope.querySelector(selector);

function esc(value) {
  return String(value ?? '').replace(/[&<>"']/g, character => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
  })[character]);
}

function toast(message, type = 'success') {
  let notice = $('.toast');
  if (!notice) {
    notice = document.createElement('div');
    notice.className = 'toast';
    notice.setAttribute('role', 'status');
    document.body.append(notice);
  }
  notice.className = `toast ${type === 'error' ? 'toast-error' : ''}`;
  notice.textContent = message;
  notice.hidden = false;
  clearTimeout(toast.timer);
  toast.timer = setTimeout(() => { notice.hidden = true; }, 3600);
}

function table(headers, rows) {
  const head = headers.map(header => `<th scope="col">${esc(header)}</th>`).join('');
  const body = rows.map(row => `<tr>${row.map(cell => {
    if (cell && typeof cell === 'object') {
      const className = /^[a-z0-9 _-]+$/i.test(cell.className || '') ? cell.className : '';
      return `<td><span class="${className}">${esc(cell.text)}</span></td>`;
    }
    return `<td>${esc(cell)}</td>`;
  }).join('')}</tr>`).join('');
  return `<div class="table-wrap"><table><thead><tr>${head}</tr></thead><tbody>${body || `<tr><td class="empty-cell" colspan="${headers.length}">No records to show.</td></tr>`}</tbody></table></div>`;
}

function grade(total) {
  if (total >= 80) return 'A';
  if (total >= 70) return 'B';
  if (total >= 60) return 'C';
  if (total >= 50) return 'D';
  return 'F';
}

const root = document.body.dataset.root || '';
const page = document.body.dataset.page;
let appData = {};
const studentLinks = [
  ['dashboard', 'Dashboard', 'dashboard.html'],
  ['results', 'Results', 'results.html'],
  ['attendance', 'Attendance', 'attendance.html'],
  ['certificates', 'Certificates', 'certificates.html'],
  ['transfer', 'Transfer', 'transfer.html'],
  ['profile', 'Profile', 'profile.html']
];
const instructorSections = [
  ['overview', 'Overview'],
  ['performance', 'Performance'],
  ['results', 'Results'],
  ['attendance', 'Attendance'],
  ['transfers', 'Transfers'],
  ['announcements', 'Announcements'],
  ['certificates', 'Certificates']
];
const hodSections = [
  ['overview', 'Overview'],
  ['programs', 'Programs'],
  ['review', 'Review'],
  ['attendance', 'Attendance'],
  ['transfers', 'Transfers'],
  ['certificates', 'Certificates']
];
const directorSections = [
  ['overview', 'Overview'],
  ['cohorts', 'Cohorts'],
  ['results', 'Results'],
  ['policies', 'Policies'],
  ['certificates', 'Certificates'],
  ['transfers', 'Transfers'],
  ['reports', 'Reports'],
  ['announcements', 'Announcements'],
  ['audit', 'Audit']
];
const superadminSections = [
  ['overview', 'Overview'],
  ['users', 'Users and roles'],
  ['setup', 'Academic setup'],
  ['enrollment', 'Enrollment'],
  ['geofencing', 'Geofencing'],
  ['certificates', 'Certificates'],
  ['transfers', 'Transfer rules'],
  ['notifications', 'Notifications'],
  ['system', 'System']
];

function currentUser() {
  if (appData.user) return appData.user;
  try { return JSON.parse(sessionStorage.getItem('skillportal-user') || 'null'); }
  catch { return null; }
}

function renderLogin() {
  $('#main').innerHTML = `
    <section class="login-layout">
      <div class="login-intro">
        <div class="brand-mark">SP</div>
        <p class="eyebrow">Skills for what comes next</p>
        <h1>Learn a skill.<br>Build your future.</h1>
        <p class="intro-copy">Your training, progress and credentials, all in one place.</p>
        <div class="login-accent" aria-hidden="true"></div>
      </div>
      <section class="login-panel" aria-labelledby="login-title">
        <p class="eyebrow">SkillPortal</p>
        <h2 id="login-title">Welcome back</h2>
        <p class="muted">Sign in to continue to your portal.</p>
        <form id="login-form" class="form-stack">
          <label>Email address<input name="email" type="email" autocomplete="username" required placeholder="you@example.com"></label>
          <label>Password<input name="password" type="password" autocomplete="current-password" required placeholder="Enter your password"></label>
          <p id="login-error" class="form-error" role="alert" hidden></p>
          <button class="button button-primary button-wide" type="submit">Sign in</button>
        </form>
        <p class="demo-note">New student? <a href="${root}register.html">Create an account</a></p>
        <p class="demo-note">Demo access: use a role email such as <strong>student@demo.com</strong> and password <strong>1234</strong>.</p>
      </section>
    </section>`;

  $('#login-form').addEventListener('submit', event => {
    event.preventDefault();
    const error = $('#login-error');
    error.hidden = true;
    const values = new FormData(event.currentTarget);
    portalApi('auth/login.php', { method: 'POST', body: { email: values.get('email'), password: values.get('password') } })
      .then(({ user }) => {
        sessionStorage.setItem('skillportal-user', JSON.stringify(user));
        window.location.href = user.role === 'student' ? 'student/dashboard.html' : 'admin/index.html';
      })
      .catch(message => {
        error.textContent = message.message;
        error.hidden = false;
      });
  });
}

async function renderRegister() {
  let programs;
  let cohorts;
  try {
    ({ programs, cohorts = [] } = await portalApi('auth/register.php'));
  } catch (error) {
    $('#main').textContent = error.message;
    $('#main').classList.add('startup-error');
    return;
  }
  const programOptions = programs.map(program => `<option value="${esc(program.id)}">${esc(program.name)} · ${esc(program.department)}</option>`).join('');
  const cohortOptions = cohorts.map(cohort => `<option value="${esc(cohort.id)}" data-program="${esc(cohort.programId)}">${esc(cohort.name)} · ${esc(cohort.code)} · ${Math.max(0, Number(cohort.capacity) - Number(cohort.students))} places</option>`).join('');
  $('#main').innerHTML = `
    <section class="login-layout registration-layout">
      <div class="login-intro">
        <div class="brand-mark">SP</div>
        <p class="eyebrow">Start your training</p>
        <h1>Join SkillPortal.<br>Build your next skill.</h1>
        <p class="intro-copy">Create a student account to follow your attendance, results, and credentials.</p>
        <div class="login-accent" aria-hidden="true"></div>
      </div>
      <section class="login-panel registration-panel" aria-labelledby="register-title">
        <p class="eyebrow">STUDENT ACCOUNT</p>
        <h2 id="register-title">Create your account</h2>
        <p class="muted">Use your official matric number and select your active program.</p>
        <form id="registration-form" class="form-stack">
          <label>Full legal name<input name="name" maxlength="150" autocomplete="name" required></label>
          <label>Email address<input name="email" type="email" maxlength="190" autocomplete="email" required></label>
          <label>Matric number<input name="matric" maxlength="40" autocomplete="off" required></label>
          <label>Program<select name="programId" required><option value="">Choose a program</option>${programOptions}</select></label>
          <label id="registration-cohort-label" hidden>Training cohort<select name="cohortId"><option value="">Choose a cohort</option>${cohortOptions}</select></label>
          <label>Phone number<input name="phone" type="tel" maxlength="20" autocomplete="tel"></label>
          <label>Date of birth<input name="dateOfBirth" type="date" autocomplete="bday"></label>
          <label>Gender <span class="optional">(optional)</span><input name="gender" maxlength="40" autocomplete="sex"></label>
          <label>Home address <span class="optional">(optional)</span><textarea name="address" rows="2" maxlength="500" autocomplete="street-address"></textarea></label>
          <label>Next of kin <span class="optional">(optional)</span><input name="nextOfKin" maxlength="150"></label>
          <label>Password<input name="password" type="password" minlength="10" autocomplete="new-password" required><span class="optional">At least 10 characters with uppercase, lowercase, and a number.</span></label>
          <label>Confirm password<input name="passwordConfirmation" type="password" minlength="10" autocomplete="new-password" required></label>
          ${programs.length ? '' : '<p class="muted">There are no active programs open for registration. Please contact the training office.</p>'}
          <p id="registration-error" class="form-error" role="alert" hidden></p>
          <button class="button button-primary button-wide" type="submit" ${programs.length ? '' : 'disabled'}>Create student account</button>
        </form>
        <p class="demo-note">Already registered? <a href="${root}index.html">Sign in</a></p>
      </section>
    </section>`;

  const form = $('#registration-form');
  const programSelect = $('select[name="programId"]', form);
  const cohortSelect = $('select[name="cohortId"]', form);
  const cohortLabel = $('#registration-cohort-label', form);
  const updateCohorts = () => {
    const activeOptions = [...cohortSelect.options].filter(option => option.value && option.dataset.program === programSelect.value);
    [...cohortSelect.options].forEach(option => { option.hidden = Boolean(option.value) && option.dataset.program !== programSelect.value; });
    cohortLabel.hidden = activeOptions.length === 0;
    cohortSelect.required = activeOptions.length > 0;
    if (!activeOptions.some(option => option.value === cohortSelect.value)) cohortSelect.value = '';
  };
  programSelect.addEventListener('change', updateCohorts);
  cohortSelect.addEventListener('change', updateCohorts);
  updateCohorts();
  const password = $('input[name="password"]', form);
  const confirmation = $('input[name="passwordConfirmation"]', form);
  const error = $('#registration-error');
  form.addEventListener('submit', async event => {
    event.preventDefault();
    error.hidden = true;
    if (password.value !== confirmation.value) {
      error.textContent = 'The password confirmation does not match.';
      error.hidden = false;
      confirmation.focus();
      return;
    }
    const values = Object.fromEntries(new FormData(form).entries());
    const button = $('button[type="submit"]', form);
    button.disabled = true;
    try {
      const { user } = await portalApi('auth/register.php', { method: 'POST', body: values });
      sessionStorage.setItem('skillportal-user', JSON.stringify(user));
      window.location.href = `${root}student/dashboard.html`;
    } catch (requestError) {
      error.textContent = requestError.message;
      error.hidden = false;
      button.disabled = false;
    }
  });
}

function renderShell(user) {
  const isStudent = user.role === 'student';
  const title = pageTitle(page, user);
  const links = isStudent
    ? studentLinks.map(([key, label, file]) => `<a class="side-link ${page === key ? 'active' : ''}" href="${root}student/${file}" ${page === key ? 'aria-current="page"' : ''}><span class="nav-dot" aria-hidden="true"></span>${label}</a>`).join('')
    : user.role === 'instructor'
      ? instructorSections.map(([id, label]) => `<a class="side-link instructor-nav-link" href="#${id}" data-section="${id}"><span class="nav-dot" aria-hidden="true"></span>${label}</a>`).join('')
      : user.role === 'hod'
        ? hodSections.map(([id, label]) => `<a class="side-link instructor-nav-link" href="#${id}" data-section="${id}"><span class="nav-dot" aria-hidden="true"></span>${label}</a>`).join('')
        : user.role === 'director'
          ? directorSections.map(([id, label]) => `<a class="side-link instructor-nav-link" href="#${id}" data-section="${id}"><span class="nav-dot" aria-hidden="true"></span>${label}</a>`).join('')
          : user.role === 'superadmin'
            ? superadminSections.map(([id, label]) => `<a class="side-link instructor-nav-link" href="#${id}" data-section="${id}"><span class="nav-dot" aria-hidden="true"></span>${label}</a>`).join('')
            : `<a class="side-link active" href="${root}admin/index.html" aria-current="page"><span class="nav-dot" aria-hidden="true"></span>Workspace</a>`;
  const notifications = appData.notifications || [];
  const unread = notifications.filter(item => Number(item.unread)).length;
  $('#main').innerHTML = `
    <div class="app-shell">
      <aside class="sidebar">
        <a class="brand" href="${root}${isStudent ? 'student/dashboard.html' : 'admin/index.html'}"><span class="brand-mark brand-small">SP</span><span>SkillPortal</span></a>
        <p class="side-caption">${isStudent ? 'LEARNING' : user.role === 'instructor' ? 'TEACHING' : user.role === 'director' ? 'INSTITUTION' : user.role === 'superadmin' ? 'SYSTEM' : 'ADMINISTRATION'}</p>
        <nav class="side-nav" aria-label="Main navigation">${links}</nav>
        <div class="sidebar-footer"><span class="online-dot"></span>Training portal <span class="side-version">v1.0</span></div>
      </aside>
      <section class="workspace">
        <header class="topbar">
          <div class="topbar-title"><span class="mobile-brand">SP</span><span>${esc(title)}</span></div>
          <div class="topbar-actions">
            <div class="alerts-wrap">
              <button class="alerts-button" id="alerts-toggle" type="button" aria-expanded="false" aria-controls="alerts-menu">Alerts <span class="alert-count">${unread}</span></button>
              <div class="alerts-menu" id="alerts-menu" hidden>
                <h2>Notifications</h2>
                ${notifications.map(item => `<div class="alert-item"><p>${esc(item.text)}</p><time>${esc(item.date)}</time></div>`).join('') || '<p class="muted">You are all caught up.</p>'}
              </div>
            </div>
            <span class="avatar" aria-label="${esc(user.name)}">${esc(user.name.charAt(0).toUpperCase())}</span>
            <span class="user-name">${esc(user.name)}</span>
            <button type="button" class="button button-outline logout-button" id="logout">Log out</button>
          </div>
        </header>
        <section class="page-content">${renderPage(user)}</section>
      </section>
    </div>`;
  $('#logout').addEventListener('click', () => {
    portalApi('auth/logout.php', { method: 'POST' }).finally(() => {
      sessionStorage.removeItem('skillportal-user');
      window.location.href = `${root}index.html`;
    });
  });
  $('#alerts-toggle').addEventListener('click', () => {
    const menu = $('#alerts-menu');
    menu.hidden = !menu.hidden;
    $('#alerts-toggle').setAttribute('aria-expanded', String(!menu.hidden));
  });
  if (user.role === 'instructor' || user.role === 'hod' || user.role === 'director' || user.role === 'superadmin') {
    const updateInstructorNav = () => {
      const activeId = window.location.hash.slice(1) || 'overview';
      document.querySelectorAll('.instructor-nav-link').forEach(link => {
        const active = link.dataset.section === activeId;
        link.classList.toggle('active', active);
        if (active) link.setAttribute('aria-current', 'location');
        else link.removeAttribute('aria-current');
      });
    };
    window.onhashchange = updateInstructorNav;
    updateInstructorNav();
  }
  bindPageEvents(user);
}

function pageTitle(name, user) {
  const titles = { dashboard: 'Dashboard', results: 'Results', attendance: 'Attendance', certificates: 'Certificates', transfer: 'Transfer request', profile: 'My profile', admin: 'Workspace' };
  if (name === 'admin' && user.role === 'instructor') return 'Class performance';
  if (name === 'admin' && user.role === 'hod') return 'Department dashboard';
  if (name === 'admin' && user.role === 'director') return 'Institution oversight';
  if (name === 'admin' && user.role === 'superadmin') return 'System administration';
  return name === 'admin' ? `${roleLabel(user.role)} workspace` : (titles[name] || 'SkillPortal');
}

function roleLabel(role) {
  return ({ instructor: 'Instructor', hod: 'Head of Department', director: 'Director', superadmin: 'Super Admin' })[role] || 'Admin';
}

function renderPage(user) {
  const renderers = {
    dashboard: renderDashboard,
    results: renderResults,
    attendance: renderAttendance,
    certificates: renderCertificates,
    transfer: renderTransfer,
    profile: renderProfile,
    admin: () => renderAdmin(user)
  };
  return (renderers[page] || renderers.dashboard)();
}

function renderDashboard() {
  const summary = appData.summary;
  const attendance = summary.attendance;
  const attendancePercent = attendance.total ? Math.round(attendance.present / attendance.total * 100) : 0;
  const average = Math.round(summary.scores.average || 0);
  const announcements = summary.announcements;
  return `
    <div class="page-heading"><div><p class="eyebrow">STUDENT OVERVIEW</p><h1>Welcome back, ${esc(summary.student.fullName.split(' ')[0])}</h1><p class="muted">Here is your learning progress at a glance.</p></div><span class="date-chip">${new Date().toLocaleDateString(undefined, { weekday: 'long', month: 'short', day: 'numeric' })}</span></div>
    <section class="stat-grid" aria-label="Learning statistics">
      <article class="card stat-card"><div class="stat-label">Attendance</div><div class="stat-value">${attendancePercent}<span>%</span></div><div class="progress-track" role="progressbar" aria-label="Attendance" aria-valuenow="${attendancePercent}" aria-valuemin="0" aria-valuemax="100"><span style="width:${attendancePercent}%"></span></div><p class="stat-foot">${attendance.present} of ${attendance.total} days <span class="${attendancePercent >= summary.policy.minAttendance ? 'text-success' : 'text-danger'}">${attendancePercent >= summary.policy.minAttendance ? 'Meets' : 'Below'} ${summary.policy.minAttendance}% minimum</span></p></article>
      <article class="card stat-card"><div class="stat-label">Published average</div><div class="stat-value">${average}<span>/100</span></div><p class="stat-foot">Across ${summary.scores.count} published courses</p></article>
      <article class="card stat-card"><div class="stat-label">Certificate status</div><div class="stat-value stat-word">${esc(summary.certificate.status === 'cleared' ? 'Cleared' : 'In progress')}</div><p class="stat-foot">${esc(summary.certificate.title)}</p></article>
    </section>
    <section class="content-section"><div class="section-heading"><div><p class="eyebrow">CAMPUS UPDATES</p><h2>Announcements</h2></div><span class="section-count">${announcements.length} updates</span></div>
      <div class="announcement-list">${announcements.map(item => `<article class="card announcement ${item.pinned ? 'pinned' : ''}"><div class="announcement-top"><h3>${esc(item.title)}</h3>${item.pinned ? '<span class="tag tag-pinned">Pinned</span>' : ''}</div><p>${esc(item.text)}</p><time>${esc(item.date)}</time></article>`).join('')}</div>
    </section>`;
}

function renderResults() {
  const scores = appData.results.scores;
  const rows = scores.map(score => {
    const total = Number(score.total);
    return [score.code, score.course, score.test, score.exam, total,
      { text: grade(total), className: 'tag tag-grade' },
      total < appData.results.passMark ? { text: 'Below pass mark', className: 'tag tag-danger' } : { text: 'Pass', className: 'tag tag-success' }];
  });
  return `<div class="page-heading"><div><p class="eyebrow">ACADEMIC RECORD</p><h1>Results</h1><p class="muted">Only published course results are shown here.</p></div><span class="date-chip">Pass mark: ${appData.results.passMark}</span></div>
    <section class="card content-card"><div class="section-heading"><div><h2>Published scores</h2><p class="muted">Test /30 and exam /70</p></div><span class="section-count">${scores.length} courses</span></div>${table(['Code', 'Course', 'Test /30', 'Exam /70', 'Total', 'Grade', 'Status'], rows)}</section>`;
}

function renderAttendance() {
  const summary = appData.summary;
  const attendance = summary.attendance;
  const session = summary.session;
  const attendancePercent = attendance.total ? Math.round(attendance.present / attendance.total * 100) : 0;
  const rows = attendance.recent.map(record => [record.date, record.time, { text: record.status, className: `tag ${record.status === 'Present' ? 'tag-success' : 'tag-warning'}` }]);
  const sessionDetails = session ? `<div class="detail-list"><div><dt>Date</dt><dd>${esc(session.date)}</dd></div><div><dt>Session time</dt><dd>${esc(session.startTime)} - ${esc(session.endTime)}</dd></div><div><dt>Venue</dt><dd>${esc(session.venue)}</dd></div><div><dt>Check-in radius</dt><dd>${session.radius} m</dd></div></div>` : '<p class="muted">There is no open attendance session for your enrolled courses.</p>';
  return `<div class="page-heading"><div><p class="eyebrow">CHECK-IN RECORD</p><h1>Attendance</h1><p class="muted">Check in for an active session when you arrive on campus.</p></div></div>
    <section class="attendance-grid"><article class="card session-card"><div class="section-heading"><div><p class="eyebrow">ACTIVE SESSION</p><h2>${esc(session ? session.title : 'No active session')}</h2></div><span class="tag ${session ? 'tag-success' : 'tag-warning'}">${session ? 'Open' : 'Closed'}</span></div>${sessionDetails}
      <p class="muted">A live GPS fix is required for check-in. Allow location access when your browser asks.</p><button class="button button-primary" type="button" id="check-in" ${session ? '' : 'disabled'}>Check in with GPS</button><p id="checkin-message" class="checkin-message" role="status" aria-live="polite"></p>
    </article><aside class="card attendance-summary"><p class="eyebrow">YOUR ATTENDANCE</p><div class="stat-value">${attendancePercent}<span>%</span></div><p class="muted">${attendance.present} present days out of ${attendance.total}</p><div class="progress-track" role="progressbar" aria-label="Attendance" aria-valuenow="${attendancePercent}" aria-valuemin="0" aria-valuemax="100"><span style="width:${attendancePercent}%"></span></div><p class="stat-foot">Minimum required: ${summary.policy.minAttendance}%</p></aside></section>
    <section class="card content-card"><div class="section-heading"><div><p class="eyebrow">RECENT ACTIVITY</p><h2>Recent check-ins</h2></div></div>${table(['Date', 'Time', 'Status'], rows)}</section>`;
}

function renderCertificates() {
  const certificate = appData.certificates.find(item => item.status === 'cleared');
  if (!certificate) return `<div class="page-heading"><div><p class="eyebrow">ACHIEVEMENTS</p><h1>Certificates</h1></div></div><section class="card content-card empty-state"><span class="empty-icon" aria-hidden="true">•</span><h2>Not cleared yet</h2><p class="muted">Your certificate will be available when all completion requirements are met.</p></section>`;
  return `<div class="page-heading"><div><p class="eyebrow">ACHIEVEMENTS</p><h1>Certificates</h1><p class="muted">Your completed training credentials.</p></div></div>
    <article class="certificate-card"><div class="certificate-topline"><span class="brand-mark">SP</span><span class="tag tag-success">Cleared</span></div><p class="eyebrow">CERTIFICATE OF COMPLETION</p><h2>${esc(certificate.title)}</h2><p class="certificate-recipient">Awarded to <strong>${esc(certificate.recipient)}</strong></p><div class="certificate-meta"><div><span>Certificate serial</span><strong>${esc(certificate.serial)}</strong></div><div><span>Program</span><strong>${esc(certificate.program)}</strong></div></div><button class="button button-primary" type="button" id="download-certificate">Download certificate</button></article>`;
}

function renderTransfer() {
  const transfer = appData.transfer;
  const options = transfer.programs.map(program => `<option value="${esc(program.id)}">${esc(program.name)}</option>`).join('');
  const reasons = transfer.reasons.map(reason => {
    const label = typeof reason === 'string' ? reason : reason.label;
    const requiresDocument = typeof reason === 'object' && reason.requiresDocument;
    return `<option value="${esc(label)}" data-requires-document="${requiresDocument ? 'true' : 'false'}">${esc(label)}${requiresDocument ? ' · proof required' : ''}</option>`;
  }).join('');
  return `<div class="page-heading"><div><p class="eyebrow">PROGRAM SERVICES</p><h1>Transfer request</h1><p class="muted">Request a change to another training program.</p></div></div>
    <section class="card content-card"><div class="section-heading"><div><h2>Start a request</h2><p class="muted">Your current program is shown for reference.</p></div></div><form id="transfer-form" class="form-grid">
      <label>Current program<input value="${esc(transfer.currentProgram)}" readonly></label>
      <label>Transfer to<select name="program" required><option value="">Choose a program</option>${options}</select></label>
      <label>Reason<select name="reason" required><option value="">Choose a reason</option>${reasons}</select></label>
      <label class="span-all">Explanation<textarea name="explanation" rows="4" minlength="20" required placeholder="Tell us why you are requesting this transfer (at least 20 characters)."></textarea></label>
      <label class="span-all">Supporting document <span id="transfer-document-requirement" class="optional">(optional)</span><input name="attachment" type="file" accept=".pdf,.jpg,.jpeg,.png"></label>
      <div class="span-all"><button class="button button-primary" type="submit">Submit transfer request</button></div>
    </form></section><section class="card content-card"><div class="section-heading"><div><p class="eyebrow">REQUEST HISTORY</p><h2>Your requests</h2></div></div>${renderTransferRequests()}</section>`;
}

function renderTransferRequests() {
  const transfers = appData.transfer.transfers;
  if (!transfers.length) return '<p class="muted empty-message">No transfer requests submitted.</p>';
  const stages = ['Instructor comment', 'Current HOD', 'Target HOD', 'Director'];
  return `<div class="transfer-list">${transfers.map(request => `<article class="transfer-item"><div class="transfer-summary"><div><h3>${esc(request.program)}</h3><p>${esc(request.reason)} · Submitted ${esc(request.date)}</p><p class="transfer-explanation">${esc(request.explanation)}</p></div><span class="tag tag-warning">${esc(request.status)}</span></div><ol class="tracker">${stages.map((stage, index) => `<li class="${index < request.stage ? 'complete' : ''}"><span class="tracker-dot">${index < request.stage ? '✓' : index + 1}</span><span>${esc(stage)}</span></li>`).join('')}</ol></article>`).join('')}</div>`;
}

function renderProfile() {
  const profile = appData.profile;
  return `<div class="page-heading"><div><p class="eyebrow">ACCOUNT SETTINGS</p><h1>My profile</h1><p class="muted">Keep your contact details up to date.</p></div></div>
    <div class="profile-grid"><section class="card content-card"><div class="section-heading"><div><h2>Contact information</h2><p class="muted">These details can be edited.</p></div></div><form id="profile-form" class="form-grid">
      <label>Phone number<input name="phone" type="tel" pattern="[0-9]{11}" maxlength="11" required value="${esc(profile.phone)}" title="Enter an 11-digit phone number"></label>
      <label>Email address<input name="email" type="email" required value="${esc(profile.email)}"></label>
      <label class="span-all">Home address<textarea name="address" rows="2" required>${esc(profile.address)}</textarea></label>
      <label class="span-all">Next of kin<input name="nextOfKin" required value="${esc(profile.nextOfKin)}"></label>
      <div class="span-all"><button class="button button-primary" type="submit">Save changes</button></div>
    </form></section><aside class="card content-card"><div class="section-heading"><div><h2>Academic record</h2><p class="muted">These fields are managed by the institution.</p></div></div><dl class="locked-list"><div><dt>Matric number</dt><dd>${esc(profile.matric)}</dd></div><div><dt>Program</dt><dd>${esc(profile.program)}</dd></div><div><dt>Cohort</dt><dd>${esc(profile.cohort || 'Not assigned')}</dd></div><div><dt>Department</dt><dd>${esc(profile.dept)}</dd></div><div><dt>Status</dt><dd>${esc(profile.status)}</dd></div></dl></aside></div>
    <section class="card content-card correction-card"><div class="section-heading"><div><p class="eyebrow">RECORD SUPPORT</p><h2>Request a correction</h2><p class="muted">For legal name, date of birth or gender updates, attach supporting proof.</p></div></div><form id="correction-form" class="form-grid"><label>Field to correct<select name="field" required><option value="">Choose a field</option><option>Legal name</option><option>Date of birth</option><option>Gender</option></select></label><label>New value<input name="value" required></label><label class="span-all">Proof document<input type="file" name="proof" accept=".pdf,.jpg,.jpeg,.png" required></label><div class="span-all"><button class="button button-outline" type="submit">Submit correction request</button></div></form></section>`;
}

function renderSuperAdminWorkspace() {
    const data = appData.superadmin || {};
    const settings = data.settings || {};
    const certificate = settings.certificate || {};
    const notifications = settings.notifications || {};
    const attendance = settings.attendance || {};
    const departments = data.departments || [];
    const programs = data.programs || [];
    const departmentOptions = departments.map(item => `<option value="${esc(item.id)}">${esc(item.name)}</option>`).join('');
    const programOptions = programs.map(item => `<option value="${esc(item.id)}">${esc(item.name)}</option>`).join('');
    const instructorOptions = (data.instructors || []).map(item => `<option value="${esc(item.id)}">${esc(item.name)}</option>`).join('');
    const records = (headers, items, entity, fields) => `<div class="table-wrap"><table><thead><tr>${[...headers, 'Manage'].map(label => `<th scope="col">${esc(label)}</th>`).join('')}</tr></thead><tbody>${items.length ? items.map(item => `<tr>${fields(item).map(value => `<td>${esc(value)}</td>`).join('')}<td><button class="button button-outline button-small" type="button" data-superadmin-edit="${entity}" data-record-id="${esc(item.id)}">Edit</button></td></tr>`).join('') : `<tr><td class="empty-cell" colspan="${headers.length + 1}">No records to show.</td></tr>`}</tbody></table></div>`;
    const users = (data.users || []).map(item => `<tr><td>${esc(item.name)}</td><td>${esc(item.email)}</td><td>${esc(roleLabel(item.role))}</td><td>${esc(item.departmentId || item.programId || 'Institution-wide')}</td><td>${esc(item.status)}</td><td><div class="button-row"><button class="button button-outline button-small" type="button" data-superadmin-edit-user="${esc(item.id)}">Edit</button><button class="button button-outline button-small" type="button" data-superadmin-reset="${esc(item.id)}">Reset password</button>${item.status === 'active' ? `<button class="button button-danger button-small" type="button" data-superadmin-status="${esc(item.id)}" data-status="suspended">Suspend</button><button class="button button-danger button-small" type="button" data-superadmin-status="${esc(item.id)}" data-status="inactive">Remove</button>` : `<button class="button button-outline button-small" type="button" data-superadmin-status="${esc(item.id)}" data-status="active">Reactivate</button>`}</div></td></tr>`).join('');
    const transferReasons = (settings.transferReasons || []).map(item => `${item.label}${item.requiresDocument ? '|document' : ''}`).join('\n');
    const auditRows = (data.audit || []).map(item => [item.createdAt, item.actor, item.action, `${item.entityType}${item.entityId ? ` #${item.entityId}` : ''}`]);
    const integrity = data.integrity || {};
    const summary = data.summary || {};
    return `<div id="overview" class="instructor-overview"><div class="page-heading"><div><p class="eyebrow">SYSTEM ADMINISTRATION</p><h1>Super Admin console</h1><p class="muted">Manage accounts, academic structure, attendance configuration, and platform settings. Academic decisions remain with the Director and department chain.</p></div><span class="date-chip">${summary.users || 0} accounts</span></div>
      <section class="stat-grid instructor-stat-grid" aria-label="System summary"><article class="card stat-card"><div class="stat-label">Active students</div><div class="stat-value">${summary.students || 0}</div></article><article class="card stat-card"><div class="stat-label">Active staff</div><div class="stat-value">${summary.staff || 0}</div></article><article class="card stat-card"><div class="stat-label">Active courses</div><div class="stat-value">${summary.courses || 0}</div></article><article class="card stat-card"><div class="stat-label">Active enrollments</div><div class="stat-value">${summary.enrollments || 0}</div></article></section>
      <section id="users" class="card content-card instructor-section"><div class="section-heading"><div><p class="eyebrow">IDENTITY AND ACCESS</p><h2>Accounts and roles</h2><p class="muted">Removal disables an account and retains its academic and audit history.</p></div></div><form class="form-grid" data-superadmin-form="user_create"><label>Full name<input name="name" maxlength="150" required></label><label>Email<input name="email" type="email" maxlength="190" required></label><label>Initial password<input name="password" type="password" minlength="10" required></label><label>Role<select name="role" required><option value="student">Student</option><option value="instructor">Instructor</option><option value="hod">Head of Department</option><option value="director">Director</option><option value="superadmin">Super Admin</option></select></label><label>Department<select name="departmentId"><option value="">Institution-wide / choose for role</option>${departmentOptions}</select></label><label data-student-field>Program<select name="programId"><option value="">Choose program</option>${programOptions}</select></label><label data-student-field>Matric number<input name="matric" maxlength="40"></label><label data-staff-field>Staff title<input name="title" maxlength="100"></label><div class="span-all"><button class="button button-primary" type="submit">Create account</button></div></form><div class="table-wrap"><table><thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Scope</th><th>Status</th><th>Actions</th></tr></thead><tbody>${users || '<tr><td class="empty-cell" colspan="6">No accounts to show.</td></tr>'}</tbody></table></div></section>
      <section id="setup" class="card content-card instructor-section"><div class="section-heading"><div><p class="eyebrow">ACADEMIC STRUCTURE</p><h2>Departments, programs, and courses</h2></div></div><div class="admin-setup-grid"><form class="form-grid" data-superadmin-form="department_save"><h3>Department</h3><input name="id" type="hidden"><label>Name<input name="name" maxlength="120" required></label><label>Code<input name="code" maxlength="20" required></label><button class="button button-outline" type="submit">Save department</button>${records(['Name', 'Code'], departments, 'departments', item => [item.name, item.code])}</form><form class="form-grid" data-superadmin-form="program_save"><h3>Program</h3><input name="id" type="hidden"><label>Name<input name="name" maxlength="150" required></label><label>Code<input name="code" maxlength="20" required></label><label>Department<select name="departmentId" required><option value="">Choose department</option>${departmentOptions}</select></label><label><input name="active" type="checkbox" checked> Active</label><button class="button button-outline" type="submit">Save program</button>${records(['Name', 'Code', 'Dept.', 'Status'], programs, 'programs', item => [item.name, item.code, item.departmentId, item.active ? 'Active' : 'Inactive'])}</form><form class="form-grid" data-superadmin-form="course_save"><h3>Course</h3><input name="id" type="hidden"><label>Code<input name="code" maxlength="30" required></label><label>Title<input name="title" maxlength="180" required></label><label>Department<select name="departmentId" required><option value="">Choose department</option>${departmentOptions}</select></label><label>Instructor<select name="instructorId"><option value="">Unassigned</option>${instructorOptions}</select></label><label><input name="active" type="checkbox" checked> Active</label><button class="button button-outline" type="submit">Save course</button>${records(['Code', 'Course', 'Dept.', 'Status'], data.courses || [], 'courses', item => [item.code, item.title, item.departmentId, item.active ? 'Active' : 'Inactive'])}</form></div></section>
      <section id="enrollment" class="card content-card instructor-section"><div class="section-heading"><div><p class="eyebrow">LEARNER SETUP</p><h2>Bulk course enrollment</h2><p class="muted">CSV columns: matric_no,course_code,enrolled_at. Maximum 500 rows per import.</p></div></div><form id="enrollment-import-form" class="form-grid"><label>Enrollment CSV<input name="csv" type="file" accept=".csv,text/csv" required></label><div><button class="button button-primary" type="submit">Import enrollments</button></div></form>${table(['Matric', 'Learner', 'Course', 'Enrolled', 'Status'], (data.enrollments || []).map(item => [item.matric, item.learner, item.course, item.enrolledAt, item.status]))}</section>
      <section id="geofencing" class="card content-card instructor-section"><div class="section-heading"><div><p class="eyebrow">ATTENDANCE SECURITY</p><h2>Venues and sessions</h2><p class="muted">Set institution-wide venue boundaries and adjust active attendance time windows.</p></div></div><form class="form-grid" data-superadmin-form="geofence_save"><input name="id" type="hidden"><label>Venue<input name="name" maxlength="150" required></label><label>Latitude<input name="latitude" type="number" min="-90" max="90" step="0.0000001" required></label><label>Longitude<input name="longitude" type="number" min="-180" max="180" step="0.0000001" required></label><label>Radius (m)<input name="radius" type="number" min="1" max="50000" required></label><label><input name="active" type="checkbox" checked> Active</label><div class="span-all"><button class="button button-primary" type="submit">Save venue</button></div>${records(['Venue', 'Coordinates', 'Radius', 'Status'], data.geofences || [], 'geofences', item => [item.name, `${item.latitude}, ${item.longitude}`, item.radius, item.active ? 'Active' : 'Inactive'])}</form><form class="form-grid" data-superadmin-form="session_save"><input name="id" type="hidden"><h3>Attendance session window</h3><label>Starts<input name="startsAt" type="datetime-local" required></label><label>Ends<input name="endsAt" type="datetime-local" required></label><label>Status<select name="status" required><option value="scheduled">Scheduled</option><option value="open">Open when within window</option><option value="closed">Closed</option></select></label><div><button class="button button-outline" type="submit">Save session window</button></div>${records(['Session', 'Course', 'Venue', 'Starts', 'Ends', 'Status'], data.sessions || [], 'sessions', item => [item.title, item.course, item.venue, item.startsAt, item.endsAt, item.status])}</form></section>
      <section id="certificates" class="card content-card instructor-section"><div class="section-heading"><div><p class="eyebrow">CERTIFICATE CONFIGURATION</p><h2>Template and verification defaults</h2><p class="muted">Configuration only; this role cannot approve or revoke certificates.</p></div></div><div class="form-grid"><label>Issuer<input form="superadmin-settings-form" name="issuer" maxlength="120" value="${esc(certificate.issuer || '')}"></label><label>Serial prefix<input form="superadmin-settings-form" name="serialPrefix" maxlength="30" value="${esc(certificate.serialPrefix || '')}"></label><label>Next serial<input form="superadmin-settings-form" name="nextSerial" type="number" min="1" value="${Number(certificate.nextSerial || 1)}"></label><label>Signature name<input form="superadmin-settings-form" name="signatureName" maxlength="150" value="${esc(certificate.signatureName || '')}"></label><label>Logo URL<input form="superadmin-settings-form" name="logoUrl" type="url" maxlength="500" value="${esc(certificate.logoUrl || '')}"></label><label>QR verification URL<input form="superadmin-settings-form" name="qrVerifyBaseUrl" type="url" maxlength="500" value="${esc(certificate.qrVerifyBaseUrl || '')}"></label><label class="span-all">Wording<textarea form="superadmin-settings-form" name="wording" rows="3" maxlength="2000">${esc(certificate.wording || '')}</textarea></label></div></section>
      <section id="transfers" class="card content-card instructor-section"><div class="section-heading"><div><p class="eyebrow">TRANSFER CONFIGURATION</p><h2>Eligible reasons and documents</h2><p class="muted">One reason per line. Append |document when proof is required.</p></div></div><label>Reasons<textarea form="superadmin-settings-form" name="transferReasons" rows="6">${esc(transferReasons)}</textarea></label></section>
      <section id="notifications" class="card content-card instructor-section"><div class="section-heading"><div><p class="eyebrow">COMMUNICATION CHANNELS</p><h2>Channels and templates</h2><p class="muted">Provider credentials are not stored here; delivery needs an external provider integration.</p></div></div><div class="form-grid"><label><input form="superadmin-settings-form" name="channel_email" type="checkbox" ${notifications.channels?.email ? 'checked' : ''}> Email</label><label><input form="superadmin-settings-form" name="channel_sms" type="checkbox" ${notifications.channels?.sms ? 'checked' : ''}> SMS</label><label><input form="superadmin-settings-form" name="channel_push" type="checkbox" ${notifications.channels?.push ? 'checked' : ''}> Push</label><label class="span-all">Announcement template<textarea form="superadmin-settings-form" name="template_announcement" rows="2">${esc(notifications.templates?.announcement || '')}</textarea></label><label class="span-all">Result template<textarea form="superadmin-settings-form" name="template_result" rows="2">${esc(notifications.templates?.result || '')}</textarea></label></div></section>
      <section id="system" class="card content-card instructor-section"><div class="section-heading"><div><p class="eyebrow">SYSTEM CONTROLS</p><h2>Anti-spoofing, integrity, audit</h2></div></div><div class="form-grid"><label>Maximum failed attempts<input form="superadmin-settings-form" name="maxFailedAttempts" type="number" min="1" max="30" value="${Number(attendance.maxFailedAttempts || 5)}"></label><label>Attempt window (minutes)<input form="superadmin-settings-form" name="windowMinutes" type="number" min="1" max="1440" value="${Number(attendance.windowMinutes || 30)}"></label></div><div class="stat-grid instructor-stat-grid"><article class="card stat-card"><div class="stat-label">Students missing profiles</div><div class="stat-value">${integrity.studentsWithoutProfile || 0}</div></article><article class="card stat-card"><div class="stat-label">Staff missing profiles</div><div class="stat-value">${integrity.staffWithoutProfile || 0}</div></article><article class="card stat-card"><div class="stat-label">Expired sessions still open</div><div class="stat-value">${integrity.expiredSessionsOpen || 0}</div></article></div><p class="muted">Database backups are not generated by the portal. Configure and verify scheduled MySQL backups on the host.</p><div class="section-heading section-heading-spaced"><div><h2>Recent audit events</h2></div></div>${table(['Time', 'Actor', 'Action', 'Entity'], auditRows)}</section>
      <form id="superadmin-settings-form" hidden></form><div class="superadmin-settings-save"><button class="button button-primary" type="submit" form="superadmin-settings-form">Save settings</button></div></div>`;
  }

  function renderAdmin(user) {
  if (user.role === 'instructor') return renderInstructorWorkspace();
  if (user.role === 'hod') return renderHodWorkspace();
  if (user.role === 'director') return renderDirectorWorkspace();
  if (user.role === 'superadmin') return renderSuperAdminWorkspace();
  const items = appData.queue.items;
  const scoreEntry = appData.scoreOptions ? `<section class="card content-card"><div class="section-heading"><div><p class="eyebrow">ASSESSMENT ENTRY</p><h2>Save a draft score</h2></div></div><form id="score-form" class="form-grid"><label>Course<select name="course_id" required><option value="">Choose a course</option>${appData.scoreOptions.courses.map(course => `<option value="${esc(course.id)}">${esc(course.code)} · ${esc(course.title)}</option>`).join('')}</select></label><label>Student<select name="student_id" required><option value="">Choose a student</option>${appData.scoreOptions.learners.map(learner => `<option value="${esc(learner.id)}" data-course="${esc(learner.courseId)}">${esc(learner.name)}</option>`).join('')}</select></label><label>Test score<input name="test_score" type="number" min="0" max="30" step="0.5" required></label><label>Exam score<input name="exam_score" type="number" min="0" max="70" step="0.5" required></label><div class="span-all"><button class="button button-primary" type="submit">Save draft</button></div></form></section>` : '';
  const queueTable = items.length ? `<div class="table-wrap"><table><thead><tr>${['Reference', 'Learner', 'Request', 'Submitted', 'Actions'].map(label => `<th scope="col">${label}</th>`).join('')}</tr></thead><tbody>${items.map(item => `<tr><td>${esc(item.id)}</td><td>${esc(item.learner)}</td><td>${esc(item.item)}</td><td>${esc(item.submitted)}</td><td>${renderActionButtons(item)}</td></tr>`).join('')}</tbody></table></div>` : '<div class="all-clear"><span class="all-clear-mark" aria-hidden="true">✓</span><h2>All clear</h2><p class="muted">There are no items waiting for your review.</p></div>';
  return `<div class="page-heading"><div><p class="eyebrow">REVIEW QUEUE</p><h1>${esc(roleLabel(user.role))} workspace</h1><p class="muted">Review requests assigned to your role.</p></div><span class="date-chip">${items.length} waiting</span></div>${scoreEntry}<section class="card content-card"><div class="section-heading"><div><h2>Items awaiting action</h2></div></div>${queueTable}</section>`;
}

function renderActionButtons(item) {
  const actions = item.type === 'transfer' ? ['Approve', 'Reject', 'Return']
    : item.type === 'score_verify' ? ['Verify', 'Return']
      : item.type === 'score_publish' ? ['Publish']
        : item.type === 'score_flag' ? ['Resolve']
            : item.type === 'score_withdraw' ? ['Withdraw']
              : item.type === 'attendance_correction' ? ['Approve', 'Reject']
            : ['Approve', 'Reject'];
    return actions.map(action => `<button class="button button-small ${action === 'Reject' || action === 'Return' || action === 'Withdraw' ? 'button-danger' : 'button-outline'}" type="button" data-kind="${esc(item.type)}" data-action="${esc(action.toLowerCase())}" data-id="${esc(item.id)}">${esc(action)}</button>`).join(' ');
}

function renderHodWorkspace() {
  const hod = appData.hod || { department: { name: 'Department' }, programs: [], courses: [], scores: [], attendanceCorrections: [], attendanceFlags: [], outgoingTransfers: [], incomingTransfers: [], certificateEligible: [], sessions: [], announcements: [] };
  const programRows = (hod.programs || []).map(program => [program.name, program.code, `${program.activeStudents || 0}`, `${program.completedStudents || 0}`, `${program.dropouts || 0}`, `${program.attendanceRate || 0}%`, `${program.passRate || 0}%`]);
  const reviewItems = appData.queue?.items || [];
  const transferCards = [
    ...(hod.outgoingTransfers || []).map(request => `<article class="transfer-item"><div class="transfer-summary"><div><h3>${esc(request.learner)} · ${esc(request.matric)}</h3><p>Outgoing transfer to ${esc(request.targetProgram)} · ${esc(request.reason)}</p><p class="transfer-explanation">${esc(request.explanation || 'No additional context provided.')}</p></div><span class="tag tag-warning">Current department</span></div></article>`),
    ...(hod.incomingTransfers || []).map(request => `<article class="transfer-item"><div class="transfer-summary"><div><h3>${esc(request.learner)} · ${esc(request.matric)}</h3><p>Incoming transfer to ${esc(request.targetProgram)} · ${esc(request.reason)}</p><p class="transfer-explanation">${esc(request.explanation || 'No additional context provided.')}</p><p class="muted">Capacity: ${esc(request.targetEnrollment || 0)}/${esc(request.tpcapacity || request.capacity || 0)} · Prereqs: ${request.prerequisitesMet === 0 || request.prerequisitesMet === false ? 'Not met' : 'Met'}</p></div><span class="tag tag-warning">Target department</span></div></article>`)
  ];
  const sessionRows = (hod.sessions || []).slice(0, 8).map(session => [session.code, session.title, session.program, session.instructor, session.venue, session.startsAt]);
  const eligibleRows = (hod.certificateEligible || []).slice(0, 10).map(learner => [learner.learner, learner.matric, `${learner.attendanceRate || 0}%`, `${learner.scoreAverage || 0}%`]);
  const pendingQueue = reviewItems.length ? reviewItems.map(item => `<article class="transfer-item"><div class="transfer-summary"><div><h3>${esc(item.learner)}</h3><p>${esc(item.item)} · ${esc(item.submitted)}</p></div><span class="tag tag-warning">Pending</span></div><div class="button-row">${renderActionButtons(item)}</div></article>`).join('') : '<p class="muted empty-message">No department items are awaiting review.</p>';

  return `<div id="overview" class="instructor-overview"><div class="page-heading"><div><p class="eyebrow">DEPARTMENT · REVIEW</p><h1>${esc(hod.department?.name || 'Department')} dashboard</h1><p class="muted">A summary of program performance, pending reviews and completion checks.</p></div><span class="date-chip">${(hod.programs || []).length} programs</span></div>
    <section class="stat-grid instructor-stat-grid" aria-label="Department summary"><article class="card stat-card"><div class="stat-label">Active learners</div><div class="stat-value">${(hod.programs || []).reduce((sum, program) => sum + Number(program.activeStudents || 0), 0)}</div><p class="stat-foot">Across the department</p></article><article class="card stat-card"><div class="stat-label">Average attendance</div><div class="stat-value">${((hod.programs || []).reduce((sum, program) => sum + Number(program.attendanceRate || 0), 0) / Math.max((hod.programs || []).length, 1)).toFixed(1)}<span>%</span></div><p class="stat-foot">Program-level attendance</p></article><article class="card stat-card"><div class="stat-label">Pass rate</div><div class="stat-value">${((hod.programs || []).reduce((sum, program) => sum + Number(program.passRate || 0), 0) / Math.max((hod.programs || []).length, 1)).toFixed(1)}<span>%</span></div><p class="stat-foot">Across published score results</p></article><article class="card stat-card"><div class="stat-label">Pending review</div><div class="stat-value">${reviewItems.length}</div><p class="stat-foot">Scores, transfers and corrections</p></article></section>
    <section id="programs" class="card content-card instructor-section"><div class="section-heading"><div><p class="eyebrow">PROGRAMS</p><h2>Department portfolio</h2><p class="muted">Learner activity and attainment across programs.</p></div></div>${table(['Program', 'Code', 'Active', 'Completed', 'Dropouts', 'Attendance', 'Pass rate'], programRows)}</section>
    <section id="review" class="card content-card instructor-section"><div class="section-heading"><div><p class="eyebrow">REVIEW</p><h2>Outstanding actions</h2><p class="muted">All department items requiring a decision are shown here.</p></div></div>${pendingQueue}</section>
    <section id="attendance" class="card content-card instructor-section"><div class="section-heading"><div><p class="eyebrow">ATTENDANCE</p><h2>Recent sessions</h2><p class="muted">Operational view across departmental courses.</p></div></div>${table(['Code', 'Session', 'Program', 'Instructor', 'Venue', 'Starts'], sessionRows)}</section>
    <section id="transfers" class="card content-card instructor-section"><div class="section-heading"><div><p class="eyebrow">TRANSFERS</p><h2>Program transfer review</h2><p class="muted">Current and target department transfer decisions.</p></div></div><div class="transfer-list">${transferCards.length ? transferCards.join('') : '<p class="muted empty-message">No transfer requests are waiting on department review.</p>'}</div></section>
    <section id="certificates" class="card content-card instructor-section"><div class="section-heading"><div><p class="eyebrow">CERTIFICATION</p><h2>Students ready for review</h2><p class="muted">Eligible learners meet the attendance and pass-rate thresholds.</p></div></div>${eligibleRows.length ? table(['Learner', 'Matric', 'Attendance', 'Average'], eligibleRows) : '<p class="muted empty-message">No students are currently ready for certificate review.</p>'}</section></div>`;
}

function renderDirectorWorkspace() {
  const director = appData.director || { summary: {}, departments: [], transfers: [], certificates: [], announcements: [], audit: [], policy: { minAttendance: 75, passMark: 50 } };
  const policy = director.policy || appData.policies?.policy || { minAttendance: 75, passMark: 50 };
  const summary = director.summary || {};
  const departmentRows = (director.departments || []).map(item => [item.name, `${item.learners || 0}`, `${item.activeEnrollments || 0}`, `${item.attendanceRate || 0}%`, `${item.passRate || 0}%`, `${item.publishedScores || 0}`, `${item.certificatesIssued || 0}`, `${item.transfers || 0}`]);
  const transferRows = (director.transfers || []).map(item => [item.learner, item.targetProgram, item.reason, item.submitted, item.status]);
  const certificates = director.certificates || [];
  const certificateTable = certificates.length ? `<form id="certificate-batch-form"><div class="button-row certificate-batch-actions"><label><input id="select-pending-certificates" type="checkbox"> Select pending</label><button class="button button-primary button-small" type="submit">Clear selected</button></div><div class="table-wrap"><table><thead><tr><th scope="col">Select</th><th scope="col">Learner</th><th scope="col">Certificate</th><th scope="col">Serial</th><th scope="col">Submitted</th><th scope="col">Actions</th></tr></thead><tbody>${certificates.map(item => `<tr><td>${item.status === 'pending' ? `<input type="checkbox" name="certificate_ids" value="${esc(item.id)}" aria-label="Select ${esc(item.learner)} certificate">` : '—'}</td><td>${esc(item.learner)}</td><td>${esc(item.title)}</td><td>${esc(item.serial)}</td><td>${esc(item.submitted || '')}</td><td>${item.status === 'pending' ? `<button class="button button-outline button-small" type="button" data-kind="certificate" data-action="approve" data-id="${esc(item.id)}">Clear</button> <button class="button button-danger button-small" type="button" data-kind="certificate" data-action="reject" data-id="${esc(item.id)}">Reject</button>` : item.status === 'cleared' ? `<button class="button button-danger button-small" type="button" data-kind="certificate" data-action="revoke" data-id="${esc(item.id)}">Revoke</button>` : esc(item.status)}</td></tr>`).join('')}</tbody></table></div></form>` : '<p class="muted empty-message">No certificate approvals are pending.</p>';
  const publishedScores = director.publishedScores || [];
  const publishedScoreCards = publishedScores.length ? publishedScores.map(item => `<article class="transfer-item"><div class="transfer-summary"><div><h3>${esc(item.learner)} · ${esc(item.code)}</h3><p>${esc(item.course)} · ${esc(item.total)} points · Published ${esc(item.published)}</p></div><button class="button button-danger button-small" type="button" data-kind="score_withdraw" data-action="withdraw" data-id="${esc(item.id)}">Withdraw result</button></div></article>`).join('') : '<p class="muted empty-message">No published results are available to withdraw.</p>';
  const announcementRows = (director.announcements || []).map(item => [item.title, item.body, item.pinned ? 'Pinned' : 'Standard', item.published]);
  const auditRows = (director.audit || []).map(item => [item.actor, item.action, item.entity_type || item.entityType, item.createdAt]);
  const reviewItems = appData.queue?.items || [];
  const cohortSetup = renderDirectorCohortSection(director);

  return `${cohortSetup}<div id="overview" class="instructor-overview"><div class="page-heading"><div><p class="eyebrow">TRAINING DIRECTOR</p><h1>Institution oversight</h1><p class="muted">Cross-department monitoring for performance, certification, transfers and policy compliance.</p></div><span class="date-chip">${summary.programCount || 0} programs</span></div>
    <section class="stat-grid instructor-stat-grid" aria-label="Director summary"><article class="card stat-card"><div class="stat-label">Active learners</div><div class="stat-value">${summary.activeLearners || 0}</div><p class="stat-foot">Students currently active in training</p></article><article class="card stat-card"><div class="stat-label">Published scores</div><div class="stat-value">${summary.publishedScores || 0}</div><p class="stat-foot">Across all departments</p></article><article class="card stat-card"><div class="stat-label">Pending review</div><div class="stat-value">${(summary.pendingScores || 0) + (summary.pendingTransfers || 0) + (summary.pendingCertificates || 0)}</div><p class="stat-foot">Scores, transfers and certificates</p></article><article class="card stat-card"><div class="stat-label">Policy baseline</div><div class="stat-value">${policy.minAttendance || 75}<span>%</span></div><p class="stat-foot">Minimum attendance · pass mark ${policy.passMark || 50}%</p></article></section>
    <section id="results" class="card content-card instructor-section"><div class="section-heading"><div><p class="eyebrow">RESULTS</p><h2>Final approval queue</h2><p class="muted">Verified results wait for director release before they are visible to students.</p></div></div>${reviewItems.length ? reviewItems.map(item => `<article class="transfer-item"><div class="transfer-summary"><div><h3>${esc(item.learner)}</h3><p>${esc(item.item)} · ${esc(item.submitted)}</p></div><span class="tag tag-warning">Pending</span></div><div class="button-row">${renderActionButtons(item)}</div></article>`).join('') : '<p class="muted empty-message">There are no results awaiting your release.</p>'}<div class="section-heading section-heading-spaced"><div><h2>Published results</h2><p class="muted">Withdraw a release to return it to Director review; students will no longer see it.</p></div></div>${publishedScoreCards}</section>
    <section id="policies" class="card content-card instructor-section"><div class="section-heading"><div><p class="eyebrow">POLICIES</p><h2>Grading and attendance rules</h2><p class="muted">These values drive the eligibility rules used across the platform.</p></div></div><form id="policy-form" class="form-grid"><label>Minimum attendance % <input name="minAttendance" type="number" min="0" max="100" step="0.1" value="${Number(policy.minAttendance || 75)}" required></label><label>Pass mark % <input name="passMark" type="number" min="0" max="100" step="0.1" value="${Number(policy.passMark || 50)}" required></label><div class="span-all"><button class="button button-primary" type="submit">Save institutional policy</button></div></form></section>
    <section id="certificates" class="card content-card instructor-section"><div class="section-heading"><div><p class="eyebrow">CERTIFICATES</p><h2>Final certificate review</h2><p class="muted">Clear certificates individually or in a batch, reject requests, or revoke a cleared certificate.</p></div></div>${certificateTable}</section>
    <section id="transfers" class="card content-card instructor-section"><div class="section-heading"><div><p class="eyebrow">TRANSFERS</p><h2>Institution transfer decisions</h2><p class="muted">Final review of exceptions and appeals across departments.</p></div></div>${transferRows.length ? table(['Learner', 'Target program', 'Reason', 'Submitted', 'Status'], transferRows) : '<p class="muted empty-message">No transfer decisions are pending at the director level.</p>'}</section>
    <section id="reports" class="card content-card instructor-section"><div class="section-heading"><div><p class="eyebrow">REPORTS</p><h2>Cross-department performance</h2><p class="muted">Attendance, pass rates, published scores, certificates, and transfer volumes.</p></div><div class="button-row"><button class="button button-outline button-small" type="button" data-director-export="csv">Export Excel-compatible CSV</button><button class="button button-outline button-small" type="button" data-director-export="pdf">Print / Save PDF</button></div></div>${table(['Department', 'Learners', 'Active enrollments', 'Attendance', 'Pass rate', 'Published scores', 'Certificates', 'Transfers'], departmentRows)}</section>
    <section id="announcements" class="card content-card instructor-section"><div class="section-heading"><div><p class="eyebrow">ANNOUNCEMENTS</p><h2>Institution-wide updates</h2><p class="muted">Pinned and urgent updates are visible to everyone.</p></div></div><form id="announcement-form" class="form-grid"><label>Title<input name="title" maxlength="180" required></label><label class="span-all">Announcement<textarea name="body" rows="4" minlength="10" maxlength="5000" required></textarea></label><label><input name="pinned" type="checkbox"> Pin this announcement</label><div class="span-all"><button class="button button-primary" type="submit">Post institution-wide update</button></div></form>${announcementRows.length ? table(['Title', 'Message', 'Priority', 'Published'], announcementRows) : '<p class="muted empty-message">No institution-wide announcements have been posted yet.</p>'}</section>
    <section id="audit" class="card content-card instructor-section"><div class="section-heading"><div><p class="eyebrow">AUDIT</p><h2>Sensitive actions log</h2><p class="muted">A chronological record of approvals, policy changes and institutional decisions.</p></div></div>${auditRows.length ? table(['Actor', 'Action', 'Entity', 'Time'], auditRows) : '<p class="muted empty-message">No audit events have been recorded yet.</p>'}</section></div>`;
}

function renderInstructorWorkspace() {
  const data = appData.instructor;
  const courses = data.courses;
  const courseOptions = courses.map(course => `<option value="${esc(course.id)}">${esc(course.code)} · ${esc(course.title)}</option>`).join('');
  const learnerOptions = data.roster.map(learner => `<option value="${esc(learner.studentId)}" data-course="${esc(learner.courseId)}">${esc(learner.learner)} · ${esc(learner.matric)}</option>`).join('');
  const sessionOptions = data.sessions.map(session => `<option value="${esc(session.id)}" data-course="${esc(session.courseId)}">${esc(session.title)} · ${esc(session.startsAt)}</option>`).join('');
  const venueOptions = data.geofences.map(venue => `<option value="${esc(venue.id)}">${esc(venue.name)} · ${esc(venue.radius)} m</option>`).join('');
  const rosterRows = data.roster.map(learner => [learner.code, learner.learner, learner.matric,
    learner.attendancePercent === null ? 'No sessions' : `${learner.attendancePercent}% (${learner.attendancePresent}/${learner.attendanceTotal})`,
    learner.publishedScores ? `${learner.scoreAverage}% (${learner.publishedScores})` : 'No published scores',
    { text: learner.atRisk ? 'At risk' : 'On track', className: `tag ${learner.atRisk ? 'tag-danger' : 'tag-success'}` }]);
  const atRiskRows = data.atRisk.map(learner => [learner.code, learner.learner,
    learner.attendancePercent !== null && learner.attendancePercent < 75 ? `${learner.attendancePercent}% attendance` : 'Attendance on track',
    learner.publishedScores > 0 && learner.scoreAverage < 50 ? `${learner.scoreAverage}% average` : 'Scores on track']);
  const checkinRows = data.checkins.map(record => [record.code, record.learner, record.matric, record.checkedInAt, `${Math.round(Number(record.distance))} m`, record.status]);
  const eligibleRows = data.certificateEligible.map(learner => [learner.learner, learner.matric, `${learner.attendancePercent}%`, `${learner.scoreAverage}%`, { text: 'Eligible to review', className: 'tag tag-success' }]);
  const courseList = courses.length ? courses.map(course => `<span class="tag tag-grade">${esc(course.code)} · ${esc(course.title)}</span>`).join(' ') : '<p class="muted">No active courses are assigned to your account.</p>';
  const rosterTable = table(['Course', 'Learner', 'Matric', 'Attendance', 'Published average', 'Progress'], rosterRows);
  const atRiskTable = data.atRisk.length ? table(['Course', 'Learner', 'Attendance', 'Scores'], atRiskRows) : '<div class="all-clear"><h2>No at-risk learners</h2><p class="muted">Your current classes have no learners below the attendance or score thresholds.</p></div>';
  const checkinsTable = data.checkins.length ? table(['Course', 'Learner', 'Matric', 'Checked in', 'Distance', 'Status'], checkinRows) : '<p class="muted empty-message">No check-ins in currently open sessions.</p>';
  const eligibleTable = data.certificateEligible.length ? table(['Learner', 'Matric', 'Attendance', 'Average', 'Eligibility'], eligibleRows) : '<p class="muted empty-message">No eligible students in your classes yet.</p>';
  const scoredLearners = data.roster.filter(learner => Number(learner.publishedScores) > 0);
  const attendanceLearners = data.roster.filter(learner => learner.attendancePercent !== null);
  const studentIds = new Set(data.roster.map(learner => String(learner.studentId)));
  const atRiskIds = new Set(data.atRisk.map(learner => String(learner.studentId)));
  const averageScore = scoredLearners.length ? scoredLearners.reduce((sum, learner) => sum + Number(learner.scoreAverage), 0) / scoredLearners.length : 0;
  const averageAttendance = attendanceLearners.length ? attendanceLearners.reduce((sum, learner) => sum + Number(learner.attendancePercent), 0) / attendanceLearners.length : 0;
  const courseSummaries = courses.map(course => {
    const learners = data.roster.filter(learner => String(learner.courseId) === String(course.id));
    const scored = learners.filter(learner => Number(learner.publishedScores) > 0);
    const attended = learners.filter(learner => learner.attendancePercent !== null);
    const passCount = scored.filter(learner => Number(learner.scoreAverage) >= 50).length;
    return [course.code, learners.length,
      attended.length ? `${(attended.reduce((sum, learner) => sum + Number(learner.attendancePercent), 0) / attended.length).toFixed(1)}%` : 'No sessions',
      scored.length ? `${(scored.reduce((sum, learner) => sum + Number(learner.scoreAverage), 0) / scored.length).toFixed(1)}%` : 'No published scores',
      scored.length ? `${Math.round(passCount / scored.length * 100)}% (${passCount}/${scored.length})` : 'No published scores',
      learners.filter(learner => learner.atRisk).length];
  });
  const performanceTable = table(['Course', 'Learners', 'Avg. attendance', 'Avg. score', 'Pass rate', 'At risk'], courseSummaries);
  const transfers = data.transfers.length ? data.transfers.map(request => `<article class="transfer-item"><div class="transfer-summary"><div><h3>${esc(request.learner)} · ${esc(request.matric)}</h3><p>Transfer to ${esc(request.targetProgram)} · ${esc(request.reason)} · ${esc(request.submitted)}</p><p class="transfer-explanation">${esc(request.explanation)}</p>${request.comments ? `<p class="muted"><strong>Previous review notes:</strong> ${esc(request.comments)}</p>` : ''}</div><span class="tag tag-warning">Instructor review</span></div><form class="transfer-review-form" data-transfer-id="${esc(request.id)}"><label>Comment or recommendation<textarea name="comment" rows="2" minlength="5" required placeholder="Add context for the HOD"></textarea></label><div class="button-row"><button class="button button-outline" type="submit" data-decision="comment">Add comment</button><button class="button button-primary" type="submit" data-decision="approve">Recommend transfer</button><button class="button button-danger" type="submit" data-decision="reject">Do not recommend</button></div></form></article>`).join('') : '<p class="muted empty-message">No transfer requests are assigned to your classes.</p>';
  const flags = data.scoreFlags.length ? table(['Course', 'Learner', 'Matric', 'Flag', 'Note', 'Date'], data.scoreFlags.map(flag => [flag.code, flag.learner, flag.matric, flag.type, flag.note, flag.created])) : '<p class="muted empty-message">No open score flags.</p>';
  const announcements = data.announcements.length ? table(['Course', 'Title', 'Announcement', 'Posted'], data.announcements.map(item => [item.code, item.title, item.body, item.published])) : '<p class="muted empty-message">No class announcements posted yet.</p>';

  return `<div id="overview" class="instructor-overview"><div class="page-heading"><div><p class="eyebrow">INSTRUCTOR · CLASS REVIEW</p><h1>Your classes</h1><p class="muted">A performance overview across your assigned courses.</p></div><span class="date-chip">${courses.length} active courses</span></div>
    <section class="instructor-course-strip">${courseList}</section>
    <section class="stat-grid instructor-stat-grid" aria-label="Class performance summary"><article class="card stat-card"><div class="stat-label">Students enrolled</div><div class="stat-value">${studentIds.size}</div><p class="stat-foot">Across ${courses.length} courses</p></article><article class="card stat-card"><div class="stat-label">Average attendance</div><div class="stat-value">${averageAttendance.toFixed(1)}<span>%</span></div><p class="stat-foot">${attendanceLearners.length} course enrollments measured</p></article><article class="card stat-card"><div class="stat-label">Published score average</div><div class="stat-value">${averageScore.toFixed(1)}<span>/100</span></div><p class="stat-foot">${scoredLearners.length} course results available</p></article><article class="card stat-card"><div class="stat-label">At-risk students</div><div class="stat-value">${atRiskIds.size}</div><p class="stat-foot">Below attendance or score threshold</p></article></section>
    <section id="performance" class="card content-card instructor-section"><div class="section-heading"><div><p class="eyebrow">PERFORMANCE REVIEW</p><h2>Course summary</h2><p class="muted">Pass rate is based on learners with published scores; a passing average is 50 or higher.</p></div></div>${performanceTable}</section></div>
    <section id="results" class="card content-card instructor-section"><div class="section-heading"><div><p class="eyebrow">RESULTS</p><h2>Enter or edit draft scores</h2><p class="muted">Use a class roster entry or import the CSV template format.</p></div></div><form id="score-form" class="form-grid"><label>Course<select name="course_id" required><option value="">Choose a course</option>${courseOptions}</select></label><label>Student<select name="student_id" required><option value="">Choose a learner</option>${learnerOptions}</select></label><label>Test score <span class="optional">/30</span><input name="test_score" type="number" min="0" max="30" step="0.5" required></label><label>Exam score <span class="optional">/70</span><input name="exam_score" type="number" min="0" max="70" step="0.5" required></label><div class="span-all"><button class="button button-primary" type="submit">Save draft score</button></div></form><hr class="section-rule"><form id="bulk-score-form" class="form-grid"><label>Course<select name="course_id" required><option value="">Choose a course</option>${courseOptions}</select></label><label>CSV file<input name="csv" type="file" accept=".csv,text/csv" required></label><div class="span-all"><p class="muted">CSV columns: <code>matric_no,test_score,exam_score</code>. Import is all-or-nothing; only draft scores can be changed.</p><button class="button button-outline" type="submit">Upload scores CSV</button></div></form><div class="section-heading section-heading-spaced"><div><h2>Roster and progress</h2></div></div>${rosterTable}<div class="section-heading section-heading-spaced"><div><h2>At-risk learners</h2></div><span class="section-count">${data.atRisk.length} course records</span></div>${atRiskTable}</section>
    <section class="card content-card instructor-section"><div class="section-heading"><div><p class="eyebrow">SCORE FOLLOW-UP</p><h2>Flag missing or disputed scores</h2></div></div><form id="score-flag-form" class="form-grid"><label>Course<select name="course_id" required><option value="">Choose a course</option>${courseOptions}</select></label><label>Student<select name="student_id" required><option value="">Choose a learner</option>${learnerOptions}</select></label><label>Flag type<select name="type" required><option value="">Choose a flag</option><option value="missing">Missing score</option><option value="disputed">Disputed score</option></select></label><label>Details<input name="note" maxlength="1000" required placeholder="Short explanation"></label><div class="span-all"><button class="button button-outline" type="submit">Submit score flag</button></div></form><div class="section-heading section-heading-spaced"><div><h2>Open flags</h2></div></div>${flags}</section>
    <section id="attendance" class="card content-card instructor-section"><div class="section-heading"><div><p class="eyebrow">ATTENDANCE</p><h2>Create a session</h2><p class="muted">The department default geofence is used unless you select another active venue.</p></div></div><form id="session-form" class="form-grid"><label>Course<select name="course_id"><option value="">Use department default course</option>${courseOptions}</select></label><label>Venue geofence<select name="geofence_id"><option value="">Use department default venue</option>${venueOptions}</select></label><label>Session title<input name="title" maxlength="180" required></label><label>Starts at<input name="starts_at" type="datetime-local" required></label><label>Ends at<input name="ends_at" type="datetime-local" required></label><div class="span-all"><button class="button button-primary" type="submit">Create attendance session</button></div></form><div class="section-heading section-heading-spaced"><div><h2>Open check-ins</h2></div><button class="button button-outline button-small" type="button" id="refresh-checkins">Refresh</button></div><div id="live-checkins">${checkinsTable}</div><div class="section-heading section-heading-spaced"><div><h2>Submit a correction for HOD approval</h2><p class="muted">Use for GPS failure or an approved absence. This does not directly edit the record.</p></div></div><form id="attendance-correction-form" class="form-grid"><label>Course<select name="course_id" required><option value="">Choose a course</option>${courseOptions}</select></label><label>Session<select name="session_id" required><option value="">Choose a session</option>${sessionOptions}</select></label><label>Student<select name="student_id" required><option value="">Choose a learner</option>${learnerOptions}</select></label><label>Requested status<select name="requested_status" required><option value="present">Present</option><option value="excused">Excused absence</option></select></label><label class="span-all">Reason<textarea name="reason" minlength="8" required placeholder="GPS failure, approved absence, or another reason"></textarea></label><div class="span-all"><button class="button button-outline" type="submit">Submit correction request</button></div></form></section>
    <section id="transfers" class="card content-card instructor-section"><div class="section-heading"><div><p class="eyebrow">TRANSFER REVIEW</p><h2>Requests from your classes</h2><p class="muted">Add a note or send your recommendation to the HOD.</p></div></div><div class="transfer-list">${transfers}</div></section>
    <section id="announcements" class="card content-card instructor-section"><div class="section-heading"><div><p class="eyebrow">CLASS COMMUNICATION</p><h2>Post an announcement</h2><p class="muted">Only enrolled students in the selected class will see it.</p></div></div><form id="announcement-form" class="form-grid"><label>Course<select name="course_id" required><option value="">Choose a course</option>${courseOptions}</select></label><label>Title<input name="title" maxlength="180" required></label><label class="span-all">Announcement<textarea name="body" minlength="10" maxlength="5000" required></textarea></label><div class="span-all"><button class="button button-primary" type="submit">Post to class</button></div></form><div class="section-heading section-heading-spaced"><div><h2>Your announcements</h2></div></div>${announcements}</section>
    <section id="certificates" class="card content-card instructor-section"><div class="section-heading"><div><p class="eyebrow">COMPLETION REVIEW</p><h2>Certificate eligibility</h2><p class="muted">Read-only eligibility uses attendance and published results across assigned courses.</p></div></div>${eligibleTable}</section>`;
}

function bindPageEvents(user) {
  if (page === 'attendance') bindAttendance();
  if (page === 'certificates') $('#download-certificate')?.addEventListener('click', downloadCertificate);
  if (page === 'transfer') bindTransfer();
  if (page === 'profile') bindProfile();
  if (page === 'admin') {
    if (user.role === 'instructor') {
      bindInstructorWorkspace();
    } else {
      bindAdmin(user);
      if (user.role === 'director') bindDirectorWorkspace();
      if (user.role === 'superadmin') bindSuperAdminWorkspace();
      if (appData.scoreOptions && $('#score-form')) bindScoreForm();
    }
  }
}

function bindAttendance() {
  $('#check-in').addEventListener('click', () => {
    const message = $('#checkin-message');
    const button = $('#check-in');
    if (!navigator.geolocation || !window.isSecureContext) {
      message.className = 'checkin-message message-error';
      message.textContent = 'Check-in requires GPS in a secure browser context. Open the portal over HTTPS or localhost and enable location services.';
      return;
    }
    button.disabled = true;
    message.className = 'checkin-message';
    message.textContent = 'Getting a fresh GPS location…';
    const submit = async position => {
      try {
        const { latitude, longitude, accuracy } = position.coords;
        const result = await portalApi('student/mark_attendance.php', { method: 'POST', body: { latitude, longitude, accuracy } });
        await refreshPage(currentUser());
        const message = $('#checkin-message');
        message.className = 'checkin-message message-success';
        message.textContent = `Check-in recorded. You are ${result.distance} m from ${result.venue}.`;
        toast('Attendance check-in recorded.');
      } catch (error) {
        const message = $('#checkin-message');
        message.className = 'checkin-message message-error';
        message.textContent = error.data?.reason === 'inaccurate_location'
          ? 'GPS accuracy is too low to confirm you are inside the attendance boundary. Move outdoors and try again.'
          : error.data?.distance !== undefined
          ? `You are ${error.data.distance} m away. The check-in limit is ${error.data.radius} m.`
          : error.message;
        toast(error.message, 'error');
      } finally {
        $('#check-in').disabled = false;
      }
    };
    navigator.geolocation.getCurrentPosition(submit, error => {
      button.disabled = false;
      message.className = 'checkin-message message-error';
      message.textContent = error.code === error.PERMISSION_DENIED
        ? 'Location permission is required for attendance check-in. Enable it in your browser settings and try again.'
        : 'We could not get a reliable GPS location. Move to an open area and try again.';
    }, { enableHighAccuracy: true, maximumAge: 0, timeout: 15000 });
  });
}

function downloadCertificate() {
  const certificateData = appData.certificates.find(item => item.status === 'cleared');
  if (!certificateData) return;
  const certificate = `<!doctype html><html lang="en"><meta charset="utf-8"><title>${esc(certificateData.title)}</title><style>body{font-family:Georgia,serif;text-align:center;padding:12vh 8vw;color:#29221e}main{border:8px double #c2410c;padding:4rem 2rem}h1{color:#c2410c;font-size:2.4rem}p{font-size:1.2rem}.serial{font:14px Arial,sans-serif;color:#65584f}</style><main><p>SKILLPORTAL · CERTIFICATE OF COMPLETION</p><h1>${esc(certificateData.title)}</h1><p>This certificate is awarded to <strong>${esc(certificateData.recipient)}</strong>.</p><p>Program: ${esc(certificateData.program)}</p><p class="serial">Serial: ${esc(certificateData.serial)}</p></main></html>`;
  const blob = new Blob([certificate], { type: 'text/html' });
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = `${certificateData.serial}.html`;
  link.click();
  URL.revokeObjectURL(url);
}

function bindTransfer() {
  const form = $('#transfer-form');
  const reasonSelect = $('select[name="reason"]', form);
  const attachment = $('input[name="attachment"]', form);
  const requirement = $('#transfer-document-requirement', form);
  const updateDocumentRequirement = () => {
    const required = reasonSelect.selectedOptions[0]?.dataset.requiresDocument === 'true';
    attachment.required = required;
    requirement.textContent = required ? '(required for this reason)' : '(optional)';
  };
  reasonSelect.addEventListener('change', updateDocumentRequirement);
  updateDocumentRequirement();
  form.addEventListener('submit', event => {
    event.preventDefault();
    const formData = new FormData(event.currentTarget);
    formData.set('program_id', String(formData.get('program')));
    portalApi('student/transfer_apply.php', { method: 'POST', body: formData })
      .then(() => refreshPage(currentUser()).then(() => toast('Transfer request submitted.')))
      .catch(error => toast(error.message, 'error'));
  });
}

function bindProfile() {
  $('#profile-form').addEventListener('submit', event => {
    event.preventDefault();
    const values = new FormData(event.currentTarget);
    const profile = Object.fromEntries(values.entries());
    portalApi('student/profile.php', { method: 'POST', body: profile })
      .then(() => {
        appData.user.email = profile.email;
        sessionStorage.setItem('skillportal-user', JSON.stringify(appData.user));
        toast('Profile details saved.');
      })
      .catch(error => toast(error.message, 'error'));
  });
  $('#correction-form').addEventListener('submit', event => {
    event.preventDefault();
    portalApi('student/correction_request.php', { method: 'POST', body: new FormData(event.currentTarget) })
      .then(() => {
        toast('Correction request submitted for review.');
        event.currentTarget.reset();
      })
      .catch(error => toast(error.message, 'error'));
  });
}

function bindAdmin(user) {
  document.querySelectorAll('[data-action]').forEach(button => button.addEventListener('click', async () => {
    const id = Number(button.dataset.id);
    const action = button.dataset.action;
    const kind = button.dataset.kind;
    const routes = {
      score_verify: ['admin/scores_verify.php', { score_id: id }],
      score_publish: ['admin/scores_publish.php', { score_id: id }],
      score_withdraw: ['admin/scores_withdraw.php', { score_id: id }],
      transfer: ['admin/transfer_decide.php', { transfer_id: id, decision: action, comment: '' }],
      attendance_correction: ['admin/attendance_correction_decide.php', { correction_id: id, decision: action }],
      score_flag: ['admin/score_flag_decide.php', { flag_id: id }],
      certificate: ['admin/cert_approve.php', { certificate_id: id, decision: action }],
      profile_correction: ['admin/profile_correction_decide.php', { request_id: id, decision: action }],
    };
    if (!routes[kind]) return;
    try {
      const reasonPrompts = {
        score_verify: 'Add a brief verification reason (5-1000 characters).',
        attendance_correction: 'Add the HOD decision reason (5-1000 characters).',
        transfer: 'Add the transfer decision reason (5-1000 characters).',
        certificate: 'Add a certificate decision reason (5-1000 characters).',
        score_withdraw: 'Explain why this published result is being withdrawn (5-1000 characters).'
      };
      const payload = { ...routes[kind][1] };
      if (kind === 'score_verify' || kind === 'attendance_correction') {
        const reason = window.prompt(reasonPrompts[kind]);
        if (reason === null) return;
        if (reason.trim().length < 5) {
          toast('A reason of at least 5 characters is required.', 'error');
          return;
        }
        payload.reason = reason.trim();
      }
      if (kind === 'transfer') {
        const reason = window.prompt(reasonPrompts[kind]);
        if (reason === null) return;
        if (reason.trim().length < 5) {
          toast('A reason of at least 5 characters is required.', 'error');
          return;
        }
        payload.reason = reason.trim();
        payload.comment = reason.trim();
      }
      if ((kind === 'certificate' && ['reject', 'revoke'].includes(action)) || kind === 'score_withdraw') {
        if (kind === 'certificate' && action === 'revoke' && !window.confirm('Revoke this cleared certificate?')) return;
        const reason = window.prompt(reasonPrompts[kind]);
        if (reason === null) return;
        if (reason.trim().length < 5) {
          toast('A reason of at least 5 characters is required.', 'error');
          return;
        }
        payload.reason = reason.trim();
      }
      await portalApi(routes[kind][0], { method: 'POST', body: payload });
      toast('Request updated.');
      await refreshPage(user);
    } catch (error) {
      toast(error.message, 'error');
    }
  }));
}

function renderDirectorCohortSection(director) {
  const programs = (director.programs || []).map(program => `<option value="${esc(program.id)}">${esc(program.name)} · ${esc(program.code)}</option>`).join('');
  const cohorts = director.cohorts || [];
  const rows = cohorts.map(cohort => `<tr><td>${esc(cohort.name)}</td><td>${esc(cohort.code)}</td><td>${esc(cohort.program)}</td><td>${esc(cohort.students)}/${esc(cohort.capacity)}</td><td>${esc(cohort.startsOn || 'Not started')}</td><td>${esc(cohort.endsOn || 'Not started')}</td><td>${esc(cohort.status)}</td><td>${cohort.status === 'planned' ? `<button class="button button-primary button-small" type="button" data-cohort-action="cohort_start" data-cohort-id="${esc(cohort.id)}">Start cohort</button>` : cohort.status === 'active' ? `<button class="button button-danger button-small" type="button" data-cohort-action="cohort_end" data-cohort-id="${esc(cohort.id)}">End cohort</button>` : 'Ended'}</td></tr>`).join('');
  return `<section id="cohorts" class="card content-card instructor-section"><div class="section-heading"><div><p class="eyebrow">COHORT MANAGEMENT</p><h2>Start or end a cohort</h2><p class="muted">A cohort runs for six calendar months from its start date. Ending it early closes enrollment immediately.</p></div></div><form id="director-cohort-form" class="form-grid"><label>Cohort name<input name="name" maxlength="150" required placeholder="Solar Installation · Intake 01"></label><label>Cohort code<input name="code" maxlength="40" required></label><label>Program<select name="programId" required><option value="">Choose a program</option>${programs}</select></label><label>Capacity<input name="capacity" type="number" min="1" max="100000" value="100" required></label><div class="span-all"><button class="button button-primary" type="submit">Create planned cohort</button></div></form><div class="table-wrap"><table><thead><tr>${['Cohort', 'Code', 'Program', 'Students / capacity', 'Started', 'Ends', 'Status', 'Action'].map(label => `<th scope="col">${label}</th>`).join('')}</tr></thead><tbody>${rows || `<tr><td class="empty-cell" colspan="8">No cohorts have been created.</td></tr>`}</tbody></table></div></section>`;
}

function bindDirectorWorkspace() {
  $('#director-cohort-form')?.addEventListener('submit', event => {
    const body = Object.fromEntries(new FormData(event.currentTarget).entries());
    body.action = 'cohort_create';
    submitDirectorCohortForm(event, body, 'Cohort created. Start it when enrollment is ready.');
  });
  document.querySelectorAll('[data-cohort-action]').forEach(button => button.addEventListener('click', async () => {
    const action = button.dataset.cohortAction;
    const cohortId = Number(button.dataset.cohortId);
    if (action === 'cohort_end' && !window.confirm('End this cohort now? Its end date will be today.')) return;
    try {
      await portalApi('admin/director_cohort_manage.php', { method: 'POST', body: { action, cohortId } });
      await refreshPage(currentUser());
      toast(action === 'cohort_start' ? 'Cohort started. It is scheduled to run for six months.' : 'Cohort ended.');
    } catch (error) { toast(error.message, 'error'); }
  }));
  $('#policy-form')?.addEventListener('submit', event => submitJsonForm(event, 'admin/policies_save.php', 'Institutional policy saved.'));
  $('#announcement-form')?.addEventListener('submit', event => submitJsonForm(event, 'admin/director_announcement_save.php', 'Institution-wide announcement posted.'));
  $('#select-pending-certificates')?.addEventListener('change', event => {
    document.querySelectorAll('#certificate-batch-form input[name="certificate_ids"]').forEach(input => {
      input.checked = event.currentTarget.checked;
    });
  });
  $('#certificate-batch-form')?.addEventListener('submit', async event => {
    event.preventDefault();
    const certificateIds = [...document.querySelectorAll('#certificate-batch-form input[name="certificate_ids"]:checked')].map(input => Number(input.value));
    if (!certificateIds.length) {
      toast('Select at least one pending certificate.', 'error');
      return;
    }
    if (!window.confirm(`Clear ${certificateIds.length} selected certificate${certificateIds.length === 1 ? '' : 's'}?`)) return;
    try {
      await portalApi('admin/cert_approve.php', { method: 'POST', body: { certificate_ids: certificateIds, decision: 'approve' } });
      await refreshPage(currentUser());
      toast(`${certificateIds.length} certificate${certificateIds.length === 1 ? '' : 's'} cleared.`);
    } catch (error) {
      toast(error.message, 'error');
    }
  });
  document.querySelectorAll('[data-director-export]').forEach(button => button.addEventListener('click', () => {
    exportDirectorReport(button.dataset.directorExport);
  }));
}

async function submitDirectorCohortForm(event, body, successMessage) {
  event.preventDefault();
  try {
    await portalApi('admin/director_cohort_manage.php', { method: 'POST', body });
    await refreshPage(currentUser());
    toast(successMessage);
  } catch (error) {
    toast(error.message, 'error');
  }
}

function bindSuperAdminWorkspace() {
  const accountForm = $('[data-superadmin-form="user_create"]');
  if (accountForm && !accountForm.elements.userId) {
    const userIdField = document.createElement('input');
    userIdField.type = 'hidden';
    userIdField.name = 'userId';
    accountForm.append(userIdField);
  }
  const updateAccountFields = () => {
    const role = accountForm.elements.role.value;
    const isStudent = role === 'student';
    const isStaff = ['instructor', 'hod', 'director'].includes(role);
    accountForm.querySelectorAll('[data-student-field]').forEach(label => {
      label.hidden = !isStudent;
      label.querySelector('input, select').required = isStudent;
    });
    accountForm.querySelectorAll('[data-staff-field]').forEach(label => { label.hidden = !isStaff; });
    accountForm.elements.departmentId.required = isStudent || isStaff;
    accountForm.elements.programId.required = isStudent;
    accountForm.elements.password.required = !accountForm.elements.userId.value;
    accountForm.querySelector('button[type="submit"]').textContent = accountForm.elements.userId.value ? 'Update account' : 'Create account';
  };
  accountForm?.elements.role.addEventListener('change', updateAccountFields);
  if (accountForm) updateAccountFields();

  document.querySelectorAll('[data-superadmin-form]').forEach(form => form.addEventListener('submit', async event => {
    event.preventDefault();
    const action = form.dataset.superadminForm === 'user_create' && form.elements.userId?.value ? 'user_update' : form.dataset.superadminForm;
    const values = Object.fromEntries(new FormData(form).entries());
    values.action = action;
    if (form.elements.active) values.active = form.elements.active.checked;
    try {
      await portalApi('admin/superadmin_manage.php', { method: 'POST', body: values });
      await refreshPage(currentUser());
      toast(action === 'user_create' ? 'Account created.' : 'Record saved.');
    } catch (error) {
      toast(error.message, 'error');
    }
  }));

  document.querySelectorAll('[data-superadmin-edit]').forEach(button => button.addEventListener('click', () => {
    const collection = button.dataset.superadminEdit;
    const records = appData.superadmin?.[collection] || [];
    const record = records.find(item => String(item.id) === button.dataset.recordId);
    const action = { departments: 'department_save', programs: 'program_save', courses: 'course_save', geofences: 'geofence_save', sessions: 'session_save' }[collection];
    const form = document.querySelector(`[data-superadmin-form="${action}"]`);
    if (!record || !form) return;
    for (const field of form.elements) {
      if (!field.name || !(field.name in record)) continue;
      if (field.type === 'checkbox') field.checked = Boolean(Number(record[field.name]) || record[field.name] === true);
      else field.value = record[field.name] ?? '';
    }
    form.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }));

  document.querySelectorAll('[data-superadmin-edit-user]').forEach(button => button.addEventListener('click', () => {
    const account = (appData.superadmin?.users || []).find(item => String(item.id) === button.dataset.superadminEditUser);
    if (!account || !accountForm) return;
    for (const field of accountForm.elements) {
      if (field.name in account) field.value = account[field.name] ?? '';
    }
    accountForm.elements.userId.value = account.id;
    accountForm.elements.password.value = '';
    accountForm.elements.role.dispatchEvent(new Event('change'));
    accountForm.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }));

  document.querySelectorAll('[data-superadmin-status]').forEach(button => button.addEventListener('click', async () => {
    const status = button.dataset.status;
    if (!window.confirm(`${status === 'inactive' ? 'Remove' : status === 'suspended' ? 'Suspend' : 'Reactivate'} this account?`)) return;
    try {
      await portalApi('admin/superadmin_manage.php', { method: 'POST', body: { action: 'user_status', userId: Number(button.dataset.superadminStatus), status } });
      await refreshPage(currentUser());
      toast('Account status updated.');
    } catch (error) { toast(error.message, 'error'); }
  }));

  document.querySelectorAll('[data-superadmin-reset]').forEach(button => button.addEventListener('click', async () => {
    const password = window.prompt('Enter a new password with at least 10 characters.');
    if (password === null) return;
    if (password.length < 10) { toast('Password must contain at least 10 characters.', 'error'); return; }
    try {
      await portalApi('admin/superadmin_manage.php', { method: 'POST', body: { action: 'password_reset', userId: Number(button.dataset.superadminReset), password } });
      toast('Password reset.');
    } catch (error) { toast(error.message, 'error'); }
  }));

  $('#enrollment-import-form')?.addEventListener('submit', async event => {
    event.preventDefault();
    const body = new FormData(event.currentTarget);
    body.set('action', 'enrollment_import');
    try {
      const result = await portalApi('admin/superadmin_manage.php', { method: 'POST', body });
      await refreshPage(currentUser());
      toast(`${result.imported} enrollments imported.`);
    } catch (error) { toast(error.message, 'error'); }
  });

  $('#superadmin-settings-form')?.addEventListener('submit', async event => {
    event.preventDefault();
    const form = event.currentTarget;
    const fields = Object.fromEntries(new FormData(form).entries());
    const reasons = String(fields.transferReasons || '').split(/\r?\n/).map(line => line.trim()).filter(Boolean).map(line => {
      const [label, requirement] = line.split('|');
      return { label: label.trim(), requiresDocument: requirement?.trim().toLowerCase() === 'document' };
    });
    const body = {
      action: 'settings_save',
      attendance: { maxFailedAttempts: fields.maxFailedAttempts, windowMinutes: fields.windowMinutes },
      transferReasons: reasons,
      certificate: {
        issuer: fields.issuer, serialPrefix: fields.serialPrefix, nextSerial: fields.nextSerial,
        signatureName: fields.signatureName, logoUrl: fields.logoUrl, qrVerifyBaseUrl: fields.qrVerifyBaseUrl, wording: fields.wording
      },
      notifications: {
        channels: { email: Boolean(form.elements.channel_email.checked), sms: Boolean(form.elements.channel_sms.checked), push: Boolean(form.elements.channel_push.checked) },
        templates: { announcement: fields.template_announcement, result: fields.template_result }
      }
    };
    try {
      await portalApi('admin/superadmin_manage.php', { method: 'POST', body });
      await refreshPage(currentUser());
      toast('System settings saved.');
    } catch (error) { toast(error.message, 'error'); }
  });
}

function exportDirectorReport(format) {
  const headers = ['Department', 'Learners', 'Active enrollments', 'Attendance %', 'Pass rate %', 'Published scores', 'Certificates issued', 'Transfers'];
  const rows = (appData.director?.departments || []).map(item => [
    item.name, item.learners, item.activeEnrollments, item.attendanceRate, item.passRate,
    item.publishedScores, item.certificatesIssued, item.transfers
  ]);
  if (format === 'pdf') {
    document.body.classList.add('director-report-print');
    window.addEventListener('afterprint', () => document.body.classList.remove('director-report-print'), { once: true });
    window.print();
    return;
  }
  const csv = [headers, ...rows].map(row => row.map(value => {
    let text = String(value ?? '');
    if (/^[=+\-@]/.test(text)) text = `'${text}`;
    return `"${text.replace(/"/g, '""')}"`;
  }).join(',')).join('\r\n');
  const url = URL.createObjectURL(new Blob([`\uFEFF${csv}`], { type: 'text/csv;charset=utf-8' }));
  const link = document.createElement('a');
  link.href = url;
  link.download = `institution-report-${new Date().toISOString().slice(0, 10)}.csv`;
  link.click();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
}

function bindScoreForm() {
  const form = $('#score-form');
  const courseSelect = $('select[name="course_id"]', form);
  const learnerSelect = $('select[name="student_id"]', form);
  const testInput = $('input[name="test_score"]', form);
  const examInput = $('input[name="exam_score"]', form);
  const populateDraft = () => {
    const saved = appData.scoreOptions.scores.find(score => String(score.courseId) === courseSelect.value && String(score.studentId) === learnerSelect.value);
    const editable = !saved || saved.status === 'draft';
    testInput.value = editable && saved ? saved.test : '';
    examInput.value = editable && saved ? saved.exam : '';
    testInput.disabled = !editable;
    examInput.disabled = !editable;
    form.dataset.locked = editable ? 'false' : 'true';
  };
  courseSelect.addEventListener('change', () => {
    const courseId = courseSelect.value;
    [...learnerSelect.options].forEach(option => {
      option.hidden = Boolean(option.value) && option.dataset.course !== courseId;
    });
    learnerSelect.value = '';
    populateDraft();
  });
  learnerSelect.addEventListener('change', populateDraft);
  form.addEventListener('submit', event => {
    event.preventDefault();
    if (form.dataset.locked === 'true') {
      toast('Verified and published scores cannot be edited.', 'error');
      return;
    }
    const values = Object.fromEntries(new FormData(form).entries());
    portalApi('admin/scores_save.php', { method: 'POST', body: values })
      .then(() => refreshPage(currentUser()).then(() => toast('Draft score saved.')))
      .catch(error => toast(error.message, 'error'));
  });
}

let liveCheckinTimer;

function bindInstructorWorkspace() {
  if ($('#score-form')) bindScoreForm();
  bindCourseLearnerFilter('#score-flag-form');
  bindCourseLearnerFilter('#attendance-correction-form', 'session_id');

  $('#bulk-score-form')?.addEventListener('submit', event => {
    event.preventDefault();
    portalApi('admin/scores_bulk.php', { method: 'POST', body: new FormData(event.currentTarget) })
      .then(result => refreshPage(currentUser()).then(() => toast(`${result.imported} draft scores imported.`)))
      .catch(error => toast(error.data?.details ? `${error.message} ${error.data.details.join(' ')}` : error.message, 'error'));
  });
  $('#score-flag-form')?.addEventListener('submit', event => submitJsonForm(event, 'admin/score_flag.php', 'Score flag submitted.'));
  $('#session-form')?.addEventListener('submit', event => {
    const form = event.currentTarget;
    const course = form.elements.course_id;
    const venue = form.elements.geofence_id;
    if (!course.value) {
      const fallback = [...course.options].find(option => option.value && option.value !== '');
      if (fallback) course.value = fallback.value;
    }
    if (!venue.value) {
      const fallback = [...venue.options].find(option => option.value && option.value !== '');
      if (fallback) venue.value = fallback.value;
    }
    submitJsonForm(event, 'admin/attendance_session_save.php', 'Attendance session created.');
  });
  $('#attendance-correction-form')?.addEventListener('submit', event => submitJsonForm(event, 'admin/attendance_correction_submit.php', 'Correction sent to the HOD for review.'));
  $('#announcement-form')?.addEventListener('submit', event => submitJsonForm(event, 'admin/announcement_save.php', 'Announcement posted to the class.'));
  document.querySelectorAll('.transfer-review-form').forEach(form => form.addEventListener('submit', async event => {
    event.preventDefault();
    const button = event.submitter;
    const comment = new FormData(form).get('comment');
    const decision = button?.dataset.decision || 'comment';
    const route = decision === 'comment' ? 'admin/transfer_comment.php' : 'admin/transfer_decide.php';
    const body = decision === 'comment'
      ? { transfer_id: Number(form.dataset.transferId), comment }
      : { transfer_id: Number(form.dataset.transferId), decision, comment };
    try {
      await portalApi(route, { method: 'POST', body });
      await refreshPage(currentUser());
      toast(decision === 'comment' ? 'Transfer comment added.' : 'Transfer recommendation submitted.');
    } catch (error) {
      toast(error.message, 'error');
    }
  }));
  $('#refresh-checkins')?.addEventListener('click', refreshLiveCheckins);
  clearInterval(liveCheckinTimer);
  const now = Date.now();
  if (appData.instructor.sessions.some(session => session.status !== 'closed'
    && Date.parse(session.startsAt) <= now
    && Date.parse(session.endsAt) >= now)) {
    liveCheckinTimer = setInterval(refreshLiveCheckins, 30000);
  }
}

function bindCourseLearnerFilter(formSelector, sessionField = null) {
  const form = $(formSelector);
  if (!form) return;
  const courseSelect = $('select[name="course_id"]', form);
  const learnerSelect = $('select[name="student_id"]', form);
  const sessionSelect = sessionField ? $(`select[name="${sessionField}"]`, form) : null;
  courseSelect.addEventListener('change', () => {
    for (const option of learnerSelect.options) option.hidden = Boolean(option.value) && option.dataset.course !== courseSelect.value;
    learnerSelect.value = '';
    if (sessionSelect) {
      for (const option of sessionSelect.options) option.hidden = Boolean(option.value) && option.dataset.course !== courseSelect.value;
      sessionSelect.value = '';
    }
  });
}

async function submitJsonForm(event, route, successMessage) {
  event.preventDefault();
  const values = Object.fromEntries(new FormData(event.currentTarget).entries());
  try {
    await portalApi(route, { method: 'POST', body: values });
    await refreshPage(currentUser());
    toast(successMessage);
  } catch (error) {
    toast(error.message, 'error');
  }
}

async function refreshLiveCheckins() {
  try {
    const response = await portalApi('admin/attendance_live.php');
    appData.instructor.checkins = response.checkins;
    const rows = response.checkins.map(record => [record.code, record.learner, record.matric, record.checkedInAt, `${Math.round(Number(record.distance))} m`, record.status]);
    $('#live-checkins').innerHTML = response.checkins.length
      ? table(['Course', 'Learner', 'Matric', 'Checked in', 'Distance', 'Status'], rows)
      : '<p class="muted empty-message">No check-ins in currently open sessions.</p>';
  } catch (error) {
    toast(error.message, 'error');
  }
}

async function loadPageData(user) {
  const notifications = await portalApi('student/notifications.php');
  appData = { ...appData, user, notifications: notifications.notifications };
  if (page === 'dashboard' || page === 'attendance') {
    appData.summary = await portalApi('student/summary.php');
  } else if (page === 'results') {
    appData.results = await portalApi('student/results.php');
  } else if (page === 'certificates') {
    appData.certificates = (await portalApi('student/certificates.php')).certificates;
  } else if (page === 'transfer') {
    appData.transfer = await portalApi('student/transfer_apply.php');
  } else if (page === 'profile') {
    appData.profile = (await portalApi('student/profile.php')).profile;
  } else if (page === 'admin') {
    if (user.role === 'instructor') {
      appData.instructor = await portalApi('admin/instructor_workspace.php');
      appData.scoreOptions = {
        courses: appData.instructor.courses,
        learners: appData.instructor.roster.map(learner => ({ id: learner.studentId, name: learner.learner, courseId: learner.courseId })),
        scores: (await portalApi('admin/score_options.php')).scores,
      };
    } else if (user.role === 'hod') {
      appData.hod = await portalApi('admin/hod_workspace.php');
      appData.queue = await portalApi('admin/queue.php');
      appData.scoreOptions = await portalApi('admin/score_options.php');
    } else if (user.role === 'director') {
      appData.director = await portalApi('admin/director_workspace.php');
      appData.queue = await portalApi('admin/queue.php');
      appData.policies = await portalApi('admin/policies.php');
    } else if (user.role === 'superadmin') {
      appData.superadmin = await portalApi('admin/superadmin_workspace.php');
      delete appData.queue;
    } else {
      appData.queue = await portalApi('admin/queue.php');
      delete appData.scoreOptions;
    }
    if (user.role !== 'instructor' && user.role !== 'hod' && user.role !== 'director') {
      delete appData.scoreOptions;
    }
  }
}

async function refreshPage(user) {
  await loadPageData(user);
  renderShell(user);
}

async function initialize() {
  if (page === 'login') {
    renderLogin();
    return;
  }
  if (page === 'register') {
    await renderRegister();
    return;
  }
  try {
    const { user } = await portalApi('auth/me.php');
    appData.user = user;
    sessionStorage.setItem('skillportal-user', JSON.stringify(user));
    await loadPageData(user);
    renderShell(user);
  } catch (error) {
    if (error.status !== 401) {
      $('#main').textContent = error.message;
      $('#main').classList.add('startup-error');
    }
  }
}

initialize();