// Runs each helper for real, in Docker, and checks it from the outside the way Mendbird talks to it.
//   node helpers/test/run.mjs            everything
//   node helpers/test/run.mjs linux      one part: wordpress, php, laravel, linux, apps or github
// Needs Docker. Uses its own copy of the signing rules (HMAC-SHA256 over "pull:host:time", "reply:time:body" and
// "push:time:body"), so it checks the helpers against the published design, not against Mendbird's code.
import { execFile, execFileSync } from "node:child_process";
import { promisify } from "node:util";
import { createHmac, randomBytes, timingSafeEqual } from "node:crypto";
import { createServer } from "node:http";
import { mkdirSync, readFileSync, writeFileSync, chmodSync, rmSync, cpSync } from "node:fs";
import { fileURLToPath } from "node:url";

const here = fileURLToPath(new URL(".", import.meta.url)), out = `${here}out/`;
const only = process.argv[2];
const KEY = randomBytes(32).toString("base64url"), HOST = "localhost", SITE = "7";
const hmac = line => createHmac("sha256", KEY).update(line).digest("hex");
const same = (a, b) => a.length === b.length && timingSafeEqual(Buffer.from(a), Buffer.from(b));
const sh = (cmd, args, opts = {}) => execFileSync(cmd, args, { encoding: "utf8", stdio: ["ignore", "pipe", "pipe"], cwd: here, ...opts });
// The Linux runs post to a receiver in this process, so they must not block it while they wait.
const shAsync = async (cmd, args, opts = {}) => (await promisify(execFile)(cmd, args, { encoding: "utf8", cwd: here, maxBuffer: 64 << 20, ...opts })).stdout;
const compose = (...args) => sh("docker", ["compose", "-p", "mendbird-helper-test", "-f", "compose.yml", ...args]);
const sleep = ms => new Promise(r => setTimeout(r, ms));
let failed = 0;
const check = (ok, what) => { console.log(`${ok ? "ok  " : "FAIL"} ${what}`); if (!ok) failed++; };

// What a helper may ever send, from docs/helper-design.md.
const KINDS = new Set(["cms", "plugin", "theme", "php", "ext", "db", "server", "node", "composer", "npm", "pypi", "gem", "go", "cargo", "maven", "nuget", "hex", "pub", "os", "deb", "rpm", "apk"]);
const SETTINGS = new Set(["debug_on", "dashboard_code_editor", "plugin_auto_updates", "php_version_announced", "admins"]);
function onlyAllowed(list) {
  const top = Object.keys(list).every(k => ["helper", "software", "settings"].includes(k));
  const items = list.software.every(it => KINDS.has(it.kind) && Object.keys(it).every(k => ["kind", "name", "version", "active"].includes(k)));
  const settings = Object.entries(list.settings).every(([k, v]) => SETTINGS.has(k) && (typeof v === "boolean" || Number.isInteger(v)));
  return top && items && settings;
}

function fill(path, target) {
  const text = readFileSync(`${here}../${path}`, "utf8").replaceAll("__MENDBIRD_KEY__", KEY).replaceAll("__MENDBIRD_HOST__", HOST).replaceAll("__MENDBIRD_SITE__", SITE);
  mkdirSync(target.replace(/[^/]+$/, ""), { recursive: true });
  writeFileSync(target, text);
  return target;
}

// Asks a helper the way Mendbird does. Returns the response, its body, and whether the reply signature is right.
async function ask(url, { time = Math.floor(Date.now() / 1000), key = KEY } = {}) {
  const sign = createHmac("sha256", key).update(`pull:${HOST}:${time}`).digest("hex");
  const res = await fetch(url, { headers: { "mendbird-time": String(time), "mendbird-sign": sign }, redirect: "manual" });
  const body = await res.text();
  return { res, body, signed: same(res.headers.get("mendbird-sign") ?? "", hmac(`reply:${time}:${body}`)) };
}
async function until(url, tries = 60) {
  for (let i = 0; i < tries; i++) { try { if ((await fetch(url, { redirect: "manual" })).status < 500) return; } catch {} await sleep(2000); }
  throw new Error(`${url} never came up`);
}

async function wordpress() {
  console.log("\nWordPress plugin");
  fill("wordpress/mendbird-helper.php", `${out}wordpress/mendbird-helper.php`);
  compose("up", "-d", "db", "wordpress", "cli");
  await until("http://localhost:8081/");
  const wp = (...args) => compose("exec", "-T", "cli", "wp", ...args);
  for (let i = 0; i < 30; i++) { try { wp("db", "check"); break; } catch { await sleep(2000); } }
  try { wp("core", "is-installed"); } catch {
    wp("core", "install", "--url=http://localhost:8081", "--title=Bakery", "--admin_user=owner", "--admin_password=test-only-password", "--admin_email=owner-private@example.com", "--skip-email");
  }
  wp("user", "create", "customer", "customer-private@example.com", "--role=subscriber", "--display_name=Private Customer Name", "--porcelain").trim;
  wp("post", "create", "--post_title=PRIVATE-POST-TITLE", "--post_status=private", "--post_content=private words");
  wp("plugin", "activate", "mendbird-helper");

  const url = "http://localhost:8081/?mendbird-helper";
  const stranger = await fetch(url);
  const strangerBody = await stranger.text();
  check(stranger.status === 200 && !strangerBody.includes('"software"') && !stranger.headers.get("mendbird-sign"), "unsigned: the normal page, nothing to show a helper is there");
  check(!(await ask(url, { key: "a-guess" })).body.includes('"software"'), "signed with the wrong key: the normal page");
  check(!(await ask(url, { time: Math.floor(Date.now() / 1000) - 400 })).body.includes('"software"'), "a request over five minutes old: the normal page");

  const time = Math.floor(Date.now() / 1000) + 1;
  const { res, body, signed } = await ask(url, { time });
  check(res.status === 200 && /json/.test(res.headers.get("content-type")), "Mendbird's request: the list, as JSON");
  check(signed, "the reply is signed, so Mendbird can tell it was not changed");
  const list = JSON.parse(body);
  check(onlyAllowed(list), "the list holds only the agreed fields");
  check(list.software.some(s => s.kind === "cms" && s.name === "wordpress" && /^\d+\.\d+/.test(s.version)), "WordPress and its version");
  check(list.software.some(s => s.kind === "plugin" && s.name === "mendbird-helper" && s.active === true), "plugins, with versions and whether active");
  check(list.software.some(s => s.kind === "theme" && s.active === true), "themes");
  check(list.software.some(s => s.kind === "db" && s.name === "mariadb"), "the database");
  check(list.software.some(s => s.kind === "php") && list.software.some(s => s.kind === "server" && s.name === "apache"), "PHP and the web server");
  check(list.settings.debug_on === true && list.settings.admins === 1, `settings: debug mode on, one admin, counted not named ${JSON.stringify(list.settings)}`);
  check(!/private|owner|customer|test-only|@example/i.test(body), "no names, emails, posts or passwords anywhere in it");
  check(!(await ask(url, { time })).body.includes('"software"'), "the same signed request a second time: the normal page");
  await connect(wp, url);
}

// The copy from wordpress.org has no key written in. Its Connect button goes to Mendbird, which sends the admin back
// with a one time code; the plugin swaps the code for the key with a stand-in Mendbird on port 8090.
async function connect(wp, url) {
  console.log("\nWordPress plugin from wordpress.org, connected");
  writeFileSync(`${out}wordpress/mendbird-helper.php`, readFileSync(`${here}../wordpress/mendbird-helper.php`, "utf8"));
  await sleep(3000); // PHP's file cache in the site checks for changes every two seconds
  const KEY2 = randomBytes(32).toString("base64url"), KEY3 = randomBytes(32).toString("base64url");
  const asked = [];
  const mendbird = createServer((req, res) => {
    let body = "";
    req.on("data", c => (body += c)).on("end", () => {
      asked.push({ path: req.url, body });
      const code = JSON.parse(body || "{}").code;
      const answer = { "code-for-this-site-0001": { site: 7, host: HOST, key: KEY2 }, "code-for-another-site-01": { site: 8, host: "other.example", key: KEY3 } }[code];
      res.writeHead(answer ? 200 : 400, { "content-type": "application/json" }).end(JSON.stringify(answer ?? { error: "no" }));
    });
  }).listen(8090);
  try {
    check(!(await ask(url)).body.includes('"software"'), "not connected yet: the normal page, even to the old key");
    // Run without blocking this process, which is also the stand-in Mendbird the plugin calls.
    const php = async code => (await shAsync("docker", ["compose", "-p", "mendbird-helper-test", "-f", "compose.yml", "exec", "-T", "cli", "wp", "eval", code])).trim();
    const start = new URL(await php("echo mendbird_helper_connect_url();"));
    const state = start.searchParams.get("state");
    check(start.origin === "http://host.docker.internal:8090" && start.pathname === "/connect" && start.searchParams.get("site") === HOST && /\/wp-admin\/tools\.php\?page=mendbird-helper$/.test(start.searchParams.get("back")) && /^\w{32}$/.test(state), "Connect sends the admin to Mendbird with the site, the way back and a state");
    check(/not started from this site/.test(await php(`var_export(mendbird_helper_finish("code-for-this-site-0001", "a-state-it-never-made-000"));`)), "a code with someone else's state is refused, and Mendbird is not even asked");
    check(asked.length === 0, "nothing was sent for it");
    // The same visit's state still works: a wrong link must not cancel a connection under way.
    const again = state;
    check(await php(`var_export(mendbird_helper_finish("code-for-this-site-0001", "${again}"));`) === "true", "the right state, after a wrong one: the code is swapped for the key");
    check(asked.length === 1 && asked[0].path === "/api/connect" && asked[0].body === '{"code":"code-for-this-site-0001"}', "the swap sends only the code");
    check(await php("echo wp_json_encode(get_option('mendbird_helper_connection'));") === JSON.stringify({ key: KEY2, host: HOST, site: 7 }), "the key is kept in the site's options");
    check(!(await php("echo implode(',', array_keys(wp_load_alloptions()));")).split(",").includes("mendbird_helper_connection"), "and not loaded on every page");
    const time = Math.floor(Date.now() / 1000) + 2;
    const pulled = await ask(url, { key: KEY2, time });
    check(pulled.res.status === 200 && pulled.body.includes('"software"') && same(pulled.res.headers.get("mendbird-sign") ?? "", createHmac("sha256", KEY2).update(`reply:${time}:${pulled.body}`).digest("hex")), "Mendbird's request with the new key: the list, signed with it");
    check(/not started from this site/.test(await php(`var_export(mendbird_helper_finish("code-for-this-site-0001", "${again}"));`)), "the same way back a second time is refused");
    const third = new URL(await php("echo mendbird_helper_connect_url();")).searchParams.get("state");
    check(/is for other\.example, but this site is localhost/.test(await php(`var_export(mendbird_helper_finish("code-for-another-site-01", "${third}"));`)), "a key for another site is refused");
    check(/does not look like a Mendbird helper key/.test(await php(`var_export(mendbird_helper_paste("${KEY3}"));`)), "a pasted key needs its whole line");
    check(await php(`var_export(mendbird_helper_paste("mb1:${HOST}:${KEY3}"));`) === "true", "a pasted line with this site's name is kept");
    check((await ask(url, { key: KEY3, time: time + 1 })).body.includes('"software"') && !(await ask(url, { key: KEY2, time: time + 2 })).body.includes('"software"'), "the pasted key replaces the old one");
  } finally { mendbird.close(); }
}

async function php() {
  console.log("\nSingle PHP file");
  cpSync(`${here}php-site`, `${out}php-site`, { recursive: true });
  fill("php/mendbird-helper.php", `${out}php-site/html/mendbird-helper.php`);
  compose("up", "-d", "php");
  const url = "http://localhost:8082/mendbird-helper.php";
  await until("http://localhost:8082/");
  const stranger = await fetch(url);
  check(stranger.status === 404 && (await stranger.text()) === "Not found\n", "unsigned: Not found");
  check((await ask(url, { key: "a-guess" })).res.status === 404, "signed with the wrong key: Not found");
  const { res, body, signed } = await ask(url);
  check(res.status === 200 && signed, "Mendbird's request: the list, signed");
  const list = JSON.parse(body);
  check(onlyAllowed(list), "the list holds only the agreed fields");
  check(list.software.some(s => s.kind === "composer" && s.name === "guzzlehttp/guzzle" && s.version === "7.4.0"), "packages from composer.lock, one folder above");
  check(list.software.some(s => s.name === "monolog/monolog" && s.version === "3.7.0"), "a leading v is dropped from versions");
  check(!list.software.some(s => s.name === "phpunit/phpunit"), "packages for development only are left out");
  check(list.software.some(s => s.kind === "cms" && s.name === "joomla" && s.version === "4.4.2"), "Joomla's version from its manifest");
  check(!/never-sent|APP_KEY|DB_PASSWORD/.test(body), "nothing from the .env file");
  const shown = compose("exec", "-T", "php", "php", "/var/www/html/mendbird-helper.php", "--show");
  check(JSON.parse(shown).helper.kind === "php", "php mendbird-helper.php --show prints the list on the server");
}

// Laravel: a fresh app with the package from helpers/laravel, its settings and routes cached as on a live server.
async function laravel() {
  console.log("\nLaravel package");
  const name = "mendbird-laravel-test";
  sh("docker", ["rm", "-f", name]);
  sh("docker", ["run", "-d", "--name", name, "-p", "8084:8000", "-v", `${here}../laravel:/pkg:ro`, "composer:2", "sh", "-c",
    ["composer create-project -q --no-interaction laravel/laravel /app", "cd /app", "composer config repositories.mendbird path /pkg", "composer require -q --no-interaction mendbird/helper:@dev",
      `printf 'MENDBIRD_KEY=${KEY}\\nMENDBIRD_HOST=${HOST}\\n' >> .env`, "php artisan config:cache", "php artisan route:cache", "php artisan serve --host=0.0.0.0 --port=8000"].join(" && ")]);
  try {
    const url = "http://localhost:8084/mendbird-helper";
    // Fetching while the app is still being built trips up Node's HTTP client, so wait for the server to say it is up.
    for (let i = 0; !sh("docker", ["logs", name], { stdio: ["ignore", "pipe", "pipe"] }).includes("Server running"); i++) { if (i > 150) throw new Error("Laravel never came up"); await sleep(2000); }
    const stranger = await fetch(url);
    check(stranger.status === 404 && (await stranger.text()) === "Not found\n", "unsigned: Not found");
    check(!stranger.headers.get("set-cookie"), "no cookies and no session");
    check((await ask(url, { key: "a-guess" })).res.status === 404, "signed with the wrong key: Not found");
    check((await ask(url, { time: Math.floor(Date.now() / 1000) - 600 })).res.status === 404, "ten minutes old: Not found");
    const { res, body, signed } = await ask(url);
    check(res.status === 200 && signed, "Mendbird's request: the list, signed, with settings and routes cached");
    const list = JSON.parse(body);
    check(onlyAllowed(list) && list.helper.kind === "laravel", "the list holds only the agreed fields");
    check(list.software.some(s => s.kind === "composer" && s.name === "laravel/framework" && /^\d+\.\d+/.test(s.version)), "Laravel's own version, from Composer's record");
    check(!list.software.some(s => s.name === "phpunit/phpunit"), "packages for development only are left out");
    check(list.settings.debug_on === true, "a new app's debug mode is seen as on");
    check(!/APP_KEY|base64:|DB_PASSWORD/.test(body), "nothing from the .env file");
    const shown = sh("docker", ["exec", "-w", "/app", name, "php", "artisan", "mendbird:show"]);
    check(JSON.parse(shown).helper.kind === "laravel", "php artisan mendbird:show prints the list");
  } finally {
    if (!process.env.KEEP) sh("docker", ["rm", "-f", name]);
  }
}

// Linux: each system posts to a receiver here, which checks the signature, the time and the shape.
const SYSTEMS = [
  ["debian:12", "apt-get update -qq && apt-get install -y -qq curl openssl nginx-light php-cli >/dev/null", "deb", "debian"],
  ["ubuntu:24.04", "apt-get update -qq && apt-get install -y -qq curl openssl nginx php-cli >/dev/null", "deb", "ubuntu"],
  ["alpine:3.20", "apk add --no-cache -q curl openssl nginx php83 && ln -sf /usr/bin/php83 /usr/bin/php", "apk", "alpine"],
  ["rockylinux/rockylinux:9", "dnf install -y -q openssl nginx >/dev/null", "rpm", "rocky"],
];
// A stand in for mendbird.com/api/helper: it keeps what it is sent, and says whether the signature and time are right.
function receiver(got) {
  return createServer((req, res) => {
    let body = "";
    req.on("data", c => (body += c));
    req.on("end", () => {
      const time = Number(req.headers["mendbird-time"]);
      const ok = req.url === "/api/helper" && req.headers["mendbird-site"] === SITE && Math.abs(Date.now() / 1000 - time) <= 300 && same(String(req.headers["mendbird-sign"]), hmac(`push:${time}:${body}`));
      got.push({ ok, body });
      res.writeHead(ok ? 204 : 403).end();
    });
  }).listen(8799);
}

async function linux() {
  console.log("\nLinux program");
  chmodSync(fill("linux/mendbird-helper", `${out}linux/mendbird-helper`), 0o755);
  const got = [], server = receiver(got);
  try {
    for (const [image, setup, pkg, os] of SYSTEMS) {
      got.length = 0;
      const run = await shAsync("docker", ["run", "--rm", "--add-host=host.docker.internal:host-gateway", "-e", "MENDBIRD_URL=http://host.docker.internal:8799",
        "-v", `${out}linux/mendbird-helper:/usr/local/bin/mendbird-helper:ro`, image, "sh", "-c",
        `${setup} && su nobody -s /bin/sh -c '/usr/local/bin/mendbird-helper --show' && su nobody -s /bin/sh -c /usr/local/bin/mendbird-helper && echo sent`], { timeout: 600000 });
      const shown = JSON.parse(run.slice(0, run.lastIndexOf("sent")).trim());
      check(onlyAllowed(shown), `${image}: --show prints only the agreed fields`);
      check(shown.software[0].kind === "os" && shown.software[0].name === os, `${image}: names its system`);
      check(shown.software.filter(s => s.kind === pkg).length > 20, `${image}: lists its ${pkg} packages (${shown.software.filter(s => s.kind === pkg).length})`);
      check(shown.software.some(s => s.kind === "server" && s.name === "nginx" && /^\d/.test(s.version)), `${image}: finds nginx and its version`);
      check(got.length === 1 && got[0].ok, `${image}: runs as an ordinary user and sends a signed list`);
      check(got[0] && JSON.stringify(JSON.parse(got[0].body)) === JSON.stringify(shown), `${image}: sends exactly what --show prints`);
    }
  } finally { server.close(); }
}

// Apps on a Linux server: a real Django in a virtual environment, Node.js, PHP, Ruby and Rust packages, and a real Go
// program, built here first. The helper must read every one as an ordinary user without running any of them.
async function apps() {
  console.log("\nApps on a Linux server");
  chmodSync(fill("linux/mendbird-helper", `${out}linux/mendbird-helper`), 0o755);
  cpSync(`${here}apps`, `${out}apps`, { recursive: true });
  await shAsync("docker", ["run", "--rm", "-v", `${out}apps/go:/src`, "-w", "/src", "-e", "CGO_ENABLED=0", "golang:1.22-alpine", "sh", "-c",
    "go mod init example.com/server >/dev/null 2>&1; go get golang.org/x/text@v0.3.7 >/dev/null 2>&1 && go build -o server . && chmod 755 server"], { timeout: 600000 });
  const got = [], server = receiver(got);
  try {
    const run = await shAsync("docker", ["run", "--rm", "--add-host=host.docker.internal:host-gateway", "-e", "MENDBIRD_URL=http://host.docker.internal:8799",
      "-v", `${out}linux/mendbird-helper:/usr/local/bin/mendbird-helper:ro`, "-v", `${out}apps:/fixture:ro`, "debian:12", "sh", "-c",
      "apt-get update -qq && apt-get install -y -qq curl openssl python3-venv php-cli >/dev/null && cp -r /fixture /srv/apps && python3 -m venv /srv/apps/shop/venv && /srv/apps/shop/venv/bin/pip install -q Django==4.2.0 >/dev/null 2>&1 && chmod -R a+rX /srv/apps"
      + " && su nobody -s /bin/sh -c 'MENDBIRD_APPS=\"/srv/apps/shop /srv/apps/go/server /srv/apps/missing\" /usr/local/bin/mendbird-helper --show' && su nobody -s /bin/sh -c 'MENDBIRD_APPS=\"/srv/apps/shop /srv/apps/go/server\" /usr/local/bin/mendbird-helper' && echo sent"], { timeout: 900000 });
    const shown = JSON.parse(run.slice(0, run.lastIndexOf("sent")).trim());
    const has = (kind, name, version) => shown.software.some(s => s.kind === kind && s.name === name && s.version === version);
    check(onlyAllowed(shown), "the list holds only the agreed fields");
    // pip records Django 4.2.0 as 4.2.
    check(has("pypi", "django", "4.2") && shown.software.some(s => s.kind === "pypi" && s.name === "asgiref"), "Python: Django and what it brought with it, from the virtual environment");
    check(has("npm", "express", "4.17.1") && has("npm", "@hono/node-server", "1.11.0"), "Node.js: packages from node_modules, scoped ones too");
    check(has("composer", "laravel/framework", "10.0.0") && !shown.software.some(s => s.name === "phpunit/phpunit"), "PHP: Composer's installed packages, without those for development");
    check(has("gem", "actionpack", "6.0.0") && has("cargo", "tokio", "1.0.0"), "Ruby and Rust lock files");
    check(has("go", "golang.org/x/text", "v0.3.7") && shown.software.some(s => s.kind === "go" && s.name === "stdlib" && /^1\.22\./.test(s.version)), "Go: the modules and Go version inside the program, without running it");
    check(!/never-sent/.test(JSON.stringify(shown)), "nothing from .env");
    check(got.length === 1 && got[0].ok && JSON.stringify(JSON.parse(got[0].body)).length > 0, "sends a signed list");
    // Alpine's smaller tools read binary files differently, so the Go program and node_modules are checked there too.
    const tiny = await shAsync("docker", ["run", "--rm", "-v", `${out}linux/mendbird-helper:/usr/local/bin/mendbird-helper:ro`, "-v", `${out}apps:/srv/apps:ro`, "alpine:3.20", "sh", "-c",
      "su nobody -s /bin/sh -c 'MENDBIRD_APPS=\"/srv/apps/shop /srv/apps/go/server\" /usr/local/bin/mendbird-helper --show'"], { timeout: 300000 });
    const alpine = JSON.parse(tiny).software, on = (kind, name) => alpine.some(s => s.kind === kind && s.name === name);
    check(on("go", "golang.org/x/text") && on("go", "stdlib") && on("npm", "@hono/node-server") && on("gem", "actionpack"), "Alpine: the same apps read the same way");
  } finally { server.close(); }
}

// GitHub: the workflow's program, run the way the workflow runs it, in a clean Node.js container over a small repository.
async function github() {
  console.log("\nGitHub workflow");
  const yml = readFileSync(`${here}../github/mendbird.yml`, "utf8");
  check(yml.includes("__SCRIPT__") && yml.includes("${{ secrets.MENDBIRD_KEY }}") && !yml.includes("__MENDBIRD_KEY__"), "the workflow takes its key from a GitHub secret, never from the file");
  check(/permissions:\s*\n\s*contents: read/.test(yml), "the workflow asks GitHub only for permission to read");
  cpSync(`${here}github-repo`, `${out}github-repo`, { recursive: true });
  const got = [], server = receiver(got);
  try {
    const run = await shAsync("docker", ["run", "--rm", "--add-host=host.docker.internal:host-gateway", "-e", "MENDBIRD_URL=http://host.docker.internal:8799", "-e", `MENDBIRD_SITE=${SITE}`, "-e", `MENDBIRD_KEY=${KEY}`,
      "-v", `${here}../github/mendbird-helper.mjs:/helper.mjs:ro`, "-v", `${out}github-repo:/repo:ro`, "-w", "/repo", "--user", "1000", "node:22-alpine", "node", "/helper.mjs"], { timeout: 300000 });
    const shown = JSON.parse(run.slice(run.indexOf("{"), run.lastIndexOf("Sent to Mendbird.")).trim());
    check(onlyAllowed(shown), "the list holds only the agreed fields");
    for (const [kind, name] of [["npm", "hono"], ["pypi", "django"], ["composer", "guzzlehttp/guzzle"], ["node", "node"]]) check(shown.software.some(s => s.kind === kind && s.name === name), `reads ${name} from its lock file`);
    check(!shown.software.some(s => s.name === "vitest"), "packages for development only are left out");
    check(!/never-sent|password/i.test(JSON.stringify(shown)), "nothing from .env or the code");
    check(got.length === 1 && got[0].ok, "sends a signed list");
    check(got[0] && JSON.stringify(JSON.parse(got[0].body)) === JSON.stringify(shown), "sends exactly what it prints in the log");
  } finally { server.close(); }
}

try {
  if (!only || only === "wordpress") await wordpress();
  if (!only || only === "php") await php();
  if (!only || only === "laravel") await laravel();
  if (!only || only === "linux") await linux();
  if (!only || only === "apps") await apps();
  if (!only || only === "github") await github();
} finally {
  if (!process.env.KEEP && (!only || ["wordpress", "php"].includes(only))) compose("down", "-v");
  if (!process.env.KEEP) rmSync(out, { recursive: true, force: true });
}
console.log(failed ? `\n${failed} failed` : "\nAll passed");
process.exit(failed ? 1 : 0);
