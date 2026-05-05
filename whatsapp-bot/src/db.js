const mysql = require('mysql2/promise');

function createPool(config) {
  return mysql.createPool({
    host: config.database.host,
    port: config.database.port,
    user: config.database.user,
    password: config.database.password,
    database: config.database.name,
    waitForConnections: true,
    connectionLimit: 5,
    namedPlaceholders: true,
    charset: 'utf8mb4'
  });
}

module.exports = { createPool };
