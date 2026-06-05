const jsdom = require("jsdom");
const { JSDOM } = jsdom;
const fs = require("fs");

const html = fs.readFileSync('AdminConsoleV2_test.html', 'utf8');

const virtualConsole = new jsdom.VirtualConsole();
virtualConsole.on("error", () => { console.log("JSDOM Error:", ...arguments); });
virtualConsole.on("warn", () => { console.log("JSDOM Warn:", ...arguments); });
virtualConsole.on("info", () => { console.log("JSDOM Info:", ...arguments); });
virtualConsole.on("dir", () => { console.log("JSDOM Dir:", ...arguments); });

const dom = new JSDOM(html, {
  runScripts: "dangerously",
  resources: "usable",
  virtualConsole
});

setTimeout(() => {
  try {
    dom.window.switchTab('schema');
    console.log("Switched to schema.");
    console.log("Schema HTML:", dom.window.document.getElementById('schemaCategories').innerHTML);
    
    dom.window.switchTab('settings');
    console.log("Switched to settings.");
    console.log("Settings HTML:", dom.window.document.getElementById('tab-settings').innerHTML.substring(0, 100));
  } catch(e) {
    console.log("Caught Error during switchTab:", e);
  }
}, 2000);
