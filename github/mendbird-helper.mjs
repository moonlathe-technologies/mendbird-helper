// Mendbird helper for sites built from GitHub: Cloudflare Workers and Pages, Vercel, Netlify, AWS Lambda, or anything
// else deployed from a repository. Version 1.0.0.
//
// It reads the lock files in your repository, which list every package your site is built from with its exact
// version, and sends that list to Mendbird, which checks each one against the lists of known security flaws.
//
// How it works, in full, because you should be able to check:
//
// 1. It runs as a GitHub Action: when you push to your main branch, once a day, and whenever you press Run workflow.
// 2. It reads package names and versions from package-lock.json, yarn.lock, pnpm-lock.yaml, composer.lock,
//    requirements.txt, poetry.lock, uv.lock, Pipfile.lock, Gemfile.lock, go.mod, Cargo.lock, gradle.lockfile,
//    packages.lock.json, mix.lock and pubspec.lock, up to three folders down (leaving out test, example and docs
//    folders), and the Node.js version from .nvmrc or .node-version. Nothing else is read: not your code, not your
//    settings, not your other secrets.
// 3. It prints the list in the Action's log, then signs it with your MENDBIRD_KEY secret and posts it to
//    https://mendbird.com/api/helper. Mendbird refuses anything unsigned, older than five minutes, or sent twice.
//
// It never changes your repository, opens no pull requests, and needs only permission to read. Delete the workflow
// file and it stops. Licence: MIT. The code is public at https://github.com/moonlathe-technologies/mendbird-helper

import { readFileSync, readdirSync } from "node:fs";
import { createHmac } from "node:crypto";
import { join } from "node:path";

const VERSION = "1.0.0";
const ENDPOINT = `${process.env.MENDBIRD_URL || "https://mendbird.com"}/api/helper`;
// Folders that hold installed or built copies, or tests, examples and docs, rather than the live site's own lock files.
const SKIP = new Set(["node_modules", "vendor", "venv", "dist", "build", "target", "out", "coverage", "test", "tests", "__tests__", "spec", "fixtures", "__fixtures__", "testdata", "example", "examples", "docs"]);

function* lockFiles(dir, depth = 0) {
  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    if (entry.isFile()) yield [entry.name, join(dir, entry.name)];
    else if (entry.isDirectory() && depth < 3 && !SKIP.has(entry.name) && !entry.name.startsWith(".")) yield* lockFiles(join(dir, entry.name), depth + 1);
  }
}

// Python names are compared in their standard form: lower case, with runs of - _ and . as one -.
const python = name => name.toLowerCase().replace(/[-_.]+/g, "-");
// [[package]] tables with a name and a version, as in poetry.lock, uv.lock and Cargo.lock.
const tomlPackages = text => [...text.matchAll(/\[\[package\]\]\s*\nname = "([^"]+)"\s*\nversion = "([^"]+)"/g)].map(m => [m[1], m[2]]);

// Each reader turns one kind of lock file into [name, version] pairs. Packages only used while developing are left
// out where the file says which they are, as they are not part of the live site.
const READERS = {
  "package-lock.json": ["npm", text => {
    const lock = JSON.parse(text);
    if (lock.packages) return Object.entries(lock.packages).filter(([path, p]) => path && !p.dev && !p.link && p.version).map(([path, p]) => [path.slice(path.lastIndexOf("node_modules/") + 13), p.version]);
    const walk = deps => Object.entries(deps ?? {}).flatMap(([name, p]) => (p.dev ? [] : [[name, p.version], ...walk(p.dependencies)]));
    return walk(lock.dependencies);
  }],
  "yarn.lock": ["npm", text => text.split(/\n(?=\S)/).flatMap(block => {
    const head = block.match(/^"?((?:@[^@/]+\/)?[^@"\s]+)@/), version = block.match(/\n\s+version:? "?([^"\s]+)"?/);
    return head && version && !version[1].includes("use.local") ? [[head[1], version[1]]] : [];
  })],
  "pnpm-lock.yaml": ["npm", text => [...text.matchAll(/^ {2}'?\/?((?:@[^@/\s]+\/)?[^@/\s']+)[@/](\d[^(:'\s]*)/gm)].map(m => [m[1], m[2]])],
  "composer.lock": ["composer", text => (JSON.parse(text).packages ?? []).map(p => [p.name, String(p.version).replace(/^v/, "")])],
  "poetry.lock": ["pypi", text => tomlPackages(text).map(([n, v]) => [python(n), v])],
  "uv.lock": ["pypi", text => tomlPackages(text).map(([n, v]) => [python(n), v])],
  "Pipfile.lock": ["pypi", text => Object.entries(JSON.parse(text).default ?? {}).map(([n, p]) => [python(n), String(p.version ?? "").replace(/^==/, "")])],
  "Gemfile.lock": ["gem", text => [...text.matchAll(/^ {4}([\w.-]+) \(([^)\s-]+)[^)]*\)$/gm)].map(m => [m[1], m[2]])],
  "go.mod": ["go", text => [...text.matchAll(/^(?:require\s+|\t)([\w.~/-]+\.[\w.~/-]+)\s+(v[\w.+-]+)/gm)].map(m => [m[1], m[2]])],
  "Cargo.lock": ["cargo", text => tomlPackages(text)],
  // Java and Kotlin: "group:artifact:version=configurations", leaving out those used only by tests.
  "gradle.lockfile": ["maven", text => [...text.matchAll(/^([\w.-]+:[\w.-]+):([^=\s]+)=(.+)$/gm)].filter(m => m[3].split(",").some(c => !/^test/i.test(c))).map(m => [m[1], m[2]])],
  // .NET: every package each target framework resolved to, except the solution's own projects.
  "packages.lock.json": ["nuget", text => Object.values(JSON.parse(text).dependencies ?? {}).flatMap(deps => Object.entries(deps).filter(([, p]) => p.type !== "Project" && p.resolved).map(([n, p]) => [n, p.resolved]))],
  // Elixir: "name": {:hex, :name, "1.2.3", ...
  "mix.lock": ["hex", text => [...text.matchAll(/\{:hex, :([\w]+), "([^"]+)"/g)].map(m => [m[1], m[2]])],
  // Dart and Flutter: hosted packages, leaving out those for development only.
  "pubspec.lock": ["pub", text => text.split(/\n(?= {2}\S)/).flatMap(block => {
    const name = block.match(/^ {2}([\w]+):/), version = block.match(/\n {4}version: "?([^"\s]+)"?/);
    return name && version && /source: hosted/.test(block) && !/dependency: "direct dev"/.test(block) ? [[name[1], version[1]]] : [];
  })],
};
READERS["npm-shrinkwrap.json"] = READERS["package-lock.json"];
const isRequirements = name => /^requirements.*\.txt$/.test(name);
const requirements = text => [...text.matchAll(/^([A-Za-z0-9][\w.-]*)(?:\[[^\]]*\])?\s*==\s*([\w.!+-]+)/gm)].map(m => [python(m[1]), m[2]]);

export function list(root = process.cwd()) {
  const seen = new Map();
  const add = (kind, name, version) => {
    if (name && version) seen.set(`${kind} ${name} ${version}`, { kind, name, version });
  };
  for (const [name, path] of lockFiles(root)) {
    const [kind, read] = READERS[name] ?? (isRequirements(name) ? ["pypi", requirements] : []);
    if (read) {
      try { for (const [n, v] of read(readFileSync(path, "utf8"))) add(kind, n, v); } catch { console.log(`Could not read ${path}, so it was skipped.`); }
    }
    if (name === ".nvmrc" || name === ".node-version") add("node", "node", readFileSync(path, "utf8").trim().replace(/^v/, "").match(/^\d+(\.\d+){0,2}$/)?.[0]);
  }
  return { helper: { kind: "github", version: VERSION }, software: [...seen.values()], settings: {} };
}

async function send() {
  const body = JSON.stringify(list());
  console.log("This is everything the Mendbird helper sends:\n" + JSON.stringify(JSON.parse(body), null, 2));
  if (process.argv.includes("--show")) return;
  const key = process.env.MENDBIRD_KEY, site = process.env.MENDBIRD_SITE;
  if (!key) throw new Error("The MENDBIRD_KEY secret is missing. Add it under Settings, Secrets and variables, Actions, with the key from your Mendbird account.");
  const time = Math.floor(Date.now() / 1000);
  const sign = createHmac("sha256", key).update(`push:${time}:${body}`).digest("hex");
  const res = await fetch(ENDPOINT, { method: "POST", body, headers: { "content-type": "application/json", "mendbird-site": site, "mendbird-time": String(time), "mendbird-sign": sign }, signal: AbortSignal.timeout(30000) });
  if (!res.ok) throw new Error(`Mendbird did not accept the list (${res.status}). If you made a new key in your Mendbird account, update the MENDBIRD_KEY secret.`);
  console.log("Sent to Mendbird.");
}

if (process.argv[1] === undefined || import.meta.url === `file://${process.argv[1]}`) {
  send().catch(e => { console.error(e.message); process.exit(1); });
}
