export const metadata = {
  title: 'Buy Per Square Foot',
  description: "India's most trusted industrial and warehousing real estate platform",
};

/**
 * The site's pages are static HTML in /public and are served directly by
 * Next.js. This layout only wraps the handful of Next routes (currently
 * just the "/" redirect), so it stays deliberately bare.
 */
export default function RootLayout({ children }) {
  return (
    <html lang="en">
      <body>{children}</body>
    </html>
  );
}
