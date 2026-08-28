'use strict';

const catchAsync = require('../utils/catchAsync');
const { success, created } = require('../utils/ApiResponse');
const authService = require('../services/auth.service');
const env = require('../config/env');

/** POST /api/v1/auth/register */
const register = catchAsync(async (req, res) => {
  const { accessToken, user } = await authService.register(res, req.body);
  created(res, { message: 'Account created', data: { accessToken, user } });
});

/** POST /api/v1/auth/login */
const login = catchAsync(async (req, res) => {
  const { accessToken, user } = await authService.login(res, req.body);
  success(res, { message: 'Signed in', data: { accessToken, user } });
});

/** POST /api/v1/auth/admin — "Staff access" passkey panel on the login page */
const adminLogin = catchAsync(async (req, res) => {
  const { accessToken, user } = await authService.adminLogin(res, req.body.passkey);
  success(res, { message: 'Admin session started', data: { accessToken, user } });
});

/**
 * GET /api/v1/auth/google
 *
 * Google OAuth is not wired up on this server yet (no client credentials in
 * .env). Rather than 404 — which surfaces as a confusing dead button —
 * redirect back to the login page with a flag it already knows how to
 * display. Swap this for the real consent-screen redirect once
 * GOOGLE_CLIENT_ID / GOOGLE_CLIENT_SECRET are configured.
 */
const googleStart = (req, res) => {
  res.redirect('/login.html?error=google_not_configured');
};

/** POST /api/v1/auth/refresh */
const refresh = catchAsync(async (req, res) => {
  const { accessToken, user } = await authService.refresh(req, res);
  success(res, { message: 'Session refreshed', data: { accessToken, user } });
});

/** POST /api/v1/auth/logout */
const logout = catchAsync(async (req, res) => {
  await authService.logout(req, res);
  success(res, { message: 'Signed out' });
});

/** GET /api/v1/auth/me */
const me = catchAsync(async (req, res) => {
  success(res, { message: 'Session valid', data: { user: req.user } });
});

module.exports = { register, login, adminLogin, googleStart, refresh, logout, me };
