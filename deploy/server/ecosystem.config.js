/**
 * PM2 process configuration — keeps the API running continuously.
 *
 * PM2 supervises the Node process: if it crashes, PM2 restarts it. If it
 * crash-loops (a config error, say), the backoff and max_restarts settings
 * stop it hammering the database forever and leave the failure visible in
 * the logs instead.
 *
 *   npm run pm2:start     start under supervision
 *   npm run pm2:status    is it alive?
 *   npm run pm2:logs      tail the logs
 *   npm run pm2:restart   restart after a code change
 *   npm run pm2:stop      stop it
 */
module.exports = {
  apps: [
    {
      name: 'bpsf-api',
      script: 'src/server.js',
      cwd: __dirname,

      /* One process is right here: the bottleneck is MongoDB round-trips,
         not CPU. Clustering would also multiply the connection pool by the
         number of workers, which can exhaust the Atlas connection limit on
         a free/shared tier. Revisit only under measured CPU load. */
      instances: 1,
      exec_mode: 'fork',

      /* Restart on crash, with backoff so a boot-time failure (bad
         credentials, unreachable DB) does not spin at full speed. */
      autorestart: true,
      max_restarts: 10,
      min_uptime: '10s',      // under this counts as a failed start
      restart_delay: 4000,
      exp_backoff_restart_delay: 200,

      /* Recycle if memory runs away — a leak degrades gracefully instead of
         taking the box down. */
      max_memory_restart: '400M',

      /* Do NOT watch files in production: an editor writing a temp file
         would restart the API mid-request. Use pm2:restart deliberately. */
      watch: false,

      env: {
        NODE_ENV: 'development',
      },
      env_production: {
        NODE_ENV: 'production',
      },

      /* Logs persist to disk so a crash at 3am is still diagnosable. */
      error_file: 'logs/error.log',
      out_file: 'logs/out.log',
      merge_logs: true,
      time: true,

      /* Give in-flight requests time to finish on restart/stop, matching
         the graceful-shutdown handler in server.js. */
      kill_timeout: 16000,
      listen_timeout: 10000,
    },
  ],
};
