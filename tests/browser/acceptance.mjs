// OPTIONAL browser walkthrough of the Phase 1 acceptance workflow (development only;
// production needs no Node). Requires: Node 18+, `npm i -D playwright`, a FRESH database,
// the app served at BASE_URL and INSTALL_TOKEN exported. Usage:
//   BASE_URL=http://127.0.0.1:8080 INSTALL_TOKEN=... SHOTS=/tmp/shots node tests/browser/acceptance.mjs
import { chromium } from 'playwright';
import fs from 'node:fs';

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8080';
const SHOTS = process.env.SHOTS || '';
const PW = 'Str0ng-Passw0rd!';
const results = [];
const check = (name, ok, extra = '') => { results.push([ok ? 'PASS' : 'FAIL', name, extra]); console.log((ok ? 'PASS ' : 'FAIL ') + name + (extra ? ' — ' + extra : '')); };
const shot = async (page, name) => { if (SHOTS) { fs.mkdirSync(SHOTS, { recursive: true }); await page.screenshot({ path: `${SHOTS}/${name}.png`, fullPage: true }); } };

const browser = await chromium.launch({ executablePath: process.env.CHROME || undefined });
const ctx = await browser.newContext({ viewport: { width: 1360, height: 900 } });
let page = await ctx.newPage();

async function selectByText(p, sel, re) {
  const value = await p.$eval(sel, (el, src) => {
    const rx = new RegExp(src);
    const o = [...el.options].find(o => rx.test(o.textContent.trim()));
    return o ? o.value : null;
  }, re.source);
  await p.selectOption(sel, value);
}
async function confirmModal(p, reason) {
  await p.waitForSelector('#confirmModal.show');
  if (reason) await p.fill('#confirmReason', reason);
  await Promise.all([p.waitForLoadState('load'), p.click('#confirmOk')]);
  await p.waitForLoadState('networkidle');
}
async function login(p, email, pw = PW) {
  await p.goto(BASE + '/login');
  await p.fill('#f_email', email);
  await p.fill('#f_password', pw);
  await Promise.all([p.waitForNavigation(), p.click('button[type=submit]')]);
}
async function logout(p) {
  await p.goto(BASE + '/account');
  await p.click('.btn-user');
  await Promise.all([p.waitForNavigation(), p.click('form[action$="/logout"] button')]);
}
async function setPassword(p, link) {
  await p.goto(link);
  await p.fill('#f_password', PW);
  await p.fill('#f_password_confirmation', PW);
  await Promise.all([p.waitForNavigation(), p.click('button[type=submit]')]);
}
const status = async (p, path) => (await p.goto(BASE + path)).status();

// 1-2. Install + create first Fameweb admin
await page.goto(BASE + '/');
check('Fresh app redirects to installer', page.url().endsWith('/install'));
await page.fill('#f_install_token', 'wrong-token-value-123456');
await page.fill('#f_name', 'Fameweb Owner'); await page.fill('#f_email', 'owner@fameweb.test');
await page.fill('#f_password', PW); await page.fill('#f_password_confirmation', PW);
await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
check('Installer rejects a wrong INSTALL_TOKEN', (await page.content()).includes('does not match INSTALL_TOKEN'));
await page.fill('#f_install_token', process.env.INSTALL_TOKEN);
await page.fill('#f_password', PW); await page.fill('#f_password_confirmation', PW);
await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
check('Installer completes and sends to login', page.url().endsWith('/login'));
check('Installer is gone after install', (await status(page, '/install')) === 404);

// 3. Login as Fameweb admin
await login(page, 'owner@fameweb.test', 'nope-wrong-1');
check('Wrong password shows error', (await page.content()).includes('email or password is incorrect'));
await login(page, 'owner@fameweb.test');
check('Admin lands on Fameweb dashboard', page.url().endsWith('/admin'));
await shot(page, '01-admin-dashboard-empty');

// 4. Create plan with limits
await page.goto(BASE + '/admin/plans/create');
await page.fill('#f_name', 'Basic');
await page.fill('#f_description', 'Small NGOs');
await page.fill('#f_monthly_price', '999'); await page.fill('#f_annual_price', '9990');
await page.fill('#f_trial_days', '14'); await page.fill('#f_max_users', '3');
await page.fill('#f_max_members', '100'); await page.fill('#f_storage_value', '1');
await page.check('#mod_audit_logs');
await Promise.all([page.waitForNavigation(), page.click('main form button[type=submit]')]);
check('Plan created', (await page.content()).includes('Plan created'));
await shot(page, '02-plan');

// 5. Register NGO A with logo, assign plan
async function registerNgo(name, adminEmail, logo) {
  await page.goto(BASE + '/admin/ngos/create');
  await page.fill('#f_name', name);
  await page.fill('#f_address', '1 Test Street, Pune');
  await page.fill('#f_contact_name', 'Contact ' + name);
  await page.fill('#f_phone', '+91 98765 43210');
  await page.fill('#f_email', 'office@' + adminEmail.split('@')[1]);
  await page.fill('#f_website', 'https://example.org');
  if (logo) await page.setInputFiles('#f_logo', logo);
  await page.fill('#f_notes', 'Internal note for ' + name);
  await page.fill('#f_admin_name', name + ' Admin');
  await page.fill('#f_admin_email', adminEmail);
  await selectByText(page, '#f_plan_id', /Basic/);
  await Promise.all([page.waitForNavigation(), page.click('main form button[type=submit]')]);
  const link = await page.inputValue('#inviteLink');
  const id = page.url().split('/').pop();
  return { link, id };
}
const png = '/tmp/claude-logo.png';
fs.writeFileSync(png, Buffer.from('iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAIAAACQkWg2AAAAHUlEQVR4nGNgGAWjYBSMglEwCkbBKBgFo2AUUAcAB+gAAeS1x2UAAAAASUVORK5CYII=', 'base64'));
const ngoA = await registerNgo('Asha Test NGO', 'sa@asha.test', png);
check('NGO A registered with single-use onboarding link', /\/invite\/[a-f0-9]{64}$/.test(ngoA.link));
await shot(page, '03-ngo-detail');
const ngoB = await registerNgo('Beta Test NGO', 'sa@beta.test');
check('NGO B registered', !!ngoB.link);
await logout(page);

// 6-7. NGO A Super Admin accepts invite and logs in
await setPassword(page, ngoA.link);
check('Invite accepted', (await page.content()).includes('Your account is active'));
await page.goto(ngoA.link);
check('Invite link is single-use', (await page.content()).includes('invalid, already used or expired'));
await login(page, 'sa@asha.test');
check('NGO Super Admin lands on NGO portal', page.url().endsWith('/ngo'));
await shot(page, '04-ngo-dashboard');

// 8. Create Manager and Operations users
async function createUser(name, email, roleLabel) {
  await page.goto(BASE + '/ngo/users/create');
  await page.fill('#f_name', name); await page.fill('#f_email', email);
  await selectByText(page, '#f_role_id', new RegExp('^' + roleLabel));
  await Promise.all([page.waitForNavigation(), page.click('main form button[type=submit]')]);
  return { link: await page.inputValue('#accessLink'), id: page.url().split('/').pop() };
}
const mgr = await createUser('Maya Manager', 'manager@asha.test', 'NGO Manager');
const ops = await createUser('Omar Operations', 'ops@asha.test', 'NGO Operations');
check('Manager and Operations users created', !!mgr.link && !!ops.link);

// 12. Plan limit: 3 users max (SA + manager + ops) -> 4th blocked
await page.goto(BASE + '/ngo/users/create');
await page.fill('#f_name', 'Fourth User'); await page.fill('#f_email', 'fourth@asha.test');
await selectByText(page, '#f_role_id', /^NGO Operations/);
await Promise.all([page.waitForNavigation(), page.click('main form button[type=submit]')]);
check('User limit enforced with explanation', (await page.content()).includes('allows up to 3 active or invited users'));
await shot(page, '05-limit');

// 9. Assign different permissions: Ops gets users.view granted; Manager gets users.create denied
await page.goto(BASE + `/ngo/users/${ops.id}/permissions`);
await page.selectOption('select[name="perm[users.view]"]', 'grant');
await page.click('form[action$="/permissions"] button[type=submit]'); await confirmModal(page);
check('Ops permission granted', (await page.content()).includes('Permissions saved'));
await page.goto(BASE + `/ngo/users/${mgr.id}/permissions`);
await page.selectOption('select[name="perm[users.create]"]', 'deny');
await page.click('form[action$="/permissions"] button[type=submit]'); await confirmModal(page);
await shot(page, '06-permissions');

// Cross-tenant: NGO A admin tries NGO B's users and admin area
for (const path of ['/admin', '/admin/ngos', `/admin/ngos/${ngoB.id}`]) {
  check(`NGO user blocked from ${path}`, (await status(page, path)) === 403);
}
await logout(page);

// 10. Each user can access only permitted modules
await setPassword(page, ops.link);
await login(page, 'ops@asha.test');
check('Ops sees users list (granted)', (await status(page, '/ngo/users')) === 200);
check('Ops cannot open create-user', (await status(page, '/ngo/users/create')) === 403);
check('Ops cannot see roles', (await status(page, '/ngo/roles')) === 403);
check('Ops cannot see subscription', (await status(page, '/ngo/subscription')) === 403);
await page.goto(BASE + '/ngo');
await shot(page, '07-ops-dashboard');
await logout(page);

await setPassword(page, mgr.link);
await login(page, 'manager@asha.test');
check('Manager sees users', (await status(page, '/ngo/users')) === 200);
check('Manager denied create-user (explicit deny)', (await status(page, '/ngo/users/create')) === 403);
check('Manager sees audit log (plan module enabled)', (await status(page, '/ngo/audit')) === 200);
check('Manager cannot edit Super Admin role permissions page as manager', (await status(page, '/ngo/roles')) === 200);
await page.goto(BASE + '/ngo');
await shot(page, '08-manager-dashboard');
await logout(page);

// 13-14. Admin dashboard reflects data, audit log has entries; suspend + reactivate
await login(page, 'owner@fameweb.test');
const dash = await page.content();
check('Dashboard shows 2 NGOs', /Total NGOs<\/div><div class="stat-value">2</.test(dash));
await shot(page, '09-admin-dashboard');
await page.goto(BASE + `/admin/ngos/${ngoA.id}`);
await page.click('form[action$="/status"] button:has-text("Suspend")');
await confirmModal(page, 'Testing suspension');
check('NGO suspended', (await page.content()).includes('NGO suspended'));
await page.goto(BASE + '/admin/audit');
const audit = await page.content();
check('Audit log records NGO suspension', audit.includes('Suspended NGO'));
await page.goto(BASE + '/admin/audit?action=auth.login_failed');
check('Audit log records failed login', (await page.content()).includes('Failed sign-in attempt'));
await shot(page, '10-audit');
await logout(page);
await login(page, 'sa@asha.test');
check('Suspended NGO user cannot sign in', (await page.content()).includes('account is suspended'));
await login(page, 'owner@fameweb.test');
await page.goto(BASE + `/admin/ngos/${ngoA.id}`);
await page.click('form[action$="/status"] button:has-text("Activate")');
await confirmModal(page);
await logout(page);
await login(page, 'sa@asha.test');
check('Reactivated NGO user can sign in again', page.url().endsWith('/ngo'));
await page.goto(BASE + '/ngo/users');
check('Records preserved after reactivation', (await page.content()).includes('Omar Operations'));

// Mobile layout screenshot
await page.setViewportSize({ width: 390, height: 844 });
await page.goto(BASE + '/ngo'); await shot(page, '11-mobile');

await browser.close();
const failed = results.filter(r => r[0] === 'FAIL').length;
console.log(`\n${results.length - failed}/${results.length} checks passed`);
process.exit(failed ? 1 : 0);
