const fs = require("node:fs");
const path = require("node:path");
const { spawnSync } = require("node:child_process");

const root = path.resolve(__dirname, "..");
let failures = 0;

function run(command, args) {
  return spawnSync(command, args, {
    cwd: root,
    encoding: "utf8",
    stdio: ["ignore", "pipe", "pipe"],
    timeout: 30000,
    windowsHide: true,
  });
}

function filesIn(relative) {
  const dir = path.join(root, relative);
  return fs.readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
    const name = path.join(relative, entry.name);
    return entry.isDirectory() ? filesIn(name) : entry.isFile() ? [name] : [];
  });
}

function check(label, result) {
  if (result.error || result.status !== 0) {
    failures++;
    console.error(`FAIL ${label}\n${result.stderr || result.stdout || result.error.message}`);
    return false;
  }
  return true;
}

const sources = ["client", "assets", "core", "_build", "scripts"].flatMap(filesIn);
const jsFiles = sources.filter((file) => /\.(?:js|cjs)$/.test(file));
for (const file of jsFiles) check(file, run(process.execPath, ["--check", file]));
console.log(`JavaScript: checked ${jsFiles.length} files.`);

const phpFiles = sources.filter((file) => file.endsWith(".php"));
const toolsDir = path.join(root, ".local", "tools");
let phpBins = process.env.PHP_BIN ? [process.env.PHP_BIN] : [];
if (!phpBins.length && fs.existsSync(toolsDir)) {
  phpBins = fs.readdirSync(toolsDir).filter((name) => name.startsWith("php-"))
    .map((name) => path.join(toolsDir, name, process.platform === "win32" ? "php.exe" : "bin/php"))
    .filter((file) => fs.existsSync(file));
}
if (!phpBins.length) phpBins = ["php"];
for (const php of phpBins) {
  const version = run(php, ["-n", "-v"]);
  if (!check(`PHP runtime: ${php} (set PHP_BIN if needed)`, version)) continue;
  for (const file of phpFiles) check(file, run(php, ["-n", "-l", file]));
  console.log(`${version.stdout.split(/\r?\n/)[0]}: checked ${phpFiles.length} files.`);
}

const read = (file) => fs.readFileSync(path.join(root, file), "utf8");
const pkg = JSON.parse(read("package.json"));
const lock = JSON.parse(read("package-lock.json"));
const versions = {
  package: pkg.version,
  lock: lock.version,
  lockRoot: lock.packages?.[""]?.version,
  build: read("_build/build.config.php").match(/define\(\s*['"]PKG_VERSION['"]\s*,\s*['"]([^'"]+)['"]/)?.[1],
  model: read("core/components/modxmcp/model/modxmcp.class.php").match(/const VERSION\s*=\s*['"]([^'"]+)['"]/)?.[1],
};
if (Object.values(versions).some((version) => version !== pkg.version)) {
  failures++;
  console.error("FAIL release versions:", versions);
}
if (!read("CHANGELOG.md").split(/\r?\n/).some((line) => line.startsWith(`## ${pkg.version} `))) {
  failures++;
  console.error(`FAIL CHANGELOG entry for ${pkg.version}`);
}
console.log(`Release metadata: ${pkg.version}.`);
console.log(failures ? `${failures} check(s) failed.` : "All checks passed (syntax and release metadata; no live writes).");
process.exitCode = failures ? 1 : 0;
