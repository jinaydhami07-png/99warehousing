/**
 * Process entry point.
 *
 * Responsibilities beyond "listen on a port":
 *   • connect to MongoDB BEFORE accepting traffic, so the server never
 *     serves requests it cannot fulfil
 *   • shut down gracefully on SIGTERM/SIGINT so in-flight requests finish
 *     and the connection pool closes cleanly (important on Kubernetes and
 *     any platform that sends SIGTERM before killing a container)
 *   • crash loudly on unhandled rejections and uncaught exceptions rather
 *     than limping along in an unknown state
 */
'use strict';

const app = require('./app');
const env = require('./config/env');
const logger = require('./config/logger');
const database = require('./config/database');

let server;

async function start() {
  // Await the connection: if the database is unreachable, fail at boot with
  // a clear message rather than surfacing 500s to the first users.
  await database.connect();

  /* Say where images are going, at boot, every time. Whether uploads land in
     S3 or fall back to MongoDB depends on two environment variables, and a
     silent fallback is the kind of thing that gets noticed weeks later when
     the database has quietly grown by a gigabyte. */
  const storage = require('./services/storage.service');
  if (storage.available()) {
    logger.info(
      { bucket: env.media.s3.bucket, region: env.media.s3.region, cdn: Boolean(env.media.s3.publicBaseUrl) },
      'Media storage: AWS S3' + (env.media.s3.publicBaseUrl ? ' via CDN' : ' (direct, no CDN configured)')
    );
  } else if (env.media.disabled) {
    /* Chosen, not stumbled into. Logged at info because nothing is wrong:
       photos are still resized, still served at several widths, still fast.
       They just live in MongoDB. */
    logger.info(
      'Media storage: MongoDB (MEDIA_DRIVER=mongo — S3 deliberately off). ' +
        'Images are resized and stored at every width in the database.'
    );
  } else {
    logger.warn(
      'Media storage: MongoDB — AWS_S3_BUCKET and AWS_REGION are not set, so ' +
        'uploaded image bytes are stored in the database. Set them in server/.env ' +
        'to use S3, or set MEDIA_DRIVER=mongo to silence this.'
    );
  }

  server = app.listen(env.port, () => {
    /* env.port is a port number normally, but a Unix socket path when
       Passenger supplies one. Printing "http://localhost:/tmp/x.sock" for the
       socket case is the kind of line that sends you debugging the wrong
       thing in cPanel's stderr.log. */
    const where =
      typeof env.port === 'number'
        ? `http://localhost:${env.port}`
        : `unix socket ${env.port}`;

    logger.info(
      { port: env.port, env: env.nodeEnv, pid: process.pid },
      `API listening on ${where}`
    );
  });

  // Slightly above the typical 60s ALB idle timeout, so the load balancer
  // closes idle connections first. Prevents sporadic 502s.
  server.keepAliveTimeout = 65_000;
  server.headersTimeout = 66_000;
}

/**
 * Graceful shutdown: stop accepting new connections, let in-flight requests
 * drain, then close the database pool.
 */
async function shutdown(signal) {
  logger.info({ signal }, 'Shutdown signal received');

  // Hard limit — if something hangs, exit anyway rather than blocking the
  // orchestrator's termination grace period forever.
  const forceExit = setTimeout(() => {
    logger.fatal('Graceful shutdown timed out — forcing exit');
    process.exit(1);
  }, 15_000);
  forceExit.unref();

  try {
    if (server) {
      await new Promise((resolve, reject) =>
        server.close((err) => (err ? reject(err) : resolve()))
      );
      logger.info('HTTP server closed');
    }
    await database.disconnect();
    clearTimeout(forceExit);
    process.exit(0);
  } catch (err) {
    logger.fatal({ err }, 'Error during shutdown');
    process.exit(1);
  }
}

process.on('SIGTERM', () => shutdown('SIGTERM'));
process.on('SIGINT', () => shutdown('SIGINT'));

/**
 * An unhandled rejection means a promise failed with nobody watching. The
 * process is now in an undefined state, so shut down and let the supervisor
 * restart a clean one.
 */
process.on('unhandledRejection', (reason) => {
  logger.fatal({ err: reason }, 'Unhandled promise rejection — shutting down');
  shutdown('unhandledRejection');
});

/**
 * An uncaught exception cannot be recovered from safely: the stack is gone
 * and state may be corrupt. Exit immediately without attempting cleanup
 * that could itself throw.
 */
process.on('uncaughtException', (err) => {
  logger.fatal({ err }, 'Uncaught exception — exiting immediately');
  process.exit(1);
});

start().catch((err) => {
  logger.fatal({ err: err.message }, 'Failed to start server');
  process.exit(1);
});
