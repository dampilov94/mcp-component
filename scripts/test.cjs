// Offline tests: pure PHP logic and the real kernel with stubbed MODX classes, and the Node
// bridge against a mock endpoint and against that kernel. Nothing here contacts a real site.
const path = require("node:path");
const { spawnSync } = require("node:child_process");

const root = path.resolve(__dirname, "..");
const run = (command, args) => spawnSync(command, args, { cwd: root, stdio: "inherit", timeout: 120000 }).status;

const php = run(process.env.PHP_BIN || "php", ["-n", path.join("tests", "php", "run.php")]);
const node = run(process.execPath, ["--test", path.join("tests", "client.test.js"), path.join("tests", "endpoint.test.js")]);
process.exitCode = php === 0 && node === 0 ? 0 : 1;
