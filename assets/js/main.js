/**
 * ALERTO - Disaster Preparedness and Relief Assistance System
 * Client-side script for public navigation and admin sidebar interactions
 */

document.addEventListener('DOMContentLoaded', () => {
  // 1. Public Mobile Navigation Menu Toggle (index.html)
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

  // 2. Admin Sidebar Mobile Toggle (requests.html, verifications.html)
  const sidebarMobileToggle = document.getElementById('sidebarMobileToggle');
  const adminSidebar = document.getElementById('adminSidebar');
  const sidebarBackdrop = document.getElementById('sidebarBackdrop');

  if (sidebarMobileToggle && adminSidebar) {
    const toggleSidebar = () => {
      adminSidebar.classList.toggle('sidebar-open');
      if (sidebarBackdrop) {
        sidebarBackdrop.classList.toggle('active');
      }
    };

    sidebarMobileToggle.addEventListener('click', toggleSidebar);

    if (sidebarBackdrop) {
      sidebarBackdrop.addEventListener('click', () => {
        adminSidebar.classList.remove('sidebar-open');
        sidebarBackdrop.classList.remove('active');
      });
    }
  }
});
