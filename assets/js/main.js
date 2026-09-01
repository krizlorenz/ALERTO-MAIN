/**
 * ALERTO - Disaster Preparedness and Relief Assistance System
 * Client-side script for header and navigation interactions
 */

document.addEventListener('DOMContentLoaded', () => {
  // Mobile Navigation Menu Toggle
  const menuToggle = document.getElementById('menuToggle');
  const mobileNavMenu = document.getElementById('mobileNavMenu');

  if (menuToggle && mobileNavMenu) {
    menuToggle.addEventListener('click', () => {
      const isExpanded = menuToggle.getAttribute('aria-expanded') === 'true';
      menuToggle.setAttribute('aria-expanded', String(!isExpanded));
      menuToggle.classList.toggle('active');
      mobileNavMenu.classList.toggle('active');
    });

    mobileNavMenu.querySelectorAll('a').forEach((link) => {
      link.addEventListener('click', () => {
        menuToggle.classList.remove('active');
        menuToggle.setAttribute('aria-expanded', 'false');
        mobileNavMenu.classList.remove('active');
      });
    });
  }
});
