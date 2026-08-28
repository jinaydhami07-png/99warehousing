/* ============================================================
   Buy Per Square Foot — shared site behaviour
   Navbar scroll state, mobile menu, scroll reveal.
   Loaded with `defer` on every page. Page-specific logic stays
   inline in each page's own <script> block.
   ============================================================ */
(function () {
  'use strict';

  /* ─── Navbar scroll state ─── */
  var navbar = document.getElementById('navbar') || document.querySelector('.navbar');

  if (navbar) {
    var onScroll = function () {
      var scrolled = window.scrollY > 50;
      navbar.classList.toggle('scrolled', scrolled);
      var arrow = document.querySelector('.scroll-arrow');
      if (arrow) arrow.style.opacity = scrolled ? '0' : '1';
    };
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();
  }

  /* ─── Mobile menu ─── */
  var menu = document.getElementById('mobileMenu');
  var hamburger = document.getElementById('hamburger');

  function toggleMenu() {
    if (!menu) return;
    var open = menu.classList.toggle('open');
    if (hamburger) {
      var bars = hamburger.querySelectorAll('span');
      if (bars.length === 3) {
        bars[0].style.transform = open ? 'rotate(45deg) translate(5px,5px)' : '';
        bars[1].style.opacity = open ? '0' : '';
        bars[2].style.transform = open ? 'rotate(-45deg) translate(5px,-5px)' : '';
      }
      hamburger.setAttribute('aria-expanded', String(open));
    }
  }

  // Pages wire this up via onclick="toggleMenu()", so it has to be global.
  window.toggleMenu = toggleMenu;

  // Close the menu on Escape.
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && menu && menu.classList.contains('open')) toggleMenu();
  });

  /* ─── Scroll reveal ─── */
  var revealables = document.querySelectorAll('.reveal');

  if (revealables.length) {
    if ('IntersectionObserver' in window) {
      var observer = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry, i) {
          if (!entry.isIntersecting) return;
          setTimeout(function () { entry.target.classList.add('visible'); }, i * 80);
          observer.unobserve(entry.target);
        });
      }, { threshold: 0.1 });
      revealables.forEach(function (el) { observer.observe(el); });
    } else {
      revealables.forEach(function (el) { el.classList.add('visible'); });
    }
  }
})();
