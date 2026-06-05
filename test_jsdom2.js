process.on('unhandledRejection', (reason, promise) => {
  console.log('Unhandled Rejection at:', promise, 'reason:', reason);
});
const jsdom = require("jsdom");
const { JSDOM } = jsdom;
const fs = require("fs");

const html = fs.readFileSync('AdminConsoleV2_test.html', 'utf8');

const dom = new JSDOM(html, {
  runScripts: "dangerously",
  resources: "usable"
});

setTimeout(() => {
  try {
    dom.window.switchTab('schema');
    console.log("Schema HTML:", dom.window.document.getElementById('schemaCategories').innerHTML);
    dom.window.switchTab('settings');
    console.log("Settings HTML length:", dom.window.document.getElementById('tab-settings').innerHTML.length);
  } catch(e) {
    console.log("Caught Error during switchTab:", e);
  }
}, 2000);
