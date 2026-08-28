/** @type {import('next').NextConfig} */
const nextConfig = {
  reactStrictMode: true,

  // Property images can be large; the upload route enforces its own limit.
  experimental: {
    serverActions: { bodySizeLimit: '12mb' },
  },

  async redirects() {
    return [
      // The site's pages are static HTML in /public. Send "/" to the homepage.
      { source: '/', destination: '/index.html', permanent: false },
    ];
  },

  async headers() {
    return [
      {
        source: '/api/:path*',
        headers: [
          { key: 'X-Content-Type-Options', value: 'nosniff' },
          { key: 'Cache-Control', value: 'no-store' },
        ],
      },
    ];
  },
};

module.exports = nextConfig;
