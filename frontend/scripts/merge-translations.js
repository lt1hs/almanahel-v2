const fs = require("fs");
const path = require("path");

const dir = path.join(__dirname, "..", "src", "translations");

function deepMerge(target, source) {
  for (const key of Object.keys(source)) {
    if (
      source[key] &&
      typeof source[key] === "object" &&
      !Array.isArray(source[key])
    ) {
      if (!target[key] || typeof target[key] !== "object") target[key] = {};
      deepMerge(target[key], source[key]);
    } else {
      target[key] = source[key];
    }
  }
  return target;
}

for (const locale of ["fa", "ar"]) {
  const mainPath = path.join(dir, `${locale}.json`);
  const extPath = path.join(dir, `extensions.${locale}.json`);
  const main = JSON.parse(fs.readFileSync(mainPath, "utf8"));
  const ext = JSON.parse(fs.readFileSync(extPath, "utf8"));
  deepMerge(main, ext);
  fs.writeFileSync(mainPath, JSON.stringify(main, null, 2) + "\n");
  console.log(`Merged extensions into ${locale}.json`);
}
