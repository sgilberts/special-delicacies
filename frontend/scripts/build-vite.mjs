import { spawnSync } from "node:child_process";
import { fileURLToPath } from "node:url";

const viteCli = fileURLToPath(new URL("../node_modules/vite/bin/vite.js", import.meta.url));
const env = {
  ...process.env,
  RAYON_NUM_THREADS: process.env.RAYON_NUM_THREADS || "1",
};

const result = spawnSync(
  process.execPath,
  ["--max-old-space-size=512", viteCli, "build", "--minify=false"],
  { env, stdio: "inherit" },
);

if (result.error) throw result.error;
process.exitCode = result.status ?? 1;
