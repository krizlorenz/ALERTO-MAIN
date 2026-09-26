/**
 * ALERTO - Disaster Preparedness and Relief Assistance System
 * Client-side script for public navigation and admin sidebar interactions
 */

document.addEventListener('DOMContentLoaded', () => {
  // 1. Mobile Navigation Menu Toggle (admin-homepage.php, user-homepage.php, index.html)
  const menuToggle = document.getElementById('menuToggle');
  const mobileNavMenu = document.getElementById('mobileNavMenu');

  if (menuToggle && mobileNavMenu) {
    const toggleMobileNav = () => {
      const isExpanded = menuToggle.getAttribute('aria-expanded') === 'true';
      menuToggle.setAttribute('aria-expanded', String(!isExpanded));
      menuToggle.classList.toggle('active');
      mobileNavMenu.classList.toggle('active');
    };

    const closeMobileNav = () => {
      menuToggle.classList.remove('active');
      menuToggle.setAttribute('aria-expanded', 'false');
      mobileNavMenu.classList.remove('active');
    };

    menuToggle.addEventListener('click', (e) => {
      e.stopPropagation();
      toggleMobileNav();
    });

    // Close when clicking navigation links
    mobileNavMenu.querySelectorAll('a').forEach((link) => {
      link.addEventListener('click', closeMobileNav);
    });

    // Close when clicking outside
    document.addEventListener('click', (e) => {
      if (
        mobileNavMenu.classList.contains('active') &&
        !mobileNavMenu.contains(e.target) &&
        !menuToggle.contains(e.target)
      ) {
        closeMobileNav();
      }
    });

    // Close on Escape key press
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && mobileNavMenu.classList.contains('active')) {
        closeMobileNav();
      }
    });
  }

  // 2. Admin Sidebar Mobile Toggle (admin-request.php, admin-verify.php)
  const sidebarMobileToggle = document.getElementById('sidebarMobileToggle');
  const adminSidebar = document.getElementById('adminSidebar');
  const sidebarBackdrop = document.getElementById('sidebarBackdrop');

  if (sidebarMobileToggle && adminSidebar) {
    const openSidebar = () => {
      adminSidebar.classList.add('sidebar-open');
      sidebarMobileToggle.setAttribute('aria-expanded', 'true');
      if (sidebarBackdrop) {
        sidebarBackdrop.classList.add('active');
      }
    };

    const closeSidebar = () => {
      adminSidebar.classList.remove('sidebar-open');
      sidebarMobileToggle.setAttribute('aria-expanded', 'false');
      if (sidebarBackdrop) {
        sidebarBackdrop.classList.remove('active');
      }
    };

    const toggleSidebar = () => {
      if (adminSidebar.classList.contains('sidebar-open')) {
        closeSidebar();
      } else {
        openSidebar();
      }
    };

    sidebarMobileToggle.addEventListener('click', (e) => {
      e.stopPropagation();
      toggleSidebar();
    });

    if (sidebarBackdrop) {
      sidebarBackdrop.addEventListener('click', closeSidebar);
    }

    // Close on Escape key press
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && adminSidebar.classList.contains('sidebar-open')) {
        closeSidebar();
      }
    });

    // Close when clicking links inside sidebar on mobile
    adminSidebar.querySelectorAll('.nav-item-link, .sidebar-logout-link').forEach((link) => {
      link.addEventListener('click', () => {
        if (window.innerWidth < 901) {
          closeSidebar();
        }
      });
    });
  }

  // 3. Student Sidebar Mobile Toggle (user-homepage.php)
  const studentSidebarMobileToggle = document.getElementById('studentSidebarMobileToggle');
  const studentSidebar = document.getElementById('studentSidebar');
  const studentSidebarBackdrop = document.getElementById('studentSidebarBackdrop') || document.getElementById('sidebarBackdrop');

  if (studentSidebarMobileToggle && studentSidebar) {
    const openStudentSidebar = () => {
      studentSidebar.classList.add('sidebar-open');
      studentSidebarMobileToggle.setAttribute('aria-expanded', 'true');
      if (studentSidebarBackdrop) {
        studentSidebarBackdrop.classList.add('active');
      }
    };

    const closeStudentSidebar = () => {
      studentSidebar.classList.remove('sidebar-open');
      studentSidebarMobileToggle.setAttribute('aria-expanded', 'false');
      if (studentSidebarBackdrop) {
        studentSidebarBackdrop.classList.remove('active');
      }
    };

    const toggleStudentSidebar = () => {
      if (studentSidebar.classList.contains('sidebar-open')) {
        closeStudentSidebar();
      } else {
        openStudentSidebar();
      }
    };

    studentSidebarMobileToggle.addEventListener('click', (e) => {
      e.stopPropagation();
      toggleStudentSidebar();
    });

    if (studentSidebarBackdrop) {
      studentSidebarBackdrop.addEventListener('click', closeStudentSidebar);
    }

    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && studentSidebar.classList.contains('sidebar-open')) {
        closeStudentSidebar();
      }
    });
  }
});
