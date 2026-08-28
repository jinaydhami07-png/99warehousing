'use strict';

const express = require('express');
const database = require('../config/database');
const { success } = require('../utils/ApiResponse');

const router = express.Router();

/**
 * Liveness + readiness in one endpoint.
 *
 * Returns 503 when the database is unreachable so a load balancer or
 * orchestrator stops routing traffic to this instance instead of serving
 * errors to users.
 */
router.get('/', (req, res) => {
  const db = database.health();
  const status = db.ready ? 200 : 503;

  res.status(status).json({
    success: db.ready,
    message: db.ready ? 'OK' : 'Degraded — database unavailable',
    data: {
      uptime: Math.floor(process.uptime()),
      timestamp: new Date().toISOString(),
      database: db.status,
      memoryMB: Math.round(process.memoryUsage().heapUsed / 1024 / 1024),
    },
  });
});

module.exports = router;
