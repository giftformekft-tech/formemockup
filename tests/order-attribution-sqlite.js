// Execute the report's actual read queries against a small, local SQLite fixture.
// Node 22+; no npm dependencies or external database required.
const fs = require('node:fs');
const { DatabaseSync } = require('node:sqlite');
const database = new DatabaseSync(process.argv[2]);
try {
    const sql = fs.readFileSync(0, 'utf8');
    if (process.argv[3] === 'exec') { database.exec(sql); process.stdout.write('[]'); }
    else process.stdout.write(JSON.stringify(database.prepare(sql).all()));
} finally { database.close(); }
